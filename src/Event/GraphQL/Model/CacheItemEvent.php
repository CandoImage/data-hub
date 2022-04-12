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

use GraphQL\Executor\ExecutionResult;
use Pimcore\Event\Traits\RequestAwareTrait;
use Symfony\Component\HttpFoundation\Request;
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
     * @var bool
     */
    protected $useCache;

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
     * @param Request $request
     * @param bool $useCache
     */
    public function __construct(Request $request, ExecutionResult $result, bool $useCache)
    {
        $this->request = $request;
        $this->result = $result;
        $this->useCache = $useCache;
    }
}
