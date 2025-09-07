<?php

namespace NetherByte\NetherMenus\requirement\types;

use NetherByte\NetherMenus\requirement\RequirementHandler;
use pocketmine\player\Player;

class HasExpRequirement implements RequirementHandler {
    public function matches(Player $player, array $spec, string $type): ?bool {
        if (strtolower($type) !== 'has exp') {
            return null;
        }
        $amount = isset($spec['amount']) && is_numeric($spec['amount']) ? (int)$spec['amount'] : 0;
        $levelMode = (bool)($spec['level'] ?? false);
        $xpMgr = $player->getXpManager();
        if ($levelMode) {
            return $xpMgr->getXpLevel() >= $amount;
        }
        if (method_exists($xpMgr, 'getCurrentTotalXp')) {
            return $xpMgr->getCurrentTotalXp() >= $amount;
        }
        return ($xpMgr->getXpLevel() * 100) >= $amount; // fallback approximation
    }
}
