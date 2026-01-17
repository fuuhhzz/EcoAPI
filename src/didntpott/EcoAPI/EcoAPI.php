<?php

namespace didntpott\EcoAPI;

use didntpott\EcoAPI\commands\BalanceCommand;
use didntpott\EcoAPI\commands\EconomyCommand;
use didntpott\EcoAPI\commands\PayCommand;
use didntpott\EcoAPI\commands\TokenCommand;
use didntpott\EcoAPI\commands\TopBalanceCommand;
use didntpott\EcoAPI\provider\Economy;
use didntpott\EcoAPI\utils\MessageHandler;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\utils\SingletonTrait;
use SQLite3;

class EcoAPI extends PluginBase implements Listener
{

    use SingletonTrait;

    private SQLite3 $db;
    private Economy $economy;
    /** @var array<string, array<string, float>> */
    private array $playerCache = [];
    /** @var array<string, bool> */
    private array $playerDirty = [];
    private bool $useCache = true;
    private ?\SQLite3Stmt $upsertPlayerStmt = null;

    public function onEnable(): void
    {
        $this->saveDefaultConfig();
        $this->saveResource("config.yml", false);

        $this->useCache = $this->getConfig()->get("use-cache", true);
        $startingBalance = $this->getConfig()->get("starting-balance", 0.0);
        $startingTokens = $this->getConfig()->get("starting-tokens", 0.0);

        $this->initDatabase();

        self::setInstance($this);

        MessageHandler::getInstance();

        $this->economy = new Economy($this, $startingBalance, $startingTokens);

        // Register commands
        $commandMap = $this->getServer()->getCommandMap();
        $commandMap->register("ecoapi", new EconomyCommand());
        $commandMap->register("balance", new BalanceCommand());
        $commandMap->register("token", new TokenCommand());
        $commandMap->register("pay", new PayCommand());
        $commandMap->register("topbalance", new TopBalanceCommand());

        $this->getServer()->getPluginManager()->registerEvents($this, $this);
    }

    private function initDatabase(): void
    {
        @mkdir($this->getDataFolder());
        $this->db = new SQLite3($this->getDataFolder() . "economy.db");
        $this->db->exec("PRAGMA foreign_keys = ON;");
        $this->db->exec("PRAGMA journal_mode = WAL;");
        $this->db->exec("PRAGMA synchronous = NORMAL;");

        $this->db->exec("CREATE TABLE IF NOT EXISTS player (
            player_name TEXT PRIMARY KEY NOT NULL,
            balance REAL DEFAULT 0,
            tokens REAL DEFAULT 0,
            multiplier REAL DEFAULT 1
        )");

        $this->db->exec("CREATE INDEX IF NOT EXISTS player_name_idx ON player (player_name);");
    }

    public function reloadMessages(): void
    {
        $this->reloadConfig();
        MessageHandler::getInstance()->reload();
    }

    public function onDisable(): void
    {
        if ($this->useCache) {
            $this->saveAllPlayerData();
        }

        if (isset($this->db)) {
            $this->db->close();
        }
        $this->getLogger()->info("CustomEconomy has been disabled!");
    }

    private function saveAllPlayerData(): void
    {
        if (empty($this->playerCache)) {
            return;
        }

        $stmt = $this->getUpsertPlayerStatement();
        if ($stmt === null) {
            return;
        }

        $this->db->exec("BEGIN IMMEDIATE");

        foreach ($this->playerCache as $name => $data) {
            if (!$this->isPlayerDirty($name)) {
                continue;
            }

            $stmt->bindValue(":balance", $data['balance'], SQLITE3_FLOAT);
            $stmt->bindValue(":tokens", $data['tokens'], SQLITE3_FLOAT);
            $stmt->bindValue(":multiplier", $data['multiplier'], SQLITE3_FLOAT);
            $stmt->bindValue(":player_name", $name, SQLITE3_TEXT);
            $stmt->execute();

            $this->playerDirty[$name] = false;
        }

        $this->db->exec("COMMIT");
    }

    public function onPlayerJoin(PlayerJoinEvent $event): void
    {
        if (!$this->useCache) return;

        $this->economy->primeCache($event->getPlayer());
    }

    public function onPlayerQuit(PlayerQuitEvent $event): void
    {
        if (!$this->useCache) return;

        $player = $event->getPlayer();
        $name = strtolower($player->getName());

        $this->savePlayerData($player);
        unset($this->playerCache[$name]);
        unset($this->playerDirty[$name]);
    }

    private function savePlayerData(Player $player): void
    {
        $name = strtolower($player->getName());
        if (!isset($this->playerCache[$name]) || !$this->isPlayerDirty($name)) return;

        $data = $this->playerCache[$name];
        $stmt = $this->getUpsertPlayerStatement();
        if ($stmt === null) {
            return;
        }

        $stmt->bindValue(":balance", $data['balance'], SQLITE3_FLOAT);
        $stmt->bindValue(":tokens", $data['tokens'], SQLITE3_FLOAT);
        $stmt->bindValue(":multiplier", $data['multiplier'], SQLITE3_FLOAT);
        $stmt->bindValue(":player_name", $name, SQLITE3_TEXT);
        $stmt->execute();

        $this->playerDirty[$name] = false;
    }

    public function getDatabase(): SQLite3
    {
        return $this->db;
    }

    public function getEconomy(): Economy
    {
        return $this->economy;
    }

    public function getPlayerCache(): array
    {
        return $this->playerCache;
    }

    public function setPlayerCache(string $playerName, array $data): void
    {
        if (!$this->useCache) return;

        $playerName = strtolower($playerName);
        $this->playerCache[$playerName] = [
            'balance' => $data['balance'] ?? 0.0,
            'tokens' => $data['tokens'] ?? 0.0,
            'multiplier' => $data['multiplier'] ?? 1.0
        ];
        $this->playerDirty[$playerName] = $this->playerDirty[$playerName] ?? false;
    }

    public function hasCachedPlayer(string $playerName): bool
    {
        return isset($this->playerCache[strtolower($playerName)]);
    }

    public function isCacheEnabled(): bool
    {
        return $this->useCache;
    }

    public function updateCache(string $playerName, string $field, float $value, bool $markDirty = true): void
    {
        if (!$this->useCache) return;

        $playerName = strtolower($playerName);
        if (!isset($this->playerCache[$playerName])) {
            $this->playerCache[$playerName] = [
                'balance' => 0.0,
                'tokens' => 0.0,
                'multiplier' => 1.0
            ];
        }

        $this->playerCache[$playerName][$field] = $value;
        if ($markDirty) {
            $this->playerDirty[$playerName] = true;
        }
    }

    public function getFromCache(string $playerName, string $field, float $default = 0.0): float
    {
        if (!$this->useCache) return $default;

        $playerName = strtolower($playerName);
        return $this->playerCache[$playerName][$field] ?? $default;
    }

    public function markPlayerDirty(string $playerName): void
    {
        if (!$this->useCache) return;

        $this->playerDirty[strtolower($playerName)] = true;
    }

    private function isPlayerDirty(string $playerName): bool
    {
        return $this->playerDirty[strtolower($playerName)] ?? false;
    }

    private function getUpsertPlayerStatement(): ?\SQLite3Stmt
    {
        if ($this->upsertPlayerStmt === null) {
            $stmt = $this->db->prepare(
                "INSERT INTO player (player_name, balance, tokens, multiplier)
                VALUES (:player_name, :balance, :tokens, :multiplier)
                ON CONFLICT(player_name) DO UPDATE SET
                    balance = excluded.balance,
                    tokens = excluded.tokens,
                    multiplier = excluded.multiplier"
            );
            if ($stmt === false) {
                $this->getLogger()->error("Failed to prepare upsert statement for player data.");
                return null;
            }
            $this->upsertPlayerStmt = $stmt;
        }

        return $this->upsertPlayerStmt;
    }
}
