<?php

namespace MultiVersion\network;

use Closure;
use CortexPE\std\ReflectionUtils;
use InvalidArgumentException;
use MultiVersion\network\proto\chunk\MVChunkCache;
use MultiVersion\network\proto\MVLoginPacketHandler;
use MultiVersion\network\proto\PacketTranslator;
use pocketmine\event\server\DataPacketReceiveEvent;
use pocketmine\event\server\DataPacketSendEvent;
use pocketmine\network\mcpe\compression\CompressBatchPromise;
use pocketmine\network\mcpe\compression\Compressor;
use pocketmine\network\mcpe\compression\DecompressionException;
use pocketmine\network\mcpe\compression\ZlibCompressor;
use pocketmine\network\mcpe\encryption\DecryptionException;
use pocketmine\network\mcpe\handler\SessionStartPacketHandler;
use pocketmine\network\mcpe\handler\SpawnResponsePacketHandler;
use pocketmine\network\mcpe\NetworkSession;
use pocketmine\network\mcpe\PacketBroadcaster;
use pocketmine\network\mcpe\PacketSender;
use pocketmine\network\mcpe\protocol\ClientboundPacket;
use pocketmine\network\mcpe\protocol\Packet;
use pocketmine\network\mcpe\protocol\PacketDecodeException;
use pocketmine\network\mcpe\protocol\PacketPool;
use pocketmine\network\mcpe\protocol\PlayStatusPacket;
use pocketmine\network\mcpe\protocol\serializer\PacketBatch;
use pocketmine\network\mcpe\protocol\ServerboundPacket;
use pocketmine\network\NetworkSessionManager;
use pocketmine\network\PacketHandlingException;
use pocketmine\player\PlayerInfo;
use pocketmine\player\UsedChunkStatus;
use pocketmine\Server;
use pocketmine\timings\Timings;
use pocketmine\utils\BinaryStream;
use pocketmine\utils\TextFormat;
use pocketmine\utils\Utils;
use ReflectionException;

class MVNetworkSession extends NetworkSession{

	private ?PacketTranslator $pkTranslator = null;
	private MVRakLibInterface $interface;
	private Server $server;
	private PacketPool $packetPool;
	private Compressor $compressor;
	private bool $isFirstPacket = true;
	private bool $enableCompression = true;

	public function __construct(Server $server, MVRakLibInterface $interface, NetworkSessionManager $manager, PacketSender $sender, PacketBroadcaster $broadcaster, string $ip, int $port){
		$this->packetPool = PacketPool::getInstance();
		$this->compressor = ZlibCompressor::getInstance();
		$this->interface = $interface;
		$this->server = $server;
		parent::__construct($server, $manager, $this->packetPool, $sender, $broadcaster, $this->compressor, $ip, $port);
		$this->setHandler(new MVLoginPacketHandler(
			$this->server,
			$this,
			function(PlayerInfo $info) : void{
				ReflectionUtils::setProperty(NetworkSession::class, $this, "info", $info);
				$this->getLogger()->info("Player: " . TextFormat::AQUA . $info->getUsername() . TextFormat::RESET);
				$this->getLogger()->setPrefix("NetworkSession: " . $this->getDisplayName());
			},
			function(bool $isAuthenticated, bool $authRequired, ?string $error, ?string $clientPubKey) : void{
				ReflectionUtils::invoke(
					NetworkSession::class, $this, "setAuthenticationStatus",
					$isAuthenticated, $authRequired, $error, $clientPubKey
				);
			}
		));
	}

	private function onSessionStartSuccess() : void{
		$this->getLogger()->debug("Session start handshake completed, awaiting login packet");
		$this->flushSendBuffer(true);
		$this->enableCompression = true;
		$this->setHandler(new MVLoginPacketHandler(
			$this->server,
			$this,
			function(PlayerInfo $info) : void{
				ReflectionUtils::setProperty(NetworkSession::class, $this, "info", $info);
				$this->getLogger()->info("Player: " . TextFormat::AQUA . $info->getUsername() . TextFormat::RESET);
				$this->getLogger()->setPrefix("NetworkSession: " . $this->getDisplayName());
				ReflectionUtils::getProperty(NetworkSession::class, $this, "manager")->markLoginReceived($this);
			},
			function(bool $isAuthenticated, bool $authRequired, ?string $error, ?string $clientPubKey) : void{
				ReflectionUtils::invoke(
					NetworkSession::class, $this, "setAuthenticationStatus",
					$isAuthenticated, $authRequired, $error, $clientPubKey
				);
			}
		));
	}

	public function getInterface() : MVRakLibInterface{
		return $this->interface;
	}

	public function setPacketTranslator(PacketTranslator $pkTranslator) : void{
		$this->pkTranslator = $pkTranslator;
		$this->setBroadcaster($pkTranslator->getBroadcaster());
		$pkTranslator->setup($this);
	}

	public function setBroadcaster(PacketBroadcaster $broadcaster) : void{
		ReflectionUtils::setProperty(NetworkSession::class, $this, "broadcaster", $broadcaster);
	}

	public function getPacketPool() : PacketPool{
		return $this->packetPool;
	}

	public function setPacketPool(PacketPool $packetPool) : void{
		$this->packetPool = $packetPool;
		ReflectionUtils::setProperty(NetworkSession::class, $this, "packetPool", $packetPool);
	}

	public function setCompressor(Compressor $compressor) : void{
		$this->compressor = $compressor;
		ReflectionUtils::setProperty(NetworkSession::class, $this, "compressor", $compressor);
	}

	public function getPacketTranslator() : ?PacketTranslator{
		return $this->pkTranslator;
	}

	public function getProtocolVersion() : string{
		return $this->pkTranslator::PROTOCOL_VERSION;
	}

	/**
	 * @throws ReflectionException
	 */
	public function handleEncoded(string $payload) : void{
		if(!ReflectionUtils::getProperty(NetworkSession::class, $this, "connected")){
			return;
		}

		Timings::$playerNetworkReceive->startTiming();
		try{
			/*$incomingPacketBatchBudget = ReflectionUtils::getProperty(NetworkSession::class, $this, "incomingPacketBatchBudget");
			if($incomingPacketBatchBudget <= 0){
				ReflectionUtils::invoke(NetworkSession::class, $this, "updatePacketBudget");
				if($incomingPacketBatchBudget <= 0){
					throw new PacketHandlingException("Receiving packets too fast");
				}
			}
			ReflectionUtils::setProperty(NetworkSession::class, $this, "incomingPacketBatchBudget", $incomingPacketBatchBudget - 1);*/

			$cipher = ReflectionUtils::getProperty(NetworkSession::class, $this, "cipher");
			if($cipher !== null){
				Timings::$playerNetworkReceiveDecrypt->startTiming();
				try{
					$payload = $cipher->decrypt($payload);
				}catch(DecryptionException $e){
					$this->getLogger()->debug("Encrypted packet: " . base64_encode($payload));
					throw PacketHandlingException::wrap($e, "Packet decryption error");
				}finally{
					Timings::$playerNetworkReceiveDecrypt->stopTiming();
				}
			}

			if($this->enableCompression){
				Timings::$playerNetworkReceiveDecompress->startTiming();
				try{
					$decompressed = $this->compressor->decompress($payload);
				}catch(DecompressionException $e){
					if($this->isFirstPacket){
						$this->getLogger()->debug("Failed to decompress packet: " . base64_encode($payload));

						$this->enableCompression = false;
						$this->setHandler(new SessionStartPacketHandler(
							$this->server,
							$this,
							fn() => $this->onSessionStartSuccess()
						));

						$decompressed = $payload;
					}else{
						$this->getLogger()->debug("Failed to decompress packet: " . base64_encode($payload));
						throw PacketHandlingException::wrap($e, "Compressed packet batch decode error");
					}
				}finally{
					Timings::$playerNetworkReceiveDecompress->stopTiming();
				}
			}else{
				$decompressed = $payload;
			}

			try{
				$count = 0;
				foreach((new MVPacketBatch($decompressed))->getPackets($this, ReflectionUtils::getProperty(NetworkSession::class, $this, "packetSerializerContext"), 500) as [$packet, $buffer]){
					if(++$count > 1300){
						throw new PacketHandlingException("Too many packets in batch");
					}
					if($packet === null){
						$this->getLogger()->debug("Unknown packet: " . base64_encode($buffer));
						throw new PacketHandlingException("Unknown packet received");
					}
					try{
						$this->handleDataPacket($packet, $buffer);
					}catch(PacketHandlingException $e){
						$this->getLogger()->debug($packet->getName() . ": " . base64_encode($buffer));
						throw PacketHandlingException::wrap($e, "Error processing " . $packet->getName());
					}
				}
			}catch(PacketDecodeException $e){
				$this->getLogger()->logException($e);
				throw PacketHandlingException::wrap($e, "Packet batch decode error");
			}finally{
				$this->isFirstPacket = false;
			}
		}finally{
			Timings::$playerNetworkReceive->stopTiming();
		}
	}

	public function handleDataPacket(Packet $packet, string $buffer) : void{
		if(!$packet instanceof ServerboundPacket){
			throw new PacketDecodeException("Unexpected non-serverbound packet");
		}
		if($this->pkTranslator === null){
			parent::handleDataPacket($packet, $buffer);
			return;
		}
		$timings = Timings::getReceiveDataPacketTimings($packet);
		$timings->startTiming();
		try{
			$stream = $this->pkTranslator->getPacketSerializerFactory()->newDecoder($buffer, 0, $this->pkTranslator->getPacketSerializerFactory()->newSerializerContext());
			try{
				$packet->decode($stream);
			}catch(PacketDecodeException $e){
				throw PacketHandlingException::wrap($e);
			}

			$name = $packet->getName();
			$packet = $this->pkTranslator->handleIncoming($packet);
			if($packet === null){
				$this->getLogger()->debug("Prevented receiving $name from v" . $this->pkTranslator::PROTOCOL_VERSION . " player");
				return;
			}

			if(!$stream->feof()){
				$remains = substr($stream->getBuffer(), $stream->getOffset());
				$this->getLogger()->debug("Still " . strlen($remains) . " bytes unread in " . $packet->getName() . ": " . bin2hex($remains));
			}
		}finally{
			$timings->stopTiming();
		}

		$timings = Timings::getHandleDataPacketTimings($packet);
		$timings->startTiming();
		try{
			//TODO: I'm not sure DataPacketReceiveEvent should be included in the handler timings, but it needs to be
			//included for now to ensure the receivePacket timings are counted the way they were before
			$ev = new DataPacketReceiveEvent($this, $packet);
			$ev->call();
			if(!$ev->isCancelled() and ($this->getHandler() === null or !$packet->handle($this->getHandler()))){
				$this->getLogger()->debug("Unhandled " . $packet->getName() . ": " . base64_encode($stream->getBuffer()));
			}
		}finally{
			$timings->stopTiming();
		}
	}

	/**
	 * @throws ReflectionException
	 */
	public function sendDataPacket(ClientboundPacket $packet, bool $immediate = false) : bool{
		if($this->pkTranslator === null){
			return parent::sendDataPacket($packet, $immediate);
		}

		if(!ReflectionUtils::getProperty(NetworkSession::class, $this, "connected")){
			return false;
		}

		//Basic safety restriction. TODO: improve this
		if(!ReflectionUtils::getProperty(NetworkSession::class, $this, "loggedIn") and !$packet->canBeSentBeforeLogin()){
			throw new InvalidArgumentException("Attempted to send " . get_class($packet) . " to " . $this->getDisplayName() . " too early");
		}

		$timings = Timings::getSendDataPacketTimings($packet);
		$timings->startTiming();
		try{
			$ev = new DataPacketSendEvent([$this], [$packet]);
			$ev->call();
			if($ev->isCancelled()){
				return false;
			}

			$packet = $this->pkTranslator->handleOutgoing($packet);
			if($packet === null){
				return false;
			}

			$this->addToSendBuffer(MVPacketBatch::rawFromPackets($this->getPacketTranslator()->getPacketSerializerFactory(), $packet)->getBuffer());
			if($immediate){
				$this->flushSendBuffer(true);
			}

			return true;
		}finally{
			$timings->stopTiming();
		}
	}

	private function flushSendBuffer(bool $immediate = false) : void{
		$sendBuffer = ReflectionUtils::getProperty(NetworkSession::class, $this, "sendBuffer");
		if(count($sendBuffer) > 0){
			Timings::$playerNetworkSend->startTiming();
			try{
				$syncMode = null;
				if($immediate){
					$syncMode = true;
				}elseif(ReflectionUtils::getProperty(NetworkSession::class, $this, "forceAsyncCompression")){
					$syncMode = false;
				}

				$stream = new BinaryStream();
				PacketBatch::encodeRaw($stream, $sendBuffer);

				if($this->enableCompression){
					$promise = $this->server->prepareBatch(new PacketBatch($stream->getBuffer()), $this->compressor, $syncMode, Timings::$playerNetworkSendCompressSessionBuffer);
				}else{
					$promise = new CompressBatchPromise();
					$promise->resolve($stream->getBuffer());
				}

				ReflectionUtils::setProperty(NetworkSession::class, $this, "sendBuffer", []);
				ReflectionUtils::invoke(NetworkSession::class, $this, "queueCompressedNoBufferFlush", $promise, $immediate);
			}finally{
				Timings::$playerNetworkSend->stopTiming();
			}
		}
	}

	/**
	 * Instructs the networksession to start using the chunk at the given coordinates. This may occur asynchronously.
	 *
	 * @param int                      $chunkX
	 * @param int                      $chunkZ
	 * @param Closure                  $onCompletion To be called when chunk sending has completed.
	 *
	 * @phpstan-param Closure() : void $onCompletion
	 */
	public function startUsingChunk(int $chunkX, int $chunkZ, Closure $onCompletion) : void{
		if($this->pkTranslator === null){
			parent::startUsingChunk($chunkX, $chunkZ, $onCompletion);
			return;
		}
		Utils::validateCallableSignature(function() : void{
		}, $onCompletion);

		$world = $this->getPlayer()->getLocation()->getWorld();
		MVChunkCache::getInstance($world, $this->compressor, $this->getPacketTranslator()->getPacketSerializerFactory())->request($chunkX, $chunkZ)->onResolve(
		//this callback may be called synchronously or asynchronously, depending on whether the promise is resolved yet
			function(CompressBatchPromise $promise) use ($world, $onCompletion, $chunkX, $chunkZ) : void{
				if(!$this->isConnected()){
					return;
				}
				$currentWorld = $this->getPlayer()->getLocation()->getWorld();
				if($world !== $currentWorld or ($status = $this->getPlayer()->getUsedChunkStatus($chunkX, $chunkZ)) === null){
					$this->getLogger()->debug("Tried to send no-longer-active chunk $chunkX $chunkZ in world " . $world->getFolderName());
					return;
				}
				if(!$status->equals(UsedChunkStatus::REQUESTED_SENDING())){
					//TODO: make this an error
					//this could be triggered due to the shitty way that chunk resends are handled
					//right now - not because of the spammy re-requesting, but because the chunk status reverts
					//to NEEDED if they want to be resent.
					return;
				}
				$world->timings->syncChunkSend->startTiming();
				try{
					$this->queueCompressed($promise);
					$onCompletion();
				}finally{
					$world->timings->syncChunkSend->stopTiming();
				}
			}
		);
	}

	public function tick() : void{
		if(!$this->isConnected()){
			ReflectionUtils::invoke(NetworkSession::class, $this, "dispose");
			return;
		}

		if(ReflectionUtils::getProperty(NetworkSession::class, $this, "info") === null){
			if(time() >= ReflectionUtils::getProperty(NetworkSession::class, $this, "connectTime") + 10){
				$this->disconnect("Login timeout");
				return;
			}

			return;
		}

		$player = ReflectionUtils::getProperty(NetworkSession::class, $this, "player");
		if($player !== null){
			$player->doChunkRequests();

			$dirtyAttributes = $player->getAttributeMap()->needSend();
			$this->syncAttributes($player, $dirtyAttributes);
			foreach($dirtyAttributes as $attribute){
				//TODO: we might need to send these to other players in the future
				//if that happens, this will need to become more complex than a flag on the attribute itself
				$attribute->markSynchronized();
			}
		}

		$this->flushSendBuffer();
	}

	public function queueCompressed(CompressBatchPromise $payload, bool $immediate = false) : void{
		Timings::$playerNetworkSend->startTiming();
		try{
			$this->flushSendBuffer($immediate); //Maintain ordering if possible
			ReflectionUtils::invoke(NetworkSession::class, $this, "queueCompressedNoBufferFlush", $payload, $immediate);
		}finally{
			Timings::$playerNetworkSend->stopTiming();
		}
	}

	public function notifyTerrainReady() : void{
		if(($handler = $this->pkTranslator?->handleInGame($this)) === null){
			parent::notifyTerrainReady();
			return;
		}
		$this->getLogger()->debug("Sending spawn notification, waiting for spawn response");
		$this->sendDataPacket(PlayStatusPacket::create(PlayStatusPacket::PLAYER_SPAWN));
		$this->setHandler(new SpawnResponsePacketHandler(function() use ($handler) : void{
			$this->getLogger()->debug("Received spawn response, entering in-game phase");
			$this->getPlayer()->setImmobile(false); //TODO: HACK: we set this during the spawn sequence to prevent the client sending junk movements
			$this->getPlayer()->doFirstSpawn();
			ReflectionUtils::setProperty(NetworkSession::class, $this, "forceAsyncCompression", false);
			$this->setHandler($handler);
		}));
	}

	public function onServerRespawn() : void{
		parent::onServerRespawn();
		if(($handler = $this->pkTranslator?->handleInGame($this)) === null){
			return;
		}
		$this->setHandler($handler);
	}
}