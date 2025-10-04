<?php

namespace NetherByte\NetherMenus;

use pocketmine\command\Command;
use pocketmine\plugin\Plugin;
use pocketmine\plugin\PluginBase;
use pocketmine\plugin\PluginOwned;
use NetherByte\NetherMenus\command\NetherMenusCommand;
use NetherByte\NetherMenus\command\GuiAdminCommand;
use NetherByte\NetherMenus\libs\tedo0627\inventoryui\InventoryUI;
use NetherByte\NetherMenus\ui\gui\CustomGUI;
use pocketmine\utils\Config;
use NetherByte\NetherMenus\requirement\RequirementEvaluator;
use NetherByte\NetherMenus\hook\InventoryCloseListener;
use pocketmine\player\Player;
use NetherByte\NetherMenus\hook\PlaceholdersHook;
use NetherByte\NetherMenus\hook\PocketVaultHook;

class NetherMenus extends PluginBase {
    
    private static ?NetherMenus $instance = null;
    private array $guiCache = [];
    private array $guiInstances = [];
    // Track last modification times of GUI YAML files to auto-reload on change
    private array $guiMTime = [];
    private array $configData = [
        'max-line-length' => 30,
        'force-word-break' => true
    ];
    
    private array $commandMap = []; // Maps custom commands to GUI IDs
    private bool $resourcePackReady = false; // InventoryUI resource pack + force_resources check
    // Per-player state
    private array $currentMenu = []; // uuid => guiId
    private array $lastMenu = [];    // uuid => guiId
    
    public static function getInstance(): NetherMenus {
        return self::$instance;
    }
    
    protected function onLoad(): void {
        self::$instance = $this;
    }
    
    public function onEnable(): void {
        // Save default config if it doesn't exist
        $this->saveDefaultConfig();
        
        // Load configuration
        $this->reloadConfig();
        $guiSettings = $this->getConfig()->get('gui-settings', []);
        
        // Update config data with values from config file
        $this->configData['max-line-length'] = $guiSettings['max-line-length'] ?? $this->configData['max-line-length'];
        $this->configData['force-word-break'] = $guiSettings['force-word-break'] ?? $this->configData['force-word-break'];
        
        // Setup InventoryUI virion; do NOT hard-fail the plugin if the RP is missing
        try {
            InventoryUI::setup($this);
            $this->resourcePackReady = true;
        } catch (\Exception $e) {
            $this->resourcePackReady = false;
            $this->getLogger()->warning("InventoryUI not ready: " . $e->getMessage());
            $this->getLogger()->warning("Plugin will not work and features will be degraded");
            $this->getLogger()->warning("Download the resource pack from (https://github.com/NetherByte233/NetherMenus/releases/download/v1.0.0/InventoryUIResourcePack-main.mcpack)");
        }
        
        // Register listeners
        $this->getServer()->getPluginManager()->registerEvents(new InventoryCloseListener(), $this);
        
        // Register base commands first
        $this->getServer()->getCommandMap()->register($this->getName(), new NetherMenusCommand());
        $this->getServer()->getCommandMap()->register($this->getName(), new GuiAdminCommand());
        
        // Preload all GUI data and register custom commands
        $this->preloadAllGuis();
        $this->registerCustomCommands();
        
        // Hooks
        PlaceholdersHook::register($this);
        PocketVaultHook::init($this);
        
        if (!$this->resourcePackReady) {
            $this->getLogger()->notice("NetherMenus: Resource pack not detected. Please download/enable the Inventory UI Resource Pack for full functionality.");
        }
    }
    
    public function onDisable(): void {
        // Silence unnecessary shutdown messages to comply with Poggit B3
    }
    
    /**
     * Get the maximum line length for lore text wrapping
     */
    public function getMaxLineLength(): int {
        return $this->configData['max-line-length'];
    }
    
    /**
     * Check if long words should be force-broken
     */
    public function shouldForceWordBreak(): bool {
        return $this->configData['force-word-break'];
    }

    /**
     * Returns whether the InventoryUI resource pack checks passed.
     */
    public function isResourcePackReady(): bool {
        return $this->resourcePackReady;
    }
    
    // --- Per-player menu tracking ---
    public function setCurrentMenu(Player $player, string $guiId): void {
        $uuid = $player->getUniqueId()->toString();
        $prev = $this->currentMenu[$uuid] ?? null;
        if ($prev !== null && $prev !== $guiId) {
            $this->lastMenu[$uuid] = $prev;
        }
        $this->currentMenu[$uuid] = $guiId;
    }
    
    public function clearCurrentMenu(Player $player): void {
        $uuid = $player->getUniqueId()->toString();
        if (isset($this->currentMenu[$uuid])) {
            $this->lastMenu[$uuid] = $this->currentMenu[$uuid];
            unset($this->currentMenu[$uuid]);
        }
    }
    
    public function getCurrentMenuId(Player $player): ?string {
        return $this->currentMenu[$player->getUniqueId()->toString()] ?? null;
    }
    
    public function getLastMenuId(Player $player): ?string {
        return $this->lastMenu[$player->getUniqueId()->toString()] ?? null;
    }
    
    public function isInMenu(Player $player): bool {
        return isset($this->currentMenu[$player->getUniqueId()->toString()]);
    }
    
    /**
     * Preload all GUI data from YAML files into memory
     */
    private function preloadAllGuis(): void {
        $dataFolder = $this->getGuiDataFolder();
        if (!is_dir($dataFolder)) {
            mkdir($dataFolder, 0777, true);
        }
        
        $files = glob($dataFolder . "*.yml");
        $loadedCount = 0;
        foreach ($files as $file) {
            $guiName = strval(basename($file, ".yml"));
            $cfg = new Config($file, Config::YAML);
            $data = $cfg->getAll();
            if (is_array($data)) {
                // Normalize open_command to array for internal use
                if (!isset($data['open_command'])) {
                    $data['open_command'] = ["gui $guiName"];
                } elseif (is_string($data['open_command'])) {
                    $data['open_command'] = [$data['open_command']];
                }
                $this->guiCache[$guiName] = $data;
                $this->guiMTime[$guiName] = @filemtime($file) ?: time();
                $loadedCount++;
            }
        }
        
        if ($loadedCount > 0) {
            $this->getLogger()->debug("Loaded $loadedCount GUI files from cache");
        }
    }
    
    /**
     * Get cached GUI data
     */
    public function getCachedGui(string $guiName): ?array {
        return $this->guiCache[$guiName] ?? null;
    }
    
    /**
     * Get all available GUIs as an associative array of [id => name]
     */
    public function getAvailableGuis(): array {
        $guis = [];
        foreach ($this->guiCache as $id => $data) {
            $guis[$id] = $data['name'] ?? $id; // Use the display name if available, otherwise fall back to ID
        }
        return $guis;
    }
    
    /**
     * Reload a specific GUI into cache
     */
    public function reloadGui(string $guiName): void {
        $dataFolder = $this->getGuiDataFolder();
        $file = $dataFolder . $guiName . ".yml";
        if (file_exists($file)) {
            $cfg = new Config($file, Config::YAML);
            $data = $cfg->getAll();
            if (is_array($data)) {
                // Normalize open_command to array
                if (isset($data['open_command']) && is_string($data['open_command'])) {
                    $data['open_command'] = [$data['open_command']];
                }
                $guiName = strval($guiName);
                $this->guiCache[$guiName] = $data;
                $this->guiMTime[$guiName] = @filemtime($file) ?: time();
                // Update the cached instance if it exists
                if (isset($this->guiInstances[$guiName])) {
                    $this->guiInstances[$guiName] = new CustomGUI($this, $guiName, $data);
                }
            }
        }
    }
    
    /**
     * Reload all GUIs from disk
     */
    public function reloadGuis(): void {
        $this->guiCache = []; // Clear existing cache
        $this->guiInstances = []; // Clear all cached instances
        $this->guiMTime = []; // Clear mtimes
        $this->commandMap = []; // Clear command map
        $this->preloadAllGuis(); // Reload all GUIs from disk
        $this->registerCustomCommands(); // Re-register custom commands
    }
    
    /**
     * Get the GUI data folder path
     */
    public function getGuiDataFolder(): string {
        return $this->getDataFolder() . "guis/";
    }
    
    /**
     * Create a GUI instance from saved data
     */
    /**
     * Register all custom commands from GUI configurations
     */
    private function registerCustomCommands(): void {
        $commandMap = $this->getServer()->getCommandMap();
        
        // Unregister previously registered commands we tracked
        foreach ($this->commandMap as $cmd => $guiId) {
            $command = $commandMap->getCommand($cmd);
            if ($command !== null) {
                $commandMap->unregister($command);
            }
        }
        
        $this->commandMap = [];
        
        // Register new commands
        foreach ($this->guiCache as $guiId => $guiData) {
            if (isset($guiData['open_command']) && is_array($guiData['open_command'])) {
                foreach ($guiData['open_command'] as $command) {
                    $command = trim($command);
                    if (!empty($command)) {
                        $cmdName = explode(' ', $command)[0]; // Get the base command
                        
                        // Skip if this is the default gui command to avoid conflicts
                        if (strtolower($cmdName) === 'gui') {
                            continue;
                        }
                        
                        $this->commandMap[$cmdName] = $guiId;
                        
                        // Handle existing registrations gracefully
                        $existing = $commandMap->getCommand($cmdName);
                        if ($existing instanceof PluginOwned && $existing->getOwningPlugin() === $this) {
                            // This command is ours from a previous enable/reload: unregister then replace
                            $commandMap->unregister($existing);
                            $existing = null;
                        }
                        
                        // Only register if not already registered (by someone else)
                        if ($existing === null) {
                            try {
                                $commandMap->register($this->getName(), new class($cmdName, $guiId, $guiData) extends Command implements PluginOwned {
                                    private string $guiId;
                                    private Plugin $plugin;
                                    
                                    public function __construct(string $name, string $guiId, array $guiData) {
                                        parent::__construct($name, $guiData['command_description'] ?? 'Open a custom GUI');
                                        $this->guiId = $guiId;
                                        $this->plugin = NetherMenus::getInstance();
                                        $this->setPermission("nethermenus.command");
                                    }
                                    
                                    public function execute(\pocketmine\command\CommandSender $sender, string $commandLabel, array $args) {
                                        if (!$sender instanceof \pocketmine\player\Player) {
                                            $sender->sendMessage("This command can only be used in-game");
                                            return;
                                        }
                                        // Degraded mode: resource pack missing
                                        if (!$this->plugin->isResourcePackReady()) {
                                            $sender->sendMessage("§cNetherMenus requires the Inventory UI Resource Pack and force_resources=true. Please download/accept the resource pack; commands are disabled until then.");
                                            return;
                                        }
                                        
                                        $gui = $this->plugin->createGUIFromData($this->guiId);
                                        
                                        if ($gui !== null) {
                                            // Enforce open_requirement before opening; do not flash the GUI if it fails
                                            $eval = $gui->canPlayerOpen($sender);
                                            if ($eval['pass']) {
                                                RequirementEvaluator::runSuccessActions($sender, $eval);
                                                if ($sender->getCurrentWindow() !== null) {
                                                    $sender->removeCurrentWindow();
                                                }
                                                $this->plugin->setCurrentMenu($sender, $this->guiId);
                                                $sender->setCurrentWindow($gui);
                                            } else {
                                                RequirementEvaluator::runDenyActions($sender, $eval);
                                            }
                                         } else {
                                             $sender->sendMessage("§cGUI not found! It may have been removed or corrupted.");
                                         }
                                    }
                                    
                                    public function getOwningPlugin(): Plugin {
                                        return $this->plugin;
                                    }
                                });
                            } catch (\Exception $e) {
                                $this->getLogger()->error("Failed to register command '$cmdName': " . $e->getMessage());
                            }
                        } else {
                            // Another plugin owns this command. Skip without spamming warnings.
                            $this->getLogger()->debug("Command '$cmdName' already registered by another plugin; skipping.");
                        }
                    }
                }
            }
        }
    }
    
    /**
     * Get the GUI ID associated with a custom command
     */
    public function getGuiIdByCommand(string $command): ?string {
        return $this->commandMap[$command] ?? null;
    }
    
    public function createGUIFromData(string $guiName): ?CustomGUI {
        $guiName = strval($guiName);
        if (!$this->isResourcePackReady()) {
            return null; // safeguard: do not instantiate GUI when RP is missing
        }
        
        // Auto-reload if the underlying YAML file changed since last cache
        $dataFolder = $this->getGuiDataFolder();
        $file = $dataFolder . $guiName . ".yml";
        if (file_exists($file)) {
            $currentMTime = @filemtime($file) ?: null;
            $cachedMTime = $this->guiMTime[$guiName] ?? null;
            if ($currentMTime !== null && $cachedMTime !== null && $currentMTime > $cachedMTime) {
                $this->reloadGui($guiName);
            }
        }
        
        // Return cached instance if available
        if (isset($this->guiInstances[$guiName])) {
            return $this->guiInstances[$guiName];
        }
        
        // Otherwise create a new instance and cache it
        $guiData = $this->getCachedGui($guiName);
        if ($guiData === null) {
            return null;
        }
        // Validate and sanitize GUI data to prevent crashes from malformed YAML
        $validated = $this->validateAndSanitizeGuiData($guiData, $guiName);
        if ($validated === null) {
            $this->getLogger()->error("GUI '$guiName' has invalid configuration. Open attempt blocked.");
            return null;
        }
        $gui = new CustomGUI($this, $guiName, $validated);
        $this->guiInstances[$guiName] = $gui;
        return $gui;
    }

    /**
     * Validate and sanitize GUI YAML data structure.
     * Returns sanitized array or null if irrecoverably invalid.
     */
    private function validateAndSanitizeGuiData(array $data, string $guiName): ?array {
        // id
        if (!isset($data['id']) || !is_string($data['id']) || $data['id'] === '') {
            $data['id'] = $guiName;
        }
        // name
        if (!isset($data['name']) || !is_string($data['name']) || $data['name'] === '') {
            $data['name'] = $guiName;
        }
        // rows
        $rows = $data['rows'] ?? 6;
        if (!is_int($rows)) {
            if (is_numeric($rows)) { $rows = (int)$rows; } else { $rows = 6; }
        }
        if ($rows < 1 || $rows > 6) { $rows = 6; }
        $data['rows'] = $rows;
        $maxSlots = $rows * 9;
        // open_command: normalize to array of strings, fallback to default
        if (!isset($data['open_command'])) {
            $data['open_command'] = ["gui $guiName"];
        } elseif (is_string($data['open_command'])) {
            $data['open_command'] = [$data['open_command']];
        } elseif (!is_array($data['open_command'])) {
            $data['open_command'] = ["gui $guiName"];
        } else {
            $cmds = [];
            foreach ($data['open_command'] as $cmd) {
                if (is_string($cmd)) {
                    $cmd = trim($cmd);
                    if ($cmd !== '') { $cmds[] = $cmd; }
                }
            }
            if (empty($cmds)) { $cmds = ["gui $guiName"]; }
            $data['open_command'] = $cmds;
        }
        // items: strict validation against legacy format
        if (!isset($data['items']) || !is_array($data['items'])) {
            $data['items'] = [];
        }
        // Helper: validate slot spec
        $isValidSlotSpec = function($slotSpec) use ($maxSlots): bool {
            $acc = [];
            $add = function(int $n) use (&$acc, $maxSlots) { if ($n >= 0 && $n < $maxSlots) { $acc[$n] = true; } };
            $expand = function($spec) use (&$expand, &$add) {
                if (is_int($spec) || (is_numeric($spec) && $spec !== '')) {
                    $add((int)$spec);
                } elseif (is_string($spec)) {
                    $s = trim($spec);
                    if ($s === '') { return; }
                    foreach (preg_split('/\s*,\s*/', $s) as $part) {
                        if ($part === '') continue;
                        if (preg_match('/^(\d+)\s*-\s*(\d+)$/', $part, $m)) {
                            $a = (int)$m[1]; $b = (int)$m[2];
                            if ($a <= $b) { for ($i=$a; $i<=$b; $i++) { $add($i); } }
                            else { for ($i=$a; $i>=$b; $i--) { $add($i); } }
                        } elseif (is_numeric($part)) {
                            $add((int)$part);
                        }
                    }
                } elseif (is_array($spec)) {
                    foreach ($spec as $sub) { $expand($sub); }
                }
            };
            $expand($slotSpec);
            return !empty($acc);
        };
        // Validate each item entry: be tolerant of legacy formats (numeric-key slots, legacy fields)
        foreach ($data['items'] as $key => &$item) {
            if (!is_array($item)) {
                // Skip non-array item entries instead of rejecting whole GUI
                unset($data['items'][$key]);
                continue;
            }
            // Remove legacy top-level 'nbt' if present, but do not fail the GUI
            if (array_key_exists('nbt', $item)) {
                unset($item['nbt']);
            }
            // If explicit 'slot' exists but invalid, drop the slot key and let runtime legacy handling manage numeric keys
            if (array_key_exists('slot', $item) && !$isValidSlotSpec($item['slot'])) {
                unset($item['slot']);
            }
            // Light normalization of tooltip/lore/actions, no reindexing
            if (isset($item['tooltip']) && is_array($item['tooltip'])) {
                if (isset($item['tooltip']['lore'])) {
                    $l = $item['tooltip']['lore'];
                    if (is_string($l)) { $item['tooltip']['lore'] = [$l]; }
                    elseif (is_array($l)) {
                        $norm = [];
                        foreach ($l as $line) { if (is_string($line)) { $norm[] = $line; } }
                        $item['tooltip']['lore'] = $norm;
                    } else { unset($item['tooltip']['lore']); }
                }
            }
            if (isset($item['lore'])) {
                if (is_string($item['lore'])) { $item['lore'] = [$item['lore']]; }
                elseif (is_array($item['lore'])) {
                    $norm = [];
                    foreach ($item['lore'] as $line) { if (is_string($line)) { $norm[] = $line; } }
                    $item['lore'] = $norm;
                } else { unset($item['lore']); }
            }
            if (isset($item['action'])) {
                if (is_string($item['action'])) { $item['action'] = [$item['action']]; }
                if (is_array($item['action'])) {
                    $acts = [];
                    foreach ($item['action'] as $act) {
                        if (is_string($act)) {
                            $s = trim($act);
                            if ($s !== '') { $acts[] = $s; }
                        }
                    }
                    if (!empty($acts)) { $item['action'] = $acts; } else { unset($item['action']); }
                } else { unset($item['action']); }
            }
            // Priority normalization: default lowest priority if not set; clamp to [0, 2147483647]
            $prio = $item['priority'] ?? 2147483647;
            if (!is_int($prio)) {
                if (is_numeric($prio)) { $prio = (int)$prio; }
                else { $prio = 2147483647; }
            }
            if ($prio < 0) { $prio = 0; }
            if ($prio > 2147483647) { $prio = 2147483647; }
            $item['priority'] = $prio;
        }
        unset($item);
        return $data;
    }
}
