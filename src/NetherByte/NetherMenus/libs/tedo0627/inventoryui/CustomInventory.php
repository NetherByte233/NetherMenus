<?php

namespace NetherByte\NetherMenus\libs\tedo0627\inventoryui;

use pocketmine\inventory\SimpleInventory;
use pocketmine\item\Item;
use pocketmine\network\mcpe\protocol\ContainerOpenPacket;
use pocketmine\network\mcpe\protocol\SetActorLinkPacket;
use pocketmine\network\mcpe\protocol\types\BlockPosition;
use pocketmine\network\mcpe\protocol\types\entity\EntityLink;
use pocketmine\network\mcpe\protocol\types\inventory\WindowTypes;
use pocketmine\player\Player;
use pocketmine\scheduler\ClosureTask;
use NetherByte\NetherMenus\libs\tedo0627\inventoryui\exception\IllegalInventorySizeException;

class CustomInventory extends SimpleInventory {

    private int $tick = -1;

    private string $title;
    private int $length;
    private bool $scroll;
    private array $entities = [];

    public function __construct(int $size, string $title = "inventory", ?int $verticalLength = null) {
        if ($size < 0) {
            throw new IllegalInventorySizeException("The size of the inventory must be greater than 0");
        }
        parent::__construct($size);

        $this->title = $title;
        if ($verticalLength != null && 0 <= $verticalLength && $verticalLength <= 6) {
            $this->length = $verticalLength;
        } else {
            $length = intval($size / 9) + ($size % 9 == 0 ? 0 : 1);
            if ($length > 6) $length = 6;

            $this->length = $length;
        }

        $this->scroll = $this->length * 9 < $size;
    }

    public function sendOpenPacket(Player $player, int $id): array {
        $name = $player->getName();
        if (!array_key_exists($name, $this->entities)) {
            $entity = new InventoryEntity($player->getLocation());
            $entity->setSlot($this->getSize());
            $scrollFlag = $this->scroll ? 1 : 0;
            // Use per-player display title (overridable)
            $displayTitle = $this->getDisplayTitle($player);
            $entity->setNameTag("§" . $this->length . "§" . $scrollFlag . "§r§r§r§r§r§r§r§r§r" . $displayTitle);
            $this->entities[$name] = $entity;
        } else {
            $entity = $this->entities[$name];
        }

        $entity->spawnTo($player);

        $link = new EntityLink($player->getId(), $entity->getId(), EntityLink::TYPE_RIDER, true, true);
        $pk1 = SetActorLinkPacket::create($link);

        // Delays became necessary from around version 1.21.90.
        InventoryUI::getInstance()->getScheduler()->scheduleDelayedTask(new ClosureTask(function () use ($player, $id, $entity) {
            if (!$player->isOnline()) return;

            $pk2 = ContainerOpenPacket::entityInv($id, WindowTypes::CONTAINER, $entity->getId());
            $pk2->blockPosition = BlockPosition::fromVector3($entity->getLocation());
            $player->getNetworkSession()->sendDataPacket($pk2);

            $player->getNetworkSession()->getInvManager()->syncContents($this);
        }), 10);

        return [$pk1];
    }

    public final function onOpen(Player $who): void {
        parent::onOpen($who);

        $this->open($who);
    }

    public final function onClose(Player $who): void {
        parent::onClose($who);

        $name = $who->getName();
        if (array_key_exists($name, $this->entities)) {
            $this->entities[$name]->close();
            unset($this->entities[$name]);
        }

        $this->close($who);
    }

    public final function onTick(int $tick): void {
        if ($this->tick === $tick) return;

        $this->tick = $tick;
        $this->tick($tick);
    }

    public function getTitle(): string {
        return $this->title;
    }

    /**
     * Overridable hook to provide a dynamic, per-player display title.
     * Default returns the static title.
     */
    protected function getDisplayTitle(Player $player): string {
        return $this->title;
    }

    public function getVerticalLength(): int {
        return $this->length;
    }

    /**
     * Called when a player opens this inventory.
     */
    public function open(Player $player): void {

    }

    /**
     * Called every tick when someone opens inventory.
     */
    public function close(Player $player): void {

    }

    /**
     * Called when a slot in the inventory is operated.
     *
     * @return bool If true, the operation is canceled.
     */
    public function click(Player $player, int $slot, Item $sourceItem, Item $targetItem): bool {
        return false;
    }

    /**
     * Called when the player closes the inventory.
     */
    public function tick(int $tick): void {

    }
}
