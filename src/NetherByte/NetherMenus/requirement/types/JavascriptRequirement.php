<?php

namespace NetherByte\NetherMenus\requirement\types;

use NetherByte\NetherMenus\requirement\RequirementHandler;
use NetherByte\PlaceholderAPI\PlaceholderAPI;
use pocketmine\player\Player;

class JavascriptRequirement implements RequirementHandler {
    public function matches(Player $player, array $spec, string $type): ?bool {
        if (strtolower($type) !== 'javascript') {
            return null;
        }
        $expr = (string)($spec['expression'] ?? '');
        $parsed = PlaceholderAPI::parse($expr, $player);
        // Allow only safe characters: numbers, ops, parentheses, spaces, decimal points, comparisons, booleans
        $safe = preg_replace('/[^0-9\s\.+\-*\/()%<>=!&|]/', '', $parsed);
        if ($safe === null) return false;
        if (preg_match('/={3,}|!{3,}|&{3,}|\|{3,}/', $safe)) return false;
        try {
            // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged
            $result = @eval('return (bool)(' . $safe . ');');
            return (bool)$result;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
