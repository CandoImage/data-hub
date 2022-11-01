<?php

/**
 * Pimcore
 *
 * This source file is available under two different licenses:
 * - GNU General Public License version 3 (GPLv3)
 * - Pimcore Commercial License (PCL)
 * Full copyright and license information is available in
 * LICENSE.md which is distributed with this source code.
 *
 *  @copyright  Copyright (c) Pimcore GmbH (http://www.pimcore.org)
 *  @license    http://www.pimcore.org/license     GPLv3 and PCL
 */

namespace Pimcore\Bundle\DataHubBundle\Controller;

use GraphQL\Error\DebugFlag;
use GraphQL\Error\FormattedError;
use GraphQL\Error\Warning;
use GraphQL\Executor\ExecutionResult;
use GraphQL\Executor\Promise\Promise;
use GraphQL\GraphQL;
use GraphQL\Language\AST\DocumentNode;
use GraphQL\Language\DirectiveLocation;
use GraphQL\Language\Parser;
use GraphQL\Language\Source;
use GraphQL\Server\Helper;
use GraphQL\Server\OperationParams;
use GraphQL\Server\ServerConfig;
use GraphQL\Type\Definition\Directive;
use GraphQL\Type\Definition\FieldArgument;
use GraphQL\Type\Definition\Type;
use GraphQL\Type\Schema;
use Http\Discovery\Psr17FactoryDiscovery;
use Pimcore\Bundle\DataHubBundle\Configuration;
use Pimcore\Bundle\DataHubBundle\Event\GraphQL\CacheItemEvents;
use Pimcore\Bundle\DataHubBundle\Event\GraphQL\ExecutorEvents;
use Pimcore\Bundle\DataHubBundle\Event\GraphQL\Model\CacheItemEvent;
use Pimcore\Bundle\DataHubBundle\Event\GraphQL\Model\ExecutorEvent;
use Pimcore\Bundle\DataHubBundle\Event\GraphQL\Model\ExecutorExceptionEvent;
use Pimcore\Bundle\DataHubBundle\Event\GraphQL\Model\ExecutorResultEvent;
use Pimcore\Bundle\DataHubBundle\GraphQL\ClassTypeDefinitions;
use Pimcore\Bundle\DataHubBundle\GraphQL\Mutation\MutationType;
use Pimcore\Bundle\DataHubBundle\GraphQL\Query\QueryType;
use Pimcore\Bundle\DataHubBundle\GraphQL\Service;
use Pimcore\Bundle\DataHubBundle\Helper\CacheHelper;
use Pimcore\Bundle\DataHubBundle\Helper\DefaultCacheFieldResolver;
use Pimcore\Bundle\DataHubBundle\PimcoreDataHubBundle;
use Pimcore\Bundle\DataHubBundle\Service\CheckConsumerPermissionsService;
use Pimcore\Bundle\DataHubBundle\Service\FileUploadService;
use Pimcore\Bundle\DataHubBundle\Service\OutputCacheService;
use Pimcore\Cache\Runtime;
use Pimcore\Config;
use Pimcore\Controller\FrontendController;
use Pimcore\Localization\LocaleServiceInterface;
use Pimcore\Logger;
use Pimcore\Model\Factory;
use Symfony\Bridge\PsrHttpMessage\Factory\HttpFoundationFactory;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;

class WebserviceController extends FrontendController
{
    /**
     * @var EventDispatcherInterface
     */
    private $eventDispatcher;

    /**
     * @var CheckConsumerPermissionsService
     */
    private $permissionsService;

    /**
     * @var OutputCacheService
     */
    private $cacheService;

    /**
     * @var FileUploadService
     */
    private $uploadService;

    /**
     * @var Helper
     */
    protected Helper $graphQlRequestHelper;

    /**
     * @var \Symfony\Component\HttpKernel\HttpKernelInterface
     */
    protected HttpKernelInterface $httpKernel;

    /**
     * @var array
     */
    protected array $baseOperationContext = [];

    /**
     * @param EventDispatcherInterface $eventDispatcher
     * @param \Pimcore\Bundle\DataHubBundle\Service\CheckConsumerPermissionsService $permissionsService
     * @param \Pimcore\Bundle\DataHubBundle\Service\OutputCacheService $cacheService
     * @param \Pimcore\Bundle\DataHubBundle\Service\FileUploadService $uploadService
     * @param \Symfony\Component\HttpKernel\HttpKernelInterface $httpKernel
     */
    public function __construct(
        EventDispatcherInterface $eventDispatcher,
        CheckConsumerPermissionsService $permissionsService,
        OutputCacheService $cacheService,
        FileUploadService $uploadService,
        HttpKernelInterface $httpKernel
    ) {
        $this->eventDispatcher = $eventDispatcher;
        $this->permissionsService = $permissionsService;
        $this->cacheService = $cacheService;
        $this->uploadService = $uploadService;
        $this->httpKernel = $httpKernel;
        $this->graphQlRequestHelper = new Helper();
    }

    protected array $loadedQueries = [];

    protected function addLoadedQuery($queryId, DocumentNode $query)
    {
        $this->loadedQueries[$queryId] = $query;
    }

    public function queryLoader($queryId, OperationParams $params)
    {
        if (!empty($this->loadedQueries[$queryId])) {
            return $this->loadedQueries[$queryId];
        }
        $config = Config::locateConfigFile('data-hub/graphql/' . $queryId . '.graphql');
        if (file_exists($config)) {
            return $this->loadedQueries[$queryId] = file_get_contents($config);
        }

        return null;
    }

    /**
     * Hijacks Helper::loadPersistedQuery() because the helper is no as
     * reusable as a helper might could be.
     *
     * We don't want to use our built-in loader directly because this would
     * break configurability.
     *
     * @return \GraphQL\Language\AST\DocumentNode|string
     *
     * @see Helper::loadPersistedQuery()
     *
     */
    protected function loadPersistedQuery(ServerConfig $config, OperationParams $operationParams)
    {
        // Hijack the private method from the helper object by binding a closure
        // to the object instance scope.
        $loader = \Closure::bind(
            function ($config, $operationParams) {
                return $this->loadPersistedQuery($config, $operationParams);
            },
            $this->graphQlRequestHelper,
            $this->graphQlRequestHelper
        );

        return $loader($config, $operationParams);
    }

    /**
     * Dynamic context to allow context info per operation.
     *
     * @return array
     */
    public function getOperationContext(OperationParams $params, DocumentNode $doc, string $operationType): array
    {
        // Enrich base context with operations information.
        return $this->baseOperationContext + ['operation' => $params, 'doc' => $doc, 'operationType' => $operationType];
    }

    protected function getGraphQlSchema(
        array $context,
        Service $service,
        LocaleServiceInterface $localeService,
        Factory $modelFactory
    ): Schema {
        $queryType = new QueryType($service, $localeService, $modelFactory, $this->eventDispatcher, [], $context);
        $mutationType = new MutationType($service, $localeService, $modelFactory, $this->eventDispatcher, [], $context);

        try {
            $schemaConfig = [
                'query' => $queryType
            ];
            if (!$mutationType->isEmpty()) {
                $schemaConfig['mutation'] = $mutationType;
            }
            // @TODO PURE POC - DOESN'T DO ANYTHING YET.
            $schemaConfig['directives'] = array_merge(GraphQL::getStandardDirectives(), [
                'cacheable' => new Directive([
                     'name' => 'cacheable',
                     'description' => 'Marks an element of a GraphQL schema as cacheable',
                     'locations' => [
                         DirectiveLocation::OBJECT,
                         DirectiveLocation::FIELD_DEFINITION,
                         DirectiveLocation::ENUM_VALUE,
                     ],
                     'args' => [
                         new FieldArgument([
                             'name' => 'ttl',
                             'type' => Type::int(),
                             'description' => 'Set the time to live for this item',
                             'defaultValue' => 0,
                         ]),
                     ],
                 ]),
            ]);

            $schema = new Schema(
                $schemaConfig
            );
        } catch (\Exception $e) {
            Warning::enable(false);
            $schema = new Schema(
                [
                    'query' => $queryType,
                    'mutation' => $mutationType
                ]
            );
            $schema->assertValid();
            Logger::error($e);
            throw $e;
        }

        return $schema;
    }

    public function graphQLErrorFormatter($e): array
    {
        return FormattedError::createFromException($e);
    }

    public function graphQLErrorHandler(array $errors, callable $formatter)
    {
        // Run custom event for each error.
        // @FIXME Accessing the request here doesn't feel right...
        /** @var \Symfony\Component\HttpFoundation\RequestStack $requestStack */
        $requestStack = $this->get('request_stack');
        foreach ($errors as &$e) {
            $exException = new ExecutorExceptionEvent($requestStack->getCurrentRequest(), $e);
            $this->eventDispatcher->dispatch($exException, ExecutorEvents::EXCEPTION);
            $e = $exException->getException();
        }

        return array_map($formatter, $errors);
    }

    /**
     * @param Service $service
     * @param LocaleServiceInterface $localeService
     * @param Factory $modelFactory
     * @param Request $request
     *
     * @return JsonResponse|Response
     *
     * @throws \Exception
     */
    public function webonyxAction(
        Service $service,
        LocaleServiceInterface $localeService,
        Factory $modelFactory,
        Request $request
    ) {
        $clientname = $request->get('clientname');

        $configuration = Configuration::getByName($clientname);
        if (!$configuration || !$configuration->isActive()) {
            throw new NotFoundHttpException('No active configuration found for ' . $clientname);
        }

        if (!$this->permissionsService->performSecurityCheck($request, $configuration)) {
            throw new AccessDeniedHttpException('Permission denied, apikey not valid');
        }

        $operations = $this->getWebonxyOperations($request);
        $isBatchedQuery = is_array($operations);
        if (!$isBatchedQuery) {
            $operations = [$operations];
        }

        // context info, will be passed on to all resolver function
        $this->baseOperationContext = ['clientname' => $clientname, 'configuration' => $configuration];
        $config = $this->getParameter('pimcore_data_hub');

        if (isset($config['graphql']) && isset($config['graphql']['not_allowed_policy'])) {
            PimcoreDataHubBundle::setNotAllowedPolicy($config['graphql']['not_allowed_policy']);
        }
        Runtime::set('datahub_context', $this->baseOperationContext);
        ClassTypeDefinitions::build($service, $this->baseOperationContext);

        $schema = $this->getGraphQlSchema(
            $this->baseOperationContext,
            $service,
            $localeService,
            $modelFactory,
        );
        static $defaultFieldResolver = [DefaultCacheFieldResolver::class, 'defaultFieldResolver'];

        $validators = null;
        if ($request->get('novalidate')) {
            // disable all validators except the listed ones
            $validators = [
//                    new NoUndefinedVariables()
            ];
        }

        $debugFlags = DebugFlag::NONE;
        if (\Pimcore::inDebugMode()) {
            $debugFlags = DebugFlag::INCLUDE_DEBUG_MESSAGE |
                            DebugFlag::INCLUDE_TRACE |
                            DebugFlag::RETHROW_INTERNAL_EXCEPTIONS |
                            DebugFlag::RETHROW_UNSAFE_EXCEPTIONS;
        }
        // Setup GraphQl config which is used later in all the helpers.
        $config = ServerConfig::create()
            ->setSchema($schema)
            ->setFieldResolver($defaultFieldResolver)
            ->setErrorsHandler([$this, 'graphQLErrorHandler'])
            ->setErrorFormatter([$this, 'graphQLErrorFormatter'])
            ->setQueryBatching(true)
            ->setContext([$this, 'getOperationContext'])
            ->setPersistentQueryLoader([$this, 'queryLoader'])
            ->setValidationRules($validators)
            ->setRootValue([])
            ->setDebugFlag($debugFlags)
        ;

        // Prepare all operations for execution.
        $responses = [];
        foreach ($operations as $i => $operation) {
            if (!$operation->query && $operation->queryId) {
                $operation->query = $this->loadPersistedQuery($config, $operation);
            }
            $parsedQuery = Parser::parse(new Source($operation->query ?? '', 'GraphQL'));

            // Add query to Cache Helper
            CacheHelper::setQuery($parsedQuery);
            if ($response = $this->cacheService->load($request, $operation, $parsedQuery)) {
                Logger::debug('Loading response from cache');
                $responses[] = $response;
                // Remove related operation from being processed.
                unset($operations[$i]);
                continue;
            }

            Logger::debug('Cache entry not found');

            try {
                $event = new ExecutorEvent(
                    $request,
                    $operation,
                    $schema,
                    $this->baseOperationContext,
                    $parsedQuery
                );

                $this->eventDispatcher->dispatch($event, ExecutorEvents::PRE_EXECUTE);
                // Inject parsed query via query loader because there's no
                // other way atm:
                // https://github.com/webonyx/graphql-php/pull/658
                $queryId = md5($event->getQuery());
                $this->addLoadedQuery($queryId, $event->getParsedQuery());
                $operation->query = null;
                $operation->queryId = $queryId;
            } catch (\Exception $e) {
                $exException = new ExecutorExceptionEvent($request, $e);
                $this->eventDispatcher->dispatch($exException, ExecutorEvents::EXCEPTION);
                $e = $exException->getException();
                $errorFormatter = FormattedError::prepareFormatter(
                    $config->getErrorFormatter(),
                    $config->getDebugFlag()
                );
                $responses[] = new JsonResponse([
                     'errors' => [
                         [
                             'message' => $errorFormatter($e),
                         ],
                     ],
                 ], 503, ['Cache-Control' => 'no-cache, no-store, must-revalidate']);
            }
        }

        // Now execute all open operations at once.
        foreach ($this->graphQlRequestHelper->executeBatch($config, $operations) as $i => $executionResult) {
            $operation = $operations[$i];
            if ($executionResult instanceof Promise) {
                $response = $executionResult->then(function ($result) use ($config, $operation, $request) {
                    return $this->processExecutionResult($config, $result, $operation, $request);
                });
            } else {
                $response = $this->processExecutionResult($config, $executionResult, $operation, $request);
            }
            $responses[] = $response;
        }

        return $this->buildWebonyxActionResponse($isBatchedQuery, $responses);
    }

    protected function processExecutionResult(
        ServerConfig $config,
        ExecutionResult $executionResult,
        OperationParams $operation,
        Request $request
    ): Response {
        try {
            // Allow last intervention after execution.
            $exResultEvent = new ExecutorResultEvent($request, $executionResult, $operation);
            $this->eventDispatcher->dispatch($exResultEvent, ExecutorEvents::POST_EXECUTE);

            // Use the native parser to create a response.
            $response = Psr17FactoryDiscovery::findResponseFactory()->createResponse();
            $response = $this->graphQlRequestHelper->toPsrResponse(
                $exResultEvent->getResult(),
                Psr17FactoryDiscovery::findResponseFactory()->createResponse(),
                $response->getBody()
            );
            // Convert the PSR-Response to a Symfony response.
            $httpFoundationFactory = new HttpFoundationFactory();
            $response = $httpFoundationFactory->createResponse($response);

            // Allow last interference before this response is cached.
            $cacheItemEvent = new CacheItemEvent($request, $executionResult, $operation, $response);
            $this->eventDispatcher->dispatch($cacheItemEvent, CacheItemEvents::CACHE_ITEM);
            if ($cacheItemEvent->isUseCache()) {
                $this->cacheService->save($request, $response, $operation, $cacheItemEvent->getCacheTags());
            }
        } catch (\Throwable $e) {
            $exException = new ExecutorExceptionEvent($request, $e);
            $this->eventDispatcher->dispatch($exException, ExecutorEvents::EXCEPTION);
            $e = $exException->getException();

            $errorFormatter = FormattedError::prepareFormatter(
                $config->getErrorFormatter(),
                $config->getDebugFlag()
            );

            $response = new JsonResponse([
                'errors' => [
                    [
                    'message' => $errorFormatter($e),
                    ],
                ],
            ], 503, ['Cache-Control' => 'no-cache, no-store, must-revalidate']);
        }

        return $response;
    }

    /**
     * @param \Symfony\Component\HttpFoundation\Request $request
     *
     * @return \GraphQL\Server\OperationParams|\GraphQL\Server\OperationParams[]
     *
     * @throws \GraphQL\Server\RequestError
     */
    protected function getWebonxyOperations(Request $request)
    {
        $contentType = $request->headers->get('content-type') ?? '';
        if (mb_stripos($contentType, 'multipart/form-data') !== false) {
            $input = $this->uploadService->parseUploadedFiles($request);
            $operations = OperationParams::create($input);
        } else {
            // Use the webonxy native handling.
            //$input = json_decode($request->getContent(), true);
            // Convert the symfony request to a PSR17 Request.
            $psrHttpFactory = new PsrHttpFactory(
                Psr17FactoryDiscovery::findServerRequestFactory(),
                Psr17FactoryDiscovery::findStreamFactory(),
                Psr17FactoryDiscovery::findUploadedFileFactory(),
                Psr17FactoryDiscovery::findResponseFactory()
            );
            $psrRequest = $psrHttpFactory->createRequest($request);
            $operations = $this->graphQlRequestHelper->parsePsrRequest($psrRequest);
        }

        return $operations;
    }

    /**
     * @param bool $isBatchedQuery
     * @param Response[] $responses
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    protected function buildWebonyxActionResponse(bool $isBatchedQuery, array $responses): Response
    {
        $origin = '*';
        if (!empty($_SERVER['HTTP_ORIGIN'])) {
            $origin = $_SERVER['HTTP_ORIGIN'];
        }

        if (!$isBatchedQuery) {
            $response = reset($responses);
        } else {
            $output = [];
            $httpHeaders = [];
            $statusCode = 200;
            $cacheable = true;
            $minMaxAge = true;
            $response = new JsonResponse('', 200, [], true);
            foreach ($responses as $queryResponse) {
                $output[] = $queryResponse->getContent();
                // Get the "highest" statusCode as it signifies an error.
                $statusCode = max($statusCode, $queryResponse->getStatusCode());
                // Check if response is cacheable and figure out the shortest
                // ttl.
                $cacheable = $cacheable && $queryResponse->isCacheable();
                if ($cacheable && !is_null($maxAge = $queryResponse->getMaxAge())) {
                    $minMaxAge = min($minMaxAge, $maxAge);
                }
                // Merge certain http headers.
                // @TODO Add the headers we surely forgot...
                foreach (['X-Cache-Tags' => 'string'] as $headerKey => $mode) {
                    switch ($mode) {
                        case 'string':
                            $httpHeaders[$headerKey] = ($httpHeaders[$headerKey] ?? '') .
                                $queryResponse->headers->get($headerKey);
                            break;
                        case 'multiple':
                            $response->headers->set($headerKey, $queryResponse->headers->get($headerKey), false);
                            break;
                    }
                }
                // Collect all cookies.
                foreach ($queryResponse->headers->getCookies() as $cookie) {
                    $response->headers->setCookie($cookie);
                }
            }
            $response->setStatusCode($statusCode);
            $output = '[' . implode(', ', $output) . ']';
            $response->setContent($output);
            // Only pass on server-side caching as the application might can
            // control it - while it surely can't control the browser.
            $response->setMaxAge(0);
            $response->headers->addCacheControlDirective('must-revalidate');
            if (!$cacheable) {
                $response->setSharedMaxAge(0);
                $response->setPrivate();
                $response->headers->addCacheControlDirective('no-store');
            } else {
                $response->setSharedMaxAge($minMaxAge);
                $response->setPublic();
                $response->headers->removeCacheControlDirective('no-store');
            }
        }
        $response->headers->set('Access-Control-Allow-Origin', $origin);
        $response->headers->set('Access-Control-Allow-Credentials', 'true');
        $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
        $response->headers->set('Access-Control-Allow-Headers', 'Origin, Content-Type, X-Auth-Token');

        return $response;
    }
}
