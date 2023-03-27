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
 * @copyright  Copyright (c) Pimcore GmbH (http://www.pimcore.org)
 * @license    http://www.pimcore.org/license     GPLv3 and PCL
 */

namespace Pimcore\Bundle\DataHubBundle\GraphQL\Resolver;

use Doctrine\DBAL\Driver\Exception;
use GraphQL\Deferred;
use GraphQL\Executor\Promise\Adapter\SyncPromise;
use GraphQL\Language\AST\FragmentSpreadNode;
use GraphQL\Language\AST\InlineFragmentNode;
use GraphQL\Language\AST\NodeKind;
use GraphQL\Language\AST\NodeList;
use GraphQL\Type\Definition\ResolveInfo;
use Pimcore\Bundle\DataHubBundle\Configuration;
use Pimcore\Bundle\DataHubBundle\Event\GraphQL\EdgeEvents;
use Pimcore\Bundle\DataHubBundle\Event\GraphQL\ListingEvents;
use Pimcore\Bundle\DataHubBundle\Event\GraphQL\Model\EdgeEvent;
use Pimcore\Bundle\DataHubBundle\Event\GraphQL\Model\ListingEvent;
use Pimcore\Bundle\DataHubBundle\Event\GraphQL\Model\TenantEvent;
use Pimcore\Bundle\DataHubBundle\Event\GraphQL\TenantEvents;
use Pimcore\Bundle\DataHubBundle\EventListener\CacheListener;
use Pimcore\Bundle\DataHubBundle\FilterService\FilterType\HijackAbstractFilterType;
use Pimcore\Bundle\DataHubBundle\GraphQL\ElementDescriptor;
use Pimcore\Bundle\DataHubBundle\GraphQL\Exception\ClientSafeException;
use Pimcore\Bundle\DataHubBundle\GraphQL\Helper;
use Pimcore\Bundle\DataHubBundle\GraphQL\Traits\ElementIdentificationTrait;
use Pimcore\Bundle\DataHubBundle\GraphQL\Traits\PermissionInfoTrait;
use Pimcore\Bundle\DataHubBundle\GraphQL\Traits\ServiceTrait;
use Pimcore\Bundle\DataHubBundle\Helper\CacheHelper;
use Pimcore\Bundle\DataHubBundle\WorkspaceHelper;
use Pimcore\Bundle\EcommerceFrameworkBundle\Factory;
use Pimcore\Bundle\EcommerceFrameworkBundle\IndexService\ProductList\DefaultMysql;
use Pimcore\Bundle\EcommerceFrameworkBundle\IndexService\ProductList\ElasticSearch\AbstractElasticSearch;
use Pimcore\Bundle\EcommerceFrameworkBundle\Model\AbstractCategory;
use Pimcore\Bundle\EcommerceFrameworkBundle\Model\AbstractFilterDefinition;
use Pimcore\Bundle\EcommerceFrameworkBundle\IndexService\ProductList\ProductListInterface;
use Pimcore\Bundle\EcommerceFrameworkBundle\Exception\InvalidConfigException;
use Pimcore\Cache;
use Pimcore\Db;
use Pimcore\Logger;
use Pimcore\Model\DataObject\AbstractObject;
use Pimcore\Model\DataObject\ClassDefinition;
use Pimcore\Model\DataObject\Listing;
use Pimcore\Model\DataObject\Service;
use Pimcore\Model\Translation;
use stdClass;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Security\Core\Security;

class QueryType
{
    use ServiceTrait;
    use PermissionInfoTrait;
    use ElementIdentificationTrait;

    /**
     * @var EventDispatcherInterface
     */
    private $eventDispatcher;

    /**
     * @var ClassDefinition|null
     */
    protected $class;

    /**
     * @var object
     */
    protected $configuration;

    /**
     * @param EventDispatcherInterface $eventDispatcher
     * @param ClassDefinition|null $class
     * @param object $configuration
     * @param bool $omitPermissionCheck
     */
    public function __construct(
        EventDispatcherInterface $eventDispatcher,
        $class = null,
        $configuration = null,
        $omitPermissionCheck = false
    ) {
        $this->eventDispatcher = $eventDispatcher;
        $this->class = $class;
        $this->configuration = $configuration;
        $this->omitPermissionCheck = $omitPermissionCheck;
    }

    /**
     * @param ElementDescriptor|null $value
     * @param array $args
     * @param array $context
     * @param ResolveInfo|null $resolveInfo
     * @param string|null $elementType
     *
     * @return array|null
     *
     * @throws ClientSafeException
     */
    public function resolveFolderGetter($value = null, $args = [], $context = [], ResolveInfo $resolveInfo = null, $elementType = null)
    {
        if ($args && isset($args['defaultLanguage'])) {
            $this->getGraphQlService()->getLocaleService()->setLocale($args['defaultLanguage']);
        }

        $element = $this->getElementByTypeAndIdOrPath($args, $elementType);

        if (!$element) {
            return null;
        }

        if (!$this->omitPermissionCheck && !WorkspaceHelper::checkPermission($element, 'read')) {
            return null;
        }

        $data = new ElementDescriptor();
        $getter = 'get' . ucfirst($elementType) . 'FieldHelper';
        $fieldHelper = $this->getGraphQlService()->$getter();
        $fieldHelper->extractData($data, $element, $args, $context, $resolveInfo);
        $data = $data->getArrayCopy();

        return $data;
    }

    /**
     * @param ElementDescriptor|null $value
     * @param array $args
     * @param array $context
     * @param ResolveInfo|null $resolveInfo
     *
     * @return array|null
     *
     * @throws ClientSafeException
     */
    public function resolveAssetFolderGetter($value = null, $args = [], $context = [], ResolveInfo $resolveInfo = null)
    {
        return $this->resolveFolderGetter($value, $args, $context, $resolveInfo, 'asset');
    }

    /**
     * @param ElementDescriptor|null $value
     * @param array $args
     * @param array $context
     * @param ResolveInfo|null $resolveInfo
     *
     * @return array|null
     *
     * @throws ClientSafeException
     */
    public function resolveDocumentFolderGetter($value = null, $args = [], $context = [], ResolveInfo $resolveInfo = null)
    {
        return $this->resolveFolderGetter($value, $args, $context, $resolveInfo, 'document');
    }

    /**
     * @param ElementDescriptor|null $value
     * @param array $args
     * @param array $context
     * @param ResolveInfo|null $resolveInfo
     *
     * @return array|null
     *
     * @throws ClientSafeException
     */
    public function resolveObjectFolderGetter($value = null, $args = [], $context = [], ResolveInfo $resolveInfo = null)
    {
        return $this->resolveFolderGetter($value, $args, $context, $resolveInfo, 'object');
    }

    /**
     * @param ElementDescriptor|null $value
     * @param array $args
     * @param array $context
     * @param ResolveInfo|null $resolveInfo
     *
     * @return array|null
     *
     * @throws ClientSafeException
     * @deprecated args['path'] will no longer be supported by Release 1.0. Use args['fullpath'] instead.
     *
     */
    public function resolveDocumentGetter($value = null, $args = [], $context = [], ResolveInfo $resolveInfo = null)
    {
        if ($args && isset($args['defaultLanguage'])) {
            $this->getGraphQlService()->getLocaleService()->setLocale($args['defaultLanguage']);
        }

        // TODO: remove this workaround for Release 1.0
        if ($args['path'] ?? false) {
            Logger::warn("Argument 'path' deprecated: will no longer be supported by Release 1.0. Use 'fullpath' instead.");
            $args['fullpath'] = $args['path'];
        }

        $documentElement = $this->getElementByTypeAndIdOrPath($args, 'document');

        if (!$documentElement) {
            return null;
        }

        if (!$this->omitPermissionCheck) {
            if (!WorkspaceHelper::checkPermission($documentElement, 'read')) {
                return null;
            }
        }

        $data = new ElementDescriptor($documentElement);
        $this->getGraphQlService()->extractData($data, $documentElement, $args, $context, $resolveInfo);
        $data = $data->getArrayCopy();

        return $data;
    }

    /**
     * @param ElementDescriptor|null $value
     * @param array $args
     * @param array $context
     * @param ResolveInfo|null $resolveInfo
     *
     * @return ElementDescriptor|null
     *
     * @throws ClientSafeException
     */
    public function resolveAssetGetter($value = null, $args = [], $context = [], ResolveInfo $resolveInfo = null)
    {
        if ($args && isset($args['defaultLanguage'])) {
            $this->getGraphQlService()->getLocaleService()->setLocale($args['defaultLanguage']);
        }

        $assetElement = $this->getElementByTypeAndIdOrPath($args, 'asset');
        if (!$assetElement) {
            return null;
        }

        if (!$this->omitPermissionCheck) {
            if (!WorkspaceHelper::checkPermission($assetElement, 'read')) {
                return null;
            }
        }

        $data = new ElementDescriptor($assetElement);
        $this->getGraphQlService()->extractData($data, $assetElement, $args, $context, $resolveInfo);

        return $data;
    }

    /**
     * @throws \Exception
     */
    public function resolveTranslationGetter(mixed $value = null, array $args = [], array $context = [], ResolveInfo $resolveInfo = null): array
    {
        if (empty($args['key'])) {
            throw new \Exception('Argument key is mandatory');
        }

        $domain = 'messages';
        if (!empty($args['domain'])) {
            $domain = $args['domain'];
        }

        $languages = [];
        if (!empty($args['languages'])) {
            $languages = str_replace(' ', '', $args['languages']);
            $languages = explode(',', $languages);
        }

        $translation = Translation::getByKey($args['key'], $domain, false, false, $languages);
        if (!$translation) {
            return [];
        }

        $fieldHelper = $this->getGraphQlService()->getObjectFieldHelper();

        return $fieldHelper->extractData($data, $translation, $args, $context, $resolveInfo);
    }

    /**
     * @param ElementDescriptor|null $value
     * @param array $args
     * @param array $context
     * @param ResolveInfo|null $resolveInfo
     *
     * @return Deferred|ElementDescriptor
     *
     * @throws ClientSafeException
     */
    public function resolveObjectGetter($value = null, $args = [], $context = [], ResolveInfo $resolveInfo = null)
    {
        $isIdSet = $args['id'] ?? false;
        $isFullpathSet = $args['fullpath'] ?? false;

        if (!$isIdSet && !$isFullpathSet) {
            throw new ClientSafeException('object id or fullpath expected');
        }

        if ($args['defaultLanguage'] ?? false) {
            $this->getGraphQlService()->getLocaleService()->setLocale($args['defaultLanguage']);
        }

        $modelFactory = $this->getGraphQlService()->getModelFactory();
        $listClass = 'Pimcore\\Model\\DataObject\\' . ucfirst($this->class->getName()) . '\\Listing';
        /** @var Listing $objectList */
        $objectList = $modelFactory->build($listClass);
        $conditionParts = [];

        if ($isIdSet) {
            $tableName = $objectList->getDao()->getTableName();
            $conditionParts[] = '(' . $tableName . '.o_id =' . $args['id'] . ')';
        }

        if ($isFullpathSet) {
            $fullpath = Service::correctPath($args['fullpath']);
            $conditionParts[] = '(concat(o_path, o_key) =' . Db::get()->quote($fullpath) . ')';
        }

        /** @var Configuration $configuration */
        $configuration = $context['configuration'];
        $sqlGetCondition = $configuration->getSqlObjectCondition();

        if ($sqlGetCondition) {
            $conditionParts[] = '(' . $sqlGetCondition . ')';
        }

        if ($conditionParts) {
            $condition = implode(' AND ', $conditionParts);
            $objectList->setCondition($condition);
        }

        $objectList->setObjectTypes([AbstractObject::OBJECT_TYPE_OBJECT, AbstractObject::OBJECT_TYPE_FOLDER, AbstractObject::OBJECT_TYPE_VARIANT]);
        $objectList->setLimit(1);
        $objectList->setUnpublished(true);
        $objectList = $objectList->load();
        if (!$objectList) {
            $errorMessage = $this->createArgumentErrorMessage($isFullpathSet, $isIdSet, $args);
            throw new ClientSafeException($errorMessage);
        }
        $object = $objectList[0];

        if (!$this->omitPermissionCheck) {
            if (!WorkspaceHelper::checkPermission($object, 'read')) {
                throw new ClientSafeException('permission denied. check your workspace settings');
            }
        }

        // Attempt to fetch from cache if not explicitly disabled.
        if (
            (!isset($context['caching']['resolveObjectGetter']) || !empty($context['caching']['resolveObjectGetter']))
            // Note: we need a language to avoid showing data in wrong language.
            && $resolveInfo && !empty($resolveInfo->variableValues['lang'])
        ) {
            $cachedResult = $this->getCacheEntry($object, $resolveInfo, $context);
            if ($cachedResult instanceof Deferred) {
                return $cachedResult;
            }
        }

        $data = new ElementDescriptor($object);
        $data['id'] = $object->getId();
        $this->getGraphQlService()->extractData($data, $object, $args, $context, $resolveInfo);

        return $data;
    }

    /**
     * @param array|null $value
     * @param array $args
     * @param array $context
     * @param ResolveInfo|null $resolveInfo
     *
     * @return array
     */
    public function resolveEdge($value = null, $args = [], $context = [], ResolveInfo $resolveInfo = null)
    {
        $object = $value['node'];
        $nodeData = [];

        // We have Mockup Objects from Elastic here. To check the permission and avoid loading each element from DB
        // an element Type is set here
        if ($this->omitPermissionCheck || WorkspaceHelper::checkPermission($object, 'read', 'object')) {
            $data = new ElementDescriptor();
            $fieldHelper = $this->getGraphQlService()->getObjectFieldHelper();
            $nodeData = $fieldHelper->extractData($data, $object, $args, $context, $resolveInfo);
        }

        // Attempt to fetch from cache if not explicitly disabled.
        if (
            (!isset($context['caching']['resolveObjectGetter']) || !empty($context['caching']['resolveObjectGetter']))
            // Note: we need a language to avoid showing data in wrong language.
            && $resolveInfo && !empty($resolveInfo->variableValues['lang'])
        ) {
            $cachedResult = $this->getCacheEntry($object, $resolveInfo, $context);
            if ($cachedResult instanceof Deferred) {
                return $cachedResult;
            }
        }

        return $nodeData;
    }

    /**
     * @param $object
     * @param ResolveInfo $resolveInfo
     * @param array $context
     *
     * @return Deferred|null
     */
    private function getCacheEntry($object, ResolveInfo $resolveInfo, array $context): ?Deferred
    {
        $indexKey = null;
        $path = $resolveInfo->path;
        // we need to replace the index of a list item with a generic value
        // as we don't want to cache a specific position of an item only the object itself
        foreach ($resolveInfo->path as $key => $index) {
            if (is_numeric($index)) {
                $indexKey = $index;
                $path[$key] = 'delta';
            }
        }
        // Create a unique cache ID based on initial query, path, language and
        // object properties.
        $query = CacheHelper::getQueryHash($context['doc']);
        $language = $resolveInfo->variableValues['lang'];
        $cid = CacheHelper::generateCacheId(
            ['datahub-caching', $object->getClassId(), $object->getId(), $language, $query, implode(',', $path)]
        );
        if ($cachedData = Cache::load($cid)) {
            $deferred = new Deferred(function () use ($cachedData) {
                return $cachedData;
            });
            $deferred->state = SyncPromise::FULFILLED;
            $deferred->result = $cachedData;

            return $deferred;
        }
        // Register item for cache saving in cache listener. Since we don't have
        // the full data yet this is postponed after execution.
        CacheListener::addCachingItem($context['operation'], $cid, $path, $object->getId(), $indexKey);

        return null;
    }

    /**
     * @param array|null $value
     * @param array $args
     * @param array $context
     * @param ResolveInfo|null $resolveInfo
     *
     * @return array
     */
    public function resolveEdges($value = null, $args = [], $context = [], ResolveInfo $resolveInfo = null)
    {
        $objectList = $value['edges']();

        // fire post edge event
        $eventOptions = ['value' => $value, 'args' => $args, 'context' => $context];
        $event = new EdgeEvent($objectList, $resolveInfo, $eventOptions);
        $this->eventDispatcher->dispatch($event, EdgeEvents::POST_LOAD);

        // get back object list from event
        $objectList = $event->getObjects();

        $nodes = [];

        foreach ($objectList as $object) {
            // We have Mockup Objects from Elastic here. To check the permission and avoid loading each element from DB
            // an element Type is set here
            if (!$this->omitPermissionCheck && !WorkspaceHelper::checkPermission($object, 'read', 'object')) {
                continue;
            }

            $nodes[] = [
                'cursor' => 'object-' . $object->getId(),
                'node' => $object,
            ];
        }

        return $nodes;
    }

    /**
     * @param ElementDescriptor|null $value
     * @param array $args
     * @param array $context
     * @param ResolveInfo|null $resolveInfo
     *
     * @return array
     *
     * @throws \Exception
     */
    public function resolveListing($value = null, $args = [], $context = [], ResolveInfo $resolveInfo = null)
    {
        if ($args && isset($args['defaultLanguage'])) {
            $this->getGraphQlService()->getLocaleService()->setLocale($args['defaultLanguage']);
        }

        $modelFactory = $this->getGraphQlService()->getModelFactory();
        $listClass = 'Pimcore\\Model\\DataObject\\' . ucfirst($this->class->getName()) . '\\Listing';
        /** @var Listing\Concrete $objectList */
        $objectList = $modelFactory->build($listClass);
        $tableName = $objectList->getDao()->getTableName();

        $conditionParts = [];
        $db = Db::get();
        if (isset($args['ids'])) {
            // Explode it and then quote it
            if (!is_array($args['ids'])) {
                $args['ids'] = explode(',', $args['ids']);
            }
            $ids = implode(', ', array_map([$db, 'quote'], $args['ids']));
            $conditionParts[] = '(o_id IN (' . $ids . '))';
        }
        if (isset($args['fullpaths'])) {
            $quotedFullpaths = array_map(
                static function ($fullpath) use ($db) {
                    $fullpath = trim($fullpath, " '");
                    $fullpath = Service::correctPath($fullpath);

                    return $db->quote($fullpath);
                },
                str_getcsv($args['fullpaths'], ',', "'")
            );
            $conditionParts[] = '(concat(o_path, o_key) IN (' . implode(',', $quotedFullpaths) . '))';
        }

        // paging
        if (isset($args['first'])) {
            $objectList->setLimit($args['first']);
        }

        if (isset($args['after'])) {
            $objectList->setOffset($args['after']);
        }

        // sorting
        if (!empty($args['sortBy'])) {
            $objectList->setOrderKey($args['sortBy']);
            if (!empty($args['sortOrder'])) {
                $objectList->setOrder($args['sortOrder']);
            }
        }

        // Include unpublished
        if (isset($args['published']) && $args['published'] === false) {
            $objectList->setUnpublished(true);
        }

        /** @var Configuration $configuration */
        $configuration = $context['configuration'];
        $sqlListCondition = $configuration->getSqlObjectCondition();

        if ($sqlListCondition) {
            $conditionParts[] = '(' . $sqlListCondition . ')';
        }

        if (!$configuration->skipPermisssionCheck()) {
            // check permissions
            $workspacesTableName = 'plugin_datahub_workspaces_object';
            $conditionParts[] = ' (
            (
                SELECT `read` from ' . $db->quoteIdentifier($workspacesTableName) . '
                WHERE ' . $db->quoteIdentifier($workspacesTableName) . '.configuration = ' . $db->quote($configuration->getName()) . '
                AND LOCATE(CONCAT(' . $db->quoteIdentifier($tableName) . '.o_path,' . $db->quoteIdentifier($tableName) . '.o_key),' . $db->quoteIdentifier($workspacesTableName) . '.cpath)=1
                ORDER BY LENGTH(' . $db->quoteIdentifier($workspacesTableName) . '.cpath) DESC
                LIMIT 1
            )=1
            OR
            (
                SELECT `read` from ' . $db->quoteIdentifier($workspacesTableName) . '
                WHERE ' . $db->quoteIdentifier($workspacesTableName) . '.configuration = ' . $db->quote($configuration->getName()) . '
                AND LOCATE(' . $db->quoteIdentifier($workspacesTableName) . '.cpath,CONCAT(' . $db->quoteIdentifier($tableName) . '.o_path,' . $db->quoteIdentifier($tableName) . '.o_key))=1
                ORDER BY LENGTH(' . $db->quoteIdentifier($workspacesTableName) . '.cpath) DESC
                LIMIT 1
            )=1
            )';
        }

        if (isset($args['filter'])) {
            $filter = json_decode($args['filter'], false);
            if (!$filter) {
                throw new ClientSafeException('unable to decode filter');
            }

            $className = $this->class->getName();
            $columns = $this->configuration->configuration['schema']['queryEntities'][$className]['columnConfig']['columns'];

            Helper::addJoins($objectList, $filter, $columns, $mappingTable);

            $filterCondition = Helper::buildSqlCondition($tableName, $filter, null, null, $mappingTable);
            $conditionParts[] = $filterCondition;
        }

        if ($conditionParts) {
            $condition = implode(' AND ', $conditionParts);
            $objectList->setCondition($condition);
        }

        $objectList->setObjectTypes([AbstractObject::OBJECT_TYPE_OBJECT, AbstractObject::OBJECT_TYPE_FOLDER, AbstractObject::OBJECT_TYPE_VARIANT]);

        $event = new ListingEvent(
            $objectList,
            $args,
            $context,
            $resolveInfo
        );
        $this->eventDispatcher->dispatch($event, ListingEvents::PRE_LOAD);
        $objectList = $event->getListing();

        $connection = [];
        $connection['edges'] = [$objectList, 'load'];
        $connection['totalCount'] = [$objectList, 'getTotalCount'];

        return $connection;
    }

    /**
     * @param ElementDescriptor|null $value
     * @param array $args
     * @param array $context
     * @param ResolveInfo|null $resolveInfo
     *
     * @return mixed
     */
    public function resolveListingTotalCount($value = null, $args = [], $context = [], ResolveInfo $resolveInfo = null)
    {
        return $value['totalCount']();
    }

    /**
     * @param bool $isFullpathSet
     * @param bool $isIdSet
     * @param array $args
     *
     * @return string
     */
    private function createArgumentErrorMessage($isFullpathSet, $isIdSet, $args)
    {
        if ($isIdSet && $isFullpathSet) {
            return 'either id or fullpath expected but not both';
        }
        if ($isIdSet) {
            return "object with id:'" . $args['id'] . "' not found";
        }
        if ($isFullpathSet) {
            return "object with fullpath:'" . $args['fullpath'] . "' not found";
        }

        return 'either id or fullpath expected';
    }

    /**
     * Build a filter query.
     *
     * @TODO Create response format to provide facets.
     *
     * @param null $value
     * @param array $args
     * @param array $context
     * @param ResolveInfo|null $resolveInfo
     *
     * @return array|null
     *
     * @throws Exception
     * @throws InvalidConfigException
     * @throws \Doctrine\DBAL\Exception
     */
    public function resolveFilter($value = null, $args = [], $context = [], ResolveInfo $resolveInfo = null): ?array
    {
        if ($args && $args['defaultLanguage']) {
            $this->getGraphQlService()->getLocaleService()->setLocale($args['defaultLanguage']);
        }
        $factory = Factory::getInstance();
        $this->setupAssortment($args, $factory);
        $resultList = $this->getProductList($args, $factory);

        return $this->resolveFilterQuery($args, $context, $factory, $resultList, $resolveInfo );

    }

    /**
     * @throws InvalidConfigException
     * @throws Exception
     * @throws \Doctrine\DBAL\Exception
     */
    public function resolveBrandFilter($value = null, $args = [], $context = [], ResolveInfo $resolveInfo = null): ?array
    {
        if ($args && $args['defaultLanguage']) {
            $this->getGraphQlService()->getLocaleService()->setLocale($args['defaultLanguage']);
        }
        $factory = Factory::getInstance();
        $this->setupAssortment($args, $factory);
        $resultList = $this->getProductList($args, $factory);

        // check for configured brand config in index attributes
        $attributeConfig = $resultList->getTenantConfig()->getAttributeConfig();
        // how to deal with this magic string
        $brandConfig = $attributeConfig['brand'] ?? null;
        if (!$brandConfig) {
            throw new \Exception('cannot find an indexed attribute named brand');
        }
        $indexFieldName = 'relations.' . $brandConfig['name'];
        $resultList->addCondition($args['brand'], $indexFieldName);

        // @TODO: how can we call the "resolveFilter" with the correct arguments
        // steps:
        // - check if a filter for brand exists here ?
        // - re-create a filter defintion if not set
        // - copy arguemnt over as a filter input or directly
        //
        return $this->resolveFilterQuery($args, $context, $factory, $resultList, $resolveInfo );
    }

    /**
     * @param null $value
     * @param array $args
     * @param $context
     * @param ResolveInfo|null $resolveInfo
     *
     * @return mixed
     */
    public function resolveFilterTotalCount($value = null, $args = [], $context = [], ResolveInfo $resolveInfo = null)
    {
        return $value['totalCount']();
    }

    /**
     * @param null $value
     * @param array $args
     * @param $context
     * @param ResolveInfo|null $resolveInfo
     *
     * @return mixed
     */
    public function resolveFacets($value = null, $args = [], $context = [], ResolveInfo $resolveInfo = null)
    {
        //check which values are necessary if multiple facet arguments sent in the request
        //this prevents empty arrays in multiple facet types
        $facetName = end($resolveInfo->path);

        $filterNodes = null;
        /** @var NodeList $requestedFilters */
        $requestedFilters = $resolveInfo->operation->selectionSet->selections[0]->selectionSet->selections[0]->selectionSet->selections;

        foreach ($requestedFilters as $filter) {
            if (isset($filter->alias) && $filter->alias->value == $facetName) {
                $filterNodes = $filter->selectionSet->selections;
            }
        }
        $filterNames = [];
        $fragmentNames = [];
        $storeFragments = false;
        foreach ($filterNodes as $filterNode) {
            if ($filterNode->kind == NodeKind::FRAGMENT_SPREAD) {
                $fragmentNames[] = $filterNode->name->value;
                $storeFragments = true;
            }
            if ($filterNode->kind == NodeKind::INLINE_FRAGMENT) {
                $filterNames[] = $filterNode->typeCondition->name->value;
            }
        }
        //just store fragments one time
        if ($storeFragments) {
            //store all fragment type names which are set as filter fragments
            foreach ($resolveInfo->fragments as $fragment) {
                if (in_array($fragment->name->value, $fragmentNames)) {
                    $filterNames[] = $fragment->typeCondition->name->value;
                }
            }
        }

        $facets = [];
        $filterFields = [];
        foreach ($value['facets'] as $facet) {
            $filter = $facet['filter'];
            $filterType = $filter->getType();
            foreach ($filterNames as $filterName) {
                $fieldName = $filter->getField();
                // check filterName string and duplicates e.g. FilterSelect and FilterSelectSortable has the same beginning name
                if (strpos($filterName, $filterType) !== false && !in_array($fieldName, $filterFields)) {
                    $facets[] = $facet;
                    $filterFields[] = $fieldName;
                }
            }
        }
        if (!empty($facets)) {
            return $facets;
        }

        return $value['facets'];
    }

    /**
     * @param null $value
     * @param array $args
     * @param $context
     * @param ResolveInfo|null $resolveInfo
     *
     * @return mixed
     */
    public function resolveFacet($value = null, $args = [], $context = [], ResolveInfo $resolveInfo = null)
    {
        $translator = $this->getGraphQlService()->getTranslator();

        $filter = $value['filter'];
        $filterService = $value['filterService'];
        $resultList = $value['resultList'];

        // Extract the facet information.
        /* @var \Pimcore\Bundle\EcommerceFrameworkBundle\Model\AbstractFilterDefinitionType $filter */
        $filterType = $filterService->getFilterType($filter->getType());
        $field = HijackAbstractFilterType::getFieldFromFilter($filterType, $filter);
        $options = $resultList->getGroupByValues($field, true, !method_exists($filter, 'getUseAndCondition') || !$filter->getUseAndCondition());

        foreach ($options as &$option) {
            if (!empty($option['value'])) {
                $option['label'] = $option['value'];
            } else {
                $option['label'] = '';
            }
        }

        // fill config object
        $config = new stdClass();
        if (method_exists($filter, 'getConfig')) {
            $config = $filter->getConfig();
        }

        $value = [
            'filterType' => $filter->getType(),
            'field' => $field,
            'label' => $translator->trans($filter->getLabel()),
            'config' => $config,
            'options' => $options,
        ];

        return isset($value[$resolveInfo->fieldName]) ? $value[$resolveInfo->fieldName] : null;
    }

    /**
     * @param array $args
     * @param Factory $factory
     * @return array
     */
    protected function setupAssortment(array $args, Factory $factory): void
    {
        // Set tenant config.
        if (!empty($args['tenant'])) {
            $factory->getEnvironment()->setCurrentAssortmentTenant($args['tenant']);
        } else {
            if (class_exists('\Pimcore\Model\DataObject\Tenant')) {
                $security = new Security(\Pimcore::getKernel()->getContainer());
                $user = $security->getUser();
                $environment = $factory->getEnvironment();

                $tenantEvent = new TenantEvent($user);
                $this->eventDispatcher->dispatch($tenantEvent, TenantEvents::LOAD_TENANTS);

                $userTenants = $tenantEvent->getUserTenants();
                if (method_exists($environment, 'setMultipleAssortmentTenants')) {
                    $environment->setMultipleAssortmentTenants($userTenants);
                }
            }
        }
    }

    /**
     * @param array $args
     * @param array $context
     * @param Factory $factory
     * @param ProductListInterface $resultList
     * @param ResolveInfo|null $resolveInfo
     * @return array|null
     * @throws InvalidConfigException
     * @throws Exception
     * @throws \Doctrine\DBAL\Exception
     */
    protected function resolveFilterQuery(
        array $args,
        array $context,
        Factory $factory,
        ProductListInterface $resultList,
        ?ResolveInfo $resolveInfo
    ): ?array {
        /** @var AbstractFilterDefinition $filterDefinition */
        $facets = [];
        $filterDefinition = false;
        // Set default settings using a FilterDefinition if id is provided.
        if (!empty($args['filterDefinition'])) {
            if (isset($args['filterDefinition']['id'])) {
                $object = AbstractObject::getById($args['filterDefinition']['id']);
                if ($object instanceof AbstractFilterDefinition) {
                    $filterDefinition = $object;
                } elseif ($object && isset($args['filterDefinition']['relationField'])) {
                    $getter = 'get' . ucfirst($args['filterDefinition']['relationField']);
                    if (method_exists($object, $getter)) {
                        $filterDefinition = $object->$getter();
                    }
                }
            }
            if (
                !($filterDefinition instanceof AbstractFilterDefinition)
                && isset($args['filterDefinition']['fallbackFilterDefinitionId'])
            ) {
                $filterDefinition = AbstractFilterDefinition::getById($args['filterDefinition']['fallbackFilterDefinitionId']);
            }
            if ($filterDefinition) {
                $filterService = $factory->getFilterService();

                if ($pageLimit = $filterDefinition->getPageLimit()) {
                    $resultList->setLimit($pageLimit);
                }

                // we need to set the default OrderBy only on specific preconditions
                //if (empty($args['fulltext']) && empty($args['facets'])) {
                // adds default sort from FilterDefinition "Default OrderBy"
                $orderByList = [];
                if ($orderByCollection = $filterDefinition->getDefaultOrderBy()) {
                    foreach ($orderByCollection as $orderBy) {
                        if (method_exists($orderBy, 'getAdvancedSort')) {
                            $config = $factory->getIndexService()->getCurrentTenantConfig();
                            $orderByList = $orderBy->getAdvancedSort($orderByCollection, $config);
                            break;
                        } else {
                            if (method_exists($orderBy, 'getOrderField')) {
                                if ($orderBy->getOrderField()) {
                                    $orderByList[] = [$orderBy->getOrderField(), $orderBy->getDirection()];
                                    continue;
                                }
                            }
                            if ($orderBy->getField()) {
                                $orderByList[] = [$orderBy->getField(), $orderBy->getDirection()];
                            }
                        }
                    }
                }
                $resultList->setOrderKey($orderByList);
                $resultList->setOrder('ASC');
                //}

                $filterValues = [];
                if (!empty($args['facets'])) {
                    foreach ($args['facets'] as $facet) {
                        $filterValues[$facet['field']] = $facet['values'];
                    }
                }
                // Read out requested filter from GraphQL Request Query to check if an output is necessary or not
                $filterNodes = [];
                /** @var NodeList $requestedFilters */
                $requestedFilters = $resolveInfo->operation->selectionSet->selections[0]->selectionSet->selections[0]->selectionSet->selections;

                foreach ($requestedFilters as $filter) {
                    if ($filter->name->value == 'facets') {
                        $filterNodes[] = $filter->selectionSet->selections;
                    }
                }

                //Facets could be multiple in a Request e.g. to separate filters and categories
                //Merge everything together
                if (count($filterNodes) >= 1) {
                    $tempFilterNodes = [];
                    foreach ($filterNodes as $filterNode) {
                        foreach ($filterNode as $node) {
                            $tempFilterNodes[] = $node;
                        }
                    }
                    $filterNodes = $tempFilterNodes;
                }

                $requestFilters = [];
                if (!empty($filterNodes)) {
                    foreach ($filterNodes as $filterNode) {
                        if ($filterNode->kind == NodeKind::FRAGMENT_SPREAD && $filters = $filterDefinition->getFilters()) {
                            /** @var FragmentSpreadNode $filterNode */
                            //check for fragments type name because fragments can have any name
                            foreach ($filters as $savedFilter) {
                                foreach ($resolveInfo->fragments as $fragment) {
                                    if (strpos($fragment->typeCondition->name->value, $savedFilter->getType()) !== false) {
                                        if ($filterNode->name->value == $fragment->name->value) {
                                            $requestFilters[] = $fragment->typeCondition->name->value;
                                        }
                                    }
                                }
                            }
                        }
                        if ($filterNode->kind == NodeKind::INLINE_FRAGMENT) {
                            /** @var InlineFragmentNode $filterNode */
                            $requestFilters[] = $filterNode->typeCondition->name->value;
                        }
                    }
                }

                if ($filters = $filterDefinition->getFilters()) {
                    foreach ($filters as $k => $filter) {
                        // Check if this filter can handle multiple values and if
                        // not use the first values entry.
                        $filterType = $filterService->getFilterType($filter->getType());
                        $field = HijackAbstractFilterType::getFieldFromFilter($filterType, $filter);

                        // Check if filter is requested from GraphQL Query
                        $hasFilter = false;
                        foreach ($requestFilters as $requestFilter) {
                            if (strpos($requestFilter, $filter->getType()) !== false) {
                                $hasFilter = true;
                                break;
                            }
                        }
                        // If still adding field to facets which is not request an empty array is in the output result
                        if (!$hasFilter) {
                            continue;
                        }
                        if (!HijackAbstractFilterType::isMultiValueFilter($filterType, $filter)) {
                            if (isset($filterValues[$field])) {
                                $filterValues[$field] = current($filterValues[$field]);
                            }
                        }

                        $facets[$k] = [
                            'filter' => $filter,
                            'filterService' => $filterService,
                            'resultList' => $resultList,
                        ];
                    }
                }

                $currentFilters = $filterService->initFilterService($filterDefinition, $resultList, $filterValues);
            }
        }
        // paging
        if (isset($args['first'])) {
            $resultList->setLimit($args['first']);
        }
        if (isset($args['after'])) {
            $resultList->setOffset($args['after']);
        }

        // Manual sorting
        if (!empty($args['sortBy'])) {
            if (!empty($args['sortOrder'])) {
                $resultList->setOrderKey(
                    array_map(function ($a, $b) {
                        return [$a, $b];
                    }, $args['sortBy'], $args['sortOrder']));
            } else {
                $resultList->setOrderKey($args['sortBy']);
            }
        }

        if (!empty($args['variantMode'])) {
            $resultList->setVariantMode($args['variantMode']);
        }

        if (!empty($args['fulltext'])) {
            if ($resultList instanceof DefaultMysql) {
                $resultList->buildFulltextSearchWhere(
                    $resultList->getCurrentTenantConfig()->getSearchAttributes(),
                    $args['fulltext']
                );

                return $resultList->addCondition($args['fulltext'], 'relevance');
            } elseif ($resultList instanceof AbstractElasticSearch) {
                $resultList->addQueryCondition($args['fulltext']);

                // Update sorting if not manually specified. Use the currently set
                // default sorting with prefixed scoring.
                if (empty($args['sortBy'])) {
                    $sorting = $resultList->getOrderKey();
                    if (empty($sorting)) {
                        $sorting = [['_score', 'DESC']];
                    } else {
                        if (!is_array($sorting)) {
                            $sorting = [$sorting];
                        }
                        if (isset($sorting[$resultList::ADVANCED_SORT])) {
                            $sorting[$resultList::ADVANCED_SORT] = array_merge(
                                [(object)[
                                    '_score' => 'desc',
                                ]],
                                $sorting[$resultList::ADVANCED_SORT]
                            );
                        } else {
                            $sorting = array_merge([['_score', 'DESC']], $sorting);
                        }
                    }
                    $resultList->setOrderKey($sorting);
                }
            }
        }

        /** @var $configuration Configuration */
        $configuration = $context['configuration'];
        // @TODO Implement SQL Conditions in a generic way - we need to support
        // ElasticSearch.
        //@TODO Implement workspace limitation in a generic way.

        $db = Db::get();
        if ($resultList instanceof DefaultMysql) {
            // Add SQL-Conditions.
            if ($sqlListCondition = $configuration->getSqlObjectCondition()) {
                $conditionParts[] = '(' . $sqlListCondition . ')';
            }
            // check permissions
            $conditionParts[] = ' (
                                    (select `read` from plugin_datahub_workspaces_object where configuration = ' . $db->quote($configuration->getName()) . ' and LOCATE(CONCAT(o_path,o_key),cpath)=1  ORDER BY LENGTH(cpath) DESC LIMIT 1)
                                    UNION
                                    (select `read` from plugin_datahub_workspaces_object where configuration = ' . $db->quote($configuration->getName()) . ' and LOCATE(cpath,CONCAT(o_path,o_key))=1  ORDER BY LENGTH(cpath) DESC LIMIT 1)
                                 )';
            if ($conditionParts) {
                $condition = implode(' AND ', $conditionParts);
                $resultList->addCondition($condition);
            }
        } elseif ($resultList instanceof AbstractElasticSearch) {
            /** @var AbstractElasticSearch $resultList */

            // @FIXME How can we convert that to DSL?
            // We might can use something like this:
            // https://www.elastic.co/guide/en/elasticsearch/reference/6.8/sql-spec.html
            // https://github.com/elastic/elasticsearch/tree/master/x-pack/plugin/sql
            // https://github.com/opendistro-for-elasticsearch/sql
            // $sqlListCondition = $configuration->getSqlObjectCondition();

            // Fetch readablePaths to implement a access filter.
            $readablePaths = $db->fetchCol('select `cpath` from plugin_datahub_workspaces_object where configuration = ? AND `read`=1 ORDER BY LENGTH(cpath)', [$configuration->getName()]);
            // @FIXME path is not part of the system parameters indexed - see
            // \Pimcore\Bundle\EcommerceFrameworkBundle\IndexService\Worker\ElasticSearch\AbstractElasticSearch::getSystemAttributes()
            // We could hook into the indexing and add it automagically but that
            // seems intrusive.
            // $resultList->addCondition(['terms' => ['system.path' => $readablePaths]]);
        }

        /** @var AbstractCategory $category */
        if (!empty($args['category']) && ($category = AbstractObject::getById($args['category']))) {
            $resultList->setCategory($category);
        }

        $resultList->setInProductList(!isset($args['published']) || !empty($args['published']));

        $connection = [];
        $connection['edges'] = [$resultList, 'load'];
        $connection['facets'] = $facets;
        $connection['totalCount'] = [$resultList, 'count'];

        return $connection;
    }

    /**
     * @param array $args
     * @param Factory $factory
     * @return ProductListInterface
     */
    protected function getProductList(array $args, Factory $factory): ProductListInterface
    {
        if ($args && $args['defaultLanguage']) {
            // get language based tenant
            $resultList = $factory->getIndexService()->getProductListForTenant('default_' . $args['defaultLanguage']);
        } else {
            // get fallback resultList
            $resultList = $factory->getIndexService()->getProductListForCurrentTenant();
        }
        return $resultList;
    }
}
