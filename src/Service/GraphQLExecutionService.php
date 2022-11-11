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

namespace Pimcore\Bundle\DataHubBundle\Service;

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
use Pimcore\Bundle\DataHubBundle\Helper\DefaultCacheFieldResolver;
use Pimcore\Cache\Runtime;
use Pimcore\Config;
use Pimcore\Localization\LocaleServiceInterface;
use Pimcore\Logger;
use Pimcore\Model\Factory;
use Psr\Container\ContainerInterface;
use Symfony\Bridge\PsrHttpMessage\Factory\HttpFoundationFactory;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;
use Symfony\Component\DependencyInjection\ContainerAwareInterface;
use Symfony\Component\DependencyInjection\ContainerAwareTrait;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;

class GraphQLExecutionService implements ContainerAwareInterface
{
    use ContainerAwareTrait;

    /**
     * @var \Symfony\Component\EventDispatcher\EventDispatcherInterface
     */
    private EventDispatcherInterface $eventDispatcher;

    /**
     * @var \Pimcore\Bundle\DataHubBundle\Service\CheckConsumerPermissionsService
     */
    private CheckConsumerPermissionsService $permissionsService;

    /**
     * @var \Pimcore\Bundle\DataHubBundle\Service\OutputCacheService
     */
    private OutputCacheService $cacheService;

    /**
     * @var \Pimcore\Bundle\DataHubBundle\GraphQL\Service
     */
    private Service $service;

    /**
     * @var \Pimcore\Localization\LocaleServiceInterface
     */
    private LocaleServiceInterface $localeService;

    /**
     * @var \Pimcore\Model\Factory
     */
    private Factory $modelFactory;

    /**
     * @var array
     */
    protected array $baseOperationContext = [];

    /**
     * @var array
     */
    protected array $loadedQueries = [];

    /**
     * @var \Symfony\Component\HttpKernel\HttpKernelInterface
     */
    private HttpKernelInterface $httpKernel;

    /**
     * @param EventDispatcherInterface $eventDispatcher
     * @param \Pimcore\Bundle\DataHubBundle\Service\CheckConsumerPermissionsService $permissionsService
     * @param \Pimcore\Bundle\DataHubBundle\Service\OutputCacheService $cacheService
     * @param \Pimcore\Bundle\DataHubBundle\Service\FileUploadService $uploadService
     * @param \Symfony\Component\HttpKernel\HttpKernelInterface $httpKernel
     */
    public function __construct(
        ContainerInterface $container,
        EventDispatcherInterface $eventDispatcher,
        CheckConsumerPermissionsService $permissionsService,
        Service $service,
        OutputCacheService $cacheService,
        LocaleServiceInterface $localeService,
        Factory $modelFactory,
        HttpKernelInterface $httpKernel
    ) {
        $this->container = $container;
        $this->graphQlRequestHelper = new Helper();
        $this->eventDispatcher = $eventDispatcher;
        $this->permissionsService = $permissionsService;
        $this->cacheService = $cacheService;
        $this->service = $service;
        $this->localeService = $localeService;
        $this->modelFactory = $modelFactory;
        $this->httpKernel = $httpKernel;
    }

    /**
     * @param \Symfony\Component\HttpFoundation\Request $request
     *
     * @return \GraphQL\Server\OperationParams|\GraphQL\Server\OperationParams[]
     *
     * @throws \GraphQL\Server\RequestError
     */
    public function getWebonxyOperations(Request $request)
    {
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

        return $operations;
    }

    public function getGraphQlSchema(
        array $context
    ): Schema {
        // Set global execution context.
        Runtime::set('datahub_context', $context);
        ClassTypeDefinitions::build($this->service, $context);

        $queryType = new QueryType(
            $this->service,
            $this->localeService,
            $this->modelFactory,
            $this->eventDispatcher,
            [],
            $context
        );

        $mutationType = new MutationType(
            $this->service,
            $this->localeService,
            $this->modelFactory,
            $this->eventDispatcher,
            [],
            $context
        );

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

    public function getGraphQlServerConfig(
        Schema $schema,
        $context,
        $validators = null
    ): ServerConfig {
        Runtime::set('datahub_context', $context);
        static $defaultFieldResolver = [DefaultCacheFieldResolver::class, 'defaultFieldResolver'];

        $debugFlags = DebugFlag::NONE;
        if (\Pimcore::inDebugMode()) {
            $debugFlags = DebugFlag::INCLUDE_DEBUG_MESSAGE |
                DebugFlag::INCLUDE_TRACE |
                DebugFlag::RETHROW_INTERNAL_EXCEPTIONS |
                DebugFlag::RETHROW_UNSAFE_EXCEPTIONS;
        }

        $this->baseOperationContext = $context;

        return ServerConfig::create()
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
    }

    public function graphQLErrorFormatter($e): array
    {
        return FormattedError::createFromException($e);
    }

    public function graphQLErrorHandler(array $errors, callable $formatter)
    {
        // Run custom event for each error.
        // @FIXME Accessing a request here doesn't feel right...
        /** @var \Symfony\Component\HttpFoundation\RequestStack $requestStack */
        $requestStack = $this->container->get('request_stack');
        foreach ($errors as &$e) {
            $exException = new ExecutorExceptionEvent($requestStack->getCurrentRequest(), $e);
            $this->eventDispatcher->dispatch($exException, ExecutorEvents::EXCEPTION);
            $e = $exException->getException();
        }

        return array_map($formatter, $errors);
    }

    /**
     * Helper which allows our query loader to avoid unnecessary lookups.
     *
     * This also helps to workaround and inefficiency in webonyx:
     * https://github.com/webonyx/graphql-php/pull/658
     *
     * @param $queryId
     * @param \GraphQL\Language\AST\DocumentNode $query
     *
     * @return void
     */
    public function addLoadedQuery($queryId, DocumentNode $query)
    {
        $this->loadedQueries[$queryId] = $query;
    }

    /**
     * Simple query loader that checks the config directory for a file.
     *
     * @param $queryId
     * @param \GraphQL\Server\OperationParams $params
     *
     * @return false|mixed|string|null
     */
    public function queryLoader($queryId, OperationParams $params)
    {
        // Check cache first - avoid multiple filesystem scans & reads.
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
    public function loadPersistedQuery(ServerConfig $config, OperationParams $operationParams)
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
     * @param \GraphQL\Server\OperationParams $params
     * @param \GraphQL\Language\AST\DocumentNode $doc
     * @param string $operationType
     *
     * @return array
     */
    public function getOperationContext(OperationParams $params, DocumentNode $doc, string $operationType): array
    {
        // Enrich base context with operations information.
        return $this->baseOperationContext + [
            'operation' => $params,
            'doc' => $doc,
            'operationType' => $operationType
        ];
    }

    /**
     * Executes the passed in operations and takes the cache in account.
     *
     * @param \Symfony\Component\HttpFoundation\Request $request
     * @param $operations
     * @param \GraphQL\Server\ServerConfig $graphQlConfig
     * @param bool $disableCache
     * @param string $subRequestController
     *
     * @return Response[]
     *
     * @throws \GraphQL\Error\SyntaxError
     */
    public function executeOperations(
        Request $request,
        $operations,
        ServerConfig $graphQlConfig,
        bool $disableCache = false,
        string $subRequestController = 'Pimcore\Bundle\DataHubBundle\Controller\WebserviceController::webonyxOperationResponseAction'
    ): array {
        // Prepare all operations for execution.
        $responses = [];
        $operationsBatch = [];
        foreach ($operations as $operation) {
            if (!$operation->query && $operation->queryId) {
                $operation->query = $this->loadPersistedQuery($graphQlConfig, $operation);
            }
            $parsedQuery = Parser::parse(new Source($operation->query ?? '', $operation->operation ?? 'GraphQl'));

            // Check if this operation has a cached result - if so remove it
            // from the execution batch.
            // Order matters here - we need to trigger load in order to build
            // the operation metadata handling. So the disabled cache check
            // follows. Could be optimized...
            if (($response = $this->cacheService->load($request, $operation, $parsedQuery)) && !$disableCache) {
                Logger::debug('Loading response from cache');
                if (\Pimcore::inDebugMode()) {
                    $response->headers->set('X-GQL-OperationCache-Hit', 'true');
                }
                $responses[] = $response;
                continue;
            }
            Logger::debug('Cache entry not found');

            try {
                $event = new ExecutorEvent(
                    $request,
                    $operation,
                    $graphQlConfig->getSchema(),
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
                $operationsBatch[] = $operation;
            } catch (\Exception $e) {
                $exException = new ExecutorExceptionEvent($request, $e);
                $this->eventDispatcher->dispatch($exException, ExecutorEvents::EXCEPTION);
                $e = $exException->getException();
                $errorFormatter = FormattedError::prepareFormatter(
                    $graphQlConfig->getErrorFormatter(),
                    $graphQlConfig->getDebugFlag()
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

        // Now execute all open operations that are remaining in the batch.
        $executionResults = $this->graphQlRequestHelper->executeBatch($graphQlConfig, $operationsBatch);
        foreach ($executionResults as $i => $executionResult) {
            $operation = $operationsBatch[$i];
            if ($executionResult instanceof Promise) {
                $response = $executionResult->then(
                    function ($result) use ($graphQlConfig, $operation, $request, $subRequestController) {
                        return $this->processExecutionResult(
                            $graphQlConfig,
                            $result,
                            $operation,
                            $request,
                            $subRequestController
                        );
                    }
                );
            } else {
                $response = $this->processExecutionResult(
                    $graphQlConfig,
                    $executionResult,
                    $operation,
                    $request,
                    $subRequestController
                );
            }
            $responses[] = $response;
        }

        return $responses;
    }

    /**
     * Processes the execution results of Helper::executeBatch()
     *
     * Takes care of caching calls and builds a http response object for the
     * operation results.
     *
     * @param \GraphQL\Server\ServerConfig $config
     * @param \GraphQL\Executor\ExecutionResult $executionResult
     * @param \GraphQL\Server\OperationParams $operation
     * @param \Symfony\Component\HttpFoundation\Request $request
     * @param string $subRequestController
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function processExecutionResult(
        ServerConfig $config,
        ExecutionResult $executionResult,
        OperationParams $operation,
        Request $request,
        string $subRequestController = 'Pimcore\Bundle\DataHubBundle\Controller\WebserviceController::webonyxOperationResponseAction'
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

            // Run every single http response through the http kernel to allow
            // for response modifications before the response is stored in the
            // cache.
            // This enables request based bundles to do their http related
            // processing which then is store in the cache too.
            // Without this we might store incomplete http responses that are
            // later treated as full responses.
            if ($subRequestController) {
                $subRequest = $request->duplicate();
                $subRequest->attributes->set('_controller', $subRequestController);
                $subRequest->attributes->set('_graphQLServerConfig', $config);
                $subRequest->attributes->set('_graphQLExecutionResult', $executionResult);
                $subRequest->attributes->set('_graphQLOperation', $operation);
                $subRequest->attributes->set('_graphQLResponse', $response);
                $subRequest->attributes->set('_graphQlOperationMetadata', $this->cacheService->getOperationMetaData($operation));
                $response = $this->httpKernel->handle($subRequest, HttpKernelInterface::SUB_REQUEST);
            }

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
     * Helper to merge multiple operations responses into a single response.
     *
     * Takes care of evaluation if responses are cacheable and what is the
     * viable caching time (the shortest one defined).
     * Merges http headers where necessary.
     * Evaluates status codes - if a single response fails the umbrella response
     * is also marked as failed!
     *
     * @param Response[] $responses
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function mergeWebonyxResponses(array $responses): Response
    {
        $output = [];
        $httpHeaders = [];
        $statusCode = 200;
        $minMaxAge = null;
        $minSMaxAge = null;
        $private = false;
        $noStore = false;
        $response = new JsonResponse('', 200, [], true);
        foreach ($responses as $queryResponse) {
            $output[] = $queryResponse->getContent();
            // Get the "highest" statusCode as it signifies an error.
            $statusCode = max($statusCode, $queryResponse->getStatusCode());
            // Check if response is cacheable and figure out the shortest
            // ttl.
            $private = $private || $queryResponse->headers->getCacheControlDirective('private');
            $noStore = $noStore || $queryResponse->headers->hasCacheControlDirective('no-store');
            if ($queryResponse->headers->hasCacheControlDirective('max-age')) {
                $minMaxAge = (is_null($minMaxAge)) ?
                    $queryResponse->headers->getCacheControlDirective('max-age') :
                    min($minMaxAge, (int) $queryResponse->headers->getCacheControlDirective('max-age'))
                ;
            }
            if ($queryResponse->headers->hasCacheControlDirective('s-maxage')) {
                $minSMaxAge = (is_null($minSMaxAge)) ?
                    $queryResponse->headers->getCacheControlDirective('s-maxage') :
                    min($minSMaxAge, (int) $queryResponse->headers->getCacheControlDirective('s-maxage'))
                ;
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
        $response->setMaxAge((int) $minMaxAge);
        $response->headers->addCacheControlDirective('must-revalidate');
        $response->setSharedMaxAge((int) $minSMaxAge);
        if ($noStore) {
            $response->headers->addCacheControlDirective('no-store');
        }
        if ($private) {
            $response->setPrivate();
        }

        return $response;
    }
}
