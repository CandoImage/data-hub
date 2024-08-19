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

namespace Pimcore\Bundle\DataHubBundle\Model;

use GraphQL\Language\AST\ArgumentNode;
use GraphQL\Language\AST\FieldNode;
use GraphQL\Type\Definition\ResolveInfo;

/**
 * Some common additions to the DefaultMockup from \Pimcore\Bundle\EcommerceFrameworkBundle\Model\DefaultMockup.
 *
 * @see \Pimcore\Bundle\EcommerceFrameworkBundle\Model\DefaultMockup
 *
 * @method getParam \Pimcore\Bundle\EcommerceFrameworkBundle\Model\DefaultMockup::getParam()
 */
trait ElementMockupTrait
{
    public function getElementType(): ?string
    {
        return $this->getParam('elementType') ?? 'object';
    }

    /**
     * @var array
     */
    protected array $graphQLContext = [];

    public function setGraphQLContext(
        ?string $getter,
        ?array $getterArgs = null,
        ?ResolveInfo $resolveInfo = null,
        ?FieldNode $ast = null
    ): void {
        $this->graphQLContext = [
            'getter' => $getter,
            'getterArgs' => $getterArgs,
            'resolveInfo' => $resolveInfo,
            'ast' => $ast,
        ];
    }

    protected function isGraphQLCallback($function): bool
    {
        return ($this->graphQLContext['getter'] ?? null) === $function;
    }

    /**
     * @return array
     */
    protected function getGraphQLArguments(): array
    {
        $result = [];
        if ($ast = $this->graphQLContext['ast'] ?? null) {
            if ($nodeList = $ast?->arguments) {
                $count = $nodeList->count();
                for ($i = 0; $i < $count; $i++) {
                    /** @var ArgumentNode $argumentNode */
                    $argumentNode = $nodeList[$i];
                    $value = $argumentNode->value->kind === 'ListValue' ? $argumentNode->value->values : $argumentNode->value->value;
                    $result[$argumentNode->name->value] = $value;
                }
            }
        }

        return $result;
    }
}
