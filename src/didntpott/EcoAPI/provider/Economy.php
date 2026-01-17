<?php

namespace didntpott\EcoAPI\provider;

use didntpott\EcoAPI\EcoAPI;
use InvalidArgumentException;
use pocketmine\player\Player;
use RuntimeException;
use SQLite3;

class Economy
{

    private const ALLOWED_FIELDS = ['balance', 'tokens', 'multiplier'];

    private SQLite3 $db;
    private EcoAPI $plugin;
    private float $startingBalance;
    private float $startingTokens;
    /** @var array<string, \SQLite3Stmt> */
    private array $upsertFieldStatements = [];
    /** @var array<string, \SQLite3Stmt> */
    private array $selectFieldStatements = [];

    public function __construct(EcoAPI $plugin, float $startingBalance = 0.0, float $startingTokens = 0.0)
    {
        $this->plugin = $plugin;
        $this->db = $plugin->getDatabase();
        $this->startingBalance = $startingBalance;
        $this->startingTokens = $startingTokens;
    }

    public function getMultiplier(Player $player): float
    {
        return $this->getPlayerField($player, 'multiplier', 1.0);
    }

    public function getPlayerField(Player $player, string $field, float $default = 0.0): float
    {
        if (!in_array($field, self::ALLOWED_FIELDS, true)) {
            throw new InvalidArgumentException("Invalid field: $field");
        }

        $playerName = strtolower($player->getName());

        if ($this->plugin->isCacheEnabled()) {
            if (!$this->plugin->hasCachedPlayer($playerName)) {
                $result = $this->fetchPlayerData($playerName);
                $this->plugin->setPlayerCache($playerName, $result['data']);
                if (!$result['exists']) {
                    $this->plugin->markPlayerDirty($playerName);
                }
            }

            return $this->plugin->getFromCache($playerName, $field, $default);
        }

        $stmt = $this->getSelectFieldStatement($field);
        $stmt->bindValue(":player_name", $playerName, SQLITE3_TEXT);

        $result = $stmt->execute();
        if (!$result) {
            throw new RuntimeException("Failed to execute statement while retrieving field `$field`.");
        }

        $row = $result->fetchArray(SQLITE3_ASSOC);
        if ($row === false || !isset($row[$field])) {
            // Update cache with default value
            if ($this->plugin->isCacheEnabled()) {
                $this->plugin->updateCache($playerName, $field, $default);
            }
            return $default;
        }

        $value = (float)$row[$field];

        if ($this->plugin->isCacheEnabled()) {
            $this->plugin->updateCache($playerName, $field, $value);
        }

        return $value;
    }

    public function setMultiplier(Player $player, float $multiplier): void
    {
        if ($multiplier < 0) {
            throw new InvalidArgumentException("Multiplier cannot be negative.");
        }
        $this->updatePlayerField($player, 'multiplier', $multiplier);
    }

    public function updatePlayerField(Player $player, string $field, float $value): bool
    {
        if (!in_array($field, self::ALLOWED_FIELDS, true)) {
            throw new InvalidArgumentException("Invalid field: $field");
        }

        $playerName = strtolower($player->getName());

        if ($this->plugin->isCacheEnabled()) {
            if (!$this->plugin->hasCachedPlayer($playerName)) {
                $result = $this->fetchPlayerData($playerName);
                $this->plugin->setPlayerCache($playerName, $result['data']);
                if (!$result['exists']) {
                    $this->plugin->markPlayerDirty($playerName);
                }
            }

            $this->plugin->updateCache($playerName, $field, $value);
            return true;
        }

        $stmt = $this->getUpsertFieldStatement($field);
        $stmt->bindValue(":player_name", $playerName, SQLITE3_TEXT);
        $stmt->bindValue(":value", $value, SQLITE3_FLOAT);
        $stmt->execute();

        return true;
    }

    public function transferBalance(Player $sender, Player $receiver, float $amount): bool
    {
        if ($amount <= 0) {
            throw new InvalidArgumentException("Transfer amount must be positive.");
        }

        if (!$this->reduceBalance($sender, $amount)) {
            return false;
        }

        $this->addBalance($receiver, $amount);
        return true;
    }

    public function reduceBalance(Player $player, float $amount): bool
    {
        if ($amount < 0) {
            throw new InvalidArgumentException("Amount to reduce must be non-negative.");
        }
        $currentBalance = $this->getBalance($player);
        if ($currentBalance < $amount) {
            return false;
        }
        $this->updatePlayerField($player, 'balance', $currentBalance - $amount);
        return true;
    }

    public function getBalance(Player $player): float
    {
        return $this->getPlayerField($player, 'balance', $this->startingBalance);
    }

    public function addBalance(Player $player, float $amount): void
    {
        if ($amount < 0) {
            throw new InvalidArgumentException("Amount to add must be non-negative.");
        }
        $newBalance = $this->getBalance($player) + $amount;
        $this->updatePlayerField($player, 'balance', $newBalance);
    }

    public function transferTokens(Player $sender, Player $receiver, float $amount): bool
    {
        if ($amount <= 0) {
            throw new InvalidArgumentException("Transfer amount must be positive.");
        }

        if (!$this->reduceTokens($sender, $amount)) {
            return false;
        }

        $this->addTokens($receiver, $amount);
        return true;
    }

    public function reduceTokens(Player $player, float $amount): bool
    {
        if ($amount < 0) {
            throw new InvalidArgumentException("Amount to reduce must be non-negative.");
        }
        $currentTokens = $this->getTokens($player);
        if ($currentTokens < $amount) {
            return false;
        }
        $this->updatePlayerField($player, 'tokens', $currentTokens - $amount);
        return true;
    }

    public function getTokens(Player $player): float
    {
        return $this->getPlayerField($player, 'tokens', $this->startingTokens);
    }

    public function addTokens(Player $player, float $amount): void
    {
        if ($amount < 0) {
            throw new InvalidArgumentException("Amount to add must be non-negative.");
        }
        $newTokens = $this->getTokens($player) + $amount;
        $this->updatePlayerField($player, 'tokens', $newTokens);
    }

    public function resetPlayerData(Player $player): void
    {
        $this->updatePlayerField($player, 'balance', $this->startingBalance);
        $this->updatePlayerField($player, 'tokens', $this->startingTokens);
        $this->updatePlayerField($player, 'multiplier', 1.0);
    }

    public function formatCurrency(float $amount): string
    {
        return number_format($amount, 2, '.', ',');
    }

    public function primeCache(Player $player): void
    {
        if (!$this->plugin->isCacheEnabled()) {
            return;
        }

        $playerName = strtolower($player->getName());
        if ($this->plugin->hasCachedPlayer($playerName)) {
            return;
        }

        $result = $this->fetchPlayerData($playerName);
        $this->plugin->setPlayerCache($playerName, $result['data']);
        if (!$result['exists']) {
            $this->plugin->markPlayerDirty($playerName);
        }
    }

    private function fetchPlayerData(string $playerName): array
    {
        $stmt = $this->db->prepare("SELECT balance, tokens, multiplier FROM player WHERE player_name = :player_name");
        if (!$stmt) {
            throw new RuntimeException("Failed to prepare statement to retrieve player data.");
        }
        $stmt->bindValue(":player_name", $playerName, SQLITE3_TEXT);

        $result = $stmt->execute();
        if (!$result) {
            throw new RuntimeException("Failed to execute statement while retrieving player data.");
        }

        $row = $result->fetchArray(SQLITE3_ASSOC);
        if ($row === false) {
            return [
                'data' => $this->getDefaultData(),
                'exists' => false
            ];
        }

        return [
            'data' => [
                'balance' => isset($row['balance']) ? (float)$row['balance'] : $this->startingBalance,
                'tokens' => isset($row['tokens']) ? (float)$row['tokens'] : $this->startingTokens,
                'multiplier' => isset($row['multiplier']) ? (float)$row['multiplier'] : 1.0
            ],
            'exists' => true
        ];
    }

    private function getDefaultData(): array
    {
        return [
            'balance' => $this->startingBalance,
            'tokens' => $this->startingTokens,
            'multiplier' => 1.0
        ];
    }

    private function getUpsertFieldStatement(string $field): \SQLite3Stmt
    {
        if (!isset($this->upsertFieldStatements[$field])) {
            $stmt = $this->db->prepare(
                "INSERT INTO player (player_name, $field)
                VALUES (:player_name, :value)
                ON CONFLICT(player_name) DO UPDATE SET
                    $field = excluded.$field"
            );
            if (!$stmt) {
                throw new RuntimeException("Failed to prepare upsert statement for field `$field`.");
            }
            $this->upsertFieldStatements[$field] = $stmt;
        }

        return $this->upsertFieldStatements[$field];
    }

    private function getSelectFieldStatement(string $field): \SQLite3Stmt
    {
        if (!isset($this->selectFieldStatements[$field])) {
            $stmt = $this->db->prepare("SELECT $field FROM player WHERE player_name = :player_name");
            if (!$stmt) {
                throw new RuntimeException("Failed to prepare statement to retrieve field `$field`.");
            }
            $this->selectFieldStatements[$field] = $stmt;
        }

        return $this->selectFieldStatements[$field];
    }
}
