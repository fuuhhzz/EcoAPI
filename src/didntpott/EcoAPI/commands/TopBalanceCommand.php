<?php

namespace didntpott\EcoAPI\commands;

use didntpott\EcoAPI\EcoAPI;
use didntpott\EcoAPI\utils\MessageHandler;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;

class TopBalanceCommand extends Command
{
    private const DEFAULT_LIMIT = 10;

    public function __construct()
    {
        parent::__construct("topbalance", "View players with highest balance", "/topbalance [limit]", ["topbal", "baltop"]);
        $this->setPermission("economy.commands.default");
    }

    public function execute(CommandSender $sender, string $commandLabel, array $args): bool
    {
        $limit = self::DEFAULT_LIMIT;

        if (isset($args[0]) && is_numeric($args[0]) && $args[0] > 0) {
            $limit = (int)$args[0];
            if ($limit > 100) {
                $limit = 100;
            }
        }

        $api = EcoAPI::getInstance();
        $db = $api->getDatabase();
        $cache = $api->isCacheEnabled() ? $api->getPlayerCache() : [];
        $queryLimit = $limit + count($cache);

        $stmt = $db->prepare("SELECT player_name, balance FROM player ORDER BY balance DESC LIMIT :limit");

        if (!$stmt) {
            $sender->sendMessage(MessageHandler::getInstance()->getMessage("error.database"));
            return true;
        }

        $stmt->bindValue(":limit", $queryLimit, SQLITE3_INTEGER);
        $result = $stmt->execute();

        if (!$result) {
            $sender->sendMessage(MessageHandler::getInstance()->getMessage("error.database"));
            return true;
        }

        $economy = $api->getEconomy();
        $balances = [];

        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $balances[$row['player_name']] = (float)$row['balance'];
        }

        foreach ($cache as $playerName => $data) {
            $balances[$playerName] = $data['balance'] ?? 0.0;
        }

        arsort($balances, SORT_NUMERIC);
        $topBalance = array_slice($balances, 0, $limit, true);

        $sender->sendMessage(MessageHandler::getInstance()->getMessage("topbalance.header", [
            "count" => count($topBalance)
        ]));

        if (empty($topBalance)) {
            $sender->sendMessage(MessageHandler::getInstance()->getMessage("topbalance.empty"));
            return true;
        }

        $position = 1;
        foreach ($topBalance as $playerName => $balance) {
            $sender->sendMessage(MessageHandler::getInstance()->getMessage("topbalance.entry", [
                "position" => $position,
                "player" => $playerName,
                "balance" => $economy->formatCurrency($balance)
            ]));
            $position++;
        }

        return true;
    }
}
