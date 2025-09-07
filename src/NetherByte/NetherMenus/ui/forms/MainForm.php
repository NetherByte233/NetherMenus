<?php

namespace NetherByte\NetherMenus\ui\forms;

use NetherByte\NetherMenus\libs\dktapps\pmforms\CustomForm;
use NetherByte\NetherMenus\libs\dktapps\pmforms\CustomFormResponse;
use NetherByte\NetherMenus\libs\dktapps\pmforms\element\Input;
use NetherByte\NetherMenus\libs\dktapps\pmforms\element\Dropdown;
use NetherByte\NetherMenus\libs\dktapps\pmforms\element\Label;
use NetherByte\NetherMenus\libs\dktapps\pmforms\MenuForm;
use NetherByte\NetherMenus\libs\dktapps\pmforms\MenuOption;
use NetherByte\NetherMenus\libs\dktapps\pmforms\ModalForm;
use NetherByte\NetherMenus\NetherMenus;
use NetherByte\NetherMenus\ui\gui\BlankGridGUI;
use pocketmine\player\Player;
use pocketmine\utils\Config;

class MainForm {

    public function __construct(protected Player $player) {}

    function sendForm(): void {
        $options = [
            new MenuOption("Create GUI"),
            new MenuOption("GUIs"),
            new MenuOption("§aReload GUIs")
        ];
        $title = "Custom GUI Menu";

        $form = new MenuForm(
            $title, "", $options,
            function(Player $submitter, int $selected): void {
                switch($selected) {
                    case 0:
                        $this->createForm($submitter);
                        break;
                    case 1:
                        $this->listGUIs($submitter);
                        break;
                    case 2:
                        $plugin = NetherMenus::getInstance();
                        $plugin->reloadGuis();
                        $submitter->sendMessage("§aAll GUIs have been reloaded from disk!");
                        $submitter->removeCurrentWindow();
                        break;
                }
            }
        );
        $this->player->sendForm($form);
    }

    private function createForm(Player $player): void {
        $form = new CustomForm(
            "Create Custom GUI",
            [
                new Input("gui_name", "Enter GUI Display Name", "My GUI"),
                new Input("gui_id", "Enter Unique GUI ID (a-z, 0-9, _ only)", "my_gui"),
                new Dropdown(
                    "rows",
                    "Select number of rows (1-6)",
                    ["1 row (9 slots)", "2 rows (18 slots)", "3 rows (27 slots)", "4 rows (36 slots)", "5 rows (45 slots)", "6 rows (54 slots)"],
                    2
                )
            ],
            function (Player $player, CustomFormResponse $response): void {
                $guiName = trim($response->getString("gui_name"));
                $guiId = strtolower(trim($response->getString("gui_id")));
                $rows = $response->getInt("rows") + 1; // Dropdown index is 0-based
                
                // Validate inputs
                if (empty($guiName)) {
                    $player->sendMessage("§cGUI display name cannot be empty!");
                    return;
                }
                
                if (empty($guiId)) {
                    $player->sendMessage("§cGUI ID cannot be empty!");
                    return;
                }
                
                if (!preg_match('/^[a-z0-9_]+$/', $guiId)) {
                    $player->sendMessage("§cGUI ID can only contain lowercase letters, numbers, and underscores!");
                    return;
                }
                
                // Check if GUI ID already exists
                $plugin = NetherMenus::getInstance();
                $dataFolder = $plugin->getGuiDataFolder();
                $file = $dataFolder . $guiId . ".yml";
                
                if (file_exists($file)) {
                    $player->sendMessage("§cA GUI with ID '$guiId' already exists!");
                    return;
                }
                
                // Create the GUI data file with basic info (YAML structure)
                $cfg = new Config($file, Config::YAML);
                $cfg->setAll([
                    'id' => $guiId,
                    'name' => $guiName,
                    'rows' => $rows,
                    'open_command' => "gui $guiId",
                    'command_description' => "Open the {$guiName} menu",
                    'filler_item' => [
                        'material' => 'light_gray_stained_glass_pane',
                        'slots' => "0-" . (($rows * 9) - 1)
                    ],
                    'items' => []
                ]);
                $saved = $cfg->save();
                if ($saved === false) {
                    $player->sendMessage("§cFailed to create GUI file! Check server logs for details.");
                    $plugin->getLogger()->error("Failed to create GUI file: " . $file);
                    return;
                }
                
                // Clear cache and open the new GUI
                $plugin->reloadGuis();
                $player->sendMessage("§aSuccessfully created GUI with ID: §e$guiId");
                $player->setCurrentWindow(new BlankGridGUI($player, $guiId, null, null, $rows));
            },
            function(Player $player): void {
                // On close, go back to main menu
                $this->sendForm();
            }
        );
        $player->sendForm($form);
    }

    private function promptForMessage(Player $player, string $guiName, int $rows, ?int $slot = null, ?array $existingAction = null, ?int $editIndex = null): void {
        $form = new CustomForm(
            "Send Message - $guiName",
            [
                new Input("text", "Message to send to player", $existingAction['text'] ?? "Hello there!")
            ],
            function(Player $p, CustomFormResponse $response) use ($guiName, $rows, $slot, $editIndex): void {
                $text = trim($response->getString("text"));
                if ($text === '') { $p->sendMessage("§cMessage cannot be empty!"); return; }
                $line = "[Message] " . $text;
                if ($slot !== null && $editIndex !== null) {
                    // Inline replace for edit
                    $plugin = NetherMenus::getInstance();
                    $file = $plugin->getGuiDataFolder() . $guiName . ".yml";
                    $cfg = new Config($file, Config::YAML);
                    $data = $cfg->getAll();
                    $actions = $data['items'][$slot]['action'] ?? [];
                    if (is_string($actions)) { $actions = [$actions]; }
                    if (!is_array($actions) || empty($actions)) {
                        $actions = [];
                    }
                    $actions[$editIndex] = $line;
                    $data['items'][$slot]['action'] = array_values($actions);
                    $cfg->setAll($data);
                    $cfg->save();
                    $p->sendMessage("§aAction updated.");
                    $this->manageActionsForSlot($p, $guiName, $rows, $slot);
                } else {
                    $this->confirmMergeModeAndOpenGrid($p, $guiName, $rows, $line);
                }
            },
            function(Player $p) use ($guiName, $rows, $slot): void {
                if ($slot !== null) { $this->showActionForm($p, $guiName, $rows, $slot); } else { $this->showActionTypeMenu($p, $guiName, $rows); }
            }
        );
        $player->sendForm($form);
    }

    private function promptForBroadcast(Player $player, string $guiName, int $rows, ?int $slot = null, ?array $existingAction = null, ?int $editIndex = null): void {
        $form = new CustomForm(
            "Broadcast - $guiName",
            [
                new Input("text", "Broadcast text", $existingAction['text'] ?? "Server announcement!")
            ],
            function(Player $p, CustomFormResponse $response) use ($guiName, $rows, $slot, $editIndex): void {
                $text = trim($response->getString("text"));
                if ($text === '') { $p->sendMessage("§cBroadcast cannot be empty!"); return; }
                $line = "[Broadcast] " . $text;
                if ($slot !== null && $editIndex !== null) {
                    $plugin = NetherMenus::getInstance();
                    $file = $plugin->getGuiDataFolder() . $guiName . ".yml";
                    $cfg = new Config($file, Config::YAML);
                    $data = $cfg->getAll();
                    $actions = $data['items'][$slot]['action'] ?? [];
                    if (is_string($actions)) { $actions = [$actions]; }
                    if (!is_array($actions)) { $actions = []; }
                    $actions[$editIndex] = $line;
                    $data['items'][$slot]['action'] = array_values($actions);
                    $cfg->setAll($data);
                    $cfg->save();
                    $p->sendMessage("§aAction updated.");
                    $this->manageActionsForSlot($p, $guiName, $rows, $slot);
                } else {
                    $this->confirmMergeModeAndOpenGrid($p, $guiName, $rows, $line);
                }
            },
            function(Player $p) use ($guiName, $rows, $slot): void {
                if ($slot !== null) { $this->showActionForm($p, $guiName, $rows, $slot); } else { $this->showActionTypeMenu($p, $guiName, $rows); }
            }
        );
        $player->sendForm($form);
    }

    private function promptForChat(Player $player, string $guiName, int $rows, ?int $slot = null, ?array $existingAction = null, ?int $editIndex = null): void {
        $form = new CustomForm(
            "Chat - $guiName",
            [
                new Input("text", "Message player should send", $existingAction['text'] ?? "Hi! Anyone there?")
            ],
            function(Player $p, CustomFormResponse $response) use ($guiName, $rows, $slot, $editIndex): void {
                $text = trim($response->getString("text"));
                if ($text === '') { $p->sendMessage("§cChat message cannot be empty!"); return; }
                $line = "[Chat] " . $text;
                if ($slot !== null && $editIndex !== null) {
                    $plugin = NetherMenus::getInstance();
                    $file = $plugin->getGuiDataFolder() . $guiName . ".yml";
                    $cfg = new Config($file, Config::YAML);
                    $data = $cfg->getAll();
                    $actions = $data['items'][$slot]['action'] ?? [];
                    if (is_string($actions)) { $actions = [$actions]; }
                    if (!is_array($actions)) { $actions = []; }
                    $actions[$editIndex] = $line;
                    $data['items'][$slot]['action'] = array_values($actions);
                    $cfg->setAll($data);
                    $cfg->save();
                    $p->sendMessage("§aAction updated.");
                    $this->manageActionsForSlot($p, $guiName, $rows, $slot);
                } else {
                    $this->confirmMergeModeAndOpenGrid($p, $guiName, $rows, $line);
                }
            },
            function(Player $p) use ($guiName, $rows, $slot): void {
                if ($slot !== null) { $this->showActionForm($p, $guiName, $rows, $slot); } else { $this->showActionTypeMenu($p, $guiName, $rows); }
            }
        );
        $player->sendForm($form);
    }

    // Deprecated: merge/replace decision is now deferred to slot click inside BlankGridGUI
    private function confirmMergeModeAndOpenGrid(Player $player, string $guiName, int $rows, string $line): void {
        $player->sendMessage("§aNow click on the item to assign the action!");
        $player->setCurrentWindow(new BlankGridGUI(
            $player,
            $guiName,
            ['line' => $line],
            null,
            $rows
        ));
    }

    private function manageActionsForSlot(Player $player, string $guiName, int $rows, int $slot): void {
        $plugin = NetherMenus::getInstance();
        $file = $plugin->getGuiDataFolder() . $guiName . ".yml";
        $cfg = new Config($file, Config::YAML);
        $data = $cfg->getAll();
        $actions = $data['items'][$slot]['action'] ?? [];
        if (is_string($actions)) { $actions = [$actions]; }
        if (!is_array($actions) || empty($actions)) {
            $player->sendMessage("§cNo actions in this slot.");
            $this->showActionsMenu($player, $guiName, $rows);
            return;
        }
        $options = array_map(fn($s) => new MenuOption((string)$s), $actions);
        $form = new MenuForm(
            "Actions - Slot $slot",
            "Click an action to edit or delete:",
            $options,
            function(Player $submitter, int $selected) use ($guiName, $rows, $slot, $actions): void {
                $line = $actions[$selected] ?? '';
                if (!is_string($line) || $line === '') { $this->showActionsMenu($submitter, $guiName, $rows); return; }
                // Ask delete or edit
                $confirm = new MenuForm(
                    "Manage Action",
                    $line,
                    [new MenuOption("Delete"), new MenuOption("Edit"), new MenuOption("Back")],
                    function(Player $p, int $choice) use ($guiName, $rows, $slot, $selected, $actions, $line): void {
                        if ($choice === 0) {
                            // Delete
                            array_splice($actions, $selected, 1);
                            $plugin = NetherMenus::getInstance();
                            $file = $plugin->getGuiDataFolder() . $guiName . ".yml";
                            $cfg = new Config($file, Config::YAML);
                            $data = $cfg->getAll();
                            if (empty($actions)) {
                                unset($data['items'][$slot]['action']);
                            } else {
                                $data['items'][$slot]['action'] = array_values($actions);
                            }
                            $cfg->setAll($data);
                            $cfg->save();
                            $p->sendMessage("§aAction deleted.");
                            $this->manageActionsForSlot($p, $guiName, $rows, $slot);
                        } elseif ($choice === 1) {
                            // Edit by parsing tag and rebuilding
                            if (preg_match('/^\[(?<tag>[^\]]+)\]\s*(?<arg>.*)$/', $line, $m)) {
                                $tag = strtolower(trim($m['tag']));
                                $arg = trim((string)$m['arg']);
                                switch ($tag) {
                                    case 'close':
                                        // Not editable -> prompt delete directly
                                        $this->manageActionsForSlot($p, $guiName, $rows, $slot);
                                        break;
                                    case 'opengui':
                                        $this->promptForGuiSelection($p, $guiName, $rows, $slot, null, $selected);
                                        break;
                                    case 'console':
                                    case 'player':
                                        $this->promptForCommand($p, $guiName, $rows, $slot, ['command' => $arg], $selected);
                                        break;
                                    case 'message':
                                        $this->promptForMessage($p, $guiName, $rows, $slot, ['text' => $arg], $selected);
                                        break;
                                    case 'broadcast':
                                        $this->promptForBroadcast($p, $guiName, $rows, $slot, ['text' => $arg], $selected);
                                        break;
                                    case 'chat':
                                        $this->promptForChat($p, $guiName, $rows, $slot, ['text' => $arg], $selected);
                                        break;
                                    default:
                                        $p->sendMessage("§cUnknown action type.");
                                        $this->manageActionsForSlot($p, $guiName, $rows, $slot);
                                        break;
                                }
                            } else {
                                $this->manageActionsForSlot($p, $guiName, $rows, $slot);
                            }
                        } else {
                            $this->manageActionsForSlot($p, $guiName, $rows, $slot);
                        }
                    }
                );
                $submitter->sendForm($confirm);
            }
        );
        $player->sendForm($form);
    }

    private function listGUIs(Player $player): void {
        $plugin = NetherMenus::getInstance();
        $dataFolder = $plugin->getGuiDataFolder();
        $guis = [];
        $guiInfo = [];
        
        if (is_dir($dataFolder)) {
            foreach (glob($dataFolder . "*.yml") as $file) {
                $guiId = basename($file, ".yml");
                $cfg = new Config($file, Config::YAML);
                $data = $cfg->getAll();
                $displayName = $data['name'] ?? $guiId;
                $guis[] = $guiId;
                $guiInfo[$guiId] = $displayName;
            }
        }
        
        if (empty($guis)) {
            $player->sendMessage("§cNo GUIs found!");
            return;
        }
        
        // Sort by display name
        asort($guiInfo);
        
        // Create options with display name and ID
        $options = [];
        $guiIds = [];
        foreach ($guiInfo as $id => $name) {
            $options[] = new MenuOption("$name §7($id)");
            $guiIds[] = $id;
        }
        
        $form = new MenuForm(
            "Saved GUIs", 
            "Select a GUI to manage (ID in gray):", 
            $options,
            function(Player $submitter, int $selected) use ($guiIds): void {
                $guiId = $guiIds[$selected];
                $this->guiSubMenu($submitter, $guiId);
            }
        );
        $player->sendForm($form);
    }

    private function guiSubMenu(Player $player, string $guiId): void {
        // Load GUI data to get display name
        $plugin = NetherMenus::getInstance();
        $dataFile = $plugin->getGuiDataFolder() . $guiId . ".yml";
        $displayName = $guiId;
        $rows = 6; // Default rows
        
        if (file_exists($dataFile)) {
            $cfg = new Config($dataFile, Config::YAML);
            $data = $cfg->getAll();
            $displayName = $data['name'] ?? $guiId;
            $rows = $data['rows'] ?? 6;
        }
        
        $form = new MenuForm(
            "GUI: $displayName",
            "ID: $guiId\nWhat do you want to do with this GUI?\n\n§7Display Name: §f$displayName\n§7GUI ID: §f$guiId\n§7Rows: §f" . $rows . " (" . ($rows * 9) . " slots)",
            [
                new MenuOption("Open"),
                new MenuOption("Edit"),
                new MenuOption("Tooltip"),
                new MenuOption("Actions"),
                new MenuOption("§cDelete"),
                new MenuOption("Back")
            ],
            function(Player $submitter, int $selected) use ($guiId, $displayName, $rows): void {
                switch($selected) {
                    case 0: // Open
                        $plugin = NetherMenus::getInstance();
                        $gui = $plugin->createGUIFromData($guiId);
                        if ($gui !== null) {
                            $gui->open($submitter);
                        } else {
                            $submitter->sendMessage("§cFailed to open GUI with ID '$guiId'!");
                        }
                        break;
                    case 1: // Edit
                        $submitter->setCurrentWindow(new BlankGridGUI($submitter, $guiId, null, null, $rows, $displayName));
                        break;
                    case 2: // Lore
                        $this->showLoreMenu($submitter, $guiId, $rows);
                        break;
                    case 3: // Actions
                        $this->showActionsMenu($submitter, $guiId, $rows);
                        break;
                    case 4: // Delete
                        $this->confirmDeleteGUI($submitter, $guiId, $displayName);
                        break;
                    case 5: // Back
                        $this->listGUIs($submitter);
                        break;
                }
            }
        );
        $player->sendForm($form);
    }

    public function showLoreMenu(Player $player, string $guiName, int $rows): void {
        $form = new MenuForm(
            "Tooltip Menu - $guiName",
            "Select an option:",
            [
                new MenuOption("Add/Edit Tooltip"),
                new MenuOption("Remove Tooltip"),
                new MenuOption("Back")
            ],
            function(Player $submitter, int $selected) use ($guiName, $rows): void {
                switch($selected) {
                    case 0: // Add/Edit Lore
                        $this->showLoreInputForm($submitter, $guiName, $rows);
                        break;
                    case 1: // Remove Lore
                        $this->showSlotSelection($submitter, $guiName, $rows, true);
                        break;
                    case 2: // Back
                        $this->guiSubMenu($submitter, $guiName);
                        break;
                }
            }
        );
        $player->sendForm($form);
    }
    
    private function showActionsMenu(Player $player, string $guiName, int $rows): void {
        $form = new MenuForm(
            "Actions - $guiName",
            "Manage actions:",
            [
                new MenuOption("Add Action"),
                new MenuOption("Added Actions"),
                new MenuOption("Back")
            ],
            function(Player $submitter, int $selected) use ($guiName, $rows): void {
                switch($selected) {
                    case 0: // Add Action
                        $this->showActionTypeMenu($submitter, $guiName, $rows);
                        break;
                    case 1: // Added Actions (manage)
                        $this->showSlotSelection($submitter, $guiName, $rows, true, 'action');
                        break;
                    case 2: // Back
                        $this->guiSubMenu($submitter, $guiName);
                        break;
                }
            }
        );
        $player->sendForm($form);
    }
    
    private function showActionTypeMenu(Player $player, string $guiName, int $rows): void {
        $form = new MenuForm(
            "Action Type - $guiName",
            "Select an action type:",
            [
                new MenuOption("Command (Console/Player)"),
                new MenuOption("Open GUI"),
                new MenuOption("Close"),
                new MenuOption("Message"),
                new MenuOption("Broadcast"),
                new MenuOption("Chat as Player"),
                new MenuOption("Back")
            ],
            function(Player $submitter, int $selected) use ($guiName, $rows): void {
                if ($selected === 6) { // Back
                    $this->showActionsMenu($submitter, $guiName, $rows);
                    return;
                }
                
                $actionType = [
                    0 => 'command',
                    1 => 'open_gui',
                    2 => 'close_gui',
                    3 => 'message',
                    4 => 'broadcast',
                    5 => 'chat'
                ][$selected] ?? '';
                
                if (!empty($actionType)) {
                    switch ($actionType) {
                        case 'command':
                            $this->promptForCommand($submitter, $guiName, $rows);
                            break;
                        case 'open_gui':
                            $this->promptForGuiSelection($submitter, $guiName, $rows);
                            break;
                        case 'close_gui':
                            // Build action line and ask merge/replace
                            $this->confirmMergeModeAndOpenGrid($submitter, $guiName, $rows, '[Close]');
                            break;
                        case 'message':
                            $this->promptForMessage($submitter, $guiName, $rows);
                            break;
                        case 'broadcast':
                            $this->promptForBroadcast($submitter, $guiName, $rows);
                            break;
                        case 'chat':
                            $this->promptForChat($submitter, $guiName, $rows);
                            break;
                    }
                }
            }
        );
        $player->sendForm($form);
    }
    
    private function promptForCommand(Player $player, string $guiName, int $rows, ?int $slot = null, ?array $existingAction = null, ?int $editIndex = null): void {
        $form = new CustomForm(
            "Enter Command - $guiName",
            [
                new Dropdown("executor", "Run as", ["Console", "Player"], 1),
                new Input("command", "Enter command (without /)", $existingAction['command'] ?? "say Hello!")
            ],
            function(Player $player, CustomFormResponse $response) use ($guiName, $rows, $slot, $editIndex): void {
                $execIndex = $response->getInt("executor");
                $exec = $execIndex === 0 ? 'Console' : 'Player';
                $command = trim($response->getString("command"));
                if (empty($command)) {
                    $player->sendMessage("§cCommand cannot be empty!");
                    return;
                }
                $line = "[" . $exec . "] " . $command;
                if ($slot !== null && $editIndex !== null) {
                    $plugin = NetherMenus::getInstance();
                    $file = $plugin->getGuiDataFolder() . $guiName . ".yml";
                    $cfg = new Config($file, Config::YAML);
                    $data = $cfg->getAll();
                    $actions = $data['items'][$slot]['action'] ?? [];
                    if (is_string($actions)) { $actions = [$actions]; }
                    if (!is_array($actions)) { $actions = []; }
                    $actions[$editIndex] = $line;
                    $data['items'][$slot]['action'] = array_values($actions);
                    $cfg->setAll($data);
                    $cfg->save();
                    $player->sendMessage("§aAction updated.");
                    $this->manageActionsForSlot($player, $guiName, $rows, $slot);
                } else {
                    $this->confirmMergeModeAndOpenGrid($player, $guiName, $rows, $line);
                }
            },
            function(Player $player) use ($guiName, $rows, $slot): void {
                if ($slot !== null) {
                    // If editing, go back to action edit menu
                    $this->showActionForm($player, $guiName, $rows, $slot);
                } else {
                    // If adding new, go back to action type menu
                    $this->showActionTypeMenu($player, $guiName, $rows);
                }
            }
        );
        $player->sendForm($form);
    }
    
    private function promptForGuiSelection(Player $player, string $guiName, int $rows, ?int $slot = null, ?array $existingAction = null, ?int $editIndex = null): void {
        $plugin = NetherMenus::getInstance();
        $dataFolder = $plugin->getGuiDataFolder();
        $guis = [];
        
        if (is_dir($dataFolder)) {
            foreach (glob($dataFolder . "*.yml") as $file) {
                $name = basename($file, ".yml");
                if ($name !== $guiName) { // Don't show current GUI to prevent loops
                    $guis[] = $name;
                }
            }
        }
        
        if (empty($guis)) {
            $player->sendMessage("§cNo other GUIs found to open!");
            if ($slot !== null) {
                $this->showActionForm($player, $guiName, $rows, $slot);
            } else {
                $this->showActionTypeMenu($player, $guiName, $rows);
            }
            return;
        }
        
        $options = array_map(fn($name) => new MenuOption($name), $guis);
        $form = new MenuForm(
            "Select GUI to Open - $guiName",
            $slot !== null 
                ? "Select a GUI to open when this slot is clicked:" 
                : "Select a GUI to open when the slot is clicked:",
            $options,
            function(Player $player, int $selectedOption) use ($guiName, $guis, $rows, $slot, $editIndex): void {
                $targetGui = $guis[$selectedOption];
                $line = "[OpenGUI] " . $targetGui;
                if ($slot !== null && $editIndex !== null) {
                    $plugin = NetherMenus::getInstance();
                    $file = $plugin->getGuiDataFolder() . $guiName . ".yml";
                    $cfg = new Config($file, Config::YAML);
                    $data = $cfg->getAll();
                    $actions = $data['items'][$slot]['action'] ?? [];
                    if (is_string($actions)) { $actions = [$actions]; }
                    if (!is_array($actions)) { $actions = []; }
                    $actions[$editIndex] = $line;
                    $data['items'][$slot]['action'] = array_values($actions);
                    $cfg->setAll($data);
                    $cfg->save();
                    $player->sendMessage("§aAction updated.");
                    $this->manageActionsForSlot($player, $guiName, $rows, $slot);
                } else {
                    $this->confirmMergeModeAndOpenGrid($player, $guiName, $rows, $line);
                }
            },
            function(Player $player) use ($guiName, $rows, $slot): void {
                if ($slot !== null) {
                    $this->showActionForm($player, $guiName, $rows, $slot);
                } else {
                    $this->showActionTypeMenu($player, $guiName, $rows);
                }
            }
        );
        $player->sendForm($form);
    }
    
    // Removed Teleport flow as per new design

    private function showLoreInputForm(Player $player, string $guiName, int $rows): void {
        $form = new CustomForm(
            "Add/Edit Tooltip - $guiName",
            [
                new Input("title", "Name", "Enter name..."),
                new Input("description", "Description (use | for new lines)", "Enter description...")
            ],
            function(Player $submitter, CustomFormResponse $response) use ($guiName, $rows): void {
                $title = trim($response->getString("title"));
                $description = trim($response->getString("description"));
                
                if (empty($title) && empty($description)) {
                    $submitter->sendMessage("§cAt least one of title or description must be provided!");
                    $this->showLoreInputForm($submitter, $guiName, $rows);
                    return;
                }
                
                // Open the GUI for slot selection with the lore data
                $loreData = [
                    'title' => $title,
                    'description' => $description
                ];
                
                $submitter->sendMessage("§aNow click on the item you want to add this tooltip to!");
                $submitter->setCurrentWindow(new BlankGridGUI(
                    $submitter,
                    $guiName,
                    null,
                    $loreData,
                    $rows
                ));
            },
            function(Player $submitter) use ($guiName, $rows): void {
                $this->showLoreMenu($submitter, $guiName, $rows);
            }
        );
        $player->sendForm($form);
    }

    private function showSlotSelection(Player $player, string $guiName, int $rows, bool $removeMode = false, string $mode = 'lore'): void {
        $plugin = NetherMenus::getInstance();
        $dataFolder = $plugin->getGuiDataFolder();
        $file = $dataFolder . $guiName . ".yml";
        
        if (!file_exists($file)) {
            $player->sendMessage("§cGUI data not found!");
            return;
        }
        
        $cfg = new Config($file, Config::YAML);
        $data = $cfg->getAll();
        $options = [];
        $slots = [];
        
        $items = $data['items'] ?? [];
        $bySlot = [];
        if (is_array($items)) {
            foreach ($items as $k => $v) {
                $s = null;
                if (is_array($v) && isset($v['slot']) && is_numeric($v['slot'])) { $s = (int)$v['slot']; }
                elseif (is_numeric($k)) { $s = (int)$k; }
                if ($s !== null) { $bySlot[$s] = $v; }
            }
        }
        
        if ($removeMode) {
            foreach ($bySlot as $slot => $entry) {
                if (($mode === 'lore' && isset($entry['lore'])) || 
                    ($mode === 'action' && isset($entry['action']))) {
                    $displayName = $entry['display_name'] ?? 'Unknown';
                    $display = "$slot $displayName";
                    $options[] = $display;
                    $slots[] = $slot;
                }
            }
            
            if (empty($options)) {
                $player->sendMessage("§cNo " . ($mode === 'lore' ? 'tooltips' : 'actions') . " found in this GUI!");
                if ($mode === 'lore') {
                    $this->showLoreMenu($player, $guiName, $rows);
                } else {
                    $this->showActionsMenu($player, $guiName, $rows);
                }
                return;
            }
            
            $form = new MenuForm(
                ($mode === 'lore' ? "Remove Tooltip" : "Added Actions") . " - $guiName",
                $mode === 'lore' ? "Select an item to remove tooltip from:" : "Select a slot to view and manage actions:",
                array_map(fn($opt) => new MenuOption($opt), $options),
                function(Player $submitter, int $selected) use ($guiName, $slots, $mode, $rows): void {
                    $slot = $slots[$selected] ?? null;
                    if ($slot !== null) {
                        if ($mode === 'lore') {
                            $this->removeLore($submitter, $guiName, $slot);
                        } else {
                            $this->manageActionsForSlot($submitter, $guiName, $rows, $slot);
                        }
                    }
                }
            );
        } else {
            // Show all slots for selection when adding/editing
            foreach ($bySlot as $slot => $entry) {
                $hasTooltip = (isset($entry['lore']) || isset($entry['display_name'])) ? "§a[Tooltip]§r " : "";
                $hasAction = isset($entry['action']) ? "§b[Action]§r " : "";
                $hasItem = isset($entry['nbt']) || (isset($entry['material']) && is_string($entry['material']) && preg_match('/^nbt-\s*"?([^"\s]+)"?\s*$/i', trim($entry['material'])));
                $itemName = $hasItem ? 'Item' : 'Empty';
                $options[] = "Slot $slot: $hasTooltip$hasAction$itemName";
                $slots[] = $slot;
            }
            
            $form = new MenuForm(
                "Select Slot - $guiName",
                "Select a slot to edit " . ($mode === 'lore' ? 'tooltip' : 'action') . " for:",
                array_map(fn($opt) => new MenuOption($opt), $options),
                function(Player $submitter, int $selected) use ($guiName, $slots, $data, $rows, $mode): void {
                    $slot = $slots[$selected] ?? null;
                    if ($slot === null) return;
                    $entry = $bySlot[$slot] ?? [];
                    if ($mode === 'lore') {
                        $this->showLoreForm($submitter, $guiName, $rows, $slot, null);
                    } else {
                        $existingAction = $entry['action'] ?? null;
                        $this->showActionForm($submitter, $guiName, $rows, $slot, $existingAction);
                    }
                }
            );
        }
        
        $player->sendForm($form);
    }

    private function showLoreForm(Player $player, string $guiName, int $rows, int $slot = 0, ?array $existingLore = null): void {
        $plugin = NetherMenus::getInstance();
        $dataFolder = $plugin->getGuiDataFolder();
        $file = $dataFolder . $guiName . ".yml";
        
        $slotOptions = [];
        $slotMap = [];
        $defaultSlot = 0;
        $allLoreData = [];
        
        if (file_exists($file)) {
            $cfg = new Config($file, Config::YAML);
            $data = $cfg->getAll();
            $items = $data['items'] ?? [];
            $bySlot = [];
            if (is_array($items)) {
                foreach ($items as $k => $v) {
                    $s = null;
                    if (is_array($v) && isset($v['slot']) && is_numeric($v['slot'])) { $s = (int)$v['slot']; }
                    elseif (is_numeric($k)) { $s = (int)$k; }
                    if ($s !== null) { $bySlot[$s] = $v; }
                }
            }
            // Find all slots that have items (non-empty slots) and collect their lore data
            foreach ($bySlot as $slotNum => $slotData) {
                if (isset($slotData['nbt']) || (isset($slotData['material']) && is_string($slotData['material']) && preg_match('/^nbt-\s*"?([^"\s]+)"?\s*$/i', trim($slotData['material'])))) {
                    $slotOptions[] = "Slot $slotNum";
                    $slotMap[] = (int)$slotNum;
                    
                    // Store lore data for each slot if it exists
                    if (isset($slotData['lore'])) {
                        $allLoreData[(int)$slotNum] = $slotData['lore'];
                    }
                    
                    // If the current slot matches, use its index as default
                    if ((int)$slotNum === $slot) {
                        $defaultSlot = count($slotMap) - 1;
                        // Get existing lore for the current slot if not provided
                        if (!isset($existingLore) && isset($slotData['lore'])) {
                            $existingLore = $slotData['lore'];
                        }
                    }
                }
            }
        }
        
        // If no slots with items found, show error and return to menu
        if (empty($slotOptions)) {
            $player->sendMessage("§cNo items found in any slots to edit lore!");
            $this->showLoreMenu($player, $guiName, $rows);
            return;
        }
        
        // Build maps of existing tooltip data (display_name and lore description lines)
        $allDisplayNames = [];
        $bySlot = [];
        if (is_array($items)) {
            foreach ($items as $k => $v) {
                $s = null;
                if (is_array($v) && isset($v['slot']) && is_numeric($v['slot'])) { $s = (int)$v['slot']; }
                elseif (is_numeric($k)) { $s = (int)$k; }
                if ($s !== null) { $bySlot[$s] = $v; }
            }
        }
        foreach ($bySlot as $slotNum => $slotData) {
            if (isset($slotData['nbt']) || (isset($slotData['material']) && is_string($slotData['material']) && preg_match('/^nbt-\s*"?([^"\s]+)"?\s*$/i', trim($slotData['material'])))) {
                if (isset($slotData['display_name']) && is_string($slotData['display_name'])) {
                    $allDisplayNames[(int)$slotNum] = $slotData['display_name'];
                }
            }
        }
        $existingTitlePrefill = $allDisplayNames[$slotMap[$defaultSlot] ?? 0] ?? '';
        $existingDescPrefill = '';
        $existingLoreArr = $allLoreData[$slotMap[$defaultSlot] ?? 0] ?? [];
        if (is_array($existingLoreArr) && !empty($existingLoreArr)) {
            $existingDescPrefill = implode('|', array_map(fn($s) => (string)$s, $existingLoreArr));
        }

        $form = new CustomForm(
            "Edit Tooltip - $guiName - Slot " . ($slotMap[$defaultSlot] ?? '0'),
            [
                new Dropdown(
                    "slot",
                    "Select slot with items",
                    $slotOptions,
                    $defaultSlot
                ),
                new Input("title", "Name", "Enter name...", $existingTitlePrefill),
                new Input("description", "Description (use | for new lines)", "Enter description...", $existingDescPrefill)
            ],
            function(Player $submitter, CustomFormResponse $response) use ($guiName, $rows, $slotMap, $allLoreData): void {
                $selectedIndex = $response->getInt("slot");
                $newSlot = $slotMap[$selectedIndex] ?? 0;
                
                // If this is just a slot change (not form submission)
                $title = $response->getString("title");
                $description = $response->getString("description");
                
                // If this is a slot change (not form submission with data)
                if ($title === '' && $description === '') {
                    // Reload the form for the newly selected slot
                    $this->showLoreForm($submitter, $guiName, $rows, $newSlot, null);
                    return;
                }
                
                // This is an actual form submission with data
                if ($title === '' && $description === '') {
                    $submitter->sendMessage("§cPlease provide at least a Name or a Description.");
                    $this->showLoreForm($submitter, $guiName, $rows, $newSlot, null);
                    return;
                }
                
                $plugin = NetherMenus::getInstance();
                $dataFolder = $plugin->getGuiDataFolder();
                $file = $dataFolder . $guiName . ".yml";
                
                if (!file_exists($file)) {
                    $submitter->sendMessage("§cGUI data not found!");
                    return;
                }
                
                $cfg = new Config($file, Config::YAML);
                $data = $cfg->getAll();
                $items = $data['items'] ?? [];
                $bySlot = [];
                if (is_array($items)) {
                    foreach ($items as $k => $v) {
                        $s = null;
                        if (is_array($v) && isset($v['slot']) && is_numeric($v['slot'])) { $s = (int)$v['slot']; }
                        elseif (is_numeric($k)) { $s = (int)$k; }
                        if ($s !== null) { $bySlot[$s] = $v; }
                    }
                }
                // Convert description string (pipe-separated) to lore array lines
                $loreLines = [];
                if ($description !== '') {
                    $parts = array_map('trim', explode('|', $description));
                    foreach ($parts as $p) {
                        if ($p !== '') { $loreLines[] = $p; }
                    }
                }
                if (!isset($bySlot[$newSlot])) { $bySlot[$newSlot] = []; }
                // Save display_name (Name) and lore (Description) without duplicating title in lore
                if ($title !== '') { $bySlot[$newSlot]['display_name'] = $title; } else { unset($bySlot[$newSlot]['display_name']); }
                // Always persist lore key; empty array when description is blank
                $bySlot[$newSlot]['lore'] = $loreLines;
                $data['items'] = $bySlot;
                $cfg->setAll($data);
                $cfg->save();
                $submitter->sendMessage("§aTooltip saved for slot $newSlot!");
                
                // Reopen the form with the same slot to continue editing
                $this->showLoreForm($submitter, $guiName, $rows, $newSlot, null);
            },
            function(Player $submitter) use ($guiName, $rows): void {
                // On close, return to lore menu
                $this->showLoreMenu($submitter, $guiName, $rows);
            }
        );
        $player->sendForm($form);
    }
    
    /**
     * Show confirmation dialog before deleting a GUI
     */
    private function confirmDeleteGUI(Player $player, string $guiId, string $displayName): void {
        $form = new ModalForm(
            "Delete GUI",
            "Are you sure you want to delete the GUI '$displayName'?\n\n§7GUI ID: §f$guiId\n\n§cThis action cannot be undone!",
            function(Player $submitter, bool $confirm) use ($guiId, $displayName): void {
                if ($confirm) {
                    $plugin = NetherMenus::getInstance();
                    $dataFile = $plugin->getGuiDataFolder() . $guiId . ".yml";
                    
                    if (file_exists($dataFile)) {
                        if (unlink($dataFile)) {
                            // Clear any cached instances
                            $plugin->reloadGuis();
                            $submitter->sendMessage("§aGUI '$displayName' (ID: $guiId) has been deleted!");
                        } else {
                            $submitter->sendMessage("§cFailed to delete GUI file! Check server logs.");
                            $plugin->getLogger()->error("Failed to delete GUI file: " . $dataFile);
                        }
                    } else {
                        $submitter->sendMessage("§cGUI file not found!");
                    }
                } else {
                    $submitter->sendMessage("§aGUI deletion cancelled.");
                    // Return to the GUI menu
                    $this->guiSubMenu($submitter, $guiId);
                    return;
                }
                $this->listGUIs($submitter);
            }
        );
        $player->sendForm($form);
    }

private function removeLore(Player $player, string $guiName, int $slot): void {
    $plugin = NetherMenus::getInstance();
    $dataFolder = $plugin->getGuiDataFolder();
    $file = $dataFolder . $guiName . ".yml";
    if (!file_exists($file)) {
        $player->sendMessage("§cGUI data not found!");
        return;
    }
    $cfg = new Config($file, Config::YAML);
    $data = $cfg->getAll();
    $rows = (int)($data['rows'] ?? 6);
    $data['items'] = $data['items'] ?? [];
    $bySlot = [];
    if (is_array($data['items'])) {
        foreach ($data['items'] as $k => $v) {
            $s = null;
            if (is_array($v) && isset($v['slot']) && is_numeric($v['slot'])) { $s = (int)$v['slot']; }
            elseif (is_numeric($k)) { $s = (int)$k; }
            if ($s !== null) { $bySlot[$s] = $v; }
        }
    }
    if (isset($bySlot[$slot]['lore']) || isset($bySlot[$slot]['display_name'])) {
        unset($bySlot[$slot]['lore']);
        unset($bySlot[$slot]['display_name']);
        if (empty($bySlot[$slot])) {
            unset($bySlot[$slot]);
        }
        $data['items'] = $bySlot;
        $cfg->setAll($data);
        $cfg->save();
        $player->sendMessage("§aRemoved tooltip from slot $slot.");
    } else {
        $player->sendMessage("§cNo tooltip found in slot $slot.");
    }
    $this->showLoreMenu($player, $guiName, $rows);
}
    
    private function showActionForm(Player $player, string $guiName, int $rows, int $slot, ?array $existingAction = null): void {
        $form = new MenuForm(
            "Edit Action - $guiName",
            "Slot: $slot" . ($existingAction ? "\nType: " . ucfirst(str_replace('_', ' ', $existingAction['type'])) : ""),
            [
                new MenuOption("§eEdit Action"),
                new MenuOption("§cRemove Action"),
                new MenuOption("§aBack")
            ],
            function(Player $submitter, int $selected) use ($guiName, $rows, $slot, $existingAction) {
                switch ($selected) {
                    case 0: // Edit
                        // Redirect to the appropriate edit form based on action type
                        $actionType = $existingAction['type'] ?? '';
                        switch ($actionType) {
                            case 'command':
                                $this->promptForCommand($submitter, $guiName, $rows, $slot, $existingAction);
                                break;
                            case 'open_gui':
                                $this->promptForGuiSelection($submitter, $guiName, $rows, $slot, $existingAction);
                                break;
                            case 'teleport':
                                $this->promptForCoordinates($submitter, $guiName, $rows, $slot, $existingAction);
                                break;
                            case 'close_gui':
                                // Just update the action (no additional data needed for close_gui)
                                $this->saveAction($submitter, $guiName, $rows, $slot, ['type' => 'close_gui']);
                                break;
                            default:
                                $submitter->sendMessage("§cUnknown action type!");
                                $this->showActionsMenu($submitter, $guiName, $rows);
                                break;
                        }
                        break;
                        
                    case 1: // Remove
                        $this->removeAction($submitter, $guiName, $slot);
                        break;
                        
                    case 2: // Back
                        $this->showActionsMenu($submitter, $guiName, $rows);
                        break;
                }
            }
        );
        $player->sendForm($form);
    }
    
    private function saveAction(Player $player, string $guiName, int $rows, int $slot, array $actionData): void {
        $plugin = NetherMenus::getInstance();
        $dataFolder = $plugin->getGuiDataFolder();
        $file = $dataFolder . $guiName . ".yml";
        
        $cfg = new Config($file, Config::YAML);
        $data = $cfg->getAll();
        if (!is_array($data)) $data = [];
        $data['items'] = $data['items'] ?? [];
        $bySlot = [];
        if (is_array($data['items'])) {
            foreach ($data['items'] as $k => $v) {
                $s = null;
                if (is_array($v) && isset($v['slot']) && is_numeric($v['slot'])) { $s = (int)$v['slot']; }
                elseif (is_numeric($k)) { $s = (int)$k; }
                if ($s !== null) { $bySlot[$s] = $v; }
            }
        }
        if (!isset($bySlot[$slot])) { $bySlot[$slot] = []; }
        $bySlot[$slot]['action'] = $actionData;
        $data['items'] = $bySlot;
        $cfg->setAll($data);
        $cfg->save();
        $player->sendMessage("§aAction saved to slot $slot!");
        $this->showActionsMenu($player, $guiName, $rows);
    }

    private function removeAction(Player $player, string $guiName, int $slot): void {
        $plugin = NetherMenus::getInstance();
        $dataFolder = $plugin->getGuiDataFolder();
        $file = $dataFolder . $guiName . ".yml";
        if (!file_exists($file)) {
            $player->sendMessage("§cGUI data not found!");
            return;
        }
        $cfg = new Config($file, Config::YAML);
        $data = $cfg->getAll();
        $rows = (int)($data['rows'] ?? 6);
        $data['items'] = $data['items'] ?? [];
        $bySlot = [];
        if (is_array($data['items'])) {
            foreach ($data['items'] as $k => $v) {
                $s = null;
                if (is_array($v) && isset($v['slot']) && is_numeric($v['slot'])) { $s = (int)$v['slot']; }
                elseif (is_numeric($k)) { $s = (int)$k; }
                if ($s !== null) { $bySlot[$s] = $v; }
            }
        }
        if (isset($bySlot[$slot]['action'])) {
            unset($bySlot[$slot]['action']);
            if (empty($bySlot[$slot])) {
                unset($bySlot[$slot]);
            }
            $data['items'] = $bySlot;
            $cfg->setAll($data);
            $cfg->save();
            $player->sendMessage("§aRemoved action from slot $slot.");
        } else {
            $player->sendMessage("§cNo action found in slot $slot.");
        }
        $this->showActionsMenu($player, $guiName, $rows);
    }
}