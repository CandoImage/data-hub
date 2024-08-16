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

use Pimcore\Model\DataObject;
use Pimcore\Model\DataObject\ClassDefinition;
use Pimcore\Model\Element\ElementInterface;

/**
 * Extension to the DefaultMockup from \Pimcore\Bundle\EcommerceFrameworkBundle\Model\DefaultMockup.
 *
 * @see \Pimcore\Bundle\EcommerceFrameworkBundle\Model\DefaultMockup
 */
interface ElementMockupInterface
{
    /**
     * Returns the element type as \Pimcore\Model\Element\Service::getElementType() would
     *
     * @see \Pimcore\Model\Element\Service::getElementType()
     *
     * @return 'object'|'asset'|'document'|null
     */
    public function getElementType(): ?string;

    /**
     * Returns the element sub type as Pimcore\Model\Element\ElementInterface::getType() would.
     *
     * @see \Pimcore\Model\Element\ElementInterface::getType()
     *
     * @return string
     */
    public function getType(): ?string;

    public function getClass(): ?ClassDefinition;

    public function getLinkGenerator(): ?DataObject\ClassDefinition\LinkGeneratorInterface;

    /**
     * @return int
     */
    public function getId();

    /**
     * @return \Pimcore\Model\Element\ElementInterface|null
     */
    public function getOriginalObject(): ElementInterface|null;
}
