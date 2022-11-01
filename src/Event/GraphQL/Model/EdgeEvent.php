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
use GraphQL\Language\Parser;
use GraphQL\Type\Definition\ResolveInfo;
use Pimcore;
use Symfony\Component\HttpFoundation\RequestStack;
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
     * FIX ME: currently there is no better way to get the original arguments of a GraphQL query from a
     * lower level node. If there is another way this should be refactored
     *
     * @param string|null $filterNode
     *
     * @return array|mixed
     *
     * @throws SyntaxError
     */
    public function getArguments(string $filterNode = null)
    {
        if (!isset($this->options['context']['arguments'])) {
            $arguments = [];
            // @TODO Document why we do this here-
            foreach ($this->getResolveInfo()->operation->selectionSet->selections as $subNode) {
                foreach ($subNode->arguments as $argument) {
                    $arguments[$subNode->name->value][$argument->name->value] = $argument->value->value;
                }
            }
            // @TODO Document why we collect global arguments too.
            foreach ($this->getResolveInfo()->fragments as $fragment) {
                foreach ($fragment->selectionSet->selections as $subNode) {
                    foreach ($subNode->arguments as $argument) {
                        $arguments[$subNode->name->value][$argument->name->value] = $argument->value->value;
                    }
                }
            }
            $this->options['context']['arguments'] = $arguments;
        }
        if ($filterNode) {
            return $this->options['context']['arguments'][$filterNode] ?? [];
        }

        return $this->options['context']['arguments'];
    }
}
