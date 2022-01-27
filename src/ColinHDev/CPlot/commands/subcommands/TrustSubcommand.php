<?php

namespace ColinHDev\CPlot\commands\subcommands;

use ColinHDev\CPlot\commands\Subcommand;
use ColinHDev\CPlot\provider\DataProvider;
use ColinHDev\CPlotAPI\attributes\BooleanAttribute;
use ColinHDev\CPlotAPI\players\PlayerData;
use ColinHDev\CPlotAPI\players\settings\SettingIDs;
use ColinHDev\CPlotAPI\plots\flags\FlagIDs;
use ColinHDev\CPlotAPI\plots\Plot;
use ColinHDev\CPlotAPI\plots\PlotPlayer;
use ColinHDev\CPlotAPI\worlds\WorldSettings;
use pocketmine\command\CommandSender;
use pocketmine\player\Player;
use pocketmine\Server;
use poggit\libasynql\SqlError;

class TrustSubcommand extends Subcommand {

    public function execute(CommandSender $sender, array $args) : \Generator {
        if (!$sender instanceof Player) {
            $sender->sendMessage($this->getPrefix() . $this->translateString("trust.senderNotOnline"));
            return;
        }

        if (count($args) === 0) {
            $sender->sendMessage($this->getPrefix() . $this->getUsage());
            return;
        }

        $player = null;
        if ($args[0] !== "*") {
            $player = Server::getInstance()->getPlayerByPrefix($args[0]);
            if ($player instanceof Player) {
                $playerUUID = $player->getUniqueId()->getBytes();
                $playerName = $player->getName();
            } else {
                $sender->sendMessage($this->getPrefix() . $this->translateString("trust.playerNotOnline", [$args[0]]));
                $playerName = $args[0];
                $playerData = yield from DataProvider::getInstance()->awaitPlayerDataByName($playerName);
                if ($playerData === null) {
                    $sender->sendMessage($this->getPrefix() . $this->translateString("trust.playerNotFound", [$playerName]));
                    return;
                }
                $playerUUID = $playerData->getPlayerUUID();
            }
            if ($playerUUID === $sender->getUniqueId()->getBytes()) {
                $sender->sendMessage($this->getPrefix() . $this->translateString("trust.senderIsPlayer"));
                return;
            }
        } else {
            $playerUUID = "*";
            $playerName = "*";
        }

        if (!((yield from DataProvider::getInstance()->awaitWorld($sender->getWorld()->getFolderName())) instanceof WorldSettings)) {
            $sender->sendMessage($this->getPrefix() . $this->translateString("trust.noPlotWorld"));
            return;
        }

        $plot = yield from Plot::awaitFromPosition($sender->getPosition());
        if (!($plot instanceof Plot)) {
            $sender->sendMessage($this->getPrefix() . $this->translateString("trust.noPlot"));
            return;
        }

        if (!$plot->hasPlotOwner()) {
            $sender->sendMessage($this->getPrefix() . $this->translateString("trust.noPlotOwner"));
            return;
        }
        if (!$sender->hasPermission("cplot.admin.trust")) {
            if (!$plot->isPlotOwner($sender->getUniqueId()->getBytes())) {
                $sender->sendMessage($this->getPrefix() . $this->translateString("trust.notPlotOwner"));
                return;
            }
        }

        /** @var BooleanAttribute $flag */
        $flag = $plot->getFlagNonNullByID(FlagIDs::FLAG_SERVER_PLOT);
        if ($flag->getValue() === true) {
            $sender->sendMessage($this->getPrefix() . $this->translateString("trust.serverPlotFlag", [$flag->getID()]));
            return;
        }

        if ($plot->isPlotTrustedExact($playerUUID)) {
            $sender->sendMessage($this->getPrefix() . $this->translateString("trust.playerAlreadyTrusted", [$playerName]));
            return;
        }

        $plotPlayer = new PlotPlayer($playerUUID, PlotPlayer::STATE_TRUSTED);
        $plot->addPlotPlayer($plotPlayer);
        yield from DataProvider::getInstance()->savePlotPlayer($plot, $plotPlayer);
        $sender->sendMessage($this->getPrefix() . $this->translateString("trust.success", [$playerName]));

        if ($player instanceof Player) {
            $playerData = yield from DataProvider::getInstance()->awaitPlayerDataByUUID($playerUUID);
            if (!($playerData instanceof PlayerData)) {
                return;
            }
            /** @var BooleanAttribute $setting */
            $setting = $playerData->getSettingNonNullByID(SettingIDs::SETTING_INFORM_TRUSTED_ADD);
            if ($setting->getValue() === true) {
                $player->sendMessage($this->getPrefix() . $this->translateString("trust.success.player", [$sender->getName(), $plot->getWorldName(), $plot->getX(), $plot->getZ()]));
            }
        }
    }

    /**
     * @param \Throwable $error
     */
    public function onError(CommandSender $sender, \Throwable $error) : void {
        if ($sender instanceof Player && !$sender->isConnected()) {
            return;
        }
        $sender->sendMessage($this->getPrefix() . $this->translateString("trust.saveError", [$error->getMessage()]));
    }
}