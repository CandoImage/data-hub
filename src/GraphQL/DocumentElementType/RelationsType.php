<?php

/**
 * Pimcore
 *
 * This source file is available under two different licenses:
 * - GNU General Public License version 3 (GPLv3)
 * - Pimcore Enterprise License (PEL)
 * Full copyright and license information is available in
 * LICENSE.md which is distributed with this source code.
 *
 * @copyright  Copyright (c) Pimcore GmbH (http://www.pimcore.org)
 * @license    http://www.pimcore.org/license     GPLv3 and PEL
 */

namespace Pimcore\Bundle\DataHubBundle\GraphQL\DocumentElementType;

use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\ResolveInfo;
use GraphQL\Type\Definition\Type;
use Pimcore\Bundle\DataHubBundle\GraphQL\ElementDescriptor;
use Pimcore\Bundle\DataHubBundle\GraphQL\Service;
use Pimcore\Model\Document\Tag\Relation;
use Pimcore\Model\Document\Tag\Relations;

class RelationsType extends ObjectType
{
    protected static $instance;

    /**
     * @param Service $graphQlService
     * @return RelationType
     * @throws \Exception
     */
    public static function getInstance(Service $graphQlService)
    {

        if (!self::$instance) {

            $anyTargetType = $graphQlService->buildGeneralType("anytarget");

            $config =
                [
                    'name' => 'document_tagRelations',
                    'fields' => [
                        '_tagType' => [
                            'type' => Type::string(),
                            'resolve' => static function ($value = null, $args = [], $context = [], ResolveInfo $resolveInfo = null) {
                                if ($value instanceof Relation) {
                                    return $value->getType();
                                }
                            }
                        ],
                        '_tagName' => [
                            'type' => Type::string(),
                            'resolve' => static function ($value = null, $args = [], $context = [], ResolveInfo $resolveInfo = null) {
                                if ($value instanceof Relation) {
                                    return $value->getName();
                                }
                            }
                        ],
                        'relations' => [
                            'type' => Type::listOf($anyTargetType),
                            'resolve' => static function ($value = null, $args = [], $context = [], ResolveInfo $resolveInfo = null) use ($graphQlService) {

                                if ($value instanceof Relations) {
                                    $targets = $value->getElements();
                                    if ($targets) {
                                        $result = [];
                                        foreach ($targets as $target) {
                                            $data = new ElementDescriptor($target);
                                            $graphQlService->extractData($data, $target, $args, $context, $resolveInfo);
                                            $result[] = $data;

                                        }
                                        return $result;
                                    }
                                }
                            }
                        ]
                    ]
                ];
            self::$instance = new static($config);
        }

        return self::$instance;
    }


}
