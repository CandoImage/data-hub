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

use GraphQL\Language\AST\DocumentNode;
use GraphQL\Server\OperationParams;
use Symfony\Contracts\EventDispatcher\Event;

class OutputCacheGenerateCidEvent extends Event
{
    /**
     * @var string
     */
    protected string $cid = '';

    /**
     * @var \GraphQL\Server\OperationParams
     */
    protected OperationParams $operation;

    /**
     * @var \GraphQL\Language\AST\DocumentNode
     */
    protected DocumentNode $parsedQuery;

    /**
     * @param string $cid
     * @param \GraphQL\Server\OperationParams $operation
     * @param \GraphQL\Language\AST\DocumentNode $parsedQuery
     */
    public function __construct($cid, OperationParams $operation, DocumentNode $parsedQuery)
    {
        $this->operation = $operation;
        $this->parsedQuery = $parsedQuery;
        $this->cid = $cid;
    }

    /**
     * @return string
     */
    public function getCid(): string
    {
        return $this->cid;
    }

    /**
     * @param string $cid
     */
    public function setCid(string $cid): void
    {
        $this->cid = $cid;
    }

    /**
     * @return \GraphQL\Server\OperationParams
     */
    public function getOperation(): OperationParams
    {
        return $this->operation;
    }

    /**
     * @return \GraphQL\Language\AST\DocumentNode
     */
    public function getParsedQuery(): DocumentNode
    {
        return $this->parsedQuery;
    }
}
