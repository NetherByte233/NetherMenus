<?php
namespace NetherByte\NetherMenus\requirement;

use NetherByte\PlaceholderAPI\PlaceholderAPI;
use pocketmine\player\Player;
use pocketmine\item\StringToItemParser;
use pocketmine\item\Item;
use NetherByte\NetherMenus\action\ActionExecutor;
use NetherByte\NetherMenus\requirement\RequirementRegistry;

class RequirementEvaluator {
    public const MAX_INT = 2147483647;

    public static function evaluateRequirementBlock(Player $player, array $block): array {
        // Normalization
        $requirements = isset($block['requirements']) && is_array($block['requirements']) ? $block['requirements'] : [];
        $minimum = isset($block['minimum_requirements']) && is_numeric($block['minimum_requirements']) ? max(1, (int)$block['minimum_requirements']) : null;
        $stopAtSuccess = (bool)($block['stop_at_success'] ?? false);

        $results = []; // name => ['pass'=>bool,'data'=>array]
        $passes = 0;
        $total = 0;

        foreach ($requirements as $name => $spec) {
            if (!is_array($spec)) continue;
            $total++;
            $ok = self::evaluateSingle($player, $spec);
            $results[$name] = ['pass' => $ok, 'spec' => $spec];
            if ($ok) {
                $passes++;
                if ($minimum !== null && $passes >= $minimum && $stopAtSuccess) {
                    // early stop after reaching minimum
                    break;
                }
            }
        }
        $overallPass = false;
        if ($minimum !== null) {
            $overallPass = ($passes >= $minimum);
        } else {
            // If minimum not specified, require all to pass (or zero requirements means pass)
            $overallPass = ($passes === $total);
        }
        return [
            'pass' => $overallPass,
            'results' => $results,
            'minimum' => $minimum,
            'stop_at_success' => $stopAtSuccess,
            'block' => $block,
        ];
    }

    private static function evaluateSingle(Player $player, array $spec): bool {
        $type = isset($spec['type']) ? strtolower(trim((string)$spec['type'])) : '';
        $negate = false;
        if ($type !== '' && $type[0] === '!') { $negate = true; $type = substr($type, 1); }
        // First try pluggable handlers via registry
        $ok = RequirementRegistry::get()->evaluate($player, $spec, $type);
        if ($ok !== null) {
            return $negate ? !$ok : $ok;
        }
        // Fallback to built-in switch
        $ok = false;
        switch ($type) {
            case 'has permission':
                $perm = (string)($spec['permission'] ?? '');
                $ok = ($perm !== '' && $player->hasPermission($perm));
                break;
            case 'has permissions':
                $perms = $spec['permissions'] ?? [];
                if (is_string($perms)) { $perms = [$perms]; }
                $count = 0; $total = 0;
                foreach ($perms as $p) { if (!is_string($p) || $p==='') continue; $total++; if ($player->hasPermission($p)) $count++; }
                $min = isset($spec['minimum']) && is_numeric($spec['minimum']) ? (int)$spec['minimum'] : $total;
                $ok = ($count >= $min);
                break;
            case 'has money':
                $amount = isset($spec['amount']) && is_numeric($spec['amount']) ? (float)$spec['amount'] : 0.0;
                // Use PlaceholderAPI expansion from PocketVault: %pocketvault_eco_balance%
                $balStr = PlaceholderAPI::parse('%pocketvault_eco_balance%', $player);
                $bal = is_numeric($balStr) ? (float)$balStr : 0.0;
                $ok = ($bal >= $amount);
                break;
            case 'has item':
                $ok = self::checkHasItem($player, $spec);
                break;
            case 'has meta':
                $ok = self::checkHasMeta($player, $spec);
                break;
            case 'has exp':
                $ok = self::checkHasExp($player, $spec);
                break;
            case 'is near':
                $ok = self::checkIsNear($player, $spec);
                break;
            case 'string equals':
                $in = (string)self::pp($spec['input'] ?? '', $player);
                $out = (string)self::pp($spec['output'] ?? '', $player);
                $ok = ($in === $out);
                break;
            case 'string equals ignorecase':
                $in = strtolower((string)self::pp($spec['input'] ?? '', $player));
                $out = strtolower((string)self::pp($spec['output'] ?? '', $player));
                $ok = ($in === $out);
                break;
            case 'string contains':
                $in = (string)self::pp($spec['input'] ?? '', $player);
                $out = (string)self::pp($spec['output'] ?? '', $player);
                $ok = ($out === '' ? true : (str_contains($in, $out)));
                break;
            case 'string length':
                $in = (string)self::pp($spec['input'] ?? '', $player);
                $len = strlen($in);
                $min = isset($spec['min']) && is_numeric($spec['min']) ? (int)$spec['min'] : null;
                $max = isset($spec['max']) && is_numeric($spec['max']) ? (int)$spec['max'] : null;
                $ok = true;
                if ($min !== null && $len < $min) $ok = false;
                if ($max !== null && $len > $max) $ok = false;
                break;
            case '==': case '!=': case '>=': case '<=': case '>': case '<':
                $ok = self::compareValues($player, $type, $spec['input'] ?? null, $spec['output'] ?? null);
                break;
            case 'javascript':
                $expr = (string)($spec['expression'] ?? '');
                $ok = self::evalExpression($player, $expr);
                break;
            default:
                // Unsupported types treated as false to be safe
                $ok = false;
        }
        return $negate ? !$ok : $ok;
    }

    public static function runDenyActions(Player $player, array $eval): void {
        $block = $eval['block'] ?? [];
        // Per-requirement deny_actions for failed ones
        $results = $eval['results'] ?? [];
        foreach ($results as $name => $info) {
            if (!($info['pass'] ?? false)) {
                $spec = $info['spec'] ?? [];
                if (isset($spec['deny_actions'])) {
                    self::executeActions($player, $spec['deny_actions']);
                }
            }
        }
        // List-level deny_actions
        if (isset($block['deny_actions'])) {
            self::executeActions($player, $block['deny_actions']);
        }
    }

    public static function runSuccessActions(Player $player, array $eval): void {
        $block = $eval['block'] ?? [];
        // Per-requirement success_actions for passed ones
        $results = $eval['results'] ?? [];
        foreach ($results as $name => $info) {
            if (($info['pass'] ?? false)) {
                $spec = $info['spec'] ?? [];
                if (isset($spec['success_actions'])) {
                    self::executeActions($player, $spec['success_actions']);
                }
            }
        }
        // List-level success_actions
        if (isset($block['success_actions'])) {
            self::executeActions($player, $block['success_actions']);
        }
    }

    public static function executeActions(Player $player, array|string $actions): void {
        ActionExecutor::executeActions($player, $actions);
    }

    // --- Helpers below ---

    private static function pp($value, Player $player): string {
        return PlaceholderAPI::parse((string)$value, $player);
    }

    private static function compareValues(Player $player, string $op, $inSpec, $outSpec): bool {
        $inRaw = self::pp($inSpec ?? '', $player);
        $outRaw = self::pp($outSpec ?? '', $player);
        $inNum = is_numeric($inRaw) ? (float)$inRaw : null;
        $outNum = is_numeric($outRaw) ? (float)$outRaw : null;
        if ($inNum !== null && $outNum !== null) {
            switch ($op) {
                case '==': return $inNum == $outNum;
                case '!=': return $inNum != $outNum;
                case '>=': return $inNum >= $outNum;
                case '<=': return $inNum <= $outNum;
                case '>':  return $inNum >  $outNum;
                case '<':  return $inNum <  $outNum;
            }
        } else {
            switch ($op) {
                case '==': return $inRaw === $outRaw;
                case '!=': return $inRaw !== $outRaw;
                case '>=': return $inRaw >= $outRaw;
                case '<=': return $inRaw <= $outRaw;
                case '>':  return $inRaw >  $outRaw;
                case '<':  return $inRaw <  $outRaw;
            }
        }
        return false;
    }

    private static function evalExpression(Player $player, string $expr): bool {
        $parsed = self::pp($expr, $player);
        // Allow only safe characters: numbers, ops, parentheses, spaces, decimal points, comparisons, booleans
        $safe = preg_replace('/[^0-9\s\.+\-*\/()%<>=!&|]/', '', $parsed);
        if ($safe === null) return false;
        // Prevent dangerous sequences
        if (preg_match('/={3,}|!{3,}|&{3,}|\|{3,}/', $safe)) return false;
        try {
            // Evaluate as PHP boolean expression
            // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged
            $result = @eval('return (bool)(' . $safe . ');');
            return (bool)$result;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private static function checkHasExp(Player $player, array $spec): bool {
        $amount = isset($spec['amount']) && is_numeric($spec['amount']) ? (int)$spec['amount'] : 0;
        $levelMode = (bool)($spec['level'] ?? false);
        $xpMgr = $player->getXpManager();
        if ($levelMode) {
            return $xpMgr->getXpLevel() >= $amount;
        }
        // PM does not expose total points directly; approximate using current total xp if available
        if (method_exists($xpMgr, 'getCurrentTotalXp')) {
            return $xpMgr->getCurrentTotalXp() >= $amount;
        }
        // Fallback: treat level as points multiplier (rough estimate)
        return ($xpMgr->getXpLevel() * 100) >= $amount;
    }

    private static function checkIsNear(Player $player, array $spec): bool {
        $loc = (string)($spec['location'] ?? '');
        $distReq = isset($spec['distance']) && is_numeric($spec['distance']) ? (float)$spec['distance'] : 0.0;
        if ($loc === '' || $distReq <= 0) return false;
        $parts = array_map('trim', explode(',', $loc));
        if (count($parts) !== 4) return false;
        [$worldName, $x, $y, $z] = $parts;
        $x = (float)$x; $y = (float)$y; $z = (float)$z;
        $world = $player->getServer()->getWorldManager()->getWorldByName($worldName);
        if ($world === null) return false;
        if ($player->getWorld() !== $world) return false;
        $p = $player->getPosition();
        $dx = $p->getX() - $x; $dy = $p->getY() - $y; $dz = $p->getZ() - $z;
        $dist = sqrt($dx*$dx + $dy*$dy + $dz*$dz);
        return ($dist <= $distReq);
    }

    private static function checkHasMeta(Player $player, array $spec): bool {
        // We treat 'key' as a placeholder to resolve and compare with 'value' via meta_type casting
        $key = (string)($spec['key'] ?? '');
        $metaType = strtoupper((string)($spec['meta_type'] ?? 'STRING'));
        $expected = $spec['value'] ?? null;
        if ($key === null || $key === '') return false;
        $resolved = self::pp($key, $player);
        // If expected is null => pass only if resolved is non-empty
        if ($expected === null) return ($resolved !== '');
        $expectedStr = self::pp((string)$expected, $player);
        switch ($metaType) {
            case 'BOOLEAN':
                $rv = filter_var($resolved, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                $ev = filter_var($expectedStr, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                return $rv === $ev;
            case 'DOUBLE':
            case 'LONG':
            case 'INTEGER':
                if (!is_numeric($resolved) || !is_numeric($expectedStr)) return false;
                return ((float)$resolved) == ((float)$expectedStr);
            case 'STRING':
            default:
                return $resolved === $expectedStr;
        }
    }

    private static function checkHasItem(Player $player, array $spec): bool {
        // Material parsing supports names/placeholders
        $materialSpec = isset($spec['material']) ? (string)$spec['material'] : '';
        $materialParsed = $materialSpec !== '' ? strtolower(self::pp($materialSpec, $player)) : '';
        $name = isset($spec['name']) ? (string)$spec['name'] : '';
        $name = $name !== '' ? self::pp($name, $player) : '';
        $loreSpec = $spec['lore'] ?? [];
        if (is_string($loreSpec)) { $loreSpec = [$loreSpec]; }
        $loreNeedles = [];
        foreach ($loreSpec as $ls) { if (is_string($ls)) $loreNeedles[] = self::pp($ls, $player); }
        $nameContains = (bool)($spec['name_contains'] ?? false);
        $nameIgnoreCase = (bool)($spec['name_ignorecase'] ?? false);
        $loreContains = (bool)($spec['lore_contains'] ?? false);
        $loreIgnoreCase = (bool)($spec['lore_ignorecase'] ?? false);
        $strict = (bool)($spec['strict'] ?? false);
        $armor = (bool)($spec['armor'] ?? false);
        $offhand = (bool)($spec['offhand'] ?? false);
        $amountNeed = isset($spec['amount']) && is_numeric($spec['amount']) ? max(1, (int)$spec['amount']) : 1;

        $targetItem = null;
        if ($materialParsed !== '') {
            $targetItem = StringToItemParser::getInstance()->parse($materialParsed);
        }

        $count = 0;
        $checkItem = function(Item $it) use ($targetItem, $strict, $name, $nameContains, $nameIgnoreCase, $loreNeedles, $loreContains, $loreIgnoreCase): bool {
            if ($it->isNull()) return false;
            if ($strict) {
                if ($it->hasCustomName() || !empty($it->getLore())) return false;
            }
            if ($targetItem !== null) {
                // Compare by type id and meta if possible
                if ($it->getTypeId() !== $targetItem->getTypeId()) return false;
            }
            if ($name !== '') {
                $have = $it->hasCustomName() ? $it->getCustomName() : '';
                if ($nameIgnoreCase) { $have = strtolower($have); $n = strtolower($name); } else { $n = $name; }
                if ($nameContains) {
                    if (!str_contains($have, $n)) return false;
                } else {
                    if ($have !== $n) return false;
                }
            }
            if (!empty($loreNeedles)) {
                $haveLore = $it->getLore();
                // flatten and compare
                $haveStr = implode("\n", $haveLore);
                foreach ($loreNeedles as $ln) {
                    $needle = $loreIgnoreCase ? strtolower($ln) : $ln;
                    $hay = $loreIgnoreCase ? strtolower($haveStr) : $haveStr;
                    if ($loreContains) {
                        if (!str_contains($hay, $needle)) return false;
                    } else {
                        if ($hay !== $needle) return false;
                    }
                }
            }
            return true;
        };

        // Check main inventory
        foreach ($player->getInventory()->getContents() as $slot => $it) {
            if ($checkItem($it)) { $count += $it->getCount(); if ($count >= $amountNeed) return true; }
        }
        if ($armor) {
            $ai = $player->getArmorInventory();
            foreach ([$ai->getHelmet(), $ai->getChestplate(), $ai->getLeggings(), $ai->getBoots()] as $it) {
                if ($checkItem($it)) { $count += $it->getCount(); if ($count >= $amountNeed) return true; }
            }
        }
        if ($offhand && method_exists($player, 'getOffHandInventory')) {
            $off = $player->getOffHandInventory()->getItem(0);
            if ($checkItem($off)) { $count += $off->getCount(); if ($count >= $amountNeed) return true; }
        }
        return ($count >= $amountNeed);
    }
}
