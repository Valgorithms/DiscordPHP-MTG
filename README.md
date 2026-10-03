# DiscordPHP-MTG

A Magic: The Gathering API Library and bot for Discord, built using [DiscordPHP](https://github.com/discord-php/DiscordPHP), with card data from [MTGJSON](https://mtgjson.com/).

## The bot

Slash commands, right-click commands and Components V2 messages, usable in servers, DMs and group DMs — install it to a server, or to your account to use it anywhere. Names, sets, keywords and formats autocomplete; every command takes `hidden:true` to answer only you.

| Command | What it does |
| --- | --- |
| `/card show <name> [set]` | A card: art, rules text, today's price and its set. Buttons open its rulings, legality, prices, foreign names, full image and JSON; **Flip** turns a double-faced card; a picker switches printing; links go to Scryfall, EDHREC, TCGplayer, Card Kingdom and Cardmarket. |
| `/card search …` | Cards by name, type, rules text, colors, color identity, mana value, rarity, set, format legality, keyword, artist, power, toughness — in pages, sorted how you like; pick one to open it, then go **Back to results**. |
| `/card random [filters]` | A random card, with 🎲 **Another**. |
| `/card prices <name> [set]` | Today's retail and buylist prices at every store MTGJSON tracks. |
| `/card_search` | The original search, unchanged. |
| `/set show <set>` · `/set search …` | A set's size, dates, languages and boosters, with **Browse cards**, **Open a booster** and **Decks**. |
| `/booster <set> [type]` | Open a pack with the real product's odds (play, draft, collector, …): every card with its rarity, foil and price, the pack's value, **Images** and **Open another**. |
| `/deck show <deck>` · `/deck search …` | Preconstructed decks grouped by card type, and **Export decklist** for MTG Arena, Moxfield or Archidekt. |
| Right-click a message → **Find cards** | Looks up every `[[Card Name]]` (or `[[Card Name\|SET]]`) in it, or the whole message as one name. |
| `/help` · `/about` · `/invite` · `/ping` | The guide; where the data comes from and how fresh it is; install links; latency. |

The bot can also answer `[[Card Name]]` wherever it's written: set `MTG_INLINE_LOOKUPS=1` after turning on the privileged **Message Content** intent for the application in the Discord Developer Portal.

Built like [Tutelar](https://github.com/discord-php/DiscordPHP-Tutelar): each feature is a module (`MTG\Modules\*`) that declares its commands — created or updated on Discord when their definition changes — and routes clicks on its components through stable custom ids, so buttons keep working after a restart.

## How the data is served

MTGJSON publishes whole files rather than a query API, so the client keeps a local copy of MTGJSON's **AllPrintings SQLite build**. On first start it streams `AllPrintings.sqlite.gz` (~250 MB) from `mtgjson.com/api/v5`, inflating it to disk (~700 MB). After that it checks `Meta.json` once a day and downloads a new build only when MTGJSON has published one. Card and set searches run against that file; decks, card types, keywords and the build metadata come straight from the API.

**Prices** come from MTGJSON's `AllPricesToday` (TCGplayer, Card Kingdom, Cardmarket, Mana Pool, Cardhoarder). Decoding that 53 MB file takes close to a gigabyte, so the `prices` GitHub Actions workflow does it once a day and publishes a 12 MB SQLite build as an asset of this repository's [`prices` release](https://github.com/Valgorithms/DiscordPHP-MTG/releases/tag/prices) (`build-prices.php`); the bot downloads that (~5 MB gzipped) and refreshes it daily. Set `MTG_PRICES=0` to go without.

## Requirements

- PHP 8.3 or higher, with the `pdo_sqlite` and `zlib` extensions
- Composer
- About 1.4 GB of free disk space for the MTGJSON build (twice its size while a new one is swapped in)

## Installation

1. Clone the repository:
   ```cmd
   git clone https://github.com/discord-php/DiscordPHP-MTG.git
   cd DiscordPHP-MTG
   ```

2. Install dependencies:
   ```cmd
   composer install
   ```

## Usage

1. Copy `env.example` to `.env` and configure your bot token. The bot keeps the MTGJSON build in `var/mtgjson/`; set `MTGJSON_DATABASE` to keep it elsewhere.
2. Run the bot:
   ```cmd
   php bot.php
   ```
3. Alternatively, package the bot into an executable binary:
   ```powershell
   composer run-script phpacker
   ```

### As a library

`MTG` extends the DiscordPHP client, so the MTG repositories hang off it and every call returns a promise:

```php
$mtg = new \MTG\MTG([
    'token' => getenv('TOKEN'),
    'mtgjson' => [
        'database' => __DIR__.'/var/mtgjson/AllPrintings.sqlite', // default: the system temp dir
        'refresh_interval' => 86400, // seconds between checks for a new build; 0 never refreshes
        'preload' => true,           // open (or download) the build at startup
        'prices' => true,            // today's prices; false to skip, or the URL of another prices.sqlite.gz
    ],
]);

$mtg->cards->getCards(['name' => '"Black Lotus"'])->then(fn ($cards) => $cards->first());
$mtg->cards->getCards(['colorIdentity' => 'U,R', 'types' => 'Creature', 'manaValue' => 'gte5', 'pageSize' => 10]);
$mtg->cards->getCards(['gameFormat' => 'commander', 'legality' => 'Banned', 'unique' => true, 'pageSize' => 100]);
$mtg->cards->countCards(['types' => 'Creature', 'unique' => true]);       // how many cards (not printings) match
$mtg->cards->fetch('1eb3ada8-f422-5524-bf92-8463cddd8051')->then(fn ($card) => [$card->image_url, $card->getPrice('tcgplayer', 'foil')]);

$mtg->sets->getSets(['name' => 'Khans of Tarkir'])->then(fn ($sets) => $sets->first());
$mtg->sets->fetch('KTK')->then(fn ($set) => $set->releaseDate);
$mtg->sets->generateBooster('KTK')->then(fn ($pack) => $pack);          // 15 cards; foils have isFoil
$mtg->sets->getBoosterTypes('KTK');                                      // ['draft', 'arena', 'prerelease-abzan', …]

$mtg->decks->getDecks(['code' => 'KTK'])->then(fn ($decks) => $decks->first());
$mtg->decks->fetch('AbzanSiege_KTK')->then(fn ($deck) => $deck->mainBoard);

$mtg->getTypes();       // ['Artifact', 'Creature', 'Instant', …]
$mtg->getSubtypes();    // ['Advisor', 'Aetherborn', …]
$mtg->getSupertypes();  // ['Basic', 'Legendary', 'Snow', …]
$mtg->getFormats();     // ['alchemy', 'brawl', 'commander', …]
$mtg->getKeywords();    // ['abilityWords' => […], 'keywordAbilities' => […], 'keywordActions' => […]]
$mtg->getMeta();        // ['date' => '2026-10-03', 'version' => '5.3.0+20261003']

// The bot's features are modules; add your own the same way.
$mtg->addModule(new \MTG\Modules\Cards())->addModule(new \MTG\Modules\Help());
```

Card searches keep the conventions of the old api.magicthegathering.io: `|` separates alternatives and `,` requires every value of a multi-valued field (`colors=R,G|U` is "red and green, or blue"); text matches partially, and a value in double quotes matches exactly. Its parameter names (`cmc`, `set`, `flavor`, `multiverseid`, `imageUrl`, …) still work as aliases for MTGJSON's. `unique` keeps one printing per card. See `MTG\Database\CardQuery` for every filter.

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md) for guidelines.

## Credits

Card data and prices are provided by [MTGJSON](https://mtgjson.com/); card images are served by [Scryfall](https://scryfall.com/). Magic: The Gathering is © Wizards of the Coast; this project is not affiliated with or endorsed by Wizards of the Coast.

## License

This project is licensed under the MIT License. See [LICENSE.md](LICENSE.md) for details.
