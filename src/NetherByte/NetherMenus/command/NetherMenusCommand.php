<?php

namespace NetherByte\NetherMenus\command;

use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\player\Player;
use pocketmine\plugin\Plugin;
use pocketmine\plugin\PluginOwned;
use NetherByte\NetherMenus\NetherMenus;
use NetherByte\NetherMenus\ui\gui\ExampleGUI;

class NetherMenusCommand extends Command implements PluginOwned {

    public function __construct(){
        parent::__construct("gui");
        $this->setPermission("nethermenus.command");
        $this->setDescription("Open a custom GUI by name");
        $this->setUsage("/gui [name]");
        $this->setAliases([]);
    }

    public function execute(CommandSender $sender, string $commandLabel, array $args) : void {
        if (!$sender instanceof Player) {
            $sender->sendMessage("This command can only be used in-game");
            return;
        }

        $plugin = NetherMenus::getInstance();
        
        // Check if we have an argument
        if (isset($args[0]) && $args[0] !== "") {
            $input = strtolower(trim($args[0]));
            
            // If this is the base 'gui' command, check if the GUI allows it
            if ($commandLabel === 'gui') {
                $guiData = $plugin->getCachedGui($input);
                if ($guiData === null) {
                    $sender->sendMessage("§cGUI with ID '$input' not found!");
                    $this->showAvailableGuis($sender);
                    return;
                }
                
                // Check if 'gui <id>' is in the allowed commands
                $allowed = false;
                if (isset($guiData['open_command']) && is_array($guiData['open_command'])) {
                    foreach ($guiData['open_command'] as $cmd) {
                        if (strtolower(trim($cmd)) === 'gui ' . $input) {
                            $allowed = true;
                            break;
                        }
                    }
                }
                
                if (!$allowed) {
                    $sender->sendMessage("§cInvalid command.");
                    return;
                }
            }
            
            // First try to find by GUI ID if using the base 'gui' command
            $gui = null;
            if ($commandLabel === 'gui') {
                $gui = $plugin->createGUIFromData($input);
            }
            
            if ($gui === null) {
                // If not found by ID, try to find by custom command
                $guiId = $plugin->getGuiIdByCommand($commandLabel === 'gui' ? $input : $commandLabel);
                if ($guiId !== null) {
                    $gui = $plugin->createGUIFromData($guiId);
                }
            }
            
            if ($gui !== null) {
                // Enforce open requirement first
                $eval = $gui->canPlayerOpen($sender);
                if ($eval['pass']) {
                    \NetherByte\NetherMenus\requirement\RequirementEvaluator::runSuccessActions($sender, $eval);
                    if ($sender->getCurrentWindow() !== null) {
                        $sender->removeCurrentWindow();
                    }
                    // Track current menu for placeholders
                    $plugin->setCurrentMenu($sender, $commandLabel === 'gui' ? $input : ($guiId ?? $input));
                    $sender->setCurrentWindow($gui);
                } else {
                    \NetherByte\NetherMenus\requirement\RequirementEvaluator::runDenyActions($sender, $eval);
                }
            } else {
                $sender->sendMessage("§cGUI with ID or command '$input' not found!");
                $this->showAvailableGuis($sender);
            }
            return;
        }
        
        // No ID given, show usage and available GUIs
        $sender->sendMessage("§eUsage: §7/gui <id>");
        $sender->sendMessage("§7Or use the custom commands defined for each GUI.");
        $this->showAvailableGuis($sender);
    }
    
    /**
     * Shows available GUIs with their names and IDs to the sender
     */
    private function showAvailableGuis(CommandSender $sender): void {
        $plugin = NetherMenus::getInstance();
        $guis = $plugin->getAvailableGuis();
        
        if (empty($guis)) {
            $sender->sendMessage("§eNo GUIs are currently available.");
            return;
        }
        
        $sender->sendMessage("§eAvailable GUIs:");
        foreach ($guis as $guiId => $guiName) {
            $sender->sendMessage("§7- §e$guiId §7($guiName)");
        }
        $sender->sendMessage("§7Use §e/gui <id> §7to open a GUI");
    }

    public function getOwningPlugin() : Plugin{
        return NetherMenus::getInstance();
    }
} 