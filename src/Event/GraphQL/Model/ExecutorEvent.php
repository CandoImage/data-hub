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
use GraphQL\Language\AST\DocumentNode;
use GraphQL\Language\Parser;
use GraphQL\Language\Source;
use GraphQL\Server\OperationParams;
use GraphQL\Type\Schema;
use Pimcore\Event\Traits\RequestAwareTrait;
use Pimcore\Event\Traits\ResponseAwareTrait;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\EventDispatcher\Event;

class ExecutorEvent extends Event
{
    use RequestAwareTrait;
    use ResponseAwareTrait;

    /**
     * @var OperationParams
     */
    protected $operation;

    /**
     * @var string
     */
    protected $queryHash;

    /**
     * @var DocumentNode|null
     */
    protected ?DocumentNode $parsedQuery;

    /**
     * @var string
     */
    protected $query;

    /**
     * @var Schema
     */
    protected $schema;

    /**
     * @var array
     */
    protected $context;

    /**
     * @return mixed
     */
    public function getRequest()
    {
        return $this->request;
    }

    /**
     * @param mixed $request
     * @param bool $asString
     */
    public function setRequest($request, $asString = true)
    {
        $this->request = $asString ? (string)$request : $request;
    }

    /**
     * @return OperationParams
     */
    public function getOperation(): OperationParams
    {
        return $this->operation;
    }

    /**
     * @return Schema
     */
    public function getSchema()
    {
        return $this->schema;
    }

    public function setSchema(Schema $schema)
    {
        $this->schema = $schema;
    }

    /**
     * @return array
     */
    public function getContext()
    {
        return $this->context;
    }

    public function setContext(array $context)
    {
        $this->context = $context;
    }

    /**
     * @return string
     */
    public function getQuery()
    {
        return $this->operation->query;
    }

    /**
     * @param string $query
     */
    public function setQuery($query)
    {
        if ($this->queryHash != ($queryHash = md5($query))) {
            $this->operation->query = $query;
            $this->queryHash = $queryHash;
            $this->parsedQuery = null;
        }
    }

    /**
     * @return DocumentNode
     *
     * @throws SyntaxError
     */
    public function getParsedQuery(): DocumentNode
    {
        if (!$this->parsedQuery) {
            $this->parsedQuery = Parser::parse(new Source($this->getQuery() ?? '', 'GraphQL'));
        }

        return $this->parsedQuery;
    }

    /**
     * @param DocumentNode|null $parsedQuery
     */
    public function setParsedQuery(?DocumentNode $parsedQuery): void
    {
        $this->parsedQuery = $parsedQuery;
    }

    /**
     * @param Request $request
     * @param OperationParams $operation
     * @param Schema $schema
     * @param array $context
     * @param DocumentNode|null $parsedQuery
     */
    public function __construct(
        Request $request,
        OperationParams $operation,
        Schema $schema,
        $context,
        DocumentNode $parsedQuery = null
    ) {
        $this->request = $request;
        $this->operation = $operation;
        $this->schema = $schema;
        $this->context = $context;
        $this->queryHash = md5($this->operation->query);
        $this->parsedQuery = $parsedQuery;
    }
}
