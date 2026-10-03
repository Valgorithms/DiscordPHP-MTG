# DiscordPHP-MTG

A Magic: The Gathering API Library and bot for Discord, built using [DiscordPHP](https://github.com/discord-php/DiscordPHP), with card data from [MTGJSON](https://mtgjson.com/).

## Features

- Search card printings by any MTGJSON card field (name, colors, color identity, types, mana value, set, rules text, format legality, foreign names, …) and fetch one by its MTGJSON `uuid`
- Look sets up by name, code, block or type, and open booster packs from MTGJSON's booster configurations
- Browse preconstructed decks and fetch one with its cards
- Read the reference lists: card types, subtypes, supertypes, formats and keywords
- Rich card rendering for Discord: Components V2 container, mana/symbol emojis, card images from Scryfall, plus buttons for the raw JSON, image, rulings, legalities, foreign names and set
- Ships as an installable slash-command bot (`/card_search`), user-installable and usable anywhere

## How the data is served

MTGJSON publishes whole files rather than a query API, so the client keeps a local copy of MTGJSON's **AllPrintings SQLite build**. On first start it streams `AllPrintings.sqlite.gz` (~250 MB) from `mtgjson.com/api/v5`, inflating it to disk (~700 MB). After that it checks `Meta.json` once a day and downloads a new build only when MTGJSON has published one. Card and set searches run against that file; decks, card types, keywords and the build metadata come straight from the API.

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
    ],
]);

$mtg->cards->getCards(['name' => '"Black Lotus"'])->then(fn ($cards) => $cards->first());
$mtg->cards->getCards(['colorIdentity' => 'U,R', 'types' => 'Creature', 'manaValue' => 'gte5', 'pageSize' => 10]);
$mtg->cards->getCards(['gameFormat' => 'commander', 'legality' => 'Banned', 'pageSize' => 100]);
$mtg->cards->fetch('1eb3ada8-f422-5524-bf92-8463cddd8051')->then(fn ($card) => $card->image_url);

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
```

Card searches keep the conventions of the old api.magicthegathering.io: `|` separates alternatives and `,` requires every value of a multi-valued field (`colors=R,G|U` is "red and green, or blue"); text matches partially, and a value in double quotes matches exactly. Its parameter names (`cmc`, `set`, `flavor`, `multiverseid`, `imageUrl`, …) still work as aliases for MTGJSON's. See `MTG\Database\CardQuery` for every filter.

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md) for guidelines.

## Credits

Card data is provided by [MTGJSON](https://mtgjson.com/); card images are served by [Scryfall](https://scryfall.com/). Magic: The Gathering is © Wizards of the Coast; this project is not affiliated with or endorsed by Wizards of the Coast.

## License

This project is licensed under the MIT License. See [LICENSE.md](LICENSE.md) for details.
