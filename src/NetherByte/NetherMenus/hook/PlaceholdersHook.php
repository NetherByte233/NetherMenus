<?php

declare(strict_types=1);

namespace NetherByte\NetherMenus\hook;

use NetherByte\NetherMenus\NetherMenus;
use NetherByte\PlaceholderAPI\PlaceholderAPI;
use NetherByte\PlaceholderAPI\provider\Provider;
use NetherByte\PlaceholderAPI\expansion\Expansion;
use pocketmine\player\Player;

final class PlaceholdersHook
{
    public static function register(NetherMenus $plugin) : void
    {
        if (!class_exists(PlaceholderAPI::class)) {
            $plugin->getLogger()->debug("PlaceholderAPI not found. NetherMenus placeholders will be unavailable.");
            return;
        }
        try {
            PlaceholderAPI::registerProvider(new class($plugin) implements Provider {
                public function __construct(private NetherMenus $plugin){}
                public function getName() : string { return 'NetherMenus'; }
                public function listExpansions() : array { return ['nethermenus']; }
                public function provide(string $identifier) : ?Expansion
                {
                    if ($identifier === 'nethermenus') {
                        return new class($this->plugin) extends Expansion {
                            public function getName() : string { return 'NetherMenus Expansion'; }
                            public function getIdentifierPrefix() : ?string { return 'nethermenus_'; }
                            public function getAuthor() : ?string { return 'NetherByte'; }
                            public function getVersion() : ?string { return '1.0.0'; }
                            public function onRequest(string $identifier, ?Player $player) : ?string
                            {
                                // Fallback when prefix is ignored; support raw identifiers
                                return $this->resolve($identifier, $player);
                            }
                            public function onRequestWithParams(string $base, ?string $param, ?Player $player) : ?string
                            {
                                // We don't use params; dispatch to resolve
                                return $this->resolve($base, $player);
                            }
                            private function resolve(string $id, ?Player $player) : ?string
                            {
                                if ($player === null) return null;
                                $pl = $this->plugin; // NetherMenus
                                $id = strtolower($id);
                                switch ($id) {
                                    case 'nethermenus_opened_menu':
                                    case 'opened_menu':
                                        return $pl->getCurrentMenuId($player) ?? '';
                                    case 'nethermenus_is_in_menu':
                                    case 'is_in_menu':
                                        return $pl->isInMenu($player) ? 'yes' : 'no';
                                    case 'nethermenus_last_menu':
                                    case 'last_menu':
                                        return $pl->getLastMenuId($player) ?? '';
                                    case 'nethermenus_opened_menu_name':
                                    case 'opened_menu_name': {
                                        $id = $pl->getCurrentMenuId($player);
                                        if ($id === null) return '';
                                        $cfg = $pl->getCachedGui($id) ?? [];
                                        $name = (string)($cfg['name'] ?? $id);
                                        return $name;
                                    }
                                    case 'nethermenus_last_menu_name':
                                    case 'last_menu_name': {
                                        $id = $pl->getLastMenuId($player);
                                        if ($id === null) return '';
                                        $cfg = $pl->getCachedGui($id) ?? [];
                                        $name = (string)($cfg['name'] ?? $id);
                                        return $name;
                                    }
                                    default:
                                        return null;
                                }
                            }
                        };
                    }
                    return null;
                }
            });
            $plugin->getLogger()->debug("Registered NetherMenus placeholders with PlaceholderAPI.");
        } catch (\Throwable $e) {
            $plugin->getLogger()->warning("Failed to register NetherMenus placeholders: " . $e->getMessage());
        }
    }
}
