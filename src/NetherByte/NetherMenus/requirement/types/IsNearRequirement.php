<?php

namespace NetherByte\NetherMenus\requirement\types;

use NetherByte\NetherMenus\requirement\RequirementHandler;
use pocketmine\player\Player;

class IsNearRequirement implements RequirementHandler {
    public function matches(Player $player, array $spec, string $type): ?bool {
        if (strtolower($type) !== 'is near') {
            return null;
        }
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
}
