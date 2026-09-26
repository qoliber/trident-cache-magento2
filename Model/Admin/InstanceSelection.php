<?php

/**
 * Created by qoliber
 *
 * @category    Qoliber
 * @package     Qoliber_TridentCache
 * @author      Jakub Winkler <jwinkler@qoliber.com>
 */

declare(strict_types=1);

namespace Qoliber\TridentCache\Model\Admin;

use Magento\Backend\Model\Session;
use Magento\Framework\App\Area;
use Magento\Framework\App\State;

/**
 * X03: the Trident instance an administrator is looking at on the Trident
 * screens — the one every single-instance read and action there goes to
 * (statistics, entries, the warmer, launch, reflect, bans, backends …).
 * Invalidations still go to every instance.
 *
 * Kept in the admin session. Read only in the adminhtml area: the storefront,
 * cron and CLI always use the first instance, and never start an admin session
 * to find that out ({@see \Qoliber\TridentCache\Model\TridentClient} gets this
 * through a proxy).
 */
class InstanceSelection
{
    private const KEY = 'qoliber_trident_instance';

    /**
     * @param State $state
     * @param Session $session
     */
    public function __construct(
        private readonly State $state,
        private readonly Session $session
    ) {
    }

    /**
     * The chosen instance's name, or null for the default (the first).
     *
     * @return string|null
     */
    public function selected(): ?string
    {
        if (!$this->inAdmin()) {
            return null;
        }
        $name = $this->session->getData(self::KEY);
        return is_string($name) && $name !== '' ? $name : null;
    }

    /**
     * @param string|null $name Null: back to the default.
     * @return void
     */
    public function select(?string $name): void
    {
        $this->session->setData(self::KEY, $name);
    }

    /**
     * @return bool
     */
    private function inAdmin(): bool
    {
        try {
            return $this->state->getAreaCode() === Area::AREA_ADMINHTML;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
