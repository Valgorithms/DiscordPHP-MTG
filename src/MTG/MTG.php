<?php

declare(strict_types=1);

/*
 * This file is a part of the DiscordPHP-MTG project.
 *
 * Copyright (c) 2025-present Valithor Obsidion <valithor@discordphp.org>
 *
 * This file is subject to the MIT license that is bundled
 * with this source code in the LICENSE.md file.
 */

namespace MTG;

use Discord\MessageCommandClient;
use Discord\Http\Drivers\React;
use Discord\Parts\User\Client as DiscordClient;
use Discord\Stats;
use MTG\Database\Database;
use MTG\Http\Endpoint;
use MTG\Http\Http;
use MTG\Repository\CardRepository;
use MTG\Repository\DeckRepository;
use MTG\Repository\SetRepository;
use React\Http\Browser;
use React\Promise\PromiseInterface;
use React\Socket\Connector;

/**
 * The MTG client class — a DiscordPHP {@see MessageCommandClient} extended
 * with MTGJSON: an async HTTP client for the MTGJSON v5 API, a local copy of
 * its AllPrintings SQLite build for card and set searches, and the card, set
 * and deck repositories that read them.
 *
 * @link https://mtgjson.com/
 *
 * @see \Discord\MessageCommandClient The DiscordPHP client this extends
 * @see CardRepository
 * @see SetRepository
 * @see DeckRepository
 *
 * @version 1.0.0
 *
 * @property CardRepository $cards
 * @property SetRepository  $sets
 * @property DeckRepository $decks
 */
class MTG extends MessageCommandClient
{
    use HelperTrait;

    public const string GITHUB = 'https://github.com/discord-php/DiscordPHP-MTG';

    protected Stats $stats;

    /**
     * The extended HTTP client.
     *
     * @var Http Extended Discord HTTP client.
     */
    protected $mtg_http;

    /**
     * The local MTGJSON build.
     *
     * @var Database
     */
    protected $database;

    /**
     * The extended Client class.
     *
     * @var Client Extended Discord client.
     */
    protected $client;

    /**
     * @param array $options Options passed straight to the DiscordPHP client
     *                       (`socket_options` also configures the MTGJSON
     *                       connections), plus an optional `mtgjson` array:
     *                       - `database` (string): where the AllPrintings
     *                       build is kept; defaults to
     *                       `{sys_get_temp_dir()}/mtgjson/AllPrintings.sqlite`.
     *                       About 700 MB; the first run downloads ~250 MB.
     *                       - `refresh_interval` (int): seconds between
     *                       checks for a new build; default one day, `0`
     *                       never refreshes.
     *                       - `preload` (bool): open (or download) the build
     *                       at startup rather than on the first search;
     *                       default true.
     */
    public function __construct(array $options = [])
    {
        $mtgjson = (array) ($options['mtgjson'] ?? []);
        unset($options['mtgjson']);

        parent::__construct($options);

        $socketOptions = $options['socket_options'] ?? [];

        $this->mtg_http = new Http(
            '', // MTGJSON is unauthenticated — never forward the Discord bot token to it.
            $this->loop,
            $this->logger,
            new React($this->loop, $socketOptions),
        );
        $this->database = new Database(
            $this->loop,
            $this->logger,
            $this->mtg_http,
            new Browser(new Connector($socketOptions, $this->loop), $this->loop),
            (string) ($mtgjson['database'] ?? sys_get_temp_dir().DIRECTORY_SEPARATOR.'mtgjson'.DIRECTORY_SEPARATOR.Database::FILE),
            (int) ($mtgjson['refresh_interval'] ?? Database::DEFAULT_REFRESH_INTERVAL),
        );
        $this->client = $this->factory->part(Client::class, (array) $this->client);
        $this->stats = Stats::new($this);

        if ($mtgjson['preload'] ?? true) {
            $this->loop->futureTick(fn () => $this->database->ready()->then(null, function (\Throwable $e): void {
                $this->logger->error('The MTGJSON build is not available: '.$e->getMessage());
            }));
        }
    }

    /**
     * Fetches every card type with its valid subtypes and supertypes, keyed
     * by lower-case type (`artifact`, `creature`, …).
     *
     * @link https://mtgjson.com/data-models/card-types/
     *
     * @return PromiseInterface<array<string, array{subTypes: string[], superTypes: string[]}>>
     *
     * @since 1.0.0
     */
    public function getCardTypes(): PromiseInterface
    {
        return $this->mtg_http->get(new Endpoint(Endpoint::CARD_TYPES))->then(
            static fn ($response) => json_decode(json_encode($response->data ?? []), true)
        );
    }

    /**
     * Fetches the list of all card types (e.g. `Creature`, `Instant`).
     *
     * @link https://mtgjson.com/data-models/card-types/
     *
     * @return PromiseInterface<string[]>
     */
    public function getTypes(): PromiseInterface
    {
        return $this->getCardTypes()->then(static fn (array $types) => array_map('ucfirst', array_keys($types)));
    }

    /**
     * Fetches the list of all card subtypes (e.g. `Elf`, `Equipment`).
     *
     * @link https://mtgjson.com/data-models/card-types/
     *
     * @return PromiseInterface<string[]>
     */
    public function getSubtypes(): PromiseInterface
    {
        return $this->getCardTypes()->then(static fn (array $types) => self::mergeTypes($types, 'subTypes'));
    }

    /**
     * Fetches the list of all card supertypes (e.g. `Legendary`, `Snow`).
     *
     * @link https://mtgjson.com/data-models/card-types/
     *
     * @return PromiseInterface<string[]>
     */
    public function getSupertypes(): PromiseInterface
    {
        return $this->getCardTypes()->then(static fn (array $types) => self::mergeTypes($types, 'superTypes'));
    }

    /**
     * Lists the game formats MTGJSON tracks legality for, as it names them
     * (e.g. `standard`, `commander`, `paupercommander`) — the values the
     * `gameFormat` card filter takes.
     *
     * @link https://mtgjson.com/data-models/legalities/
     *
     * @return PromiseInterface<string[]>
     */
    public function getFormats(): PromiseInterface
    {
        return $this->database->ready()->then(
            fn () => array_values(array_diff(array_keys($this->database->getColumns('cardLegalities')), ['uuid']))
        );
    }

    /**
     * Fetches the keyword lists: `abilityWords`, `keywordAbilities` and
     * `keywordActions`.
     *
     * @link https://mtgjson.com/data-models/keywords/
     *
     * @return PromiseInterface<array<string, string[]>>
     *
     * @since 1.0.0
     */
    public function getKeywords(): PromiseInterface
    {
        return $this->mtg_http->get(new Endpoint(Endpoint::KEYWORDS))->then(
            static fn ($response) => json_decode(json_encode($response->data ?? []), true)
        );
    }

    /**
     * Fetches every value MTGJSON's enumerated properties can hold, keyed by
     * model then property (e.g. `card` → `rarity`).
     *
     * @link https://mtgjson.com/data-models/enum-values/
     *
     * @return PromiseInterface<array<string, array<string, string[]>>>
     *
     * @since 1.0.0
     */
    public function getEnumValues(): PromiseInterface
    {
        return $this->mtg_http->get(new Endpoint(Endpoint::ENUM_VALUES))->then(
            static fn ($response) => json_decode(json_encode($response->data ?? []), true)
        );
    }

    /**
     * Fetches the current MTGJSON build's version and date.
     *
     * @link https://mtgjson.com/data-models/meta/
     *
     * @return PromiseInterface<array{date: string, version: string}>
     *
     * @since 1.0.0
     */
    public function getMeta(): PromiseInterface
    {
        return $this->mtg_http->get(new Endpoint(Endpoint::META))->then(
            static fn ($response) => (array) ($response->data ?? [])
        );
    }

    /**
     * Sets the client part, but never back to a plain DiscordPHP one: the
     * client DiscordPHP builds before this constructor swaps in
     * {@see Client} re-installs itself once its application has loaded,
     * which would drop the MTG repositories.
     *
     * @param DiscordClient $client The client part.
     */
    public function setClient(DiscordClient $client): void
    {
        if ($client instanceof Client || ! $this->client instanceof Client) {
            parent::setClient($client);
        }
    }

    /**
     * Gets the MTG HTTP client.
     *
     * @return Http
     */
    public function getMtgHttpClient(): Http
    {
        return $this->mtg_http;
    }

    /**
     * Gets the local MTGJSON build.
     *
     * @return Database
     *
     * @since 1.0.0
     */
    public function getDatabase(): Database
    {
        return $this->database;
    }

    /**
     * Merges one list out of every card type, sorted and unique.
     *
     * @param array  $types The card types.
     * @param string $key   `subTypes` or `superTypes`.
     *
     * @return string[]
     */
    protected static function mergeTypes(array $types, string $key): array
    {
        $merged = array_unique(array_merge(...array_values(array_map(static fn (array $type) => $type[$key] ?? [], $types)) ?: [[]]));
        sort($merged);

        return $merged;
    }

    /**
     * Handles dynamic get calls to the client.
     *
     * @param string $name Variable name.
     *
     * @return mixed
     */
    public function __get(string $name)
    {
        static $allowed = ['loop', 'options', 'logger', 'http', 'mtg_http', 'database', 'application_commands'];

        if (in_array($name, $allowed)) {
            return $this->{$name};
        }

        if (null === $this->client) {
            return;
        }

        return $this->client->{$name};
    }
}
