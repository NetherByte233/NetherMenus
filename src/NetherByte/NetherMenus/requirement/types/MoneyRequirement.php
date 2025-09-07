<?php

namespace NetherByte\NetherMenus\requirement\types;

use NetherByte\NetherMenus\requirement\RequirementHandler;
use NetherByte\PlaceholderAPI\PlaceholderAPI;
use pocketmine\player\Player;

class MoneyRequirement implements RequirementHandler {
    public function matches(Player $player, array $spec, string $type): ?bool {
        $t = strtolower($type);
        if ($t !== 'has money') {
            return null;
        }
        $amount = isset($spec['amount']) && is_numeric($spec['amount']) ? (float)$spec['amount'] : 0.0;
        $balStr = PlaceholderAPI::parse('%pocketvault_eco_balance%', $player);
        $bal = is_numeric($balStr) ? (float)$balStr : 0.0;
        return ($bal >= $amount);
    }
}
