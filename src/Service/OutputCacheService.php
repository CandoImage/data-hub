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

use CandoCX\B2BProductBundle\Helper\CacheHelper;
use GraphQL\Language\Parser;
use Pimcore\Bundle\DataHubBundle\Event\GraphQL\Model\OutputCachePreLoadEvent;
use Pimcore\Bundle\DataHubBundle\Event\GraphQL\Model\OutputCachePreSaveEvent;
use Pimcore\Logger;
use Psr\Container\ContainerInterface;
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
    private $lifetime = 30;

    /**
     * Specific exclude quries
     *
     * @var array
     */
    private array $excludedQueries = [];

    /**
     * The Input GraphQL Query
     *
     * @var string
     */
    private string $query = '';

    /**
     * The Input GraphQL Variables
     *
     * @var array
     */
    private $variables = [];

    /**
     * Specific imploded Filter Values from Input Variables
     * used for Cache Key generation
     *
     * @var string
     */
    private string $filterValues = '';

    /**
     * Specific imploded Sorting Values from Input Variables
     * used for Cache Key generation
     *
     * @var string
     */
    private string $sortValues = '';

    /**
     * @var EventDispatcherInterface
     */
    public $eventDispatcher;

    /**
     * @param ContainerInterface $container
     * @param EventDispatcherInterface $eventDispatcher
     */
    public function __construct(ContainerInterface $container, EventDispatcherInterface $eventDispatcher)
    {
        $this->eventDispatcher = $eventDispatcher;

        $config = $container->getParameter('pimcore_data_hub');

        if (isset($config['graphql'])) {
            if (isset($config['graphql']['output_cache_enabled'])) {
                $this->cacheEnabled = filter_var($config['graphql']['output_cache_enabled'], FILTER_VALIDATE_BOOLEAN);
            }

            if (isset($config['graphql']['output_cache_lifetime'])) {
                $this->lifetime = intval($config['graphql']['output_cache_lifetime']);
            }

            if (isset($config['graphql']['output_cache_exclude_pattern'])) {
                $this->exclude_pattern = $config['graphql']['output_cache_exclude_pattern'];
            }
        }
        // Cando Special:
        $this->excludedQueries[] = '__schema';
        $this->excludedQueries[] = 'getAvailabilitiesAndPrices';
        $this->excludedQueries[] = 'getCartListing';
        $this->excludedQueries[] = 'getCalculatedCart';
        $this->excludedQueries[] = 'performAddToCartMutation';
        $this->excludedQueries[] = 'performSelectCartMutation';
        $this->excludedQueries[] = 'performUpdateToCartMutation';
        $this->excludedQueries[] = 'performOrderMutation';
    }

    public function load(Request $request)
    {
        if (!$this->useCache($request)) {
            return null;
        }

        // Parse Input for more specific cache key generation
        $input = json_decode($request->getContent(), true);
        $this->query = $input['query'];
        $this->variables = isset($input['variables']) ? $input['variables'] : null;

        // check if we have an excluded query here
        if ($this->isExcludedQuery($this->query)) {
            return null;
        }

        // Original Code
        //$cacheKey = $this->computeKey($request);

        // Check the filter values separate
        if (isset($this->variables['filters'])) {
            $this->filterValues = $this->getImplodedFilterValues($this->variables);
        } else {
            $this->filterValues = '';
        }
        // Check the sort values separate
        if (isset($this->variables['sortBy'])) {
            if (isset($this->variables['sortOrder'])) {
                $this->sortValues = implode('-', $this->variables['sortBy']);
                $this->sortValues .= '-' . implode('-', $this->variables['sortOrder']);
            } else {
                $this->sortValues = implode('-', $this->variables['sortBy']);
            }
        } else {
            $this->sortValues = '';
        }

        $cacheKey = CacheHelper::generateCacheId([$this->query, implode('-', $this->variables), $this->filterValues, $this->sortValues]);

        return $this->loadFromCache($cacheKey);
    }

    public function save(Request $request, JsonResponse $response, $extraTags = []): void
    {
        if ($this->useCache($request)) {
            // check if we have an excluded query here
            if ($this->query && $this->isExcludedQuery($this->query)) {
                return;
            }

            // Original Code
//            $cacheKey = $this->computeKey($request);
            $clientname = $request->get('clientname');
            $extraTags = array_merge(['output', 'datahub', $clientname], $extraTags);

            $extraTags = array_merge(CacheHelper::getTenantTags(), $extraTags);
            $cacheKey = CacheHelper::generateCacheId([$this->query, implode('-', $this->variables), $this->filterValues, $this->sortValues]);

            $event = new OutputCachePreSaveEvent($request, $response);
            $this->eventDispatcher->dispatch($event);

            $this->saveToCache($cacheKey, $event->getResponse(), $extraTags);
        }
    }

    protected function loadFromCache($key)
    {
        return \Pimcore\Cache::load($key);
    }

    protected function saveToCache($key, $item, $tags = []): void
    {
        \Pimcore\Cache::save($item, $key, $tags, $this->lifetime, 0, true);
    }

    private function computeKey(Request $request): string
    {
        $clientname = $request->get('clientname');

        $input = json_decode($request->getContent(), true);
        $input = print_r($input, true);

        return md5('output_' . $clientname . $input);
    }

    private function useCache(Request $request): bool
    {
        if (!$this->cacheEnabled) {
            Logger::debug('Output cache is disabled');

            return false;
        }

        if (\Pimcore::inDebugMode()) {
            $disableCacheForSingleRequest = filter_var($request->query->get('pimcore_nocache', 'false'), FILTER_VALIDATE_BOOLEAN)
            || filter_var($request->query->get('pimcore_outputfilters_disabled', 'false'), FILTER_VALIDATE_BOOLEAN);

            if ($disableCacheForSingleRequest) {
                Logger::debug('Output cache is disabled for this request');

                return false;
            }
        }

        // So far, cache will be used, unless the listener denies it
        $event = new OutputCachePreLoadEvent($request, true);
        $this->eventDispatcher->dispatch($event);

        return $event->isUseCache();
    }

    // Cando Special
    private function isExcludedQuery($query): bool
    {
        $query = Parser::parse($query);
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
        $filters = $variables['filters'];
        foreach ($filters as $filter) {
            if (count($filter['values']) > 1) {
                $valueList = [];
                foreach ($filter['values'] as $filterValue) {
                    $valueList[] = key($filterValue) . '-' . $filterValue[key($filterValue)];
                }
                $filterValues[] = implode($valueList);
            } else {
                $filterValues[] = $filter['field'] . '-' . implode('-', $filter['values']);
            }
        }

        return implode(',', $filterValues);
    }
}
