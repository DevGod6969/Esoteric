<?php

namespace ethaniccc\Esoteric\network;

use ethaniccc\Esoteric\data\PlayerDataManager;
use ethaniccc\Esoteric\data\process\NetworkStackLatencyHandler;
use ethaniccc\Esoteric\Esoteric;
use pocketmine\network\mcpe\cache\ChunkCache;
use pocketmine\network\mcpe\compression\CompressBatchPromise;
use pocketmine\network\mcpe\compression\Compressor;
use pocketmine\network\mcpe\NetworkSession;
use pocketmine\network\mcpe\PacketBroadcaster;
use pocketmine\network\mcpe\PacketSender;
use pocketmine\network\mcpe\protocol\LevelChunkPacket;
use pocketmine\network\mcpe\protocol\PacketPool;
use pocketmine\network\mcpe\protocol\serializer\PacketBatch;
use pocketmine\network\mcpe\protocol\serializer\PacketSerializer;
use pocketmine\network\NetworkSessionManager;
use pocketmine\player\UsedChunkStatus;
use pocketmine\Server;
use pocketmine\utils\Utils;
use pocketmine\world\format\BiomeArray;
use pocketmine\world\format\Chunk;
use function microtime;
use function spl_object_id;
use function strlen;
use function var_dump;

class NetworkSessionOverride extends NetworkSession {

	public function __construct(Server $server, NetworkSessionManager $manager, PacketPool $packetPool, PacketSender $sender, PacketBroadcaster $broadcaster, Compressor $compressor, string $ip, int $port) {
		parent::__construct($server, $manager, $packetPool, $sender, $broadcaster, $compressor, $ip, $port);
	}

	public function startUsingChunk(int $chunkX, int $chunkZ, \Closure $onCompletion) : void{
		Utils::validateCallableSignature(function() : void{}, $onCompletion);

		$data = Esoteric::getInstance()->dataManager->get($this->getPlayer()->getNetworkSession());
		$originalChunk = $this->getPlayer()->getWorld()->getChunk($chunkX, $chunkZ);
		// no need to clone the things we might not need, mainly entities
		$subChunks = [];
		foreach ($originalChunk->getSubChunks()->toArray() as $subChunk) {
			$subChunks[] = clone $subChunk;
		}
		$usedChunk = new Chunk($subChunks, null, $originalChunk->getNBTtiles(), new BiomeArray($originalChunk->getBiomeIdArray()));

		$world = $this->getPlayer()->getLocation()->getWorld();
		ChunkCache::getInstance($world, $this->getCompressor())->request($chunkX, $chunkZ)->onResolve(
		//this callback may be called synchronously or asynchronously, depending on whether the promise is resolved yet
			function(CompressBatchPromise $promise) use ($data, $world, $onCompletion, $usedChunk, $chunkX, $chunkZ) : void{
				if(!$this->isConnected()){
					return;
				}
				$currentWorld = $this->getPlayer()->getLocation()->getWorld();
				if($world !== $currentWorld or ($status = $this->getPlayer()->getUsedChunkStatus($chunkX, $chunkZ)) === null){
					$this->getLogger()->debug("Tried to send no-longer-active chunk $chunkX $chunkZ in world " . $world->getFolderName());
					return;
				}
				if(!$status->equals(UsedChunkStatus::REQUESTED())){
					//TODO: make this an error
					//this could be triggered due to the shitty way that chunk resends are handled
					//right now - not because of the spammy re-requesting, but because the chunk status reverts
					//to NEEDED if they want to be resent.
					return;
				}
				$world->timings->syncChunkSend->startTiming();
				try{
					$this->queueCompressed($promise);
					if ($data->loggedIn) {
						NetworkStackLatencyHandler::getInstance()->queue($data, function (int $timestamp) use ($data, $usedChunk, $chunkX, $chunkZ) {
							$data->world->addChunk($usedChunk, $chunkX, $chunkZ);
						});
					} else {
						$data->world->addChunk($usedChunk, $chunkX, $chunkZ);
					}
					$onCompletion();
				}finally{
					$world->timings->syncChunkSend->stopTiming();
				}
			}
		);
	}

}