<?php

namespace Pimcore\Bundle\DataHubBundle\Helper;

class CacheHelper
{
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