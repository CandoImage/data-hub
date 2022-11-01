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

namespace Pimcore\Bundle\DataHubBundle\Helper;

use GraphQL\Language\AST\DocumentNode;
use GraphQL\Language\Parser;
use GraphQL\Language\Source;

class CacheHelper
{
    private static string $query = '';

    /**
     * @param string|DocumentNode $query
     *
     * @return void
     *
     * @throws \GraphQL\Error\SyntaxError
     */
    public static function setQuery($query): void
    {
        if (!($query instanceof DocumentNode)) {
            $query = Parser::parse(new Source($query ?? '', 'GraphQL'), ['noLocation' => true]);
        }
        self::$query = md5((string)$query);
    }

    public static function getHashedQuery(): string
    {
        return self::$query;
    }

    public static function generateCacheId(array $keyItems = []): string
    {
        $allKeys = '';
        foreach ($keyItems as $key => $keyItem) {
            if ($key === array_key_last($keyItems)) {
                $allKeys .= $keyItem;
            } else {
                $allKeys .= $keyItem . '-';
            }
        }

        return md5($allKeys);
    }
}
