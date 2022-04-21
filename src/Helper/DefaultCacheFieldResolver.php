<?php

namespace Pimcore\Bundle\DataHubBundle\Helper;

use GraphQL\Language\AST\FieldNode;
use GraphQL\Type\Definition\ResolveInfo;
use Closure;
use ArrayAccess;

class DefaultCacheFieldResolver
{
    /**
     * Overrides the default field resolver from webonyx library
     * As we use caching on object level we could have simple fields with alias which then are resolved
     * with the default field resolver
     *
     * @param $objectValue
     * @param $args
     * @param $contextValue
     * @param ResolveInfo $info
     * @return mixed|null
     */
    public static function defaultFieldResolver($objectValue, $args, $contextValue, ResolveInfo $info)
    {
        $fieldName = $info->fieldName;
        $property = null;
        $aliasName = null;

        // check for alias
        $alias = null;
        $fieldAstList = $info->fieldNodes ?? [];
        foreach ($fieldAstList as $astNode) {
            if ($astNode instanceof FieldNode) {
                $alias = $astNode->alias;
            }
        }
        if ($alias) {
            $aliasName = $alias->value;
        }

        if (is_array($objectValue) || $objectValue instanceof ArrayAccess) {
            if ($aliasName && isset($objectValue[$aliasName])) {
                $property = $objectValue[$aliasName];
            }
            if (isset($objectValue[$fieldName])) {
                $property = $objectValue[$fieldName];
            }
        } elseif (is_object($objectValue)) {
            if (isset($objectValue->{$fieldName})) {
                $property = $objectValue->{$fieldName};
            }
        }

        return $property instanceof Closure
            ? $property($objectValue, $args, $contextValue, $info)
            : $property;
    }
}