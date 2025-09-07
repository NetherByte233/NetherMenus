# NetherMenus

A modern PocketMine-MP v5 GUI framework powered by the InventoryUI virion and PlaceholderAPI. Create fully dynamic menus from YAML with placeholders, requirements, actions, live updates, sounds, and more.

## Plugin metadata (plugin.yml)
- name: `NetherMenus`
- api: `[5.0.0]`
- softdepend: `[PlaceholderAPI],[PocketVault]`
- permissions:
  - `nethermenus.command` (default: true) – allows using player open commands like `/gui` and any custom open commands
  - `nethermenus.admin` (default: op) – allows using `/guiadmin`

## Highlights
- Powerful YAML-driven GUIs with PlaceholderAPI support
- In-place refresh and scheduled updates (no close/reopen)
- Open/close hooks to run actions when the menu opens/closes
- Rich action system (console, player, permissions, economy, XP, sounds, open GUI, refresh)
- Flexible item materials (named IDs, NBT, placeholders, player’s equipment)
- Dynamic item attributes (amount, dynamic_amount, leather dye, armor trims)
- Requirements per GUI, per-view, per-click with success/deny actions
- Slot ranges, filler items, rows, and custom open commands

## Requirements
- PocketMine-MP API 5.x
- PlaceholderAPI and PocketVault (Listed as a softdepend in `plugin.yml`)
- InventoryUI virion (bundled in `src/NetherByte/NetherMenus/libs/`)
- Optional economy/permissions providers via PocketVault (for money/permission actions)

## Getting Started
- Place the plugin in your server’s `plugins/` folder.
- Start the server once; config/data folders are created.
- YAML GUIs live in: `plugins_data/NetherMenus/guis/*.yml`
- Use your configured command from `open_command` inside each GUI YAML to open a menu.

## Commands & Permissions
- `/gui` – Default opener used by some examples (depends on GUI config)
  - requires `nethermenus.command`
- `/guiadmin` – Admin menu for creating/managing GUIs
  - requires `nethermenus.admin` (default: op)
- Custom commands are registered from each GUI’s `open_command:` entry (excluding `gui` to avoid conflicts). These also require `nethermenus.command`.

## YAML Schema (summary)
Top-level keys:
- `id: string` – Logical id (defaults to file name)
- `name: string` – Display title (supports placeholders)
- `rows: 1..6` – Menu rows (size is rows * 9)
- `open_command: string | string[]` – Commands that open this GUI
- `open_actions: string | string[]` – Actions to run when the GUI opens
- `close_actions: string | string[]` – Actions to run when the GUI closes
- `update_interval: number` – Seconds between updates for items with `update: true`
- `filler_item: { material: string, slots: string | string[] }` – Background filler
- `items: { … }` – Item entries (map). Each entry supports:
  - `slot: number | string | string[]` – A slot or comma/range like `"0,5,10-15"`
  - `material: string` – See Materials section
  - `amount: number` – 1..64
  - `dynamic_amount: string` – Placeholder string resolving to 1..64
  - `display_name: string` – Name (placeholders supported)
  - `lore: string | string[]` – Lore lines (placeholders supported)
  - `tooltip: { display_name?:string, lore?:string|string[] }` – Alternative name/lore container
  - `update: bool` – If true, only name/lore placeholders update according to `update_interval`
  - `dye: string` – Leather color, e.g. `purple` or `#RRGGBB`
  - `trim_material: string` – Armor trim material id (e.g. `emerald`, `copper`)
  - `trim_pattern: string` – Armor trim pattern id (e.g. `host`, `coast`)
  - `priority: number` – Lower shows first when multiple entries target same slot
  - `view_requirement: { … }` – Requirement block evaluated to show this entry
  - `click_requirement: { … }` – Requirement block evaluated when clicked
  - `success_actions: string | string[]` – Actions to run on click success
  - `action` / `actions`: legacy alternate for success_actions

## Materials
- Named/identifier items (via `StringToItemParser`):
  - `STONE`, `OAK_PLANKS`, `minecraft:netherite_chestplate`, etc.
- Base64 NBT item: `material: nbt-"<base64-serialized-nbt>"`
- Player/equipment sources:
  - `main_hand` | `mainhand`
  - `off_hand` | `offhand`
  - `armor_helmet`, `armor_chestplate`, `armor_leggings`, `armor_boots`
- Placeholder-backed material:
  - `material: placeholder-%player_item_in_hand%`
  - `material: placeholder-%player_armor_helmet%`
  - Value may resolve to any identifier above or one of the dynamic keywords.

## Trims, Dye, and Amounts
- `dye: <color>` supports common names and hex, applied to leather items when possible.
- Trims: for armor items, set `trim_material` and `trim_pattern` using simple ids:
  - Materials: `amethyst, copper, diamond, emerald, gold, iron, quartz, redstone, netherite, lapis`
  - Patterns: `bolt, coast, dune, eye, flow, host, raiser, rib, sentry, shaper, silence, snout, spire, tide, vex, ward, wayfinder, wild`
- The plugin writes trim NBT via the bundled library and also a compatibility `minecraft:trim` tag to maximize client rendering.
- `dynamic_amount` lets you control stack size via placeholders (clamped 1..64).

## Actions
Write actions as strings: `[tag] args`
- Core:
  - `[close]` – Closes the GUI (runs `close_actions`)
  - `[opengui] <guiId>` – Opens another GUI (respects that GUI’s open requirement)
  - `[message] <text>` – Sends a message to the player
  - `[broadcast] <text>` – Broadcasts a message
  - `[chat] <text>` – Makes the player chat
  - `[console] <cmd>` – Runs a command as console
  - `[player] <cmd>` – Runs a command as player
- Economy (PocketVault):
  - `[givemoney] <amount>`
  - `[takemoney] <amount>`
- Permissions (PocketVault):
  - `[givepermission] <node>`
  - `[takepermission] <node>`
- Experience:
  - `[giveexp] <amount|amountL>` (append `L` to give levels)
  - `[takeexp] <amount|amountL>`
- Sounds (Bedrock ids):
  - `[sound] <id> [volume] [pitch]` – To player
  - `[broadcastsound] <id> [volume] [pitch]` – All players
  - `[broadcastsoundworld] <id> [volume] [pitch]` – Players in same world
  - Examples: `random.orb`, `random.pop`, `note.harp`, `beacon.activate`
- GUI:
  - `[refresh]` – Re-renders items in place (no close)

All action arguments are placeholder-parsed before execution.

## Requirements
- `open_requirement` at GUI level controls if a player may open the GUI.
- `view_requirement` on an item controls whether a candidate item for a slot is visible.
- `click_requirement` on an item is evaluated when clicked.
- Requirement blocks support success/deny actions via `RequirementEvaluator`.

## Auto Updates
- Set `update_interval: <seconds>` at GUI level.
- Mark items with `update: true` to refresh only their display name and lore via placeholders at each interval.
- Use `[refresh]` to fully rerender items in-place without closing.

## Filler Items
```
filler_item:
  material: light_gray_stained_glass_pane
  slots: 0-35
```
Fills the listed slots with the given item if empty.

## Example
```
name: Actions Menu
rows: 4
open_command: action
open_actions: '[sound] beacon.activate 1 1'
close_actions: '[sound] beacon.deactivate 1 1'
update_interval: 1
filler_item:
  material: light_gray_stained_glass_pane
  slots: 0-35
items:
  Slot_20:
    material: STONE
    slot: 20
    success_actions: '[refresh]'
  Slot_24:
    material: minecraft:clock
    slot: 24
    update: true
    display_name: Ping
    lore:
      - 'Ping: %player_ping%'
  Slot_26:
    material: minecraft:netherite_chestplate
    slot: 26
    trim_material: emerald
    trim_pattern: host
```

## Notes
- Sounds require Bedrock sound IDs (test with `/playsound <id> @s`).
- Economy/permissions actions require PocketVault with providers (e.g., BedrockEconomy, NetherPerms).
- InventoryUI RP must be installed and server should allow resource packs.

## Folder Structure
- `src/NetherByte/NetherMenus/` – Main code
- `src/NetherByte/NetherMenus/ui/gui/` – GUI runtime
- `src/NetherByte/NetherMenus/libs/` – Bundled virions/libs (InventoryUI, forms, trim library)
- `guis/*.yml` – Your GUI definitions (data folder)

---
If you need help or want to add new actions or requirement types, open an issue or ask for guidance.