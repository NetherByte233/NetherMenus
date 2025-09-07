<?php

namespace NetherByte\NetherMenus\requirement\types;

use NetherByte\NetherMenus\requirement\RequirementHandler;
use NetherByte\PlaceholderAPI\PlaceholderAPI;
use pocketmine\item\Item;
use pocketmine\item\StringToItemParser;
use pocketmine\player\Player;

class HasItemRequirement implements RequirementHandler {
    public function matches(Player $player, array $spec, string $type): ?bool {
        if (strtolower($type) !== 'has item') {
            return null;
        }
        $materialSpec = isset($spec['material']) ? (string)$spec['material'] : '';
        $materialParsed = $materialSpec !== '' ? strtolower(PlaceholderAPI::parse($materialSpec, $player)) : '';
        $name = isset($spec['name']) ? (string)$spec['name'] : '';
        $name = $name !== '' ? PlaceholderAPI::parse($name, $player) : '';
        $loreSpec = $spec['lore'] ?? [];
        if (is_string($loreSpec)) { $loreSpec = [$loreSpec]; }
        $loreNeedles = [];
        foreach ($loreSpec as $ls) { if (is_string($ls)) $loreNeedles[] = PlaceholderAPI::parse($ls, $player); }
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

        foreach ($player->getInventory()->getContents() as $it) {
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
