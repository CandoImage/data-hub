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

namespace Pimcore\Bundle\DataHubBundle\GraphQL;

use Pimcore\Bundle\DataHubBundle\Configuration;
use Pimcore\Bundle\DataHubBundle\GraphQL\DataObjectType\PimcoreObjectType;
use Pimcore\Bundle\DataHubBundle\GraphQL\Exception\ClientSafeException;
use Pimcore\Cache\RuntimeCache;
use Pimcore\Cache;
use Pimcore\Db;
use Pimcore\Model\DataObject\ClassDefinition;

class ClassTypeDefinitions
{
    /**
     * @var array
     */
    public static $definitions = [];

    /**
     * Returns list of currently defined classes.
     *
     * This is executed very often while having very rarely changes.
     * Avoid triggering a full DB query every time and use cache instead.
     *
     * @see DataChangeListener::onClassDefinitionAdded()
     * @see DataChangeListener::onClassDefinitionUpdated()
     * @see DataChangeListener::onClassDefinitionDeleted()
     *
     * @param bool $skipCache
     *
     * @return array
     */
    public static function getClasses(bool $skipCache = false): array
    {
        // __METHOD__ has characters the caching handler doesn't like in an ID.
        $cid = md5(__METHOD__);
        if ($skipCache || !is_array(($listing = Cache::load($cid)))) {
            $db = Db::get();
            $listing = $db->fetchAllAssociative('SELECT id, name FROM classes');
            // Can't use __METHOD__ as tag because of invalid chars.
            Cache::save($listing, $cid, ['ClassTypeDefinitions', 'data-hub']);
        }

        return $listing;
    }

    /**
     * @param Service $graphQlService
     * @param array $context
     */
    public static function build(Service $graphQlService, $context = [])
    {
        foreach (self::getClasses() as $class) {
            $id = $class['id'];
            $name = $class['name'];
            $objectType = new PimcoreObjectType($graphQlService, $name, $id, [], $context);
            self::$definitions[$name] = $objectType;
        }

        /**
         * @var string $name
         * @var PimcoreObjectType $definition
         */
        foreach (self::$definitions as $name => $definition) {
            $definition->build($context);
        }
    }

    /**
     * @param string|ClassDefinition $class
     *
     * @return PimcoreObjectType
     *
     * @throws \Exception
     */
    public static function get($class)
    {
        $className = is_string($class) ? $class : $class->getName();
        $result = self::$definitions[$className];
        if (!$result) {
            throw new ClientSafeException('type definition ' . $className . ' not found');
        }

        return $result;
    }

    /**
     * @param bool $onlyQueryTypes
     *
     * @return array
     *
     * @throws \Exception
     */
    public static function getAll($onlyQueryTypes = false)
    {
        if ($onlyQueryTypes) {
            $context = RuntimeCache::get('datahub_context');
            /** @var Configuration $configuration */
            $configuration = $context['configuration'];
            $types = array_keys($configuration->getConfiguration()['schema']['queryEntities']);
            $result = [];
            foreach ($types as $type) {
                if (isset(self::$definitions[$type])) {
                    $result[] = self::$definitions[$type];
                }
            }

            return $result;
        }

        return self::$definitions;
    }
}
