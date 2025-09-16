<?php

namespace NetherByte\NetherMenus\command;

use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\player\Player;
use pocketmine\plugin\Plugin;
use pocketmine\plugin\PluginOwned;
use NetherByte\NetherMenus\NetherMenus;
use NetherByte\NetherMenus\ui\forms\MainForm;

class GuiAdminCommand extends Command implements PluginOwned {

    public function __construct() {
        parent::__construct("guiadmin", "GUI Admin Menu", "/guiadmin", ["gadmin"]);
        $this->setPermission("nethermenus.admin");
        $this->setDescription("Open the GUI admin menu");
        $this->setUsage("/guiadmin");
    }

    public function execute(CommandSender $sender, string $commandLabel, array $args): void {
        if (!$sender instanceof Player) {
            $sender->sendMessage("This command can only be used in-game");
            return;
        }

        if (!$sender->hasPermission("nethermenus.admin")) {
            $sender->sendMessage("§cYou don't have permission to use this command!");
            return;
        }

        // Block when InventoryUI resource pack is not available / force_resources=false
        $plugin = NetherMenus::getInstance();
        if (!$plugin->isResourcePackReady()) {
            $sender->sendMessage("§cNetherMenus requires the Inventory UI Resource Pack and force_resources=true. Please download/accept the resource pack; admin menu is disabled until then.");
            return;
        }

        (new MainForm($sender))->sendForm();
    }

    public function getOwningPlugin(): Plugin {
        return NetherMenus::getInstance();
    }
} 