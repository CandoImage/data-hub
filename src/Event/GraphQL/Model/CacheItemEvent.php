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

use GraphQL\Executor\ExecutionResult;
use GraphQL\Server\OperationParams;
use Pimcore\Event\Traits\RequestAwareTrait;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\EventDispatcher\Event;

class CacheItemEvent extends Event
{
    use RequestAwareTrait;

    /**
     * @var Request
     */
    protected $request;

    /**
     * @var ExecutionResult
     */
    protected $result;

    /**
     * @var OperationParams
     */
    protected $operation;

    /**
     * @var bool
     */
    protected $useCache;

    private array $cacheTags;

    /**
     * @var \Symfony\Component\HttpFoundation\Response
     */
    private Response $response;

    /**
     * @return Request
     */
    public function getRequest()
    {
        return $this->request;
    }

    /**
     * @return ExecutionResult
     */
    public function getResult()
    {
        return $this->result;
    }

    /**
     * @param ExecutionResult $result
     * @return void
     */
    public function setResult(ExecutionResult $result)
    {
        $this->result = $result;
    }

    /**
     * @return bool
     */
    public function isUseCache()
    {
        return $this->useCache;
    }

    /**
     * @param bool $useCache
     */
    public function setUseCache(bool $useCache)
    {
        $this->useCache = $useCache;
    }

    /**
     * @return \GraphQL\Server\OperationParams
     */
    public function getOperation(): OperationParams
    {
        return $this->operation;
    }

    /**
     * @param \GraphQL\Server\OperationParams $operation
     */
    public function setOperation(OperationParams $operation): void
    {
        $this->operation = $operation;
    }

    /**
     * @return array
     */
    public function getCacheTags(): array
    {
        return array_unique($this->cacheTags);
    }

    /**
     * @param array $cacheTags
     */
    public function setCacheTags(array $cacheTags): void
    {
        $this->cacheTags = $cacheTags;
    }

    /**
     * @param array $cacheTags
     */
    public function addCacheTags(array $cacheTags): void
    {
        $this->cacheTags = array_unique(array_merge($this->cacheTags, $cacheTags));
    }

    /**
     * @param string $cacheTag
     */
    public function addCacheTag(string $cacheTag): void
    {
        $this->cacheTags[$cacheTag] = $cacheTag;
    }

    /**
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function getResponse(): Response
    {
        return $this->response;
    }

    /**
     * @param Response $response
     * @return void
     */
    public function setResponse(\Symfony\Component\HttpFoundation\Response $response)
    {
        $this->response = $response;
    }

    /**
     * @param Request $request
     * @param \GraphQL\Executor\ExecutionResult $result
     * @param bool $useCache
     * @param \GraphQL\Server\OperationParams $operation
     */
    public function __construct(
        Request $request,
        ExecutionResult $result,
        OperationParams $operation,
        Response $response,
        bool $useCache = true,
        array $cacheTags = []
    ) {
        $this->request = $request;
        $this->result = $result;
        $this->useCache = $useCache;
        $this->operation = $operation;
        $this->cacheTags = $cacheTags;
        $this->response = $response;
    }
}
