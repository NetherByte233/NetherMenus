<?php

namespace NetherByte\NetherMenus\requirement\types;

use NetherByte\NetherMenus\requirement\RequirementHandler;
use pocketmine\player\Player;

class PermissionRequirement implements RequirementHandler {
    public function matches(Player $player, array $spec, string $type): ?bool {
        $t = strtolower($type);
        switch ($t) {
            case 'has permission':
                $perm = (string)($spec['permission'] ?? '');
                return ($perm !== '' && $player->hasPermission($perm));
            case 'has permissions':
                $perms = $spec['permissions'] ?? [];
                if (is_string($perms)) { $perms = [$perms]; }
                $count = 0; $total = 0;
                foreach ($perms as $p) {
                    if (!is_string($p) || $p === '') continue; $total++;
                    if ($player->hasPermission($p)) { $count++; }
                }
                $min = isset($spec['minimum']) && is_numeric($spec['minimum']) ? (int)$spec['minimum'] : $total;
                return ($count >= $min);
        }
        return null; // not supported by this handler
    }
}
