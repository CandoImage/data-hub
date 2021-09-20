<?php

/**
 * Cando specific Event
 */

namespace Pimcore\Bundle\DataHubBundle\Event\GraphQL;

final class TenantEvents
{
    /**
     * @Event("Pimcore\Bundle\DataHubBundle\Event\GraphQL\Model\TenantEvent")
     *
     * @var string
     */
    const LOAD_TENANTS = 'pimcore.datahub.graphql.tenants.load';
}
