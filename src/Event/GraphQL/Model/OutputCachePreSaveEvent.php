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

use Pimcore\Event\Traits\RequestAwareTrait;
use Pimcore\Event\Traits\ResponseAwareTrait;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\EventDispatcher\Event;

class OutputCachePreSaveEvent extends Event
{
    use RequestAwareTrait;
    use ResponseAwareTrait;

    protected array $tags = [];

    /**
     * @var false
     */
    private bool $skipSave;

    /**
     * @return Request
     */
    public function getRequest()
    {
        return $this->request;
    }

    /**
     * @return Response
     */
    public function getResponse()
    {
        return $this->response;
    }

    public function setResponse(Response $response)
    {
        $this->response = $response;
    }

    /**
     * @return array
     */
    public function getTags(): array
    {
        return array_unique($this->tags);
    }

    /**
     * @param array $tags
     */
    public function setTags(array $tags): void
    {
        $this->tags = $tags;
    }

    /**
     * @param array $tags
     */
    public function addTags(array $tags): void
    {
        $this->tags = array_unique(array_merge($this->tags, $tags));
    }

    /**
     * @return bool
     */
    public function isSkipSave(): bool
    {
        return $this->skipSave;
    }

    /**
     * @param bool $skipSave
     */
    public function setSkipSave(bool $skipSave): void
    {
        $this->skipSave = $skipSave;
    }

    /**
     * @param Request $request
     * @param Response $response
     * @param array $tags
     * @param bool $skipSave
     */
    public function __construct(Request $request, Response $response, array $tags, bool $skipSave = false)
    {
        $this->request = $request;
        $this->response = $response;
        $this->tags = $tags;
        $this->skipSave = $skipSave;
    }
}
