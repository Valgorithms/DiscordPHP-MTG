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

use Discord\Builders\CommandBuilder;
use Discord\MessageCommandClient;
use Discord\Http\Drivers\React;
use Discord\Parts\Interactions\Command\Command;
use Discord\Parts\User\Client as DiscordClient;
use Discord\Repository\Interaction\GlobalCommandRepository;
use Discord\Stats;
use MTG\Database\Database;
use MTG\Database\PriceDatabase;
use MTG\Database\Suggestions;
use MTG\Helpers\CommandSignature;
use MTG\Helpers\SearchCache;
use MTG\Http\Endpoint;
use MTG\Http\Http;
use MTG\Modules\Module;
use MTG\Repository\CardRepository;
use MTG\Repository\DeckRepository;
use MTG\Repository\SetRepository;
use React\Http\Browser;
use React\Promise\PromiseInterface;
use React\Socket\Connector;

/**
 * The MTG client class — a DiscordPHP {@see MessageCommandClient} extended
 * with MTGJSON: an async HTTP client for the MTGJSON v5 API, a local copy of
 * its AllPrintings SQLite build for card and set searches, today's card
 * prices, and the card, set and deck repositories that read them.
 *
 * Bot features are {@see Module}s, as in Tutelar: each registers its own
 * application commands and component handlers, and all of them boot once the
 * gateway and the application are both ready, in the order they were added.
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
     * Today's card prices, or null when turned off.
     *
     * @var PriceDatabase|null
     */
    protected ?PriceDatabase $prices = null;

    /**
     * Autocomplete answers, read from the build.
     *
     * @var Suggestions
     */
    protected Suggestions $suggestions;

    /**
     * State behind message components (search filters, opened packs).
     *
     * @var SearchCache
     */
    protected SearchCache $searchCache;

    /**
     * The bot's features, in boot order.
     *
     * @var list<Module>
     */
    protected array $modules = [];

    /**
     * Whether the modules have booted.
     *
     * @var bool
     */
    protected bool $modulesBooted = false;

    /**
     * The registered global commands, read once for {@see defineCommand()}.
     *
     * @var PromiseInterface<GlobalCommandRepository>|null
     */
    protected ?PromiseInterface $commandRepository = null;

    /**
     * When the client was constructed, for uptime.
     *
     * @var int
     */
    protected int $startedAt;

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
     *                       - `prices` (bool|string): keep today's card prices
     *                       (~5 MB download a day); a string is the URL of
     *                       another `prices.sqlite.gz`. Default true.
     *                       - `prices_database` (string): where the price
     *                       build is kept; defaults to beside the card build.
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
        $browser = new Browser(new Connector($socketOptions, $this->loop), $this->loop);
        $path = (string) ($mtgjson['database'] ?? sys_get_temp_dir().DIRECTORY_SEPARATOR.'mtgjson'.DIRECTORY_SEPARATOR.Database::FILE);
        $interval = (int) ($mtgjson['refresh_interval'] ?? Database::DEFAULT_REFRESH_INTERVAL);

        $this->database = new Database($this->loop, $this->logger, $this->mtg_http, $browser, $path, $interval);

        if (($mtgjson['prices'] ?? true) !== false) {
            $this->prices = new PriceDatabase(
                $this->loop,
                $this->logger,
                $this->mtg_http,
                $browser,
                (string) ($mtgjson['prices_database'] ?? dirname($path).DIRECTORY_SEPARATOR.PriceDatabase::FILE),
                $interval,
            );
            if (is_string($mtgjson['prices'] ?? null)) {
                $this->prices->setSource($mtgjson['prices']);
            }
        }

        $this->suggestions = new Suggestions($this->database);
        $this->searchCache = new SearchCache();
        $this->startedAt = time();
        // Swap in the client that carries the MTG repositories. DiscordPHP's own
        // client part is already loading the application and may announce
        // `application-init` before this one has it, so hand its application
        // over — before the modules boot on the same event.
        $bootstrap = $this->client;
        $this->client = $this->factory->part(Client::class, []);
        $this->once('application-init', function () use ($bootstrap): void {
            if (($this->client->application->id ?? null) === null && ($application = $bootstrap->application ?? null)) {
                $this->client->application = $application;
            }
        });
        $this->stats = Stats::new($this);

        if ($mtgjson['preload'] ?? true) {
            $this->loop->futureTick(function (): void {
                $this->database->ready()->then(null, function (\Throwable $e): void {
                    $this->logger->error('The MTGJSON build is not available: '.$e->getMessage());
                });
                $this->prices?->ready()->then(null, function (\Throwable $e): void {
                    $this->logger->warning('Card prices are not available: '.$e->getMessage());
                });
            });
        }

        $ready = false;
        $applicationReady = false;
        $boot = function () use (&$ready, &$applicationReady): void {
            if ($ready && $applicationReady && ! $this->modulesBooted) {
                $this->bootModules();
            }
        };
        $this->once('init', function () use (&$ready, $boot): void {
            $ready = true;
            $boot();
        });
        $this->once('application-init', function () use (&$applicationReady, $boot): void {
            $applicationReady = true;
            $boot();
        });
    }

    /**
     * Adds a feature. Call before {@see run()}; modules boot in the order
     * they were added, once the gateway and the application are ready.
     *
     * @param Module $module
     *
     * @return static
     *
     * @since 1.1.0
     */
    public function addModule(Module $module): static
    {
        $this->modules[] = $module;

        return $this;
    }

    /**
     * The modules added, in boot order.
     *
     * @return list<Module>
     *
     * @since 1.1.0
     */
    public function getModules(): array
    {
        return $this->modules;
    }

    /**
     * Registers a global application command, or updates it when the
     * definition in code differs from what Discord has. Unchanged commands
     * are left alone; Discord overwrites a command created again under the
     * same name and type.
     *
     * @param CommandBuilder $builder
     *
     * @return PromiseInterface<Command>
     *
     * @since 1.1.0
     */
    public function defineCommand(CommandBuilder $builder): PromiseInterface
    {
        $this->commandRepository ??= $this->application->commands->freshen();

        return $this->commandRepository->then(function (GlobalCommandRepository $commands) use ($builder) {
            $wanted = $builder->jsonSerialize();
            $type = (int) ($wanted['type'] ?? Command::CHAT_INPUT);
            $existing = $commands->find(fn (Command $command) => $command->name === $wanted['name'] && (int) ($command->type ?? Command::CHAT_INPUT) === $type);

            if ($existing instanceof Command && CommandSignature::same(json_decode(json_encode($existing), true), $wanted)) {
                return $existing;
            }

            $this->logger->info(($existing ? 'Updating' : 'Creating')." application command {$wanted['name']}");

            return $builder->create($commands)->save(($existing ? 'Update' : 'Create')." {$wanted['name']} command");
        });
    }

    /**
     * Boots every module, skipping one that fails rather than the bot.
     */
    protected function bootModules(): void
    {
        $this->modulesBooted = true;

        foreach ($this->modules as $module) {
            try {
                foreach ($module->commands($this) as $command) {
                    $this->defineCommand($command)->then(null, fn (\Throwable $e) => $this->logger->error('Could not register a '.$module->name().' command: '.$e->getMessage()));
                }
                $module->boot($this);
                $this->logger->info('Module booted: '.$module->name());
            } catch (\Throwable $e) {
                $this->logger->error('Module '.$module->name().' failed to boot: '.$e->getMessage());
            }
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
     * Gets today's card prices, or null when they are turned off.
     *
     * @return PriceDatabase|null
     *
     * @since 1.1.0
     */
    public function getPriceDatabase(): ?PriceDatabase
    {
        return $this->prices;
    }

    /**
     * Gets the autocomplete answers.
     *
     * @return Suggestions
     *
     * @since 1.1.0
     */
    public function getSuggestions(): Suggestions
    {
        return $this->suggestions;
    }

    /**
     * Gets the state behind message components.
     *
     * @return SearchCache
     *
     * @since 1.1.0
     */
    public function getSearchCache(): SearchCache
    {
        return $this->searchCache;
    }

    /**
     * Seconds since the client was constructed.
     *
     * @return int
     *
     * @since 1.1.0
     */
    public function getUptime(): int
    {
        return time() - $this->startedAt;
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
