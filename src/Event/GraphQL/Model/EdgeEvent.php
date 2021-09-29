<?php

namespace Pimcore\Bundle\DataHubBundle\Event\GraphQL\Model;

use Symfony\Contracts\EventDispatcher\Event;

class EdgeEvent extends Event
{
    /**
     * @var array
     */
    protected array $objects = [];

    /**
     * EdgeEvent constructor.
     *
     * @param array $objects
     */
    public function __construct(array $objects)
    {
        $this->objects = $objects;
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
