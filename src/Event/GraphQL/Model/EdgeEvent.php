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
        static $arguments;
        if (is_null($arguments)) {
            $arguments = [];
            // we need to parse the arguments from the original request
            /** @var RequestStack $requestStack */
            $requestStack = Pimcore::getKernel()->getContainer()->get('request_stack');
            $request = $requestStack->getCurrentRequest();
            $input = json_decode($request->getContent(), true);
            $queryParameter = $input['query'] ?? null;
            if ($queryParameter) {
                $query = Parser::parse($queryParameter);
                foreach ($query->definitions as $node) {
                    foreach ($node->selectionSet->selections as $subNode) {
                        foreach ($subNode->arguments as $argument) {
                            $arguments[$subNode->name->value][$argument->name->value] = $argument->value->value;
                        }
                    }
                }
            }
        }
        if ($filterNode) {
            return $arguments[$filterNode] ?? [];
        }

        return $arguments;
    }
}
