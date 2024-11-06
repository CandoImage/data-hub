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

use ArrayObject;
use GraphQL\Language\AST\DocumentNode;
use GraphQL\Language\Parser;
use GraphQL\Language\Source;
use GraphQL\Server\OperationParams;
use Pimcore\Bundle\DataHubBundle\Event\GraphQL\Model\OutputCacheGenerateCidEvent;
use Pimcore\Bundle\DataHubBundle\Event\GraphQL\Model\OutputCachePreLoadEvent;
use Pimcore\Bundle\DataHubBundle\Event\GraphQL\Model\OutputCachePreSaveEvent;
use Pimcore\Bundle\DataHubBundle\Event\GraphQL\OutputCacheEvents;
use Pimcore\Http\RequestHelper;
use Pimcore\Logger;
use SplObjectStorage;
use Symfony\Component\Cache\CacheItem;
use Symfony\Component\DependencyInjection\ParameterBag\ContainerBagInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

class OutputCacheService
{
    /**
     * @var bool
     */
    private $cacheEnabled = false;

    /**
     * The cached items lifetime in seconds
     *
     * @var int
     */
    private int $lifetime = 30;

    /**
     * Specific exclude queries
     *
     * @var array
     */
    private array $excludedQueries = [];

    /**
     * Specific exclude queries
     *
     * @var array
     */
    private array $exclude_pattern = [];

    /**
     * State collection for an operation.
     */
    private SplObjectStorage $operationData;

    /**
     * @var EventDispatcherInterface
     */
    public EventDispatcherInterface $eventDispatcher;

    public function __construct(
        ContainerBagInterface $container,
        EventDispatcherInterface $eventDispatcher,
        protected RequestHelper $requestHelper
    ) {
        $this->operationData = new SplObjectStorage();
        $this->eventDispatcher = $eventDispatcher;

        $dataHubConfig = $container->get('pimcore_data_hub')['supported_types'] ?? [];
        if (isset($dataHubConfig['graphql'])) {
            if (isset($dataHubConfig['graphql']['output_cache_enabled'])) {
                $this->cacheEnabled = filter_var($dataHubConfig['graphql']['output_cache_enabled'], FILTER_VALIDATE_BOOLEAN);
            }

            if (isset($dataHubConfig['graphql']['output_cache_lifetime'])) {
                $this->lifetime = intval($dataHubConfig['graphql']['output_cache_lifetime']);
            }

            if (isset($config['graphql']['output_cache_exclude_pattern'])) {
                $this->exclude_pattern = $config['graphql']['output_cache_exclude_pattern'];
            }
        }
        // Cando Special:
        $this->excludedQueries[] = '__schema';
        // B2BProductBundle
        $this->excludedQueries[] = 'getAvailabilitiesAndPrices';
        // CoreBundle
        $this->excludedQueries[] = 'getAccountAddress';
        $this->excludedQueries[] = 'getAccountData';
        $this->excludedQueries[] = 'getAccountPasswordData';
        $this->excludedQueries[] = 'getAccountAddressListing';
        $this->excludedQueries[] = 'getAddressDetails';
        $this->excludedQueries[] = 'performAddressMutation';
        $this->excludedQueries[] = 'performAddressDelete';
        $this->excludedQueries[] = 'performDefaultAddressMutation';
        $this->excludedQueries[] = 'performSetAddressOnCartMutation';
        $this->excludedQueries[] = 'performPasswordChange';
        $this->excludedQueries[] = 'getFlashMessages';
        $this->excludedQueries[] = 'setFlashMessages';
        $this->excludedQueries[] = 'getCustomerNumberListing';
        $this->excludedQueries[] = 'performCustomerNumberMutation';
        $this->excludedQueries[] = 'getCostCenterListing';
        $this->excludedQueries[] = 'performCostCenterMutation';
        $this->excludedQueries[] = 'performImpersonateMutation';
        // EcommerceBaseBundle
        $this->excludedQueries[] = 'performAddToCartMutation';
        $this->excludedQueries[] = 'getCalculatedCart';
        $this->excludedQueries[] = 'getCartListing';
        $this->excludedQueries[] = 'getCartDetails';
        $this->excludedQueries[] = 'performCartUpdate';
        $this->excludedQueries[] = 'performCartDelete';
        $this->excludedQueries[] = 'performApplyCalculatedCart';
        $this->excludedQueries[] = 'performOrderMutation';
        $this->excludedQueries[] = 'getCheckoutSuccess';
        $this->excludedQueries[] = 'getOrderListing';
        $this->excludedQueries[] = 'getOrderDetail';
        $this->excludedQueries[] = 'performSelectCartMutation';
        $this->excludedQueries[] = 'performUpdateToCartMutation';
        $this->excludedQueries[] = 'setCartPaymentMethod';
        $this->excludedQueries[] = 'setCartDeliveryType';
        $this->excludedQueries[] = 'performCartItemMutation';
        $this->excludedQueries[] = 'performCancelOrderItem';
        $this->excludedQueries[] = 'getInvoiceListing';
        $this->excludedQueries[] = 'getCreditNoteListing';
        $this->excludedQueries[] = 'getPendingDeliveryListing';
        $this->excludedQueries[] = 'performCancelPendingDeliveryItem';
        $this->excludedQueries[] = 'getWishlistListing';
        $this->excludedQueries[] = 'getWishlist';
        $this->excludedQueries[] = 'getWishlistDetails';
        $this->excludedQueries[] = 'performUpdateToWishlistMutation';
        $this->excludedQueries[] = 'performWishlistUpdate';
        $this->excludedQueries[] = 'performWishlistDelete';
        $this->excludedQueries[] = 'performAddToWishlistMutation';
        $this->excludedQueries[] = 'performSelectWishlistMutation';
        $this->excludedQueries[] = 'getReturnsListListing';
        $this->excludedQueries[] = 'getReturnsRegistrationListing';
        $this->excludedQueries[] = 'getReturnsListing';
        $this->excludedQueries[] = 'getReturnSuccess';
        $this->excludedQueries[] = 'getReturnDetail';
        $this->excludedQueries[] = 'performAddToReturnsListMutation';
        $this->excludedQueries[] = 'performUpdateReturnsListMutation';
        $this->excludedQueries[] = 'performRegisterReturnsList';
        // Project specific queries
        $this->excludedQueries[] = 'performDealerToggleState';
    }

    /**
     * Returns the operations cache id for internal purposes.
     *
     * This is used to track operations context data for caching but is only
     * part of the possible output cache id.
     *
     * @param OperationParams $operation
     *
     * @return string
     */
    public function getOperationCid(OperationParams $operation): string
    {
        if (isset($this->operationData[$operation]['operationCid'])) {
            return $this->operationData[$operation]['operationCid'];
        }

        $originalInputHash = \Closure::bind(function () {
            $originalInput = $this->originalInput;

            // Ensure only relevant parts are ingested. And exclude input that
            // should not have any impact on the result:
            // - operationname
            $originalInput = array_intersect_key($originalInput, [
                'query' => null,
                'queryid' => null,
                'documentid' => null, // alias to queryid
                'id' => null, // alias to queryid
                // 'operationname' => null,
                'variables' => null,
                'extensions' => null,
            ]);
            // Sort params to ensure consistent hashing. For the execution only
            // contents matter, order doesn't.
            asort($originalInput);
            if (isset($originalInput['variables']) && is_array($originalInput['variables'])) {
                asort($originalInput['variables']);
            }
            if (isset($originalInput['extensions']) && is_array($originalInput['extensions'])) {
                asort($originalInput['extensions']);
            }

            return md5(serialize($originalInput));
        }, $operation, $operation);

        $this->operationData[$operation] = new ArrayObject(
            array_merge(
                $this->operationData[$operation]->getArrayCopy() ?? [],
                ['operationCid' => $originalInputHash()]
            )
        );

        return $this->operationData[$operation]['operationCid'];
    }

    /**
     * Fetches the caching ID for the output cache.
     *
     * Fires an event to allow for modified caching IDs.
     * This allows scenarios like caches for logged-in users or target groups.
     *
     * BEWARE: Keep listeners as slim as possible to avoid unnecessary overhead.
     *
     * @param OperationParams $operation
     * @param DocumentNode $parsedQuery
     *
     * @return string
     */
    public function getOperationOutputCid(OperationParams $operation, DocumentNode $parsedQuery): string
    {
        $cid = $this->getOperationCid($operation);
        $filterValues = $this->operationData[$operation]['filterValues'] ?? '';
        $sortValues = $this->operationData[$operation]['sortValues'] ?? '';
        $cid .= '-' . self::cleanTag($filterValues) . '-' . self::cleanTag($sortValues);
        $event = new OutputCacheGenerateCidEvent($cid, $operation, $parsedQuery);
        $this->eventDispatcher->dispatch($event, OutputCacheEvents::GENERATE_CID);

        return $event->getCid();
    }

    public function registerOperation(OperationParams $operation, DocumentNode $parsedQuery)
    {
        $this->operationData->attach(
            $operation,
            new ArrayObject([
                'parsedQuery' => $parsedQuery,
                'filterValues' => '',
                'sortValues' => '',
                'useCache' => true,
            ])
        );

        // Check the filter values separate
        if (isset($operation->variables['filters'])) {
            $this->operationData[$operation]['filterValues'] = $this->getImplodedFilterValues($operation->variables);
        }
        // Check the sort values separate
        if (isset($operation->variables['sortBy'])) {
            if (isset($operation->variables['sortOrder'])) {
                $this->operationData[$operation]['sortValues'] = implode('-', $operation->variables['sortBy']) .
                    '-' . implode('-', $operation->variables['sortOrder']);
            } else {
                $this->operationData[$operation]['sortValues'] = implode('-', $operation->variables['sortBy']);
            }
        }
    }

    /**
     * @param Request $request
     * @param OperationParams $operation
     * @param DocumentNode $parsedQuery
     *
     * @return mixed
     */
    public function load(Request $request, OperationParams $operation, DocumentNode $parsedQuery)
    {
        $this->registerOperation($operation, $parsedQuery);
        if (!$this->useCache($request, $operation, $parsedQuery)) {
            return null;
        }
        // Check if this is an excluded query.
        if ($this->isExcludedQuery($this->operationData[$operation]['parsedQuery'])) {
            return null;
        }

        return $this->loadFromCache($operation, $parsedQuery);
    }

    /**
     * Saves an operations response to the cache.
     *
     * Saving only works if the $operation has been "registered" by either
     * calling OutputCacheService::load() or
     * OutputCacheService::registerOperation() first.
     *
     * @param Request $request
     * @param JsonResponse $response
     * @param OperationParams $operation
     * @param array $extraTags
     *
     * @return void
     */
    public function save(
        Request $request,
        JsonResponse $response,
        OperationParams $operation,
        array $extraTags = []
    ): void {
        if (!empty($this->operationData[$operation]['useCache'])) {
            $operationData = $this->operationData[$operation];
            // check if we have an excluded query here
            $query = $operationData['parsedQuery'] ?? $operation->query;
            if ($query && $this->isExcludedQuery($query)) {
                return;
            }

            $clientname = $request->get('clientname');
            $extraTags = array_merge(['output', 'datahub', $clientname], $extraTags);

            $event = new OutputCachePreSaveEvent($request, $response, $extraTags);
            $this->eventDispatcher->dispatch($event, OutputCacheEvents::PRE_SAVE);
            if (!$event->isSkipSave()) {
                $this->saveToCache($operation, $operationData['parsedQuery'], $event->getResponse(), $event->getTags());
            }
        }
    }

    protected function loadFromCache(OperationParams $operation, DocumentNode $parsedQuery)
    {
        $cacheKey = $this->getOperationOutputCid($operation, $parsedQuery);

        return \Pimcore\Cache::load($cacheKey);
    }

    /**
     * @param OperationParams $operation
     * @param DocumentNode $parsedQuery
     * @param JsonResponse $response
     * @param array $tags
     *
     * @return void
     */
    protected function saveToCache(
        OperationParams $operation,
        DocumentNode $parsedQuery,
        JsonResponse $response,
        $tags = []
    ): void {
        $cacheKey = $this->getOperationOutputCid($operation, $parsedQuery);
        \Pimcore\Cache::save($response, $cacheKey, $tags, $this->lifetime);
    }

    /**
     * @param Request $request
     *
     * @return string
     *
     *@deprecated Use $this->>getOperationOutputCid(). This was request based
     * which is not really compatible with multi-query support.
     *
     */
    private function computeKey(Request $request): string
    {
        $clientname = $request->get('clientname');

        $input = json_decode($request->getContent(), true);
        $input = print_r($input, true);

        return md5('output_' . $clientname . $input);
    }

    private function useCache(Request $request, OperationParams $operation, DocumentNode $parsedQuery): bool
    {
        if (!isset($this->operationData[$operation])) {
            $this->operationData->attach($operation, []);
        }

        if (!$this->cacheEnabled) {
            Logger::debug('Output cache is disabled');

            $this->operationData[$operation]['useCache'] = false;

            return false;
        }

        $disableCacheForSingleRequest = false;
        if (\Pimcore::inDebugMode()) {
            $disableCacheForSingleRequest = filter_var($request->query->get('pimcore_nocache', 'false'), FILTER_VALIDATE_BOOLEAN)
            || filter_var($request->query->get('pimcore_outputfilters_disabled', 'false'), FILTER_VALIDATE_BOOLEAN);
        } elseif ($this->requestHelper->isFrontendRequestByAdmin()) {
            $disableCacheForSingleRequest = true;
        }

        if ($disableCacheForSingleRequest) {
            Logger::debug('Output cache is disabled for this request');

            $this->operationData[$operation]['useCache'] = false;

            return false;
        }

        // So far, cache will be used, unless the listener denies it
        $event = new OutputCachePreLoadEvent($request, true, $operation, $parsedQuery);
        $this->eventDispatcher->dispatch($event, OutputCacheEvents::PRE_LOAD);

        $this->operationData[$operation]['useCache'] = $event->isUseCache();

        return $this->operationData[$operation]['useCache'];
    }

    /**
     * @param OperationParams $operation
     *
     * @return bool
     */
    public function isOperationCacheable(OperationParams $operation): bool
    {
        return !empty($this->operationData[$operation]['useCache']);
    }

    /**
     * Returns the meta data collected for an operation.
     *
     * @param OperationParams $operation
     *
     * @return array
     */
    public function getOperationMetaData(OperationParams $operation): ArrayObject
    {
        return $this->operationData[$operation];
    }

    /**
     * Checks if a GraphQL Query contains a non-cacheable Query.
     *
     * @param string|DocumentNode $query
     *
     * @return bool
     *
     * @CandoSpecific
     */
    public function isExcludedQuery($query): bool
    {
        if (!($query instanceof DocumentNode)) {
            $query = Parser::parse(new Source($query ?? '', 'GraphQL'), ['noLocation' => true]);
        }
        foreach ($query->definitions as $definition) {
            foreach ($definition->selectionSet->selections as $selection) {
                foreach ($this->excludedQueries as $excludedQuery) {
                    if ($selection->name->value === $excludedQuery) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    private function getImplodedFilterValues(array $variables): string
    {
        $filterValues = [];
        $filters = $variables['filters'] ?? [];
        foreach ($filters as $filter) {
            if (array_key_exists('values', $filter)) {
                if (is_array($filter['values'])) {
                    if (count($filter['values']) > 1) {
                        $valueList = [];
                        foreach ($filter['values'] as $filterValue) {
                            if (is_array($filterValue)) {
                                $valueList[] = key($filterValue) . '-' . $filterValue[key($filterValue)];
                            } else {
                                $valueList[] = ($filter['field'] ?? 'unknown-field') . '-' . implode('-', $filter['values']);
                                break;
                            }
                        }
                        $filterValues[] = implode($valueList);
                    } else {
                        $filterValues[] = ($filter['field'] ?? 'unknown-field') . '-' . implode('-', $filter['values']);
                    }
                } else {
                    $filterValues[] = ($filter['field'] ?? 'unknown-field') . '-' . $filter['values'];
                }
            } else {
                ksort($filter);
                $filterValues[] = implode('-', $filter);
            }
        }

        return implode(',', $filterValues);
    }

    public static function cleanTag(string $tag): string
    {
        return str_replace(str_split(CacheItem::RESERVED_CHARACTERS), '-', $tag);
    }
}
