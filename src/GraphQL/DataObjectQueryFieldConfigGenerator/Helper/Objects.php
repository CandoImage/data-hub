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

namespace Pimcore\Bundle\DataHubBundle\GraphQL\DataObjectQueryFieldConfigGenerator\Helper;

use GraphQL\Deferred;
use GraphQL\Executor\Promise\Adapter\SyncPromise;
use GraphQL\Type\Definition\ResolveInfo;
use Pimcore\Bundle\DataHubBundle\GraphQL\BaseDescriptor;
use Pimcore\Bundle\DataHubBundle\GraphQL\ElementDescriptor;
use Pimcore\Bundle\DataHubBundle\GraphQL\Service;
use Pimcore\Bundle\DataHubBundle\GraphQL\Traits\ServiceTrait;
use Pimcore\Bundle\DataHubBundle\WorkspaceHelper;
use Pimcore\Bundle\EcommerceFrameworkBundle\Model\DefaultMockup;
use Pimcore\Model\DataObject\ClassDefinition;
use Pimcore\Model\DataObject\ClassDefinition\Data;
use Pimcore\Model\Element\AbstractElement;

class Objects
{
    use ServiceTrait;

    /**
     * @var Data
     */
    public $fieldDefinition;

    /**
     * @var ClassDefinition
     */
    public $class;

    /**
     * @var string
     */
    public $attribute;

    /**
     * @param Service $graphQlService
     * @param string $attribute
     * @param Data $fieldDefinition
     * @param ClassDefinition $class
     */
    public function __construct(Service $graphQlService, $attribute, $fieldDefinition, $class)
    {
        $this->fieldDefinition = $fieldDefinition;
        $this->class = $class;
        $this->attribute = $attribute;
        $this->setGraphQLService($graphQlService);
    }

    /**
     * @param mixed $value
     * @param array $args
     * @param array $context
     * @param ResolveInfo|null $resolveInfo
     *
     * @return array|null
     *
     * @throws \Exception
     */
    public function resolve($value = null, $args = [], $context = [], ResolveInfo $resolveInfo = null)
    {
        if ($value instanceof BaseDescriptor) {
            $relations = Service::resolveValue($value, $this->fieldDefinition, $this->attribute, $args);
            if ($relations) {
                $result = [];
                foreach ($relations as $relation) {
                    /** @var $relation AbstractElement */
                    // Explicitly set the type of the mockup object because
                    // these don't have the matching class instance for
                    // auto-detect.
                    $type = null;
                    if ($relation instanceof DefaultMockup) {
                        $type = $relation->getType();
                    }
                    if (!WorkspaceHelper::checkPermission($relation, 'read', $type)) {
                        continue;
                    }

                    $data = new ElementDescriptor($relation);
                    $this->getGraphQlService()->extractData($data, $relation, $args, $context, $resolveInfo);
                    $result[] = $data;
                }

                return $result;
            }
        }
        $cachedValue = Service::resolveCachedValue($value, $resolveInfo);
        if ($cachedValue !== null) {
            $deferred = new Deferred(function () use ($cachedValue) {
                return $cachedValue;
            });
            $deferred->state = SyncPromise::FULFILLED;
            $deferred->result = $cachedValue;

            return $deferred;
        }

        return null;
    }
}
