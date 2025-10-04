<?php

declare(strict_types=1);

namespace NetherByte\NetherMenus\hook;

use NetherByte\NetherMenus\NetherMenus;

final class PocketVaultHook
{
    public static function init(NetherMenus $plugin) : void
    {
        // PocketVault is optional; guard all calls
        if (!class_exists(\NetherByte\PocketVault\API\PocketVaultAPI::class)) {
            $plugin->getLogger()->debug("PocketVault not found. Economy/permissions integrations will be limited.");
            return;
        }
        try {
            $eco = \NetherByte\PocketVault\API\PocketVaultAPI::getEconomy();
            $perm = \NetherByte\PocketVault\API\PocketVaultAPI::getPermissions();
            if ($eco !== null) {
                $plugin->getLogger()->debug("PocketVault economy provider detected: " . get_class($eco));
            } else {
                $plugin->getLogger()->warning("No PocketVault economy provider available. Money-based requirements/actions will be disabled.");
            }
            if ($perm !== null) {
                $plugin->getLogger()->debug("PocketVault permissions provider detected: " . get_class($perm));
            } else {
                $plugin->getLogger()->warning("No PocketVault permissions provider available. give/take permission actions will be disabled.");
            }
        } catch (\Throwable $e) {
            $plugin->getLogger()->warning("PocketVault hook initialization failed: " . $e->getMessage());
        }
    }
}
