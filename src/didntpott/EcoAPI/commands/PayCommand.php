<?php

namespace didntpott\EcoAPI\commands;

use didntpott\EcoAPI\EcoAPI;
use didntpott\EcoAPI\utils\MessageHandler;
use jojoe77777\FormAPI\CustomForm;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\player\Player;

class PayCommand extends Command
{
    public function __construct()
    {
        parent::__construct("pay", "Send money to another player", "/pay <player> <amount>", ["transfer"]);
        $this->setPermission("economy.commands.default");
    }

    public function execute(CommandSender $sender, string $commandLabel, array $args): bool
    {
        if (!$sender instanceof Player) {
            $sender->sendMessage(MessageHandler::getInstance()->getMessage("error.console-only"));
            return true;
        }

        if (count($args) < 2) {
            if ($this->isFormApiAvailable()) {
                $this->showPayForm($sender);
                return true;
            }

            MessageHandler::getInstance()->sendMessage($sender, "help.usage-pay");
            return true;
        }

        $targetName = $args[0];
        $amount = (float)$args[1];

        if ($amount <= 0) {
            MessageHandler::getInstance()->sendMessage($sender, "error.amount-positive");
            return true;
        }

        $target = EcoAPI::getInstance()->getServer()->getPlayerExact($targetName);
        if ($target === null) {
            MessageHandler::getInstance()->sendMessage($sender, "error.player-not-found", [
                "player" => $targetName
            ]);
            return true;
        }

        if ($sender->getName() === $target->getName()) {
            MessageHandler::getInstance()->sendMessage($sender, "error.pay-self");
            return true;
        }

        $economy = EcoAPI::getInstance()->getEconomy();
        if (!$economy->transferBalance($sender, $target, $amount)) {
            MessageHandler::getInstance()->sendMessage($sender, "error.insufficient-balance");
            return true;
        }

        $formattedAmount = $economy->formatCurrency($amount);
        $senderBalance = $economy->formatCurrency($economy->getBalance($sender));
        $targetBalance = $economy->formatCurrency($economy->getBalance($target));

        MessageHandler::getInstance()->sendMessage($sender, "pay.success-sender", [
            "amount" => $formattedAmount,
            "player" => $target->getName(),
            "balance" => $senderBalance
        ]);

        MessageHandler::getInstance()->sendMessage($target, "pay.success-receiver", [
            "amount" => $formattedAmount,
            "sender" => $sender->getName(),
            "balance" => $targetBalance
        ]);

        return true;
    }

    private function showPayForm(Player $player): void
    {
        $playerNames = [];
        foreach (EcoAPI::getInstance()->getServer()->getOnlinePlayers() as $onlinePlayer) {
            if ($onlinePlayer->getName() === $player->getName()) {
                continue;
            }
            $playerNames[] = $onlinePlayer->getName();
        }

        if (empty($playerNames)) {
            MessageHandler::getInstance()->sendMessage($player, "error.player-not-found", [
                "player" => "any online player"
            ]);
            return;
        }

        $form = new CustomForm(function (Player $player, ?array $data) use ($playerNames): void {
            if ($data === null) {
                return;
            }

            $playerIndex = (int)($data[0] ?? -1);
            $amount = (float)($data[1] ?? 0);
            if (!isset($playerNames[$playerIndex])) {
                return;
            }

            $targetName = $playerNames[$playerIndex];
            $this->execute($player, "pay", [$targetName, (string)$amount]);
        });

        $form->setTitle("Pay");
        $form->addDropdown("Player", $playerNames);
        $form->addInput("Amount", "100", "0");
        $player->sendForm($form);
    }

    private function isFormApiAvailable(): bool
    {
        if (!class_exists(CustomForm::class)) {
            return false;
        }

        return EcoAPI::getInstance()->getConfig()->get("use-forms", true);
    }
}
