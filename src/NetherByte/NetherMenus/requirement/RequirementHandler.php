<?php

namespace NetherByte\NetherMenus\requirement;

use pocketmine\player\Player;

interface RequirementHandler {
    /**
     * Evaluate the requirement spec for the given player.
     * Return true/false for pass/fail, or null if the handler cannot evaluate this spec.
     */
    public function matches(Player $player, array $spec, string $type): ?bool;
}
