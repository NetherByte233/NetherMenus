<?php

namespace NetherByte\NetherMenus\action;

use NetherByte\NetherMenus\NetherMenus;
use NetherByte\NetherMenus\requirement\RequirementEvaluator;
use pocketmine\player\Player;
use pocketmine\network\mcpe\protocol\PlaySoundPacket;
use NetherByte\NetherMenus\ui\gui\CustomGUI;
use pocketmine\scheduler\ClosureTask;

/**
 * Execute action lines in the common [tag] arg format.
 * Supported tags: close, opengui, message, broadcast, chat, console, player, givepermission, takepermission, givemoney, takemoney, giveexp, takeexp, broadcastsound, broadcastsoundworld, sound, refresh
 */
class ActionExecutor {
    public static function executeActions(Player $player, array|string $actions): void {
        $plugin = NetherMenus::getInstance();
        $lines = is_array($actions) ? $actions : [$actions];
        foreach ($lines as $line) {
            if (!is_string($line)) continue;
            $line = trim($line);
            if ($line === '') continue;
            if (preg_match('/^\[(?<tag>[^\]]+)\]\s*(?<arg>.*)$/', $line, $m)) {
                $tag = strtolower(trim($m['tag']));
                $rawArg = trim((string)$m['arg']);
                // Extract optional action tags like <delay=20><chance=50> appended to arg (order-insensitive)
                $delayTicks = 0; // 0 = immediate
                $chance = 100.0; // default always
                if ($rawArg !== '') {
                    // Find all <key=value> pairs and strip them from the argument
                    if (preg_match_all('/<([a-zA-Z_]+)\s*=\s*([^>]+)>/', $rawArg, $tm, PREG_SET_ORDER)) {
                        foreach ($tm as $t) {
                            $k = strtolower($t[1]);
                            $v = trim($t[2]);
                            if ($k === 'delay') {
                                if (is_numeric($v)) { $delayTicks = max(0, (int)$v); }
                            } elseif ($k === 'chance') {
                                if (is_numeric($v)) { $chance = max(0.0, min(100.0, (float)$v)); }
                            }
                            // remove this tag token from rawArg
                            $rawArg = str_replace($t[0], '', $rawArg);
                        }
                        $rawArg = trim($rawArg);
                    }
                }
                // Apply chance gating
                if ($chance < 100.0) {
                    // random 0..100 inclusive threshold
                    $roll = mt_rand(0, 10000) / 100.0; // 2 decimal precision
                    if ($roll > $chance) {
                        continue; // skip this action
                    }
                }
                // Now parse placeholders only on the argument content without the <...> tags
                $arg = $rawArg;
                if ($arg !== '') {
                    if (class_exists(\NetherByte\PlaceholderAPI\PlaceholderAPI::class)) {
                        $arg = \NetherByte\PlaceholderAPI\PlaceholderAPI::parse($arg, $player);
                    }
                }

                // Define a runner closure to execute the action, to support delay scheduling
                $runner = function() use ($tag, $arg, $player, $plugin) : void {
                    switch ($tag) {
                        case 'close':
                            $win = $player->getCurrentWindow();
                            if ($win instanceof CustomGUI) {
                                $win->runCloseActions($player);
                            }
                            $player->removeCurrentWindow();
                            break;
                        case 'opengui':
                            if ($arg !== '') {
                                $targetGui = $plugin->createGUIFromData($arg);
                                if ($targetGui !== null) {
                                    // Enforce target GUI open requirement before opening
                                    $eval = $targetGui->canPlayerOpen($player);
                                    if ($eval['pass']) {
                                        RequirementEvaluator::runSuccessActions($player, $eval);
                                        $player->removeCurrentWindow();
                                        $plugin->setCurrentMenu($player, $arg);
                                        $player->setCurrentWindow($targetGui);
                                    } else {
                                        RequirementEvaluator::runDenyActions($player, $eval);
                                    }
                                } else {
                                    $player->sendMessage("§cGUI '$arg' not found!");
                                }
                            }
                            break;
                        case 'message':
                            if ($arg !== '') { $player->sendMessage($arg); }
                            break;
                        case 'broadcast':
                            if ($arg !== '') { $player->getServer()->broadcastMessage($arg); }
                            break;
                        case 'chat':
                            if ($arg !== '') { $player->chat($arg); }
                            break;
                        case 'console':
                            if ($arg !== '') {
                                $server = $player->getServer();
                                $sender = new \pocketmine\console\ConsoleCommandSender($server, $server->getLanguage());
                                $server->dispatchCommand($sender, $arg);
                            }
                            break;
                        case 'player':
                            if ($arg !== '') { $player->getServer()->dispatchCommand($player, $arg); }
                            break;
                        case 'givepermission':
                            if ($arg !== '') {
                                $perm = $arg;
                                $prov = null;
                                if (class_exists(\NetherByte\PocketVault\API\PocketVaultAPI::class)) {
                                    $prov = \NetherByte\PocketVault\API\PocketVaultAPI::getPermissions();
                                }
                                if ($prov !== null) {
                                    // Use player name to maximize compatibility across providers
                                    $ok = $prov->playerAdd($player->getName(), $perm, true);
                                    if (!$ok) {
                                        $player->sendMessage("§cFailed to add permission via provider.");
                                    }
                                } else {
                                    $player->sendMessage("§cPermissions provider not available (PocketVault).");
                                }
                            }
                            break;
                        case 'takepermission':
                            if ($arg !== '') {
                                $perm = $arg;
                                $prov = null;
                                if (class_exists(\NetherByte\PocketVault\API\PocketVaultAPI::class)) {
                                    $prov = \NetherByte\PocketVault\API\PocketVaultAPI::getPermissions();
                                }
                                if ($prov !== null) {
                                    // Use player name to maximize compatibility across providers
                                    $prov->playerRemove($player->getName(), $perm);
                                } else {
                                    $player->sendMessage("§cPermissions provider not available (PocketVault).");
                                }
                            }
                            break;
                        case 'givemoney':
                            if ($arg !== '' && is_numeric($arg)) {
                                $amount = (float)$arg;
                                $eco = null;
                                if (class_exists(\NetherByte\PocketVault\API\PocketVaultAPI::class)) {
                                    $eco = \NetherByte\PocketVault\API\PocketVaultAPI::getEconomy();
                                }
                                if ($eco !== null) {
                                    $eco->depositPlayer($player, $amount, function() : void {}, function(string $e) use ($player) : void {
                                        $player->sendMessage("§cEconomy error: $e");
                                    });
                                } else {
                                    $player->sendMessage("§cEconomy provider not available (PocketVault).");
                                }
                            }
                            break;
                        case 'takemoney':
                            if ($arg !== '' && is_numeric($arg)) {
                                $amount = (float)$arg;
                                if ($amount < 0) { $amount = -$amount; }
                                $eco = null;
                                if (class_exists(\NetherByte\PocketVault\API\PocketVaultAPI::class)) {
                                    $eco = \NetherByte\PocketVault\API\PocketVaultAPI::getEconomy();
                                }
                                if ($eco !== null) {
                                    $eco->withdrawPlayer($player, $amount, function() : void {}, function(string $e) use ($player) : void {
                                        $player->sendMessage("§cEconomy error: $e");
                                    });
                                } else {
                                    $player->sendMessage("§cEconomy provider not available (PocketVault).");
                                }
                            }
                            break;
                        case 'giveexp':
                            if ($arg !== '') {
                                $isLevel = false;
                                $raw = $arg;
                                if (str_ends_with(strtolower($raw), 'l')) { $isLevel = true; $raw = substr($raw, 0, -1); }
                                if (is_numeric($raw)) {
                                    $amt = (int)$raw;
                                    $xp = $player->getXpManager();
                                    if ($isLevel) {
                                        if (method_exists($xp, 'addXpLevels')) { $xp->addXpLevels($amt); }
                                        else { $xp->setXpLevel($xp->getXpLevel() + $amt); }
                                    } else {
                                        if (method_exists($xp, 'addXp')) { $xp->addXp($amt); }
                                    }
                                }
                            }
                            break;
                        case 'takeexp':
                            if ($arg !== '') {
                                $isLevel = false;
                                $raw = $arg;
                                if (str_ends_with(strtolower($raw), 'l')) { $isLevel = true; $raw = substr($raw, 0, -1); }
                                if (is_numeric($raw)) {
                                    $amt = (int)$raw;
                                    if ($amt < 0) { $amt = -$amt; }
                                    $xp = $player->getXpManager();
                                    if ($isLevel) {
                                        if (method_exists($xp, 'subtractXpLevels')) { $xp->subtractXpLevels($amt); }
                                        else if (method_exists($xp, 'addXpLevels')) { $xp->addXpLevels(-$amt); }
                                        else { $xp->setXpLevel(max(0, $xp->getXpLevel() - $amt)); }
                                    } else {
                                        if (method_exists($xp, 'subtractXp')) { $xp->subtractXp($amt); }
                                        else if (method_exists($xp, 'addXp')) { $xp->addXp(-$amt); }
                                    }
                                }
                            }
                            break;
                        case 'broadcastsound':
                            // arg: <sound> <volume> <pitch>
                            if ($arg === '') { $player->sendMessage("§c[sound] requires an identifier, e.g. random.pop"); break; }
                            $parts = preg_split('/\s+/', $arg);
                            $sound = (string)($parts[0] ?? '');
                            $volume = isset($parts[1]) && is_numeric($parts[1]) ? (float)$parts[1] : 1.0;
                            $pitch = isset($parts[2]) && is_numeric($parts[2]) ? (float)$parts[2] : 1.0;
                            if ($sound !== '') {
                                $loc = $player->getLocation();
                                $pk = PlaySoundPacket::create($sound, $loc->getX(), $loc->getY(), $loc->getZ(), $volume, $pitch);
                                foreach ($player->getServer()->getOnlinePlayers() as $p) {
                                    $p->getNetworkSession()->sendDataPacket($pk);
                                }
                            }
                            break;
                        case 'broadcastsoundworld':
                            if ($arg === '') { $player->sendMessage("§c[sound] requires an identifier, e.g. random.pop"); break; }
                            $parts = preg_split('/\s+/', $arg);
                            $sound = (string)($parts[0] ?? '');
                            $volume = isset($parts[1]) && is_numeric($parts[1]) ? (float)$parts[1] : 1.0;
                            $pitch = isset($parts[2]) && is_numeric($parts[2]) ? (float)$parts[2] : 1.0;
                            if ($sound !== '') {
                                $loc = $player->getLocation();
                                $pk = PlaySoundPacket::create($sound, $loc->getX(), $loc->getY(), $loc->getZ(), $volume, $pitch);
                                foreach ($player->getWorld()->getPlayers() as $p) {
                                    $p->getNetworkSession()->sendDataPacket($pk);
                                }
                            }
                            break;
                        case 'sound':
                            if ($arg === '') { $player->sendMessage("§c[sound] requires an identifier, e.g. random.pop"); break; }
                            $parts = preg_split('/\s+/', $arg);
                            $sound = (string)($parts[0] ?? '');
                            $volume = isset($parts[1]) && is_numeric($parts[1]) ? (float)$parts[1] : 1.0;
                            $pitch = isset($parts[2]) && is_numeric($parts[2]) ? (float)$parts[2] : 1.0;
                            if ($sound !== '') {
                                $loc = $player->getLocation();
                                $pk = PlaySoundPacket::create($sound, $loc->getX(), $loc->getY(), $loc->getZ(), $volume, $pitch);
                                $player->getNetworkSession()->sendDataPacket($pk);
                            }
                            break;
                        case 'refresh':
                            $win = $player->getCurrentWindow();
                            if ($win instanceof CustomGUI) {
                                // In-place refresh: do not close the window
                                $win->refreshItems($player);
                            }
                            break;
                        default:
                            $player->sendMessage("§cUnknown action tag: [$tag]");
                    }
                };

                if ($delayTicks > 0) {
                    // Schedule delayed execution
                    $plugin->getScheduler()->scheduleDelayedTask(new ClosureTask($runner), $delayTicks);
                } else {
                    $runner();
                }
            }
        }
    }
}
