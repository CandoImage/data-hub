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

namespace Pimcore\Bundle\DataHubBundle\GraphQL\Resolver;

use GraphQL\Type\Definition\ResolveInfo;
use Pimcore\Bundle\DataHubBundle\GraphQL\Service;
use Pimcore\Bundle\DataHubBundle\GraphQL\Traits\ServiceTrait;
use Pimcore\Bundle\DataHubBundle\WorkspaceHelper;
use Throwable;

class Link
{
    use ServiceTrait;

    /**
     * @param null $value
     * @param array $args
     * @param array $context
     * @param ResolveInfo|null $resolveInfo
     *
     * @return array
     *
     * @throws \Exception
     */
    public function resolveText($value = null, $args = [], $context = [], ResolveInfo $resolveInfo = null)
    {
        if (is_array($value)) {
            return Service::resolveCachedValue($value, $resolveInfo);
        }
        if ($value instanceof \Pimcore\Model\DataObject\Data\Link) {
            return $value->getText();
        }

        return null;
    }

    /**
     * @param null $value
     * @param array $args
     * @param array $context
     * @param ResolveInfo|null $resolveInfo
     *
     * @return array
     *
     * @throws \Exception
     */
    public function resolvePath($value = null, $args = [], $context = [], ResolveInfo $resolveInfo = null)
    {
        if (is_array($value)) {
            return Service::resolveCachedValue($value, $resolveInfo);
        }
        // CANDO: BEGIN CUSTOM CODE
        // for internal links to objects check permission
        if ($value->getLinktype() === 'internal' && $value->getInternalType() === 'object' && $value->getInternal()) {
            // get linked object
            $element = \Pimcore\Model\Element\Service::getElementById($value->getInternalType(), $value->getInternal());
            if (!$element) {
                return null;
            }
            // simply catch exception and return null instead of an exception
            // will return null and prevent FE to display a link item, which leads to a not found
            try {
                if (!WorkspaceHelper::checkPermission($element, 'read')) {
                    return null;
                }
            } catch (Throwable $e) {
                return null;
            }
        }
        // CANDO: END CUSTOM CODE
        if ($value instanceof \Pimcore\Model\DataObject\Data\Link) {
            return $value->getPath();
        }
        return null;
    }
}
