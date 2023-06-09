<?php

/*
 *  ___            __  __
 * |_ _|_ ____   _|  \/  | ___ _ __  _   _
 *  | || '_ \ \ / / |\/| |/ _ \ '_ \| | | |
 *  | || | | \ V /| |  | |  __/ | | | |_| |
 * |___|_| |_|\_/ |_|  |_|\___|_| |_|\__,_|
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author Muqsit
 * @link http://github.com/Muqsit
 *
*/

declare(strict_types=1);

namespace muqsit\invmenu\transaction;

use pocketmine\inventory\transaction\action\SlotChangeAction;
use pocketmine\inventory\transaction\InventoryTransaction;
use pocketmine\item\Item;
use pocketmine\player\Player;

class InvMenuTransaction {

	private Player $player;
	private Item $out;
	private Item $in;
	private SlotChangeAction $action;
	private InventoryTransaction $transaction;

	public function __construct(Player $player, Item $out, Item $in, SlotChangeAction $action, InventoryTransaction $transaction) {
		$this->player = $player;
		$this->out = $out;
		$this->in = $in;
		$this->action = $action;
		$this->transaction = $transaction;
	}

	public function getPlayer(): Player {
		return $this->player;
	}

	/**
	 * Returns the item that was clicked / taken out of the inventory.
	 *
	 * @return Item
	 * @link InvMenuTransaction::getOut()
	 */
	public function getItemClicked(): Item {
		return $this->getOut();
	}

	public function getOut(): Item {
		return $this->out;
	}

	/**
	 * Returns the item that an item was clicked with / placed in the inventory.
	 *
	 * @return Item
	 * @link InvMenuTransaction::getIn()
	 */
	public function getItemClickedWith(): Item {
		return $this->getIn();
	}

	public function getIn(): Item {
		return $this->in;
	}

	public function getAction(): SlotChangeAction {
		return $this->action;
	}

	public function getTransaction(): InventoryTransaction {
		return $this->transaction;
	}

	public function continue(): InvMenuTransactionResult {
		return new InvMenuTransactionResult(false);
	}

	public function discard(): InvMenuTransactionResult {
		return new InvMenuTransactionResult(true);
	}
}