# EcoAPI

A powerful and optimized economy management plugin for PocketMine 5 servers.

## Features

- **Performance-Focused**: Optimized for high-performance with optional caching system
- **Multiple Currencies**: Support for both money and tokens
- **Multiplier System**: Player-specific multipliers for economy activities
- **SQLite Database**: Persistent storage of player economy data
- **Economy Commands**: Full suite of economy management commands
- **Optional Forms UI**: FormAPI-backed menus for supported commands
- **Developer API**: Easy-to-use API for developers to integrate with
- **Customizable Messages**: All messages can be customized in the config file

## Commands

| Command | Description | Permission |
|---------|-------------|------------|
| `/balance` | Check your balance | economy.commands.default |
| `/token` | Check your tokens | economy.commands.default |
| `/pay <player> <amount>` | Send money to another player | economy.commands.default |
| `/economy give <player> <amount>` | Add money to a player | economy.command.give |
| `/economy take <player> <amount>` | Take money from a player | economy.command.take |
| `/economy set <player> <amount>` | Set a player's balance | economy.command.set |
| `/economy reset <player>` | Reset a player's economy data | economy.command.reset |
| `/economy info <player>` | View a player's economy info | economy.command.info |
| `/topbalance [limit]` | View players with highest balance | economy.commands.default |

## Permissions

| Permission | Description | Default |
|------------|-------------|---------|
| economy.commands | Access to all economy commands | op |
| economy.command.give | Allows giving money to others | op |
| economy.command.take | Allows taking money from others | op |
| economy.command.set | Allows setting other players' balance | op |
| economy.command.reset | Allows resetting player economy data | op |
| economy.command.info | Allows viewing other players' economy data | op |
| economy.commands.default | Allows access to default commands | true |

## Configuration

```yaml
# Performance Settings
# Enable caching to improve performance by reducing database queries
# Player data will be saved to the database when they log out
use-cache: true

# Default Economy Values
# Starting balance for new players
starting-balance: 1000.0

# Starting tokens for new players
starting-tokens: 0.0

# Database Settings
# Database type (currently only sqlite is supported)
database-type: sqlite

# Format Settings
# Currency symbol to use when formatting amounts
currency-symbol: "$"

# Form Settings
# Enable FormAPI-based UI (requires FormAPI plugin)
use-forms: true

# Messages
# All plugin messages can be customized here
# See the messages section in config.yml for all available options
```

## Message Customization

All messages in the plugin can be customized through the `config.yml` file. You can use the following placeholders in your messages:

- `{player}` - Target player's name
- `{sender}` - Command sender's name
- `{amount}` - Amount of money/tokens
- `{balance}` - Player's new balance
- `{tokens}` - Player's new tokens
- `{multiplier}` - Player's multiplier

Example customization:

```yaml
messages:
  balance:
    show: "§6💰 Your current balance is §e{balance}"
  pay:
    success-sender: "§a💸 You sent §e{amount} §ato §e{player}§a. Your balance: §e{balance}"
```

## For Developers

### How to Use EcoAPI in Your Plugin

To access the EcoAPI in your plugin, use the following:

```php
use didntpott\EcoAPI\EcoAPI;

// Get the economy instance
$economy = EcoAPI::getInstance()->getEconomy();
```

### Basic Examples

```php
// Get player balance
$balance = EcoAPI::getInstance()->getEconomy()->getBalance($player);

// Format currency for display
$formattedBalance = EcoAPI::getInstance()->getEconomy()->formatCurrency($balance);

// Add money to player
EcoAPI::getInstance()->getEconomy()->addBalance($player, 100.0);

// Remove money from player (returns false if player doesn't have enough)
$success = EcoAPI::getInstance()->getEconomy()->reduceBalance($player, 50.0);

// Transfer money between players
$success = EcoAPI::getInstance()->getEconomy()->transferBalance($sender, $receiver, 100.0);

// Working with tokens
$tokens = EcoAPI::getInstance()->getEconomy()->getTokens($player);
EcoAPI::getInstance()->getEconomy()->addTokens($player, 5.0);

// Working with multipliers
$multiplier = EcoAPI::getInstance()->getEconomy()->getMultiplier($player);
EcoAPI::getInstance()->getEconomy()->setMultiplier($player, 1.5);
```

### Check If Player Has Enough Money

```php
public function hasEnoughMoney(Player $player, float $amount): bool {
    return EcoAPI::getInstance()->getEconomy()->getBalance($player) >= $amount;
}
```

### Make Purchases

```php
public function tryPurchase(Player $player, float $cost): bool {
    return EcoAPI::getInstance()->getEconomy()->reduceBalance($player, $cost);
}
```

## Installation

1. Download the latest release from GitHub
2. Place it in your server's `plugins` folder
3. Restart your server
4. (Optional) Install FormAPI to enable forms UI: https://github.com/jojoe77777/FormAPI
4. Configure the plugin in the `config.yml` file

## Contributing

Contributions are welcome! Please feel free to submit a Pull Request.

## License

This plugin is licensed under the MIT License - see the LICENSE file for details.
