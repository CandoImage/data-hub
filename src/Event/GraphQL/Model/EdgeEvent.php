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

namespace Pimcore\Bundle\DataHubBundle\Event\GraphQL\Model;

use Symfony\Contracts\EventDispatcher\Event;

class EdgeEvent extends Event
{
    /**
     * @var array
     */
    protected array $requestVariables = [];

    /**
     * @var array
     */
    protected array $objects = [];

    /**
     * EdgeEvent constructor.
     *
     * @param array $requestVariables
     * @param array $objects
     */
    public function __construct(array $requestVariables, array $objects)
    {
        $this->requestVariables = $requestVariables;
        $this->objects = $objects;
    }

    /**
     * @return array
     */
    public function getRequestVariables(): array
    {
        return $this->requestVariables;
    }

    /**
     * @param array $requestVariables
     */
    public function setRequestVariables(array $requestVariables): void
    {
        $this->requestVariables = $requestVariables;
    }

    /**
     * @return array
     */
    public function getObjects(): array
    {
        return $this->objects;
    }

    /**
     * @param array $objects
     */
    public function setObjects(array $objects): void
    {
        $this->objects = $objects;
    }
}
