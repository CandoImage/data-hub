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

use GraphQL\Error\SyntaxError;
use GraphQL\Server\Helper;
use GraphQL\Server\OperationParams;
use GraphQL\Server\RequestError;
use GraphQL\Validator\DocumentValidator;
use GraphQL\Validator\Rules\DisableIntrospection;
use Pimcore\Bundle\DataHubBundle\Configuration;
use Pimcore\Bundle\DataHubBundle\GraphQL\Service;
use Pimcore\Bundle\DataHubBundle\PimcoreDataHubBundle;
use Pimcore\Bundle\DataHubBundle\Service\CheckConsumerPermissionsService;
use Pimcore\Bundle\DataHubBundle\Service\FileUploadService;
use Pimcore\Bundle\DataHubBundle\Service\GraphQLExecutionService;
use Pimcore\Bundle\DataHubBundle\Service\OutputCacheService;
use Pimcore\Config;
use Pimcore\Controller\FrontendController;
use Pimcore\Helper\LongRunningHelper;
use Pimcore\Localization\LocaleServiceInterface;
use Pimcore\Model\Factory;
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
     * @var HttpKernelInterface
     */
    protected HttpKernelInterface $httpKernel;

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

    /**
     * @param Request $request
     *
     * @return array{operations: bool, resolveEdge: bool, resolveObjectGetter: bool, cidBase: string}
     */
    protected function getCachingContextConfiguration(Request $request): array
    {
        $config = [
            'operations' => true,
            'resolveEdge' => true,
            'resolveObjectGetter' => true,
            // Allows to inject global contexts into the cache handling,
            // isolating cache items by custom rules.
            'cidBase' => '',
        ];
        $env = Config::getEnvironment();
        if (!in_array(strtolower($env), ['prod', 'production'])) {
            $config['operations'] = !$request->query->has('datahub-cache-disable-operations');
            $config['resolveEdge'] = !$request->query->has('datahub-cache-disable-resolveEdge');
            $config['resolveObjectGetter'] = !$request->query->has('datahub-cache-disable-resolveObjectGetter');
        }

        $config['operations'] = false;
        $config['resolveEdge'] = false;
        $config['resolveObjectGetter'] = false;

        return $config;
    }

    /**
     * @param Service $service
     * @param LocaleServiceInterface $localeService
     * @param Factory $modelFactory
     * @param Request $request
     * @param LongRunningHelper $longRunningHelper
     * @param GraphQLExecutionService $graphQLExecutionService
     *
     * @return JsonResponse|Response
     *
     * @throws RequestError|\Exception
     * @throws SyntaxError
     */
    public function webonyxAction(
        Service $service,
        LocaleServiceInterface $localeService,
        Factory $modelFactory,
        Request $request,
        LongRunningHelper $longRunningHelper,
        GraphQLExecutionService $graphQLExecutionService
    ) {
        // Check if this is a mere request processing loop. If so simply return
        // the prepared response.
        if ($graphQLResponse = $request->attributes->get('_graphQLResponse')) {
            return $graphQLResponse;
        }

        $clientname = $request->get('clientname');

        $configuration = Configuration::getByName($clientname);
        if (!$configuration || !$configuration->isActive()) {
            throw new NotFoundHttpException('No active configuration found for ' . $clientname);
        }

        if (!$this->permissionsService->performSecurityCheck($request, $configuration)) {
            throw new AccessDeniedHttpException('Permission denied, apikey not valid');
        }

        $contentType = $request->headers->get('content-type') ?? '';
        if (mb_stripos($contentType, 'multipart/form-data') !== false) {
            $input = $this->uploadService->parseUploadedFiles($request);
            $operations = OperationParams::create($input);
        } else {
            $operations = $graphQLExecutionService->getWebonyxOperations($request);
        }

        $isBatchedQuery = is_array($operations);
        if (!$isBatchedQuery) {
            $operations = [$operations];
        }

        // context info, will be passed on to all resolver function
        $cachingConfig = $this->getCachingContextConfiguration($request);
        $context = [
            'clientname' => $clientname,
            'configuration' => $configuration,
            'caching' => $cachingConfig,
        ];
        $datahubConfig = $this->getParameter('pimcore_data_hub')['supported_types'] ?? [];

        if (isset($datahubConfig['graphql']) && isset($datahubConfig['graphql']['not_allowed_policy'])) {
            PimcoreDataHubBundle::setNotAllowedPolicy($datahubConfig['graphql']['not_allowed_policy']);
        }

        $validators = null;
        if ($request->get('novalidate')) {
            // disable all validators except the listed ones
            $validators = [
//                    new NoUndefinedVariables()
            ];
        }
        $disableIntrospection = $configuration->getSecurityConfig()['disableIntrospection'] ?? false;
        if ($disableIntrospection === true) {
            DocumentValidator::addRule(new DisableIntrospection(DisableIntrospection::ENABLED));
        }

        $schema = $graphQLExecutionService->getGraphQlSchema($context, $longRunningHelper);
        // Setup GraphQl config which is used later in all the helpers.
        $graphQlConfig = $graphQLExecutionService->getGraphQlServerConfig(
            $schema,
            $context,
            $validators
        );

        $responses = $graphQLExecutionService->executeOperations(
            $request,
            $operations,
            $graphQlConfig,
            !$cachingConfig['operations']
        );

        if (!$isBatchedQuery) {
            $response = reset($responses);
        } else {
            $response = $graphQLExecutionService->mergeWebonyxResponses($responses);
        }

        $origin = '*';
        if (!empty($_SERVER['HTTP_ORIGIN'])) {
            $origin = $_SERVER['HTTP_ORIGIN'];
        }
        $response->headers->set('Access-Control-Allow-Origin', $origin);
        $response->headers->set('Access-Control-Allow-Credentials', 'true');
        $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
        $response->headers->set('Access-Control-Allow-Headers', 'Origin, Content-Type, X-Auth-Token');
        if (!$cachingConfig['operations']) {
            $response->headers->set('X-DATAHUB-CACHE-OPERATIONS-DISABLED', 'true');
        }
        if (!$cachingConfig['resolveEdge']) {
            $response->headers->set('X-DATAHUB-CACHE-RESOLVE-EDGE-DISABLED', 'true');
        }
        if (!$cachingConfig['resolveObjectGetter']) {
            $response->headers->set('X-DATAHUB-CACHE-RESOLVE-OBJECTGETTER-DISABLED', 'true');
        }

        return $response;
    }

    /**
     * Dummy function to allow to handle each operation of a multi query request
     * like a single request.
     *
     * @param Request $request
     *
     * @return JsonResponse|Response
     *
     * @throws \Exception
     */
    public function webonyxOperationResponseAction(Request $request)
    {
        return $request->attributes->get('_graphQLResponse');
    }
}
