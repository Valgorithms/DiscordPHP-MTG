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

namespace MTG\Http;

use Discord\Http\EndpointInterface;
use Discord\Http\EndpointTrait;

/**
 * The route table for the MTGJSON v5 API — one constant per file the API
 * serves under `https://mtgjson.com/api/v5/`, with `:param` placeholders
 * bound via {@see EndpointTrait}. Same mechanics as DiscordPHP's own
 * `Endpoint`, pointed at `mtgjson.com`.
 *
 * MTGJSON is a file API: every route is a static JSON document wrapped as
 * `{"meta": {...}, "data": ...}`, rebuilt daily. The bulk files (marked
 * below) run from tens to hundreds of megabytes; card and set queries are
 * answered from the SQLite build instead (see {@see \MTG\Database\Database}).
 *
 * @link https://mtgjson.com/downloads/all-files/ All files
 * @link https://mtgjson.com/api/v5/ The API root
 *
 * @see \Discord\Http\EndpointInterface The contract this implements
 *
 * @since 0.1.0
 */
class Endpoint implements EndpointInterface
{
    use EndpointTrait;

    // GET — the build's version and date.
    public const META = 'Meta.json';
    // GET — every set, without its cards (Set List model). ~12 MB.
    public const SET_LIST = 'SetList.json';
    // GET — one set with its cards, tokens, booster configs and sealed products (Set model).
    public const SET = ':code.json';
    // GET — every deck, without its cards (Deck List model).
    public const DECK_LIST = 'DeckList.json';
    // GET — one deck with its cards (Deck model). `file_name` comes from the deck list.
    public const DECK = 'decks/:file_name.json';
    // GET — every card type with its valid subtypes and supertypes (Card Types model).
    public const CARD_TYPES = 'CardTypes.json';
    // GET — ability words, keyword abilities and keyword actions (Keywords model).
    public const KEYWORDS = 'Keywords.json';
    // GET — every value an enumerated property can hold.
    public const ENUM_VALUES = 'EnumValues.json';
    // GET — the files available in this build.
    public const COMPILED_LIST = 'CompiledList.json';
    // GET (bulk) — every card printing, keyed by set code.
    public const ALL_PRINTINGS = 'AllPrintings.json';
    // GET (bulk) — the AllPrintings SQLite build, gzipped. Streamed by {@see \MTG\Database\Database}.
    public const ALL_PRINTINGS_SQLITE_GZ = 'AllPrintings.sqlite.gz';
    // GET (bulk) — every card printing, keyed by uuid.
    public const ALL_IDENTIFIERS = 'AllIdentifiers.json';
    // GET (bulk) — every card's oracle data, keyed by name (Card (Atomic) model).
    public const ATOMIC_CARDS = 'AtomicCards.json';
    // GET (bulk) — today's prices for every card, keyed by uuid.
    public const ALL_PRICES_TODAY = 'AllPricesToday.json';
    // GET (bulk) — 90 days of prices for every card, keyed by uuid.
    public const ALL_PRICES = 'AllPrices.json';
    // GET (bulk) — TCGplayer SKUs for every card, keyed by uuid.
    public const TCGPLAYER_SKUS = 'TcgplayerSkus.json';
    // GET (bulk) — every printing legal in a format, by set (`Standard`, `Pioneer`, `Modern`, `Legacy`, `Vintage`, `Pauper`).
    // Bind `format` to e.g. `StandardAtomic` for the by-name Card (Atomic) variant.
    public const FORMAT = ':format.json';

    /**
     * Regex to identify parameters in endpoints. Stops at `.` so that
     * `:code.json` binds `code`, not `code.json`.
     *
     * @var string
     */
    public const REGEX = '/:([A-Za-z0-9_]+)/';

    /**
     * The string version of the endpoint, including all parameters.
     *
     * @var string
     */
    protected $endpoint;

    /**
     * Array of placeholders to be replaced in the endpoint.
     *
     * @var string[]
     */
    protected $vars = [];

    /**
     * Array of arguments to substitute into the endpoint.
     *
     * @var string[]
     */
    protected $args = [];

    /**
     * Array of query data to be appended
     * to the end of the endpoint with `http_build_query`.
     *
     * @var array
     */
    protected $query = [];

    /**
     * Creates an endpoint class.
     *
     * @param string $endpoint
     */
    public function __construct(string $endpoint)
    {
        $this->endpoint = $endpoint;

        if (preg_match_all(self::REGEX, $endpoint, $vars)) {
            $this->vars = $vars[1] ?? [];
        }
    }
}
