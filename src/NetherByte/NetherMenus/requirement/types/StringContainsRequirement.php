<?php

namespace NetherByte\NetherMenus\requirement\types;

use NetherByte\NetherMenus\requirement\RequirementHandler;
use NetherByte\PlaceholderAPI\PlaceholderAPI;
use pocketmine\player\Player;

class StringContainsRequirement implements RequirementHandler {
    public function matches(Player $player, array $spec, string $type): ?bool {
        if (strtolower($type) !== 'string contains') {
            return null;
        }
        $in = (string)PlaceholderAPI::parse((string)($spec['input'] ?? ''), $player);
        $out = (string)PlaceholderAPI::parse((string)($spec['output'] ?? ''), $player);
        if ($out === '') return true;
        return str_contains($in, $out);
    }
}
