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

namespace Pimcore\Bundle\DataHubBundle\EventListener;

use GraphQL\Server\OperationParams;
use Pimcore\Bundle\DataHubBundle\Event\GraphQL\Model\CacheItemEvent;
use Pimcore\Cache;
use Pimcore\Model\DataObject\ClassDefinition\Data;
use Pimcore\Model\DataObject\Concrete;
use Pimcore\Model\Document\Link;
use Pimcore\Model\Document\PageSnippet;
use Pimcore\Model\Element\AbstractElement;
use Symfony\Component\EventDispatcher\GenericEvent;

class CacheListener
{
    public static ?\SplObjectStorage $cachingItems = null;

    /**
     * Registers cache items _per_ operation for cache saving.
     *
     * Per operation is important to ensure separation of results in a multi
     * query scenario. The later processing in
     * CacheListener::onCacheItemEvent() will also process items per operation
     * and keep the isolation to avoid cache poisoning.
     *
     * @param \GraphQL\Server\OperationParams $operation
     * @param $cid
     * @param $path
     * @param $objectId
     * @param $indexKey
     * @param $lifetime
     *
     * @return void
     */
    public static function addCachingItem(
        OperationParams $operation,
        $cid,
        $path,
        $objectId = null,
        $indexKey = null,
        $lifetime = null
    ): void {
        // Initialize cache item storage if necessary.
        if (!isset(self::$cachingItems)) {
            self::$cachingItems = new \SplObjectStorage();
        }

        self::$cachingItems[$operation] = self::$cachingItems[$operation] ?? [];
        self::$cachingItems[$operation] += [$cid => [
            'objectId' => $objectId,
            'path' => $path,
            'indexKey' => $indexKey,
            'lifetime' => $lifetime,
        ]];
    }

    public static function clearCachingItems(OperationParams $operation = null): void
    {
        if (isset(self::$cachingItems)) {
            if ($operation) {
                self::$cachingItems[$operation] = [];
            } else {
                self::$cachingItems = new \SplObjectStorage();
            }
        }
    }

    public static function arrayGetNestedValue(array &$array, array $parents, &$key_exists = null)
    {
        $ref = &$array;
        foreach ($parents as $parent) {
            if (is_array($ref) && (isset($ref[$parent]) || array_key_exists($parent, $ref))) {
                $ref = &$ref[$parent];
            } else {
                $key_exists = false;

                return null;
            }
        }
        $key_exists = true;

        return $ref;
    }

    /**
     * Add the cache item meta-data before saving the cache items.
     *
     * Uses shutdown because at this point the response should be already
     * delivered and any further processing shouldn't affect reponse times.
     *
     * @param \Symfony\Component\EventDispatcher\GenericEvent $event
     *
     * @return void
     */
    public function onPimcoreShutdown(GenericEvent $event): void
    {
        foreach (self::$cachingItems ?? [] as $operation) {
            // Find items declared for caching in result set and store them in cache.
            foreach (self::$cachingItems[$operation] as $cid => $item) {
                // Extract the related cache tags.
                $cacheTags = [];
                $object = Concrete::getById($item['objectId']);
                if (!empty($object)) {
                    $cacheTags = ['datahub-cache'] + (self::getObjectCacheTags($object) ?? []);
                }
                // Not sure why force is necessary.
                Cache::save(
                    $item['data'],
                    $cid,
                    $cacheTags,
                    $item['lifetime'] ?? null,
                    0,
                    true
                );
            }
        }
    }

    /**
     * Adds the actual data to the for caching prepared cache items.
     *
     * Do NOT save yet - wait for that till shutdown to ensure response is
     * out before triggering any further processing.
     *
     *
     * @param \Pimcore\Bundle\DataHubBundle\Event\GraphQL\Model\CacheItemEvent $event
     *
     * @return void
     */
    public function onCacheItemEvent(CacheItemEvent $event): void
    {
        $operation = $event->getOperation();
        if (isset(self::$cachingItems[$operation])) {
            $result = $event->getResult();
            $data = $result->data;
            // Find items declared for caching in result set and store them in cache.
            $cachedItems = self::$cachingItems[$operation];
            foreach ($cachedItems as $cid => $item) {
                // replace the "delta" placeholder with the effective index to extract data
                $path = $item['path'];
                if ($index = array_search('delta', $path, true)) {
                    $path[$index] = $item['indexKey'];
                }
                // Extract the cacheable portion from the result.
                $value = self::arrayGetNestedValue($data, $path);
                $item['data'] = $value;
                $cachedItems[$cid] = $item;
                self::$cachingItems->offsetSet($operation, $cachedItems);
            }
        }
    }

    /**
     * Get recursively all cache tags for relations or images
     * Must use to invalidate the cache if something related changes, and we're got the correct data
     * Unfortunately we cannot use raw relation data due inheritance
     *
     * @param Concrete $concrete
     *
     * @return array
     */
    public static function getObjectCacheTags(Concrete $concrete): array
    {
        $tags = $concrete->getCacheTags();
        foreach ($concrete->getClass()->getFieldDefinitions() as $name => $def) {
            if ($def instanceof Data) {
                $getter = 'get' . ucfirst($name);
                $data = $concrete->{$getter}();
                // Use the getCacheTags integration of the field definition.
                // This might be not enough hence the further resolving further
                // down.
                $tags = $def->getCacheTags($data, $tags);

                // Handle everything as array.
                if (!is_array($data)) {
                    $data = [$data];
                }
                // Check every item in the data for a dedicated cache tags
                // handling.
                foreach ($data as $item) {
                    switch (true) {
                        case $item instanceof Concrete:
                            $tags = array_merge($tags, self::getObjectCacheTags($item));
                            break;

                        case $item instanceof AbstractElement:
                        case $item instanceof ElementInterface:
                        case $item instanceof Hardlink:
                        case $item instanceof Link:
                        case $item instanceof PageSnippet:
                            $tags = $item->getCacheTags($tags);
                            break;
                    }
                }
            }
        }

        return $tags;
    }
}
