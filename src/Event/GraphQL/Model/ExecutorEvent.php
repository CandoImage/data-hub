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
use GraphQL\Language\Parser;
use GraphQL\Language\Source;
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
     * @var mixed
     */
    protected $request;

    /**
     * @var string
     */
    protected $query;

    /**
     * @var string
     */
    protected $queryHash;

    /**
     * @var \GraphQL\Language\AST\DocumentNode|null
     */
    protected ?DocumentNode $parsedQuery;

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
     * @return Schema
     */
    public function getSchema()
    {
        return $this->schema;
    }

    /**
     * @param Schema $schema
     */
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

    /**
     * @param array $context
     */
    public function setContext(array $context)
    {
        $this->context = $context;
    }

    /**
     * @return string
     */
    public function getQuery()
    {
        return $this->query;
    }

    /**
     * @param string $query
     */
    public function setQuery($query)
    {
        if ($this->queryHash != ($queryHash = md5($query))) {
            $this->query = $query;
            $this->queryHash = $queryHash;
            $this->parsedQuery = null;
        }
    }

    /**
     * @return \GraphQL\Language\AST\DocumentNode
     *
     * @throws \GraphQL\Error\SyntaxError
     */
    public function getParsedQuery(): DocumentNode
    {
        if (!$this->parsedQuery) {
            $this->parsedQuery = Parser::parse(new Source($this->getQuery() ?? '', 'GraphQL'));
        }

        return $this->parsedQuery;
    }

    /**
     * @param \GraphQL\Language\AST\DocumentNode|null $parsedQuery
     */
    public function setParsedQuery(?DocumentNode $parsedQuery): void
    {
        $this->parsedQuery = $parsedQuery;
    }

    /**
     * @param Request $request
     * @param string $query
     * @param Schema $schema
     * @param array $context
     */
    public function __construct(Request $request, $query, Schema $schema, $context, DocumentNode $parsedQuery = null)
    {
        $this->request = $request;
        $this->query = $query;
        $this->queryHash = md5($query);
        $this->schema = $schema;
        $this->context = $context;
        $this->parsedQuery = $parsedQuery;
    }
}
