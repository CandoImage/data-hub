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

use Pimcore\Bundle\DataHubBundle\Event\GraphQL\Model\CacheItemEvent;
use Pimcore\Cache;
use Pimcore\Model\DataObject\Concrete;

class CacheListener
{
    public static array $cachingItems = [];

    public static function addCachingItem($cid, $path, $objectId = null, $indexKey = null, $lifetime = null): void
    {
        // load object to get cache tags
        $object = Concrete::getById($objectId);
        if ($object) {
            $cacheTags = ['datahub-cache'] + ($object->getCacheTags() ?? []);
            self::$cachingItems[$cid] = [
                'path' => $path,
                'tags' => $cacheTags,
                'indexKey' => $indexKey,
                'lifetime' => $lifetime,
            ];
        }
    }

    public static function clearCachingItems(): void
    {
        self::$cachingItems = [];
    }

    public static function arrayGetNestedValue(array &$array, array $parents, &$key_exists = null)
    {
        $ref = & $array;
        foreach ($parents as $parent) {
            if (is_array($ref) && (isset($ref[$parent]) || array_key_exists($parent, $ref))) {
                $ref = & $ref[$parent];
            } else {
                $key_exists = false;

                return null;
            }
        }
        $key_exists = true;

        return $ref;
    }

    public function onCacheItemEvent(CacheItemEvent $event): void
    {
        $result = $event->getResult();
        $data = $result->data;

        // Find items declared for caching in result set and store them in cache.
        foreach (self::$cachingItems as $cid => $item) {
            // replace the "delta" placeholder with the effective index to extract data
            $path = $item['path'];
            if ($index = array_search('delta', $path, true)) {
                $path[$index] = $item['indexKey'];
            }
            // Extract the cacheable portion from the result.
            $value = self::arrayGetNestedValue($data, $path);
            $cacheTags = $item['tags'] ?? [];
            Cache::save(
                $value,
                $cid,
                $cacheTags,
                $item['lifetime'] ?? null,
                0,
                true
            );
        }
        self::clearCachingItems();
    }
}
