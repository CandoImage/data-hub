<?php

declare(strict_types=1);

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
