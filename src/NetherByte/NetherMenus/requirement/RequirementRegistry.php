<?php

namespace NetherByte\NetherMenus\requirement;

use pocketmine\player\Player;

class RequirementRegistry {
    /** @var array<string, RequirementHandler> */
    private array $handlers = [];

    private static ?self $instance = null;

    public static function get(): self {
        if (self::$instance === null) {
            self::$instance = new self();
            self::$instance->loadDefaults();
        }
        return self::$instance;
    }

    public function register(string $type, RequirementHandler $handler): void {
        $this->handlers[strtolower($type)] = $handler;
    }

    public function evaluate(Player $player, array $spec, string $type): ?bool {
        $key = strtolower($type);
        if (isset($this->handlers[$key])) {
            return $this->handlers[$key]->matches($player, $spec, $type);
        }
        return null; // unknown to registry
    }

    private function loadDefaults(): void {
        // Register a few core handlers now; the evaluator will fallback for others
        $this->register('has permission', new \NetherByte\NetherMenus\requirement\types\PermissionRequirement());
        $this->register('has permissions', new \NetherByte\NetherMenus\requirement\types\PermissionRequirement());
        $this->register('has money', new \NetherByte\NetherMenus\requirement\types\MoneyRequirement());
        // String checks
        $this->register('string equals', new \NetherByte\NetherMenus\requirement\types\StringEqualsRequirement());
        $this->register('string equals ignorecase', new \NetherByte\NetherMenus\requirement\types\StringEqualsRequirement());
        $this->register('string contains', new \NetherByte\NetherMenus\requirement\types\StringContainsRequirement());
        $this->register('string length', new \NetherByte\NetherMenus\requirement\types\StringLengthRequirement());
        // Comparators
        $cmp = new \NetherByte\NetherMenus\requirement\types\ComparatorRequirement();
        foreach (['==','!=','>=','<=','>','<'] as $op) {
            $this->register($op, $cmp);
        }
    }
}
