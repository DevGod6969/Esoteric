<?php

namespace ethaniccc\Esoteric\command\subcommands\banwave;

use CortexPE\Commando\BaseSubCommand;
use ethaniccc\Esoteric\args\TargetArgument;
use ethaniccc\Esoteric\Esoteric;
use pocketmine\command\CommandSender;
use pocketmine\utils\TextFormat;

class BanwaveRemoveSubCommand extends BaseSubCommand {

	public function onRun(CommandSender $sender, string $aliasUsed, array $args): void {
		if (Esoteric::getInstance()->getBanwave() === null) {
			$sender->sendMessage(TextFormat::RED . "Banwaves are disabled");
			return;
		}

		$selected = $args['player'];
		Esoteric::getInstance()->getBanwave()->removeFromList($selected->getName());
		$sender->sendMessage(TextFormat::GREEN . "{$selected->getName()} was removed from the ban wave");
	}

	protected function prepare(): void {
		$this->setPermission('ac.command.banwave.remove');
		$this->registerArgument(0, new TargetArgument("player"));
	}
}






