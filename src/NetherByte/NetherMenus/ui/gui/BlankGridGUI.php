<?php

namespace NetherByte\NetherMenus\ui\gui;

use NetherByte\NetherMenus\libs\tedo0627\inventoryui\CustomInventory;
use NetherByte\NetherMenus\NetherMenus;
use NetherByte\NetherMenus\libs\dktapps\pmforms\MenuForm;
use NetherByte\NetherMenus\libs\dktapps\pmforms\MenuOption;
use pocketmine\item\Item;
use pocketmine\item\VanillaItems;
use pocketmine\player\Player;
use pocketmine\utils\Config;
use pocketmine\scheduler\ClosureTask;

class BlankGridGUI extends CustomInventory {
    
    private string $guiName;
    private ?array $pendingAction = null;
    private ?array $pendingLore = null;
    private int $rows;
    
    public function __construct(Player $player, string $guiId, ?array $pendingAction = null, ?array $pendingLore = null, int $rows = 6, ?string $displayName = null) {
        $this->guiName = trim($guiId); // Store the ID as guiName for backward compatibility
        $this->pendingAction = $pendingAction;
        $this->pendingLore = $pendingLore;
        $this->rows = $rows;
        
        // Use display name if provided, otherwise use the ID
        $windowTitle = $displayName !== null ? "Edit: $displayName" : "Edit GUI: $guiId";
        parent::__construct($rows * 9, $windowTitle, $rows); // rows x 9 slots
    }
    
    public function open(Player $player): void {
        // Load existing GUI if it exists
        $plugin = NetherMenus::getInstance();
        $dataFolder = $plugin->getGuiDataFolder();
        $file = $dataFolder . $this->guiName . ".yml";
        
        // Clear all slots first
        for ($i = 0; $i < $this->rows * 9; $i++) {
            $this->setItem($i, VanillaItems::AIR());
        }
        
        if (file_exists($file)) {
            $cfg = new Config($file, Config::YAML);
            $data = $cfg->getAll();
            $items = $data['items'] ?? [];
            if (is_array($items)) {
                // Helper to expand slot specifications
                $max = max(0, $this->rows * 9 - 1);
                $expandSlots = function($slotSpec) use ($max) : array {
                    $result = [];
                    $add = function(int $n) use (&$result, $max) { if ($n >= 0 && $n <= $max) { $result[$n] = true; } };
                    if (is_int($slotSpec) || (is_numeric($slotSpec) && $slotSpec !== '')) {
                        $add((int)$slotSpec);
                    } elseif (is_string($slotSpec)) {
                        $spec = trim($slotSpec);
                        if ($spec !== '') {
                            foreach (preg_split('/\s*,\s*/', $spec) as $part) {
                                if ($part === '') continue;
                                if (preg_match('/^(\d+)\s*-\s*(\d+)$/', $part, $m)) {
                                    $a = (int)$m[1]; $b = (int)$m[2];
                                    if ($a <= $b) { for ($i=$a; $i<=$b; $i++) { $add($i); } }
                                    else { for ($i=$a; $i>=$b; $i--) { $add($i); } }
                                } elseif (is_numeric($part)) {
                                    $add((int)$part);
                                }
                            }
                        }
                    } elseif (is_array($slotSpec)) {
                        foreach ($slotSpec as $sub) {
                            foreach ($expandSlots($sub) as $k => $_) { $result[$k] = true; }
                        }
                    }
                    ksort($result, SORT_NUMERIC);
                    return array_keys($result);
                };

                foreach ($items as $key => $slotData) {
                    // Determine slot spec (new or legacy)
                    $slotSpec = null;
                    if (is_array($slotData) && array_key_exists('slot', $slotData)) {
                        $slotSpec = $slotData['slot'];
                    } elseif (is_numeric($key)) {
                        $slotSpec = (int)$key;
                    } else {
                        continue;
                    }
                    $slotIndices = $expandSlots($slotSpec);
                    if (empty($slotIndices)) { continue; }
                    foreach ($slotIndices as $slotIndex) {
                        try {
                            $item = null;
                            if (isset($slotData['nbt'])) {
                                // Legacy key support
                                $nbt = unserialize(base64_decode($slotData['nbt']));
                                $item = Item::nbtDeserialize($nbt);
                            } elseif (isset($slotData['material']) && is_string($slotData['material']) && preg_match('/^nbt-\s*"?([^"\s]+)"?\s*$/i', trim($slotData['material']), $m)) {
                                $b64 = $m[1] ?? '';
                                if ($b64 !== '') {
                                    $nbt = unserialize(base64_decode($b64));
                                    $item = Item::nbtDeserialize($nbt);
                                }
                            }
                            if ($item === null) { continue; }
                            // Apply display name if present
                            if (isset($slotData['display_name']) && is_string($slotData['display_name']) && $slotData['display_name'] !== '') {
                                $item->setCustomName($slotData['display_name']);
                            } else {
                                // Hide default name in the editor when no custom display name
                                $item->setCustomName(" ");
                            }
                            // Apply lore if present (array of strings)
                            if (isset($slotData['lore']) && is_array($slotData['lore'])) {
                                $item->setLore($slotData['lore']);
                            }
                            $this->setItem($slotIndex, $item);
                        } catch (\Throwable $e) {
                            continue;
                        }
                    }
                }
            }
        }
        // Show instructions
        if ($this->pendingAction !== null) {
            $player->sendMessage("§aClick on a slot to assign the action!");
        } elseif ($this->pendingLore !== null) {
            $player->sendMessage("§aClick on a slot to assign the tooltip!");
        } else {
            $player->sendMessage("§aPlace items in slots to create your GUI!");
            $player->sendMessage("§aUse /guiadmin to manage actions and tooltip.");
        }
        parent::open($player);
    }
    
    public function click(Player $player, int $slot, Item $sourceItem, Item $targetItem): bool {
        // Handle pending action assignment
        if ($this->pendingAction !== null) {
            // If target slot already has actions, ask Merge/Replace now
            $plugin = NetherMenus::getInstance();
            $dataFolder = $plugin->getGuiDataFolder();
            $file = $dataFolder . $this->guiName . ".yml";
            $cfg = new Config($file, Config::YAML);
            $data = $cfg->getAll();
            $existing = $data['items'][$slot]['action'] ?? null;
            // Normalize existing to array
            $hasExisting = false;
            if (is_string($existing)) { $hasExisting = trim($existing) !== ''; }
            elseif (is_array($existing)) { $hasExisting = count($existing) > 0; }
            if ($hasExisting) {
                $line = $this->pendingAction['line'] ?? '';
                $form = new MenuForm(
                    "Slot Behavior",
                    "This slot already has actions. What should we do?",
                    [new MenuOption("Merge"), new MenuOption("Replace")],
                    function(Player $submitter, int $choice) use ($slot, $line): void {
                        $merge = ($choice === 0);
                        $this->saveActionToSlot($slot, ['line' => $line, 'merge' => $merge]);
                        $submitter->sendMessage("§aAction assigned to slot $slot!");
                        $this->pendingAction = null;
                        $submitter->removeCurrentWindow();
                    },
                    function(Player $submitter): void {
                        // Reopen the grid so the user can try again; keep pendingAction intact
                        NetherMenus::getInstance()->getScheduler()->scheduleDelayedTask(new ClosureTask(function() use ($submitter): void {
                            if ($submitter->isOnline()) {
                                $submitter->setCurrentWindow(new BlankGridGUI($submitter, $this->guiName, $this->pendingAction, null, $this->rows));
                            }
                        }), 1);
                    }
                );
                // Inform player and close the inventory first, then send the form on next tick to avoid conflicts with inventory click handling
                $player->sendMessage("§eSlot already has actions. Choose Merge or Replace...");
                $player->removeCurrentWindow();
                // Use a slightly longer delay to ensure client UI is ready after closing the inventory
                NetherMenus::getInstance()->getScheduler()->scheduleDelayedTask(new ClosureTask(function() use ($player, $form): void {
                    if ($player->isOnline()) {
                        $player->sendForm($form);
                    }
                }), 5);
            } else {
                $this->saveActionToSlot($slot, ['line' => ($this->pendingAction['line'] ?? ''), 'merge' => true]);
                $player->sendMessage("§aAction assigned to slot $slot!");
                $this->pendingAction = null;
                $player->removeCurrentWindow();
            }
            return true;
        }
        
        // Handle pending lore assignment
        if ($this->pendingLore !== null) {
            $this->saveLoreToSlot($slot, $this->pendingLore);
            $player->sendMessage("§aLore assigned to slot $slot!");
            $this->pendingLore = null;
            $player->removeCurrentWindow();
            return true;
        }
        
        // Normal item placement - only save non-air items
        if (!$targetItem->isNull() && !$targetItem->equals(VanillaItems::AIR())) {
            $this->saveItemToSlot($slot, $targetItem);
        } else {
            $this->clearSlot($slot);
        }
        return false; // Allow item movement
    }
    
    public function close(Player $player): void {
        // Save all slots to the file
        $plugin = NetherMenus::getInstance();
        $dataFolder = $plugin->getGuiDataFolder();
        $file = $dataFolder . $this->guiName . ".yml";
        
        // Load existing data to preserve metadata, lore and actions
        $cfg = new Config($file, Config::YAML);
        $data = $cfg->getAll();
        if (!is_array($data)) $data = [];
        
        // Ensure items container
        $data['items'] = $data['items'] ?? [];
        $items = $data['items'];
        
        // Save current items
        $newItems = [];
        // Index existing items by slot to preserve names where possible
        $bySlot = [];
        if (is_array($items)) {
            foreach ($items as $k => $v) {
                $s = null;
                if (is_array($v) && isset($v['slot']) && is_numeric($v['slot'])) { $s = (int)$v['slot']; }
                elseif (is_numeric($k)) { $s = (int)$k; }
                if ($s !== null) { $bySlot[$s] = $k; }
            }
        }
        for ($i = 0; $i < $this->rows * 9; $i++) {
            $item = $this->getItem($i);
            if (!$item->isNull() && !$item->equals(VanillaItems::AIR())) {
                $nbt = $item->nbtSerialize();
                if ($nbt !== null) {
                    $entry = [];
                    $entry['material'] = 'nbt-"' . base64_encode(serialize($nbt)) . '"';
                    $entry['slot'] = $i;
                    // Preserve existing tooltip, action, priority, slots if they exist in prior data
                    if (isset($bySlot[$i])) {
                        $old = $items[$bySlot[$i]] ?? [];
                        if (isset($old['lore'])) { $entry['lore'] = $old['lore']; }
                        if (isset($old['display_name'])) { $entry['display_name'] = $old['display_name']; }
                        if (isset($old['action'])) { $entry['action'] = $old['action']; }
                        if (isset($old['priority'])) { $entry['priority'] = $old['priority']; }
                        if (isset($old['slots'])) { $entry['slots'] = $old['slots']; }
                    }
                    $keyName = isset($bySlot[$i]) && is_string($bySlot[$i]) ? (string)$bySlot[$i] : ('Slot_' . $i);
                    $newItems[$keyName] = $entry;
                }
            }
        }
        $items = $newItems;
        
        // Persist items back
        // Keep current associative order; optional sorting can be applied if desired
        $data['items'] = $items;
        
        // Save the data back to the file (YAML)
        $cfg->setAll($data);
        $cfg->save();
        $player->sendMessage("§aGUI '$this->guiName' saved!");
        
        $plugin->reloadGui($this->guiName);
        parent::close($player);
    }
    
    private function saveItemToSlot(int $slot, Item $item): void {
        // Don't save air or null items
        if ($item->isNull() || $item->equals(VanillaItems::AIR())) {
            $this->clearSlot($slot);
            return;
        }
        
        $plugin = NetherMenus::getInstance();
        $dataFolder = $plugin->getGuiDataFolder();
        $file = $dataFolder . $this->guiName . ".yml";
        
        $cfg = new Config($file, Config::YAML);
        $data = $cfg->getAll();
        if (!is_array($data)) $data = [];
        $data['items'] = $data['items'] ?? [];
        try {
            
            // Save item as NBT string
            $nbt = $item->nbtSerialize();
            if ($nbt === null) {
                throw new \RuntimeException("Failed to serialize item NBT");
            }
            $nbtString = base64_encode(serialize($nbt));
            
            // Find existing entry key for this slot
            $existingKey = null;
            foreach ($data['items'] as $k => $v) {
                $s = null;
                if (is_array($v) && isset($v['slot']) && is_numeric($v['slot'])) { $s = (int)$v['slot']; }
                elseif (is_numeric($k)) { $s = (int)$k; }
                if ($s === $slot) { $existingKey = $k; break; }
            }
            // Preserve existing tooltip, action, priority, slots if they exist
            $existingLore = ($existingKey !== null && isset($data['items'][$existingKey]['lore'])) ? $data['items'][$existingKey]['lore'] : null;
            $existingDisplayName = ($existingKey !== null && isset($data['items'][$existingKey]['display_name'])) ? $data['items'][$existingKey]['display_name'] : null;
            $existingAction = ($existingKey !== null && isset($data['items'][$existingKey]['action'])) ? $data['items'][$existingKey]['action'] : null;
            $existingPriority = ($existingKey !== null && isset($data['items'][$existingKey]['priority'])) ? $data['items'][$existingKey]['priority'] : null;
            $existingSlots = ($existingKey !== null && isset($data['items'][$existingKey]['slots'])) ? $data['items'][$existingKey]['slots'] : null;
            
            // Determine destination key and initialize the slot data with inline NBT material format
            $destKey = $existingKey ?? ('Slot_' . $slot);
            $data['items'][$destKey] = [
                'material' => 'nbt-"' . $nbtString . '"',
                'slot' => $slot,
            ];
            
            // Preserve existing tooltip, action, priority, slots
            if ($existingLore !== null) { $data['items'][$destKey]['lore'] = $existingLore; }
            if ($existingDisplayName !== null) { $data['items'][$destKey]['display_name'] = $existingDisplayName; }
            if ($existingAction !== null) { $data['items'][$destKey]['action'] = $existingAction; }
            if ($existingPriority !== null) { $data['items'][$destKey]['priority'] = $existingPriority; }
            if ($existingSlots !== null) { $data['items'][$destKey]['slots'] = $existingSlots; }
            
            // Remove legacy key if present
            if (isset($data['items'][$destKey]['nbt'])) { unset($data['items'][$destKey]['nbt']); }
            
            // Persist items and save
            // Keep current associative order; optional sorting can be applied if desired
            $cfg->setAll($data);
            $cfg->save();
            
            $plugin->reloadGui($this->guiName);
        } catch (\Throwable $e) {
            $player = $this->getViewers()[0] ?? null;
            if ($player instanceof Player) {
                $player->sendMessage("§cError saving item: " . $e->getMessage());
            }
        }
    }
    
    private function saveActionToSlot(int $slot, array $action): void {
        $plugin = NetherMenus::getInstance();
        $dataFolder = $plugin->getGuiDataFolder();
        $file = $dataFolder . $this->guiName . ".yml";
        
        $cfg = new Config($file, Config::YAML);
        $data = $cfg->getAll();
        if (!is_array($data)) $data = [];
        $data['items'] = $data['items'] ?? [];
        // Find existing entry key for this slot
        $existingKey = null;
        foreach ($data['items'] as $k => $v) {
            $s = null;
            if (is_array($v) && isset($v['slot']) && is_numeric($v['slot'])) { $s = (int)$v['slot']; }
            elseif (is_numeric($k)) { $s = (int)$k; }
            if ($s === $slot) { $existingKey = $k; break; }
        }
        // Normalize action to the new string line format and append
        $line = '';
        if (isset($action['line']) && is_string($action['line'])) {
            $line = $action['line'];
        } elseif (isset($action['type'])) {
            // Legacy structured action -> convert
            switch ($action['type']) {
                case 'close_gui':
                    $line = '[Close]';
                    break;
                case 'open_gui':
                    $line = '[OpenGUI] ' . ($action['gui_name'] ?? '');
                    break;
                case 'command':
                    // Default to Player if no executor flag
                    $exec = isset($action['as']) && strtolower($action['as']) === 'console' ? 'Console' : 'Player';
                    $line = '[' . $exec . '] ' . ($action['command'] ?? '');
                    break;
            }
        }
        if ($line !== '') {
            $existing = $data['items'][$existingKey]['action'] ?? [];
            $merge = (bool)($action['merge'] ?? true);
            if ($merge) {
                if (is_string($existing)) { $existing = [$existing]; }
                if (!is_array($existing)) { $existing = []; }
                $existing[] = $line;
                $data['items'][$existingKey]['action'] = array_values($existing);
            } else {
                // Replace with only this line
                $data['items'][$existingKey]['action'] = [$line];
            }
        }
        // Keep current associative order; optional sorting can be applied if desired
        $cfg->setAll($data);
        $cfg->save();
        $plugin->reloadGui($this->guiName);
    }
    
    private function saveLoreToSlot(int $slot, array $lore): void {
        $plugin = NetherMenus::getInstance();
        $dataFolder = $plugin->getGuiDataFolder();
        $file = $dataFolder . $this->guiName . ".yml";
        
        // Initialize data array and load existing data
        $cfg = new Config($file, Config::YAML);
        $data = $cfg->getAll();
        if (!is_array($data)) $data = [];
        $data['items'] = $data['items'] ?? [];
        try {
            
            // Locate existing entry key for this slot to preserve arbitrary keys
            $existingKey = null;
            foreach ($data['items'] as $k => $v) {
                $s = null;
                if (is_array($v) && isset($v['slot']) && is_numeric($v['slot'])) { $s = (int)$v['slot']; }
                elseif (is_numeric($k)) { $s = (int)$k; }
                if ($s === $slot) { $existingKey = $k; break; }
            }
            if ($existingKey === null) { $existingKey = 'Slot_' . $slot; }
            if (!isset($data['items'][$existingKey]) || !is_array($data['items'][$existingKey])) { $data['items'][$existingKey] = []; }
             
            // Only update tooltip if we have at least a name or description
            if (!empty($lore['title']) || !empty($lore['description'])) {
                $title = is_string($lore['title'] ?? '') ? trim($lore['title']) : '';
                $description = $lore['description'] ?? '';
                // Set display_name from title
                if ($title !== '') {
                    $data['items'][$existingKey]['display_name'] = $title;
                } else {
                    unset($data['items'][$existingKey]['display_name']);
                }
                // Build lore lines from description only
                $lines = [];
                if (is_array($description)) {
                    foreach ($description as $idx => $line) {
                        $line = is_string($line) ? trim($line) : '';
                        if ($idx === 0 && $line === '') {
                            // Preserve intentional leading spacer
                            $lines[] = '';
                            continue;
                        }
                        if ($line !== '') { $lines[] = $line; }
                    }
                } else if (is_string($description)) {
                    // Support both '|' and newlines as separators
                    $parts = preg_split('/\||\n/', $description, -1, PREG_SPLIT_NO_EMPTY | PREG_SPLIT_DELIM_CAPTURE);
                    // Preserve a leading blank line if the description starts with a separator
                    if ($description !== '' && ($description[0] === '|' || $description[0] === "\n")) {
                        $lines[] = '';
                    }
                    foreach ($parts as $p) {
                        $p = trim((string)$p);
                        if ($p !== '') { $lines[] = $p; }
                    }
                }
                // Always include the lore key; use empty array when no description
                $data['items'][$existingKey]['lore'] = $lines;
                // Ensure slot field is set for this entry
                $data['items'][$existingKey]['slot'] = $slot;
                 
                // Save the data back to the file
                $cfg->setAll($data);
                $cfg->save();
                 
                // Update the item in the inventory with the new lore
                $item = $this->getItem($slot);
                if (!$item->isNull()) {
                    // Apply custom name and lore to the in-editor item
                    $item->setCustomName($data['items'][$existingKey]['display_name'] ?? " ");
                    $item->setLore($data['items'][$existingKey]['lore'] ?? []);
                    $this->setItem($slot, $item);
                }
                 
                $plugin->reloadGui($this->guiName);
            }
             
        } catch (\Throwable $e) {
            $plugin->getLogger()->error("Error saving lore to slot $slot: " . $e->getMessage());
            $plugin->getLogger()->logException($e);
        }
    }
    
    private function clearSlot(int $slot): void {
        $plugin = NetherMenus::getInstance();
        $dataFolder = $plugin->getGuiDataFolder();
        $file = $dataFolder . $this->guiName . ".yml";
        
        $cfg = new Config($file, Config::YAML);
        $data = $cfg->getAll();
        if (!is_array($data)) $data = [];
        $data['items'] = $data['items'] ?? [];
        
        // Find correct key by matching 'slot' field or numeric key
        $keyToRemove = null;
        foreach ($data['items'] as $k => $v) {
            $s = null;
            if (is_array($v) && isset($v['slot']) && is_numeric($v['slot'])) { $s = (int)$v['slot']; }
            elseif (is_numeric($k)) { $s = (int)$k; }
            if ($s === $slot) { $keyToRemove = $k; break; }
        }
        if ($keyToRemove !== null) {
            unset($data['items'][$keyToRemove]);
            $cfg->setAll($data);
            $cfg->save();
            $plugin->reloadGui($this->guiName);
        }
     }
} 