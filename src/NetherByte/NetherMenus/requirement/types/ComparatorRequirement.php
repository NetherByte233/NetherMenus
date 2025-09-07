<?php

namespace NetherByte\NetherMenus\requirement\types;

use NetherByte\NetherMenus\requirement\RequirementHandler;
use NetherByte\PlaceholderAPI\PlaceholderAPI;
use pocketmine\player\Player;

class ComparatorRequirement implements RequirementHandler {
    private const OPS = ['==','!=','>=','<=','>','<'];

    public function matches(Player $player, array $spec, string $type): ?bool {
        $op = strtolower($type);
        if (!in_array($op, self::OPS, true)) {
            return null;
        }
        $inRaw = (string)PlaceholderAPI::parse((string)($spec['input'] ?? ''), $player);
        $outRaw = (string)PlaceholderAPI::parse((string)($spec['output'] ?? ''), $player);
        $inNum = is_numeric($inRaw) ? (float)$inRaw : null;
        $outNum = is_numeric($outRaw) ? (float)$outRaw : null;
        if ($inNum !== null && $outNum !== null) {
            return match ($op) {
                '==' => $inNum == $outNum,
                '!=' => $inNum != $outNum,
                '>=' => $inNum >= $outNum,
                '<=' => $inNum <= $outNum,
                '>'  => $inNum >  $outNum,
                '<'  => $inNum <  $outNum,
                default => null,
            };
        }
        return match ($op) {
            '==' => $inRaw === $outRaw,
            '!=' => $inRaw !== $outRaw,
            '>=' => $inRaw >= $outRaw,
            '<=' => $inRaw <= $outRaw,
            '>'  => $inRaw >  $outRaw,
            '<'  => $inRaw <  $outRaw,
            default => null,
        };
    }
}
