<?php

namespace MultiVersion\network\proto\v361;

use CortexPE\std\ReflectionUtils;
use JsonException;
use pocketmine\entity\Attribute;
use pocketmine\network\mcpe\handler\InGamePacketHandler;
use pocketmine\network\mcpe\protocol\ModalFormResponsePacket;
use pocketmine\network\mcpe\protocol\MovePlayerPacket;
use pocketmine\network\mcpe\protocol\PlayerActionPacket;
use pocketmine\network\mcpe\protocol\types\inventory\ReleaseItemTransactionData;
use pocketmine\network\mcpe\protocol\types\PlayerAction;
use pocketmine\network\PacketHandlingException;
use pocketmine\player\Player;
use pocketmine\utils\AssumptionFailedError;

class v361InGamePacketHandler extends InGamePacketHandler{

	public function handlePlayerAction(PlayerActionPacket $packet) : bool{
		switch($packet->action){
			case PlayerAction::JUMP:
				$this->getPlayer()->jump();
				break;
			case PlayerAction::START_SPRINT:
				$this->getPlayer()->toggleSprint(true);
				break;
			case PlayerAction::STOP_SPRINT:
				$this->getPlayer()->toggleSprint(false);
				break;
			case PlayerAction::START_SNEAK:
				$this->getPlayer()->toggleSneak(true);
				break;
			case PlayerAction::STOP_SNEAK:
				$this->getPlayer()->toggleSneak(false);
				break;
			case PlayerAction::START_SWIMMING:
				$this->getPlayer()->toggleSwim(true);
				break;
			case PlayerAction::STOP_SWIMMING:
				$this->getPlayer()->toggleSwim(false);
				break;
			case PlayerAction::START_GLIDE:
				$this->getPlayer()->toggleGlide(true);
				break;
			case PlayerAction::STOP_GLIDE:
				$this->getPlayer()->toggleGlide(false);
				break;
			default:
				return parent::handlePlayerAction($packet);
		}
		return true;
	}

	public function handleMovePlayer(MovePlayerPacket $packet) : bool{
		$rawPos = $packet->position;
		$rawYaw = $packet->yaw;
		$rawPitch = $packet->pitch;
		foreach([$rawPos->x, $rawPos->y, $rawPos->z, $rawYaw, $packet->headYaw, $rawPitch] as $float){
			if(is_infinite($float) || is_nan($float)){
				$this->getPlayer()->getNetworkSession()->getLogger()->debug("Invalid movement received, contains NAN/INF components");
				return false;
			}
		}

		if($rawYaw !== $this->lastPlayerAuthInputYaw || $rawPitch !== $this->lastPlayerAuthInputPitch){
			$this->lastPlayerAuthInputYaw = $rawYaw;
			$this->lastPlayerAuthInputPitch = $rawPitch;

			$yaw = fmod($rawYaw, 360);
			$pitch = fmod($rawPitch, 360);
			if($yaw < 0){
				$yaw += 360;
			}

			$this->getPlayer()->setRotation($yaw, $pitch);
		}

		$hasMoved = $this->lastPlayerAuthInputPosition === null || !$this->lastPlayerAuthInputPosition->equals($rawPos);
		$newPos = $rawPos->round(4)->subtract(0, 1.62, 0);

		if($this->forceMoveSync && $hasMoved){
			$curPos = $this->getPlayer()->getLocation();

			if($newPos->distanceSquared($curPos) > 1){  //Tolerate up to 1 block to avoid problems with client-sided physics when spawning in blocks
				$this->getPlayer()->getNetworkSession()->getLogger()->debug("Got outdated pre-teleport movement, received " . $newPos . ', expected ' . $curPos);
				//Still getting movements from before teleport, ignore them
				return false;
			}

			// Once we get a movement within a reasonable distance, treat it as a teleport ACK and remove position lock
			$this->forceMoveSync = false;
		}

		if(!$this->forceMoveSync && $hasMoved){
			$this->lastPlayerAuthInputPosition = $rawPos;
			$this->getPlayer()->handleMovement($newPos);
		}
		return true;
	}

	private function handleReleaseItemTransaction(ReleaseItemTransactionData $data) : bool{
		$this->getPlayer()->selectHotbarSlot($data->getHotbarSlot());
		$this->getPlayer()->getNetworkSession()->getInvManager()->addPredictedSlotChanges($data->getActions());

		//TODO: use transactiondata for rollbacks here (resending entire inventory is very wasteful)
		switch($data->getActionType()){
			case ReleaseItemTransactionData::ACTION_RELEASE:
				if(!$this->getPlayer()->releaseHeldItem()){
					$this->getPlayer()->getNetworkSession()->getInvManager()->syncContents($this->getPlayer()->getInventory());
				}
				return true;
			case ReleaseItemTransactionData::ACTION_CONSUME:
				if($this->getPlayer()->isUsingItem()){
					if(!$this->getPlayer()->consumeHeldItem()){
						$hungerAttr = $this->getPlayer()->getAttributeMap()->get(Attribute::HUNGER) ?? throw new AssumptionFailedError();
						$hungerAttr->markSynchronized(false);
					}
					return true;
				}
				$this->getPlayer()->useHeldItem();
				return true;
		}

		return false;
	}

	private const MAX_FORM_RESPONSE_DEPTH = 2; //modal/simple will be 1, custom forms 2 - they will never contain anything other than string|int|float|bool|null

	public function handleModalFormResponse(ModalFormResponsePacket $packet) : bool{
		if($packet?->formData !== null){
			try{
				$responseData = json_decode($packet->formData, true, self::MAX_FORM_RESPONSE_DEPTH, JSON_THROW_ON_ERROR);
			}catch(JsonException $e){
				throw PacketHandlingException::wrap($e, "Failed to decode form response data");
			}
			return $this->getPlayer()->onFormSubmit($packet->formId, $responseData);
		}else{
			throw new PacketHandlingException("Expected either formData or cancelReason to be set in ModalFormResponsePacket");
		}
	}

	private function getPlayer() : Player{
		return ReflectionUtils::getProperty(InGamePacketHandler::class, $this, "player");
	}
}
