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
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\EventDispatcher\Event;
use Throwable;

class ExecutorExceptionEvent extends Event
{
    use RequestAwareTrait;

    /**
     * @var Throwable
     */
    protected $exception;

    /**
     * @return Throwable
     */
    public function getException()
    {
        return $this->exception;
    }

    /**
     * @param Throwable $exception
     */
    public function setException(Throwable $exception)
    {
        $this->exception = $exception;
    }

    /**
     * @param Request $request
     * @param Throwable $exception
     */
    public function __construct(Request $request, Throwable $exception)
    {
        $this->request = $request;
        $this->exception = $exception;
    }
}
