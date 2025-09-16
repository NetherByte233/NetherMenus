<?php

namespace NetherByte\NetherMenus\hook;

use NetherByte\NetherMenus\NetherMenus;
use NetherByte\NetherMenus\ui\gui\CustomGUI;
use pocketmine\event\Listener;
use pocketmine\event\inventory\InventoryCloseEvent;

class InventoryCloseListener implements Listener {
    /** @priority MONITOR */
    public function onInventoryClose(InventoryCloseEvent $event) : void {
        $inv = $event->getInventory();
        if ($inv instanceof CustomGUI) {
            // Run close actions and cancel any running update tasks
            $inv->runCloseActions($event->getPlayer());
            // Clear tracking for placeholders
            NetherMenus::getInstance()->clearCurrentMenu($event->getPlayer());
        }
    }
}
