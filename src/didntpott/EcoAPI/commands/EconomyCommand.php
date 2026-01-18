<?php

namespace didntpott\EcoAPI\commands;

use didntpott\EcoAPI\EcoAPI;
use didntpott\EcoAPI\utils\MessageHandler;
use jojoe77777\FormAPI\CustomForm;
use jojoe77777\FormAPI\SimpleForm;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\player\Player;

class EconomyCommand extends Command
{
    public function __construct()
    {
        parent::__construct("economy", "Manage player economy", "/economy", ["eco"]);
        $this->setPermission("economy.commands");
    }

    public function execute(CommandSender $sender, string $commandLabel, array $args): bool
    {
        if (!$sender->hasPermission("economy.commands")) {
            $sender->sendMessage(MessageHandler::getInstance()->getMessage("error.no-permission"));
            return true;
        }

        if (count($args) === 0) {
            if ($sender instanceof Player && $this->isFormApiAvailable()) {
                $this->showMainForm($sender);
                return true;
            }

            $this->showHelp($sender);
            return true;
        }

        $action = strtolower($args[0]);
        if (count($args) < 2) {
            $this->showActionHelp($sender, $action);
            return true;
        }

        $targetName = $args[1];
        $target = EcoAPI::getInstance()->getServer()->getPlayerExact($targetName);

        if ($target === null) {
            $sender->sendMessage(MessageHandler::getInstance()->getMessage("error.player-not-found", [
                "player" => $targetName
            ]));
            return true;
        }

        $economy = EcoAPI::getInstance()->getEconomy();

        switch ($action) {
            case "give":
            case "add":
                if (!$sender->hasPermission("economy.command.give")) {
                    $sender->sendMessage(MessageHandler::getInstance()->getMessage("error.no-permission"));
                    return true;
                }

                if (count($args) < 3) {
                    $sender->sendMessage(MessageHandler::getInstance()->getMessage("help.usage-give"));
                    return true;
                }

                $amount = (float)$args[2];
                if ($amount <= 0) {
                    $sender->sendMessage(MessageHandler::getInstance()->getMessage("error.amount-positive"));
                    return true;
                }

                $economy->addBalance($target, $amount);
                $newBalance = $economy->formatCurrency($economy->getBalance($target));

                $sender->sendMessage(MessageHandler::getInstance()->getMessage("economy.give-success-sender", [
                    "amount" => $economy->formatCurrency($amount),
                    "player" => $target->getName(),
                    "balance" => $newBalance
                ]));

                $target->sendMessage(MessageHandler::getInstance()->getMessage("economy.give-success-receiver", [
                    "amount" => $economy->formatCurrency($amount),
                    "balance" => $newBalance
                ]));
                break;

            case "take":
            case "remove":
                if (!$sender->hasPermission("economy.command.take")) {
                    $sender->sendMessage(MessageHandler::getInstance()->getMessage("error.no-permission"));
                    return true;
                }

                if (count($args) < 3) {
                    $sender->sendMessage(MessageHandler::getInstance()->getMessage("help.usage-take"));
                    return true;
                }

                $amount = (float)$args[2];
                if ($amount <= 0) {
                    $sender->sendMessage(MessageHandler::getInstance()->getMessage("error.amount-positive"));
                    return true;
                }

                if (!$economy->reduceBalance($target, $amount)) {
                    $sender->sendMessage(MessageHandler::getInstance()->getMessage("error.insufficient-target-balance", [
                        "player" => $target->getName()
                    ]));
                    return true;
                }

                $newBalance = $economy->formatCurrency($economy->getBalance($target));

                $sender->sendMessage(MessageHandler::getInstance()->getMessage("economy.take-success-sender", [
                    "amount" => $economy->formatCurrency($amount),
                    "player" => $target->getName(),
                    "balance" => $newBalance
                ]));

                $target->sendMessage(MessageHandler::getInstance()->getMessage("economy.take-success-receiver", [
                    "amount" => $economy->formatCurrency($amount),
                    "balance" => $newBalance
                ]));
                break;

            case "set":
                if (!$sender->hasPermission("economy.command.set")) {
                    $sender->sendMessage(MessageHandler::getInstance()->getMessage("error.no-permission"));
                    return true;
                }

                if (count($args) < 3) {
                    $sender->sendMessage(MessageHandler::getInstance()->getMessage("help.usage-set"));
                    return true;
                }

                $amount = (float)$args[2];
                if ($amount < 0) {
                    $sender->sendMessage(MessageHandler::getInstance()->getMessage("error.amount-negative"));
                    return true;
                }

                $economy->updatePlayerField($target, 'balance', $amount);
                $formattedAmount = $economy->formatCurrency($amount);

                $sender->sendMessage(MessageHandler::getInstance()->getMessage("economy.set-success-sender", [
                    "player" => $target->getName(),
                    "amount" => $formattedAmount
                ]));

                $target->sendMessage(MessageHandler::getInstance()->getMessage("economy.set-success-receiver", [
                    "amount" => $formattedAmount
                ]));
                break;

            case "reset":
                if (!$sender->hasPermission("economy.command.reset")) {
                    $sender->sendMessage(MessageHandler::getInstance()->getMessage("error.no-permission"));
                    return true;
                }

                $economy->resetPlayerData($target);

                $sender->sendMessage(MessageHandler::getInstance()->getMessage("economy.reset-success-sender", [
                    "player" => $target->getName()
                ]));

                $target->sendMessage(MessageHandler::getInstance()->getMessage("economy.reset-success-receiver"));
                break;

            case "info":
                if (!$sender->hasPermission("economy.command.info") && $sender->getName() !== $target->getName()) {
                    $sender->sendMessage(MessageHandler::getInstance()->getMessage("error.no-permission"));
                    return true;
                }

                $balance = $economy->formatCurrency($economy->getBalance($target));
                $tokens = $economy->formatCurrency($economy->getTokens($target));
                $multiplier = $economy->getMultiplier($target);

                $sender->sendMessage(MessageHandler::getInstance()->getMessage("economy.info-header", [
                    "player" => $target->getName()
                ]));

                $sender->sendMessage(MessageHandler::getInstance()->getMessage("economy.info-balance", [
                    "balance" => $balance
                ]));

                $sender->sendMessage(MessageHandler::getInstance()->getMessage("economy.info-tokens", [
                    "tokens" => $tokens
                ]));

                $sender->sendMessage(MessageHandler::getInstance()->getMessage("economy.info-multiplier", [
                    "multiplier" => $multiplier
                ]));
                break;

            default:
                $this->showHelp($sender);
                return true;
        }

        return true;
    }

    private function showMainForm(Player $player): void
    {
        $actions = [];

        if ($player->hasPermission("economy.command.give")) {
            $actions[] = ["label" => "Give money", "action" => "give", "needs_amount" => true];
        }

        if ($player->hasPermission("economy.command.take")) {
            $actions[] = ["label" => "Take money", "action" => "take", "needs_amount" => true];
        }

        if ($player->hasPermission("economy.command.set")) {
            $actions[] = ["label" => "Set balance", "action" => "set", "needs_amount" => true];
        }

        if ($player->hasPermission("economy.command.reset")) {
            $actions[] = ["label" => "Reset player", "action" => "reset", "needs_amount" => false];
        }

        if ($player->hasPermission("economy.command.info")) {
            $actions[] = ["label" => "Player info", "action" => "info", "needs_amount" => false];
        }

        if (empty($actions)) {
            $player->sendMessage(MessageHandler::getInstance()->getMessage("error.no-permission"));
            return;
        }

        $form = new SimpleForm(function (Player $player, ?int $data) use ($actions): void {
            if ($data === null) {
                return;
            }

            $selected = $actions[$data] ?? null;
            if ($selected === null) {
                return;
            }

            if ($selected["needs_amount"]) {
                $this->showPlayerAmountForm($player, $selected["action"]);
                return;
            }

            $this->showPlayerForm($player, $selected["action"]);
        });

        $form->setTitle("Economy");
        $form->setContent("Select an action");
        foreach ($actions as $action) {
            $form->addButton($action["label"]);
        }

        $player->sendForm($form);
    }

    private function showPlayerAmountForm(Player $player, string $action): void
    {
        $playerNames = $this->getOnlinePlayerNames();
        if (empty($playerNames)) {
            $player->sendMessage(MessageHandler::getInstance()->getMessage("error.player-not-found", [
                "player" => "any online player"
            ]));
            return;
        }

        $form = new CustomForm(function (Player $player, ?array $data) use ($playerNames, $action): void {
            if ($data === null) {
                return;
            }

            $playerIndex = (int)($data[0] ?? -1);
            $amount = (float)($data[1] ?? 0);
            if (!isset($playerNames[$playerIndex])) {
                return;
            }

            $targetName = $playerNames[$playerIndex];
            $args = [$action, $targetName, (string)$amount];
            $this->execute($player, "economy", $args);
        });

        $form->setTitle("Economy: " . ucfirst($action));
        $form->addDropdown("Player", $playerNames);
        $form->addInput("Amount", "100", "0");
        $player->sendForm($form);
    }

    private function showPlayerForm(Player $player, string $action): void
    {
        $playerNames = $this->getOnlinePlayerNames();
        if (empty($playerNames)) {
            $player->sendMessage(MessageHandler::getInstance()->getMessage("error.player-not-found", [
                "player" => "any online player"
            ]));
            return;
        }

        $form = new CustomForm(function (Player $player, ?array $data) use ($playerNames, $action): void {
            if ($data === null) {
                return;
            }

            $playerIndex = (int)($data[0] ?? -1);
            if (!isset($playerNames[$playerIndex])) {
                return;
            }

            $targetName = $playerNames[$playerIndex];
            $this->execute($player, "economy", [$action, $targetName]);
        });

        $form->setTitle("Economy: " . ucfirst($action));
        $form->addDropdown("Player", $playerNames);
        $player->sendForm($form);
    }

    private function getOnlinePlayerNames(): array
    {
        $players = [];
        foreach (EcoAPI::getInstance()->getServer()->getOnlinePlayers() as $onlinePlayer) {
            $players[] = $onlinePlayer->getName();
        }

        return $players;
    }

    private function isFormApiAvailable(): bool
    {
        if (!class_exists(SimpleForm::class) || !class_exists(CustomForm::class)) {
            return false;
        }

        return EcoAPI::getInstance()->getConfig()->get("use-forms", true);
    }

    private function showHelp(CommandSender $sender): void
    {
        $messageHandler = MessageHandler::getInstance();
        $sender->sendMessage($messageHandler->getMessage("help.header"));
        $sender->sendMessage($messageHandler->getMessage("help.available-actions"));

        if ($sender->hasPermission("economy.command.give")) {
            $sender->sendMessage($messageHandler->getMessage("help.give"));
        }

        if ($sender->hasPermission("economy.command.take")) {
            $sender->sendMessage($messageHandler->getMessage("help.take"));
        }

        if ($sender->hasPermission("economy.command.set")) {
            $sender->sendMessage($messageHandler->getMessage("help.set"));
        }

        if ($sender->hasPermission("economy.command.reset")) {
            $sender->sendMessage($messageHandler->getMessage("help.reset"));
        }

        $sender->sendMessage($messageHandler->getMessage("help.info"));
    }

    private function showActionHelp(CommandSender $sender, string $action): void
    {
        $messageHandler = MessageHandler::getInstance();

        switch ($action) {
            case "give":
            case "add":
                $sender->sendMessage($messageHandler->getMessage("help.usage-give"));
                break;

            case "take":
            case "remove":
                $sender->sendMessage($messageHandler->getMessage("help.usage-take"));
                break;

            case "set":
                $sender->sendMessage($messageHandler->getMessage("help.usage-set"));
                break;

            case "reset":
                $sender->sendMessage($messageHandler->getMessage("help.usage-reset"));
                break;

            case "info":
                $sender->sendMessage($messageHandler->getMessage("help.usage-info"));
                break;

            default:
                $this->showHelp($sender);
                break;
        }
    }
}
