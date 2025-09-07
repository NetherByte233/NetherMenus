<?php

namespace NetherByte\NetherMenus\requirement\types;

use NetherByte\NetherMenus\requirement\RequirementHandler;
use NetherByte\PlaceholderAPI\PlaceholderAPI;
use pocketmine\player\Player;

class StringLengthRequirement implements RequirementHandler {
    public function matches(Player $player, array $spec, string $type): ?bool {
        if (strtolower($type) !== 'string length') {
            return null;
        }
        $in = (string)PlaceholderAPI::parse((string)($spec['input'] ?? ''), $player);
        $len = strlen($in);
        $min = isset($spec['min']) && is_numeric($spec['min']) ? (int)$spec['min'] : null;
        $max = isset($spec['max']) && is_numeric($spec['max']) ? (int)$spec['max'] : null;
        if ($min !== null && $len < $min) return false;
        if ($max !== null && $len > $max) return false;
        return true;
    }
}
