<?php

declare(strict_types=1);

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

namespace Pimcore\Bundle\DataHubBundle\GraphQL\Traits;

use Pimcore\Bundle\EcommerceFrameworkBundle\Model\DefaultMockup;
use Pimcore\Model\Element\ElementInterface;
use Pimcore\Model\Element\Service as ElementService;

trait ElementLoaderTrait
{
    /**
     * Stores the "metadata" of a related element into the data storage which
     * then is passed around.
     *
     * It can be used with loadDataElement() which if possible will use the
     * already loaded element but will fallback to load the element using
     * the id.
     *
     * @return array
     */
    protected function setDataElement($data, ElementInterface | DefaultMockup $element)
    {
        $data['id'] = $element->getId();
        $data[ElementInterface::class . '_type'] = $element->getType();
        $data[ElementInterface::class . '_instance'] = $element;

        return $data;
    }

    /**
     *
     * @return ElementInterface
     */
    protected function loadDataElement(&$data, $type)
    {
        return self::staticLoadDataElement($data, $type);
    }

    /**
     *
     * @return ElementInterface
     */
    protected static function staticLoadDataElement(&$data, $type)
    {
        if (!isset($data[ElementInterface::class . '_instance'])) {
            $data[ElementInterface::class . '_type'] = $type;
            $data[ElementInterface::class . '_instance'] = ElementService::getElementById($type, $data['id']);
        }

        return $data[ElementInterface::class . '_instance'];
    }
}
