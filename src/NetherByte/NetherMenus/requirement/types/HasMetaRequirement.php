<?php

namespace NetherByte\NetherMenus\requirement\types;

use NetherByte\NetherMenus\requirement\RequirementHandler;
use NetherByte\PlaceholderAPI\PlaceholderAPI;
use pocketmine\player\Player;

class HasMetaRequirement implements RequirementHandler {
    public function matches(Player $player, array $spec, string $type): ?bool {
        if (strtolower($type) !== 'has meta') {
            return null;
        }
        $key = (string)($spec['key'] ?? '');
        $metaType = strtoupper((string)($spec['meta_type'] ?? 'STRING'));
        $expected = $spec['value'] ?? null;
        if ($key === '') return false;
        $resolved = PlaceholderAPI::parse($key, $player);
        if ($expected === null) return ($resolved !== '');
        $expectedStr = PlaceholderAPI::parse((string)$expected, $player);
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
}
