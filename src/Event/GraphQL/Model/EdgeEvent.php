<?php

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
