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

namespace Pimcore\Bundle\DataHubBundle\FilterService;

use Pimcore\Bundle\EcommerceFrameworkBundle\FilterService\FilterService;

/**
 * Class HijackAbstractFilterService
 *
 * Allows to access protected property of FilterService instances in order
 * to use these data in the Filter Query Type.
 *
 * @package Pimcore\Bundle\DataHubBundle\FilterService
 */
class HijackAbstractFilterService extends FilterService
{
    /**
     * Returns the configured filter types as array
     *
     *
     * @param FilterService $instance
     *
     * @return array
     */
    public static function getFilterTypes(FilterService $instance)
    {
        $types = $instance->filterTypes;
        if (!empty($types)) {
            $filterTypes = [];
            foreach ($types as $key => $filterType) {
                $filterTypes[] = $key;
            }

            return $filterTypes;
        }

        return [];
    }
}
