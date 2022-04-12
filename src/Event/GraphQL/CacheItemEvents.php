<?php

declare(strict_types=1);

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

namespace Pimcore\Bundle\DataHubBundle\Event\GraphQL;

final class CacheItemEvents
{
    /**
     * Fired to determine if a response should be cached.
     *
     * @Event("Pimcore\Bundle\DataHubBundle\Event\GraphQL\Model\CachePreLoadEvent")
     *
     * @var string
     */
    const CACHE_ITEM = 'pimcore.datahub.graphql.cache.item';
}
