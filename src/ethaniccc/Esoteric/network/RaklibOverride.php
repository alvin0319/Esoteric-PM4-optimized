<?php

namespace ethaniccc\Esoteric\network;

use Exception;
use pocketmine\network\AdvancedNetworkInterface;
use pocketmine\network\mcpe\compression\ZlibCompressor;
use pocketmine\network\mcpe\convert\TypeConverter;
use pocketmine\network\mcpe\NetworkSession;
use pocketmine\network\mcpe\PacketBroadcaster;
use pocketmine\network\mcpe\protocol\PacketPool;
use pocketmine\network\mcpe\protocol\ProtocolInfo;
use pocketmine\network\mcpe\raklib\PthreadsChannelReader;
use pocketmine\network\mcpe\raklib\PthreadsChannelWriter;
use pocketmine\network\mcpe\raklib\RakLibInterface;
use pocketmine\network\mcpe\raklib\RakLibPacketSender;
use pocketmine\network\mcpe\raklib\RakLibServer;
use pocketmine\network\mcpe\StandardPacketBroadcaster;
use pocketmine\network\Network;
use pocketmine\network\PacketHandlingException;
use pocketmine\Server;
use pocketmine\snooze\SleeperNotifier;
use pocketmine\utils\Filesystem;
use pocketmine\utils\Utils;
use raklib\protocol\EncapsulatedPacket;
use raklib\protocol\PacketReliability;
use raklib\server\ipc\RakLibToUserThreadMessageReceiver;
use raklib\server\ipc\UserToRakLibThreadMessageSender;
use raklib\server\ServerEventListener;
use raklib\utils\InternetAddress;
use RuntimeException;
use Threaded;
use function addcslashes;
use function bin2hex;
use function implode;
use function mt_rand;
use function random_bytes;
use function rtrim;
use function substr;
use const PHP_INT_MAX;
use const PTHREADS_INHERIT_CONSTANTS;

class RaklibOverride extends RakLibInterface{
	/**
	 * Sometimes this gets changed when the MCPE-layer protocol gets broken to the point where old and new can't
	 * communicate. It's important that we check this to avoid catastrophes.
	 */
	private const MCPE_RAKNET_PROTOCOL_VERSION = 10;

	private const MCPE_RAKNET_PACKET_ID = "\xfe";

	/** @var Server */
	private $server;

	/** @var Network */
	private $network;

	/** @var int */
	private $rakServerId;

	/** @var RakLibServer */
	private $rakLib;

	/** @var NetworkSession[] */
	private $sessions = [];

	/** @var RakLibToUserThreadMessageReceiver */
	private $eventReceiver;
	/** @var UserToRakLibThreadMessageSender */
	private $interface;

	/** @var SleeperNotifier */
	private $sleeper;

	/** @var PacketBroadcaster */
	private $broadcaster;

	public function __construct(Server $server){
		//parent::__construct($server);
		$this->server = $server;
		$this->rakServerId = random_int(0, PHP_INT_MAX);

		$this->sleeper = new SleeperNotifier();

		$mainToThreadBuffer = new Threaded;
		$threadToMainBuffer = new Threaded;

		$this->rakLib = new RakLibServer(
			$this->server->getLogger(),
			$mainToThreadBuffer,
			$threadToMainBuffer,
			new InternetAddress($this->server->getIp(), $this->server->getPort(), 4),
			$this->rakServerId,
			(int) $this->server->getConfigGroup()->getProperty("network.max-mtu-size", 1492),
			self::MCPE_RAKNET_PROTOCOL_VERSION,
			$this->sleeper
		);
		$this->eventReceiver = new RakLibToUserThreadMessageReceiver(
			new PthreadsChannelReader($threadToMainBuffer)
		);
		$this->interface = new UserToRakLibThreadMessageSender(
			new PthreadsChannelWriter($mainToThreadBuffer)
		);

		$this->broadcaster = new StandardPacketBroadcaster($this->server);
	}

	public function start() : void{
		$this->server->getTickSleeper()->addNotifier($this->sleeper, function() : void{
			while($this->eventReceiver->handle($this)){
			}
		});
		$this->server->getLogger()->debug("Waiting for RakLib override to start...");
		$this->rakLib->startAndWait(PTHREADS_INHERIT_CONSTANTS); //HACK: MainLogger needs constants for exception logging
		$this->server->getLogger()->debug("RakLib override booted successfully");
	}

	public function setNetwork(Network $network) : void{
		$this->network = $network;
	}

	public function tick() : void{
		if(!$this->rakLib->isRunning()){
			$e = $this->rakLib->getCrashInfo();
			if($e !== null){
				throw new RuntimeException("RakLib crashed: $e");
			}
			throw new Exception("RakLib Thread crashed without crash information");
		}
	}

	public function onClientDisconnect(int $sessionId, string $reason) : void{
		if(isset($this->sessions[$sessionId])){
			$session = $this->sessions[$sessionId];
			unset($this->sessions[$sessionId]);
			$session->onClientDisconnect($reason);
		}
	}

	public function close(int $sessionId) : void{
		if(isset($this->sessions[$sessionId])){
			unset($this->sessions[$sessionId]);
			$this->interface->closeSession($sessionId);
		}
	}

	public function shutdown() : void{
		$this->server->getTickSleeper()->removeNotifier($this->sleeper);
		$this->rakLib->quit();
	}

	public function onClientConnect(int $sessionId, string $address, int $port, int $clientID) : void{
		$session = new NetworkSessionOverride(
			$this->server,
			$this->network->getSessionManager(),
			PacketPool::getInstance(),
			new RakLibPacketSender($sessionId, $this),
			$this->broadcaster,
			ZlibCompressor::getInstance(), //TODO: this shouldn't be hardcoded, but we might need the RakNet protocol version to select it
			$address,
			$port
		);
		$this->sessions[$sessionId] = $session;
	}

	public function onPacketReceive(int $sessionId, string $packet) : void{
		if(isset($this->sessions[$sessionId])){
			if($packet === "" or $packet[0] !== self::MCPE_RAKNET_PACKET_ID){
				return;
			}
			//get this now for blocking in case the player was closed before the exception was raised
			$session = $this->sessions[$sessionId];
			$address = $session->getIp();
			$buf = substr($packet, 1);
			try{
				$session->handleEncoded($buf);
			}catch(PacketHandlingException $e){
				$errorId = bin2hex(random_bytes(6));

				$logger = $session->getLogger();
				$logger->error("Bad packet (error ID $errorId): " . $e->getMessage());

				//intentionally doesn't use logException, we don't want spammy packet error traces to appear in release mode
				$logger->debug("Origin: " . Filesystem::cleanPath($e->getFile()) . "(" . $e->getLine() . ")");
				foreach(Utils::printableTrace($e->getTrace()) as $frame){
					$logger->debug($frame);
				}
				$session->disconnect("Packet processing error (Error ID: $errorId)");
				$this->interface->blockAddress($address, 5);
			}
		}
	}

	public function blockAddress(string $address, int $timeout = 300) : void{
		$this->interface->blockAddress($address, $timeout);
	}

	public function unblockAddress(string $address) : void{
		$this->interface->unblockAddress($address);
	}

	public function onRawPacketReceive(string $address, int $port, string $payload) : void{
		$this->network->processRawPacket($this, $address, $port, $payload);
	}

	public function sendRawPacket(string $address, int $port, string $payload) : void{
		$this->interface->sendRaw($address, $port, $payload);
	}

	public function addRawPacketFilter(string $regex) : void{
		$this->interface->addRawPacketFilter($regex);
	}

	public function onPacketAck(int $sessionId, int $identifierACK) : void{

	}

	public function setName(string $name) : void{
		$info = $this->server->getQueryInformation();

		$this->interface->setName(implode(";",
				[
					"MCPE",
					rtrim(addcslashes($name, ";"), '\\'),
					ProtocolInfo::CURRENT_PROTOCOL,
					ProtocolInfo::MINECRAFT_VERSION_NETWORK,
					$info->getPlayerCount(),
					$info->getMaxPlayerCount(),
					$this->rakServerId,
					$this->server->getName(),
					TypeConverter::getInstance()->protocolGameModeName($this->server->getGamemode())
				]) . ";"
		);
	}

	public function setPortCheck(bool $name) : void{
		$this->interface->setPortCheck($name);
	}

	public function setPacketLimit(int $limit) : void{
		$this->interface->setPacketsPerTickLimit($limit);
	}

	public function onBandwidthStatsUpdate(int $bytesSentDiff, int $bytesReceivedDiff) : void{
		$this->network->getBandwidthTracker()->add($bytesSentDiff, $bytesReceivedDiff);
	}

	public function putPacket(int $sessionId, string $payload, bool $immediate = true) : void{
		if(isset($this->sessions[$sessionId])){
			$pk = new EncapsulatedPacket();
			$pk->buffer = self::MCPE_RAKNET_PACKET_ID . $payload;
			$pk->reliability = PacketReliability::RELIABLE_ORDERED;
			$pk->orderChannel = 0;

			$this->interface->sendEncapsulated($sessionId, $pk, $immediate);
		}
	}

	public function onPingMeasure(int $sessionId, int $pingMS) : void{
		if(isset($this->sessions[$sessionId])){
			$this->sessions[$sessionId]->updatePing($pingMS);
		}
	}
}