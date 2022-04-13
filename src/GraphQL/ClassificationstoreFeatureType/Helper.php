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

namespace Pimcore\Bundle\DataHubBundle\GraphQL\ClassificationstoreFeatureType;

use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\ResolveInfo;
use GraphQL\Type\Definition\Type;
use Pimcore;
use Pimcore\Bundle\DataHubBundle\GraphQL\FeatureDescriptor;
use Pimcore\Model\DataObject\Classificationstore\KeyConfig;

class Helper extends ObjectType
{
    /**
     * @return array
     */
    public static function getCommonFields()
    {
        $fields = [
            'id' => [
                'type' => Type::int(),
                'resolve' => static function ($value = null, $args = [], $context = [], ResolveInfo $resolveInfo = null) {
                    if ($value instanceof FeatureDescriptor) {
                        return $value->getId();
                    }
                }
            ],
            'name' => [
                'type' => Type::string(),
                'resolve' => static function ($value = null, $args = [], $context = [], ResolveInfo $resolveInfo = null) {
                    if ($value instanceof FeatureDescriptor) {
                        $keyConfig = KeyConfig::getById($value->getId());
                        if ($keyConfig) {
                            return $keyConfig->getName();
                        }
                    }
                }
            ],
            'translatedName' => [
                'type' => Type::string(),
                'resolve' => static function ($value = null, $args = [], $context = [], ResolveInfo $resolveInfo = null) {
                    if ($value instanceof FeatureDescriptor) {
                        $keyConfig = KeyConfig::getById($value->getId());
                        if ($keyConfig) {
                            $language = $value['_language'];
                            if (!$language) {
                                $graphQLService = Pimcore::getKernel()->getContainer()->get('Pimcore\Bundle\DataHubBundle\GraphQL\Service');
                                if ($graphQLService) {
                                    $language = $graphQLService->getLocaleService()->getLocale();
                                }
                                // Let's try to "inherit" the language from what's already been parsed from this query
                                if (!$language) {
                                    $language = 'default';
                                }
                            }
                            $translator = Pimcore::getKernel()->getContainer()->get('translator');
                            if (!$translator) {
                                return $keyConfig->getName();
                            }

                            return $translator->trans($keyConfig->getName(), [], 'admin', $language);
                        }
                    }
                }
            ],
            'description' => [
                'type' => Type::string(),
                'resolve' => static function ($value = null, $args = [], $context = [], ResolveInfo $resolveInfo = null) {
                    if ($value instanceof FeatureDescriptor) {
                        $keyConfig = KeyConfig::getById($value->getId());
                        if ($keyConfig) {
                            return $keyConfig->getDescription();
                        }
                    }
                }
            ],
            'type' => [
                'type' => Type::string(),
                'resolve' => static function ($value = null, $args = [], $context = [], ResolveInfo $resolveInfo = null) {
                    if ($value instanceof FeatureDescriptor) {
                        return $value->getType();
                    }
                }
            ]
        ];

        return $fields;
    }
}
