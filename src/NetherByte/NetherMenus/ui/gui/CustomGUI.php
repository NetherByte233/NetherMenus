<?php

namespace NetherByte\NetherMenus\ui\gui;

use NetherByte\NetherMenus\libs\tedo0627\inventoryui\CustomInventory;
use NetherByte\NetherMenus\NetherMenus;
use NetherByte\NetherMenus\util\TextFormatter;
use NetherByte\NetherMenus\requirement\RequirementEvaluator;
use NetherByte\NetherMenus\action\ActionExecutor;
use NetherByte\PlaceholderAPI\PlaceholderAPI;
use pocketmine\plugin\Plugin;
use pocketmine\item\Item;
use pocketmine\item\StringToItemParser;
use pocketmine\item\VanillaItems;
use pocketmine\player\Player;
use pocketmine\utils\TextFormat as TF;
use pocketmine\color\Color;
use pocketmine\scheduler\ClosureTask;
use pocketmine\scheduler\TaskHandler;
use pocketmine\item\Armor;
use NetherByte\NetherMenus\libs\KRUNCHSHooT\LibTrimArmor\LibTrimArmor;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\StringTag;

class CustomGUI extends CustomInventory {
    
    private string $guiName;
    private array $guiData;
    private array $cachedItems = [];
    private array $slotEntries = [];
    // For each slot store list of candidates: [ ['data'=>array,'order'=>int,'priority'=>int], ... ]
    private array $slotCandidates = [];
    private Plugin $plugin;
    private string $displayNameTemplate;
    private bool $updateEnabled = false; // legacy GUI-level flag (unused now)
    private int $lastUpdateTick = 0;
    private int $updatePeriodTicks = 20; // default to 1s
    private ?TaskHandler $updateTask = null;
    private array $openActions = [];
    private array $closeActions = [];
    
    public function __construct(Plugin $plugin, string $guiName, array $guiData) {
        $this->plugin = $plugin;
        $this->guiName = $guiName;
        $this->guiData = $guiData;
        
        // Get display name from data, fallback to GUI ID if not found
        $displayName = $guiData['name'] ?? $guiData['__name'] ?? $guiName;
        $this->displayNameTemplate = $displayName;
        // Live update flag
        $this->updateEnabled = (bool)($guiData['update'] ?? false);
        $interval = $guiData['update_interval'] ?? null;
        if (is_numeric($interval)) {
            $sec = max(0.05, (float)$interval); // ensure sane min
            $this->updatePeriodTicks = (int)round($sec * 20);
        }
        // Actions on open/close
        $this->openActions = $this->normalizeActions($guiData['open_actions'] ?? null);
        $this->closeActions = $this->normalizeActions($guiData['close_actions'] ?? null);
        
        // Use rows from YAML schema (fallback to 6)
        $rows = isset($guiData['rows']) ? (int)$guiData['rows'] : (isset($guiData['__rows']) ? (int)$guiData['__rows'] : 6);
        $size = $rows * 9;
        
        parent::__construct($size, $displayName, $rows);
    }
    
    protected function getDisplayTitle(Player $player): string {
        return PlaceholderAPI::parse($this->displayNameTemplate, $player);
    }
    
    private function normalizeActions(mixed $spec): array {
        if ($spec === null) return [];
        if (is_string($spec)) return [$spec];
        if (is_array($spec)) {
            // support either list or legacy one-item map
            if (isset($spec['type'])) return [$spec];
            // else flatten strings
            $out = [];
            foreach ($spec as $v) { if (is_string($v)) { $out[] = $v; } }
            return $out;
        }
        return [];
    }
    
    /**
     * Evaluates this GUI's open_requirement for the given player.
     * Returns the full evaluation array from RequirementEvaluator::evaluateRequirementBlock.
     * If no requirement is defined, returns a passing result structure.
     */
    public function canPlayerOpen(Player $player): array {
        $openReq = $this->guiData['open_requirement'] ?? null;
        if (!is_array($openReq)) {
            return ['pass' => true, 'results' => [], 'block' => ['requirements' => []]];
        }
        return RequirementEvaluator::evaluateRequirementBlock($player, $openReq);
    }
    
    public function open(Player $player): void {
        // Note: do NOT perform open-requirement enforcement here; callers must check canPlayerOpen() before opening
        // This hook is invoked AFTER the window is already opened by InventoryUI
        // Clear any existing items
        $this->clearAll();
        
        // Rebuild candidates and render per-player visible entries
        $this->initializeCachedItems(true);
        for ($slot = 0; $slot < $this->getSize(); $slot++) {
            $entryWrap = $this->chooseVisibleCandidateFor($player, $slot);
            if ($entryWrap === null) continue;
            $entry = $entryWrap['data'];
            // Build material per-player if needed; else base
            $base = VanillaItems::AIR();
            if (is_array($entry) && isset($entry['material'])) {
                $mat = $this->createMaterialItemForPlayer($entry, $player);
                $base = $mat ?? VanillaItems::AIR();
            }
            // Derive tooltip (display_name + lore) from new 'tooltip' or legacy fields
            $tooltip = is_array($entry) ? ($entry['tooltip'] ?? null) : null;
            $nameTpl = null;
            $loreTpl = [];
            if (is_array($tooltip)) {
                if (isset($tooltip['display_name']) && is_string($tooltip['display_name'])) { $nameTpl = $tooltip['display_name']; }
                if (isset($tooltip['lore'])) {
                    $l = $tooltip['lore'];
                    if (is_string($l)) { $loreTpl = [$l]; }
                    elseif (is_array($l)) { $loreTpl = array_values(array_filter($l, 'is_string')); }
                }
            }
            if ($nameTpl === null && is_array($entry) && isset($entry['display_name']) && is_string($entry['display_name'])) { $nameTpl = $entry['display_name']; }
            if (empty($loreTpl) && is_array($entry) && isset($entry['lore'])) {
                $l = $entry['lore'];
                if (is_string($l)) { $loreTpl = [$l]; }
                elseif (is_array($l)) { $loreTpl = array_values(array_filter($l, 'is_string')); }
            }
            if ($nameTpl !== null) { $base->setCustomName(PlaceholderAPI::parse($nameTpl, $player)); }
            else if (!$base->hasCustomName()) { $base->setCustomName(" "); }
            if (!empty($loreTpl)) {
                $parsed = [];
                foreach ($loreTpl as $line) { $parsed[] = PlaceholderAPI::parse($line, $player); }
                // Append visual-only trim info if requested
                if (is_array($entry) && (isset($entry['trim_material']) || isset($entry['trim_pattern']))) {
                    $pat = isset($entry['trim_pattern']) ? (string)$entry['trim_pattern'] : 'unknown';
                    $mat = isset($entry['trim_material']) ? (string)$entry['trim_material'] : 'unknown';
                    $parsed[] = TF::ITALIC . TF::GRAY . 'Trim: ' . $pat . ' • ' . $mat;
                }
                $wrapped = TextFormatter::formatDescription($parsed);
                $base->setLore($wrapped);
            } else { $base->setLore([]); }
            $this->setItem($slot, $base);
        }
        // Apply filler items after all other items are processed
        $this->applyFillerItems();
        
        // Reset tick update baseline on open
        $this->lastUpdateTick = 0;
        
        // Run open_actions if any
        if (!empty($this->openActions)) {
            ActionExecutor::executeActions($player, $this->openActions);
        }
        
        // Start auto-updates if any item has update: true
        $this->setupAutoUpdateIfNeeded($player);
        // Window is already open at this point; no need to call setCurrentWindow() here.
    }
    
    /**
     * Refresh the GUI contents for the given player without closing/reopening the window.
     * This re-renders items with current placeholders and visibility rules.
     */
    public function refreshItems(Player $player): void {
        try {
            $this->clearAll();
            $this->initializeCachedItems(true);
            for ($slot = 0; $slot < $this->getSize(); $slot++) {
                $entryWrap = $this->chooseVisibleCandidateFor($player, $slot);
                if ($entryWrap === null) continue;
                $entry = $entryWrap['data'];
                $base = VanillaItems::AIR();
                if (is_array($entry) && isset($entry['material'])) {
                    $mat = $this->createMaterialItemForPlayer($entry, $player);
                    $base = $mat ?? VanillaItems::AIR();
                }
                $tooltip = is_array($entry) ? ($entry['tooltip'] ?? null) : null;
                $nameTpl = null; $loreTpl = [];
                if (is_array($tooltip)) {
                    if (isset($tooltip['display_name']) && is_string($tooltip['display_name'])) { $nameTpl = $tooltip['display_name']; }
                    if (isset($tooltip['lore'])) {
                        $l = $tooltip['lore'];
                        if (is_string($l)) { $loreTpl = [$l]; }
                        elseif (is_array($l)) { $loreTpl = array_values(array_filter($l, 'is_string')); }
                    }
                }
                if ($nameTpl === null && is_array($entry) && isset($entry['display_name']) && is_string($entry['display_name'])) { $nameTpl = $entry['display_name']; }
                if (empty($loreTpl) && is_array($entry) && isset($entry['lore'])) {
                    $l = $entry['lore'];
                    if (is_string($l)) { $loreTpl = [$l]; }
                    elseif (is_array($l)) { $loreTpl = array_values(array_filter($l, 'is_string')); }
                }
                if ($nameTpl !== null) { $base->setCustomName(PlaceholderAPI::parse($nameTpl, $player)); }
                else if (!$base->hasCustomName()) { $base->setCustomName(" "); }
                if (!empty($loreTpl)) {
                    $parsed = [];
                    foreach ($loreTpl as $line) { $parsed[] = PlaceholderAPI::parse($line, $player); }
                    // Append visual-only trim info if requested
                    if (is_array($entry) && (isset($entry['trim_material']) || isset($entry['trim_pattern']))) {
                        $pat = isset($entry['trim_pattern']) ? (string)$entry['trim_pattern'] : 'unknown';
                        $mat = isset($entry['trim_material']) ? (string)$entry['trim_material'] : 'unknown';
                        $parsed[] = TF::ITALIC . TF::GRAY . 'Trim: ' . $pat . ' • ' . $mat;
                    }
                    $wrapped = TextFormatter::formatDescription($parsed);
                    $base->setLore($wrapped);
                } else { $base->setLore([]); }
                $this->setItem($slot, $base);
            }
            $this->applyFillerItems();
        } catch (\Throwable $e) {
            // silent refresh failure to avoid closing the GUI
        }
    }
    
    /**
     * Called periodically to update only items which have 'update: true'.
     * Only updates display_name/lore placeholders; material stays the same.
     */
    private function refreshUpdateItems(Player $player): void {
        try {
            for ($slot = 0; $slot < $this->getSize(); $slot++) {
                $wrap = $this->chooseVisibleCandidateFor($player, $slot);
                if ($wrap === null) continue;
                $entry = $wrap['data'];
                if (!is_array($entry) || empty($entry['update'])) continue;
                $item = $this->getItem($slot);
                if ($item->isNull()) continue;
                $nameTpl = $entry['display_name'] ?? ($entry['tooltip']['display_name'] ?? null);
                $loreTpl = [];
                if (isset($entry['tooltip']['lore'])) { $loreTpl = is_array($entry['tooltip']['lore']) ? $entry['tooltip']['lore'] : [$entry['tooltip']['lore']]; }
                elseif (isset($entry['lore'])) { $l = $entry['lore']; $loreTpl = is_array($l) ? $l : [$l]; }
                if ($nameTpl !== null && is_string($nameTpl)) {
                    $item->setCustomName(PlaceholderAPI::parse($nameTpl, $player));
                }
                if (!empty($loreTpl)) {
                    $parsed = [];
                    foreach ($loreTpl as $line) { if (is_string($line)) $parsed[] = PlaceholderAPI::parse($line, $player); }
                    // Append visual-only trim info if requested
                    if (is_array($entry) && (isset($entry['trim_material']) || isset($entry['trim_pattern']))) {
                        $pat = isset($entry['trim_pattern']) ? (string)$entry['trim_pattern'] : 'unknown';
                        $mat = isset($entry['trim_material']) ? (string)$entry['trim_material'] : 'unknown';
                        $parsed[] = TF::ITALIC . TF::GRAY . 'Trim: ' . $pat . ' • ' . $mat;
                    }
                    $item->setLore(TextFormatter::formatDescription($parsed));
                }
                $this->setItem($slot, $item);
            }
        } catch (\Throwable $e) {}
    }
    
    private function setupAutoUpdateIfNeeded(Player $player): void {
        // Cancel previous task if any
        if ($this->updateTask !== null) { $this->updateTask->cancel(); $this->updateTask = null; }
        // Determine if any item uses update: true
        $items = $this->guiData['items'] ?? [];
        $needs = false;
        foreach ($items as $entry) {
            if (is_array($entry) && !empty($entry['update'])) { $needs = true; break; }
        }
        if (!$needs) return;
        $ticks = max(1, $this->updatePeriodTicks);
        $this->updateTask = $this->plugin->getScheduler()->scheduleRepeatingTask(new ClosureTask(function() use ($player) : void {
            if ($player->isOnline() && $player->getCurrentWindow() === $this) {
                $this->refreshUpdateItems($player);
            } else {
                // player left or closed, stop updates
                if ($this->updateTask !== null) { $this->updateTask->cancel(); $this->updateTask = null; }
            }
        }), $ticks);
    }
    
    /**
     * Run close-actions and cleanup schedulers. Call from listeners or when invoking [close].
     */
    public function runCloseActions(Player $player): void {
        if (!empty($this->closeActions)) {
            ActionExecutor::executeActions($player, $this->closeActions);
        }
        if ($this->updateTask !== null) { $this->updateTask->cancel(); $this->updateTask = null; }
    }
    
    private function applyFillerItems(): void {
        // YAML schema: filler_item: { material: string, slots: string|array }
        if (!isset($this->guiData['filler_item']) || !is_array($this->guiData['filler_item'])) {
            return;
        }
        $fi = $this->guiData['filler_item'];
        $material = isset($fi['material']) && is_string($fi['material']) ? trim($fi['material']) : '';
        $slotsSpec = $fi['slots'] ?? null;
        if ($material === '' || $slotsSpec === null) { return; }
        $slots = $this->parseSlotRanges($slotsSpec);
        if (empty($slots)) { return; }
        
        try {
            $item = null;
            if (preg_match('/^nbt-\s*"?([^"\s]+)"?\s*$/i', $material, $m)) {
                $b64 = $m[1] ?? '';
                if ($b64 !== '') {
                    try {
                        $nbt = unserialize(base64_decode($b64));
                        $item = Item::nbtDeserialize($nbt);
                    } catch (\Throwable $e) {
                        $item = VanillaItems::AIR();
                    }
                }
            }
            if ($item === null) {
                $parsed = StringToItemParser::getInstance()->parse(strtolower($material));
                $item = $parsed ?? VanillaItems::AIR();
            }
            if (!$item->hasCustomName()) { $item->setCustomName(' '); }
            foreach ($slots as $idx) {
                if ($idx >= 0 && $idx < $this->getSize()) {
                    // Only set filler if slot currently empty (AIR)
                    $current = $this->getItem($idx);
                    if ($current->isNull()) {
                        $this->setItem($idx, clone $item);
                    }
                }
            }
        } catch (\Throwable $e) {
            // swallow filler errors
        }
    }
    
    /**
     * Parse slot ranges from a string or array of strings.
     * Accepts formats like "0-8", "0,5,10", or arrays combining them.
     */
    private function parseSlotRanges(array|string $slotRanges): array {
        $slots = [];
        $ranges = is_array($slotRanges) ? $slotRanges : [$slotRanges];
        foreach ($ranges as $range) {
            if (!is_string($range)) {
                if (is_int($range)) { $slots[] = $range; }
                continue;
            }
            $range = trim($range);
            if ($range === '') continue;
            if (str_contains($range, '-')) {
                [$start, $end] = array_map('intval', explode('-', $range, 2));
                if ($end < $start) { [$start, $end] = [$end, $start]; }
                $slots = array_merge($slots, range($start, $end));
            } elseif (str_contains($range, ',')) {
                $specificSlots = array_map('intval', explode(',', $range));
                $slots = array_merge($slots, $specificSlots);
            } else {
                $slots[] = (int)$range;
            }
        }
        // Unique and sorted
        $slots = array_values(array_unique($slots));
        sort($slots);
        return $slots;
    }
 
    /**
     * Create a per-player Item from an entry's material spec.
     * Supports:
     *  - Inline NBT: material: nbt-"<base64-serialized-nbt>"
     *  - Dynamic sources: main_hand|mainhand, off_hand|offhand, armor_helmet|chestplate|leggings|boots
     *  - Placeholder-backed material: placeholder-%<placeholder>% (value may resolve to the above dynamic sources or any item identifier)
     *  - Static materials via StringToItemParser (e.g., STONE, OAK_PLANKS, minecraft:netherite_chestplate)
     */
    private function createMaterialItemForPlayer(array $entry, Player $player): ?Item {
        $materialRaw = isset($entry['material']) && is_string($entry['material']) ? trim($entry['material']) : '';
        if ($materialRaw === '') { return null; }
        $item = null;
        // Inline NBT format: nbt-"<base64>" (payload must not be lowercased)
        if (preg_match('/^nbt-\s*"?([^"\s]+)"?\s*$/i', $materialRaw, $m)) {
            $b64 = $m[1] ?? '';
            if ($b64 !== '') {
                try {
                    $nbt = unserialize(base64_decode($b64));
                    $item = Item::nbtDeserialize($nbt);
                } catch (\Throwable $e) {
                    $item = VanillaItems::AIR();
                }
            }
        }
        // Placeholder-backed material
        if ($item === null && preg_match('/^placeholder-(.+)$/i', $materialRaw, $mm)) {
            $ph = trim($mm[1]);
            if ($ph !== '') {
                $resolved = PlaceholderAPI::parse($ph, $player);
                if ($resolved !== '') { $materialRaw = $resolved; }
            }
        }
        if ($item === null) {
            $material = strtolower($materialRaw);
            switch ($material) {
                case 'main_hand':
                case 'mainhand':
                    $item = clone $player->getInventory()->getItemInHand();
                    break;
                case 'off_hand':
                case 'offhand':
                    $off = method_exists($player, 'getOffHandInventory') ? $player->getOffHandInventory() : null;
                    $item = $off !== null ? clone $off->getItem(0) : VanillaItems::AIR();
                    break;
                case 'armor_helmet':
                    $item = clone $player->getArmorInventory()->getHelmet();
                    break;
                case 'armor_chestplate':
                    $item = clone $player->getArmorInventory()->getChestplate();
                    break;
                case 'armor_leggings':
                    $item = clone $player->getArmorInventory()->getLeggings();
                    break;
                case 'armor_boots':
                    $item = clone $player->getArmorInventory()->getBoots();
                    break;
                default:
                    $parsed = StringToItemParser::getInstance()->parse($material);
                    $item = $parsed ?? VanillaItems::AIR();
            }
        }
        // amount and dynamic_amount
        if (isset($entry['dynamic_amount'])) {
            $val = PlaceholderAPI::parse((string)$entry['dynamic_amount'], $player);
            if (is_numeric($val)) { $item->setCount(max(1, min(64, (int)$val))); }
        } elseif (isset($entry['amount']) && is_numeric($entry['amount'])) {
            $item->setCount(max(1, min(64, (int)$entry['amount'])));
        }
        // Leather dye (best-effort)
        if (!empty($entry['dye']) && is_string($entry['dye'])) {
            $color = $this->parseColorName($entry['dye']);
            if ($color !== null && method_exists($item, 'setCustomColor')) {
                try { $item->setCustomColor($color); } catch (\Throwable $e) {}
            }
        }
        // Armor trim using bundled LibTrimArmor (writes Trim NBT the client understands)
        if ($item instanceof Armor && (!empty($entry['trim_material']) || !empty($entry['trim_pattern']))) {
            $matIn = isset($entry['trim_material']) ? (string)$entry['trim_material'] : '';
            $patIn = isset($entry['trim_pattern']) ? (string)$entry['trim_pattern'] : '';
            // Allow placeholders inside trim values
            if ($matIn !== '') { $matIn = PlaceholderAPI::parse($matIn, $player); }
            if ($patIn !== '') { $patIn = PlaceholderAPI::parse($patIn, $player); }
            $mat = $this->normalizeTrimMaterial($matIn);
            $pat = $this->normalizeTrimPattern($patIn);
            if ($mat !== null && $pat !== null) {
                try { LibTrimArmor::create($item, $mat, $pat); } catch (\Throwable $e) { /* ignore */ }
                // Also write Bedrock-style lowercase component tag for compatibility
                $this->applyTrimNbtFallback($item, $mat, $pat);
            }
        }
        // Ensure name is non-empty (client-side aesthetics); lore applied later
        if (!$item->hasCustomName()) { $item->setCustomName(' '); }
        return $item;
    }
    
    private function parseColorName(string $name): ?Color {
        $n = strtolower(trim($name));
        $map = [
            'white'=>[0xFF,0xFF,0xFF], 'black'=>[0,0,0], 'red'=>[0xFF,0,0], 'green'=>[0,0x80,0], 'blue'=>[0,0,0xFF],
            'yellow'=>[0xFF,0xFF,0], 'purple'=>[0x80,0,0x80], 'pink'=>[0xFF,0xC0,0xCB], 'cyan'=>[0,0xFF,0xFF], 'orange'=>[0xFF,0xA5,0x00],
            'lime'=>[0x00,0xFF,0x00], 'magenta'=>[0xFF,0x00,0xFF], 'gray'=>[0x80,0x80,0x80], 'light_gray'=>[0xD3,0xD3,0xD3]
        ];
        if (isset($map[$n])) { [$r,$g,$b] = $map[$n]; return new Color($r,$g,$b); }
        if (preg_match('/^#?([0-9a-fA-F]{6})$/', $n, $m)) {
            $hex = hexdec($m[1]);
            return new Color(($hex>>16)&0xFF, ($hex>>8)&0xFF, $hex&0xFF);
        }
        return null;
    }
    
    /**
     * Map various user-provided material strings to valid LibTrimArmor materials (lowercase).
     */
    private function normalizeTrimMaterial(string $raw): ?string {
        $s = strtolower(trim($raw));
        // strip obvious prefixes/suffixes
        $s = preg_replace('/^material[.:\s_\-]*/', '', $s);
        $s = preg_replace('/_armor_trim_smithing_template$/', '', $s);
        $s = str_replace(['minecraft:', 'trim_', 'armor_trim_', 'smithing_template', '.', ' '], ['', '', '', '', '', ''], $s);
        // map aliases
        $map = [
            'amethyst'=>'amethyst', 'copper'=>'copper', 'diamond'=>'diamond', 'emerald'=>'emerald',
            'gold'=>'gold', 'golden'=>'gold', 'iron'=>'iron', 'quartz'=>'quartz', 'redstone'=>'redstone',
            'netherite'=>'netherite', 'lapis'=>'lapis', 'lapis_lazuli'=>'lapis', 'lapislazuli'=>'lapis'
        ];
        return $map[$s] ?? null;
    }
    
    /**
     * Map various user-provided pattern strings to valid LibTrimArmor patterns (lowercase).
     */
    private function normalizeTrimPattern(string $raw): ?string {
        $s = strtolower(trim($raw));
        // remove common words/suffixes
        $s = preg_replace('/^material[.:\s_\-]*/', '', $s);
        $s = str_replace(['minecraft:', 'trim_', 'armor_trim_', 'smithing_template', '.', ' '], ['', '', '', '', '', ''], $s);
        // Common java-style names like HOST_ARMOR_TRIM_SMITHING_TEMPLATE -> host
        $s = preg_replace('/_armor_trim_smithing_template$/', '', $s);
        // Accept exact library pattern ids
        $valid = [
            'bolt','coast','dune','eye','flow','host','raiser','rib','sentry','shaper',
            'silence','snout','spire','tide','vex','ward','wayfinder','wild'
        ];
        // Some alternative aliases
        $aliases = [
            'spire_armor_trim'=>'spire', 'ward_armor_trim'=>'ward', 'vex_armor_trim'=>'vex',
            'host_armor_trim'=>'host', 'sentry_armor_trim'=>'sentry', 'tide_armor_trim'=>'tide',
            'coast_armor_trim'=>'coast', 'wild_armor_trim'=>'wild', 'wayfinder_armor_trim'=>'wayfinder',
            'snout_armor_trim'=>'snout', 'silence_armor_trim'=>'silence', 'dune_armor_trim'=>'dune',
            'eye_armor_trim'=>'eye', 'flow_armor_trim'=>'flow', 'shaper_armor_trim'=>'shaper',
            'rib_armor_trim'=>'rib', 'raiser_armor_trim'=>'raiser', 'bolt_armor_trim'=>'bolt'
        ];
        if (isset($aliases[$s])) { return $aliases[$s]; }
        return in_array($s, $valid, true) ? $s : null;
    }
    
    /**
     * Some clients expect a lowercase component tag `minecraft:trim` with keys `material` and `pattern`.
     * This writes a compatible structure in addition to the library's tag to maximize rendering chances.
     */
    private function applyTrimNbtFallback(Item $item, string $material, string $pattern): void {
        try {
            $nbt = $item->getNamedTag();
            // Preferred component-like structure
            $nbt->setTag('minecraft:trim', CompoundTag::create()
                ->setTag('material', new StringTag($material))
                ->setTag('pattern', new StringTag($pattern))
            );
            // Also set a generic fallback with lowercase keys (in case some packs look for it)
            $nbt->setTag('trim', CompoundTag::create()
                ->setTag('material', new StringTag($material))
                ->setTag('pattern', new StringTag($pattern))
            );
            $item->setNamedTag($nbt);
        } catch (\Throwable $e) {
            // ignore
        }
    }
    
    private function initializeCachedItems(bool $reset = false): void {
        if ($reset) {
            $this->cachedItems = [];
            $this->slotEntries = [];
            $this->slotCandidates = [];
        }
        // First, process all defined items from YAML 'items' map
        $allItems = $this->guiData['items'] ?? [];
        
        // Helper to expand slot specifications into a list of indices
        $max = max(0, $this->getSize() - 1);
        $expandSlots = null;
        $expandSlots = function($slotSpec) use (&$expandSlots, $max) {
            $result = [];
            $add = function(int $n) use (&$result, $max) {
                if ($n >= 0 && $n <= $max) { $result[$n] = true; }
            };
            if (is_int($slotSpec) || (is_numeric($slotSpec) && $slotSpec !== '')) {
                $add((int)$slotSpec);
            } elseif (is_string($slotSpec)) {
                $spec = trim($slotSpec);
                if ($spec !== '') {
                    foreach (preg_split('/\s*,\s*/', $spec) as $part) {
                        if ($part === '') continue;
                        if (preg_match('/^(\d+)\s*-\s*(\d+)$/', $part, $m)) {
                            $a = (int)$m[1]; $b = (int)$m[2];
                            if ($a <= $b) { for ($i = $a; $i <= $b; $i++) { $add($i); } }
                            else { for ($i = $a; $i >= $b; $i--) { $add($i); } }
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
        
        // Build candidate list per slot (we no longer decide winner here)
        $candidates = []; // slot => [ ['data'=>array,'order'=>int,'priority'=>int], ... ]
        $order = 0;
        foreach ($allItems as $slotKey => $data) {
            // Determine the slot specification from new or legacy format
            $slotSpec = null;
            if (is_array($data) && array_key_exists('slot', $data)) {
                $slotSpec = $data['slot'];
            } elseif (is_numeric($slotKey)) {
                $slotSpec = (int)$slotKey; // legacy format single slot
            } else {
                continue; // invalid entry (no slot information)
            }
            $slotIndices = $expandSlots($slotSpec);
            if (count($slotIndices) === 0) { continue; }
            
            $prio = isset($data['priority']) && is_numeric($data['priority']) ? (int)$data['priority'] : PHP_INT_MAX;
            foreach ($slotIndices as $slotIndex) {
                $candidates[$slotIndex][] = ['data'=>$data, 'order'=>$order, 'priority'=>$prio];
            }
            $order++;
        }

        // Order candidates: priority ASC (0 highest), for equal priority prefer last-defined -> order DESC
        foreach ($candidates as $slotIndex => $list) {
            usort($list, function($a, $b) {
                if ($a['priority'] === $b['priority']) {
                    return ($a['order'] === $b['order']) ? 0 : (($a['order'] < $b['order']) ? 1 : -1);
                }
                return ($a['priority'] < $b['priority']) ? -1 : 1;
            });
            $this->slotCandidates[$slotIndex] = $list;
        }
    }
    
    public function click(Player $player, int $slot, Item $sourceItem, Item $targetItem): bool {
        // Determine which candidate is currently visible to this player
        $wrap = $this->chooseVisibleCandidateFor($player, $slot);
        if ($wrap !== null) {
            $entry = $wrap['data'];
            // Evaluate click requirement if any
            $clickReq = is_array($entry) ? ($entry['click_requirement'] ?? null) : null;
            if (is_array($clickReq)) {
                $eval = RequirementEvaluator::evaluateRequirementBlock($player, $clickReq);
                if ($eval['pass']) {
                    RequirementEvaluator::runSuccessActions($player, $eval);
                    // Run item-level success_actions if present
                    if (isset($entry['success_actions'])) {
                        $this->executeActions($player, $entry['success_actions']);
                    }
                } else {
                    RequirementEvaluator::runDenyActions($player, $eval);
                }
                return true;
            }
            // No click requirement: execute legacy or success_actions directly
            $actionSpec = $entry['success_actions'] ?? ($entry['action'] ?? ($entry['actions'] ?? null));
            if ($actionSpec !== null) { $this->executeActions($player, $actionSpec); }
        }
        return true; // Always prevent item movement
    }
    
    private function executeActions(Player $player, array|string $actionSpec): void {
        // Support legacy structured action OR new list of strings
        if (is_array($actionSpec) && isset($actionSpec['type'])) {
            // Legacy single action
            $this->executeLegacyAction($player, $actionSpec);
            return;
        }
        ActionExecutor::executeActions($player, $actionSpec);
    }
    
    private function executeLegacyAction(Player $player, array $actionSpec): void {
        // Legacy single action
        // TODO: Remove this method once legacy actions are phased out
        // For now, delegate to ActionExecutor
        ActionExecutor::executeActions($player, [$actionSpec]);
    }
    
    private function chooseVisibleCandidateFor(Player $player, int $slot): ?array {
        $list = $this->slotCandidates[$slot] ?? null;
        if (!is_array($list) || empty($list)) return null;
        foreach ($list as $wrap) {
            $entry = $wrap['data'];
            $viewReq = is_array($entry) ? ($entry['view_requirement'] ?? null) : null;
            if (!is_array($viewReq)) {
                return $wrap; // no requirement => visible
            }
            $eval = RequirementEvaluator::evaluateRequirementBlock($player, $viewReq);
            if ($eval['pass']) {
                // We could run success_actions for view if desired, but spec says only open/click run actions
                return $wrap;
            }
            // Run deny actions for this failed view requirement before trying next candidate
            RequirementEvaluator::runDenyActions($player, $eval);
            // else try next candidate (lower priority)
        }
        return null;
    }
}