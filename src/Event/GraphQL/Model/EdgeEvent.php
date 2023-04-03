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

use GraphQL\Error\SyntaxError;
use GraphQL\Server\OperationParams;
use GraphQL\Type\Definition\ResolveInfo;
use Symfony\Contracts\EventDispatcher\Event;

class EdgeEvent extends Event
{
    /**
     * @var array
     */
    protected array $objects = [];

    /**
     * @var ResolveInfo|null
     */
    protected ?ResolveInfo $resolveInfo;

    /**
     * @var array
     */
    protected array $options = [];

    /**
     * EdgeEvent constructor.
     *
     * @param array $objects
     * @param ResolveInfo|null $resolveInfo
     * @param array $options
     */
    public function __construct(array $objects, ?ResolveInfo $resolveInfo, array $options = [])
    {
        $this->objects = $objects;
        $this->resolveInfo = $resolveInfo;
        $this->options = $options;
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
     * @return ResolveInfo|null
     */
    public function getResolveInfo(): ?ResolveInfo
    {
        return $this->resolveInfo;
    }

    /**
     * @param ResolveInfo|null $resolveInfo
     */
    public function setResolveInfo(?ResolveInfo $resolveInfo): void
    {
        $this->resolveInfo = $resolveInfo;
    }

    /**
     * @return array
     */
    public function getOptions(): array
    {
        return $this->options;
    }

    /**
     * @param array $options
     */
    public function setOptions(array $options): void
    {
        $this->options = $options;
    }

    /**
     * @return array
     */
    public function getArguments(): array
    {
        $operation = $this->options['context']['operation'] ?? null;
        if ($operation instanceof OperationParams && $operation->operation) {
            return $operation->variables;
        }
        return [];
    }
}
