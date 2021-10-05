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

namespace Pimcore\Bundle\DataHubBundle\Event\GraphQL\Model;

use Symfony\Contracts\EventDispatcher\Event;

class EdgeEvent extends Event
{
    /**
     * @var array
     */
    protected array $objects = [];

    /**
     * @var string|null
     */
    protected ?string $type;

    /**
     * EdgeEvent constructor.
     *
     * @param array $objects
     * @param string|null $type
     */
    public function __construct(array $objects, ?string $type = null)
    {
        $this->objects = $objects;
        $this->type = $type;
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

    /**
     * @return string|null
     */
    public function getType(): ?string
    {
        return $this->type;
    }

    /**
     * @param string|null $type
     */
    public function setType(?string $type): void
    {
        $this->type = $type;
    }
}
