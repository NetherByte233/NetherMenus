<?php

namespace NetherByte\NetherMenus\requirement\types;

use NetherByte\NetherMenus\requirement\RequirementHandler;
use NetherByte\PlaceholderAPI\PlaceholderAPI;
use pocketmine\player\Player;

class StringEqualsRequirement implements RequirementHandler {
    public function matches(Player $player, array $spec, string $type): ?bool {
        $t = strtolower($type);
        if ($t !== 'string equals' && $t !== 'string equals ignorecase') {
            return null;
        }
        $in = (string)PlaceholderAPI::parse((string)($spec['input'] ?? ''), $player);
        $out = (string)PlaceholderAPI::parse((string)($spec['output'] ?? ''), $player);
        if ($t === 'string equals ignorecase') {
            return (strtolower($in) === strtolower($out));
        }
        return ($in === $out);
    }
}
