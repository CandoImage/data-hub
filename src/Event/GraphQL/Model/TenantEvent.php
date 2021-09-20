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

use Pimcore\Bundle\EcommerceFrameworkBundle\EnvironmentInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\EventDispatcher\Event;

class TenantEvent extends Event
{
    /**
     * @var EnvironmentInterface
     */
    protected $environment;

    /**
     * @var ?UserInterface
     */
    protected $user = null;

    /**
     * @var array
     */
    protected $userTenants;

    /**
     * TenantEvent constructor.
     *
     * @param EnvironmentInterface $environment
     * @param null $user
     */
    public function __construct(EnvironmentInterface $environment, $user = null)
    {
        $this->environment = $environment;
        $this->user = $user;
    }

    /**
     * @return EnvironmentInterface
     */
    public function getEnvironment(): EnvironmentInterface
    {
        return $this->environment;
    }

    /**
     * @param EnvironmentInterface $environment
     */
    public function setEnvironment(EnvironmentInterface $environment): void
    {
        $this->environment = $environment;
    }

    /**
     * @return UserInterface|null
     */
    public function getUser(): ?UserInterface
    {
        return $this->user;
    }

    /**
     * @param UserInterface|null $user
     */
    public function setUser(?UserInterface $user): void
    {
        $this->user = $user;
    }

    /**
     * @return array
     */
    public function getUserTenants(): array
    {
        return $this->userTenants;
    }

    /**
     * @param array $userTenants
     */
    public function setUserTenants(array $userTenants): void
    {
        $this->userTenants = $userTenants;
    }
}
