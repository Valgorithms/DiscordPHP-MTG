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

namespace MTG\Repository;

use Discord\Helpers\ExCollectionInterface;
use MTG\Database\CardQuery;
use MTG\Parts\Card;
use React\Promise\PromiseInterface;

/**
 * Card printings from MTGJSON's AllPrintings build — search with any
 * {@see CardQuery} filter, and fetch one by its MTGJSON `uuid` — hydrating
 * {@see Card} parts with their identifiers, legalities, rulings, foreign
 * data and purchase URLs.
 *
 * MTGJSON serves cards only inside bulk files, so these are answered from
 * the local {@see \MTG\Database\Database} copy rather than over HTTP.
 *
 * @link https://mtgjson.com/data-models/card/card-set/ Card (Set) model
 *
 * @since 0.3.0
 */
class CardRepository extends AbstractRepository
{
    use DatabaseRepositoryTrait;

    /**
     * Tables holding one row of extra card data per uuid, by Card attribute.
     *
     * @var array<string, string>
     */
    public const ONE_PER_CARD = [
        'identifiers' => 'cardIdentifiers',
        'legalities' => 'cardLegalities',
        'purchaseUrls' => 'cardPurchaseUrls',
    ];

    /**
     * Tables holding a list of extra card data per uuid, by Card attribute,
     * with their ordering.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    public const MANY_PER_CARD = [
        'rulings' => ['cardRulings', '"date", rowid'],
        'foreignData' => ['cardForeignData', '"language", rowid'],
    ];

    /**
     * @inheritDoc
     */
    protected $discrim = 'uuid';

    /**
     * MTGJSON has no per-card route; see {@see DatabaseRepositoryTrait}.
     *
     * @inheritDoc
     */
    protected $endpoints = [];

    /**
     * @inheritDoc
     */
    protected $class = Card::class;

    /**
     * Searches card printings. See {@see CardQuery} for every filter;
     * the common ones are `name`, `manaValue` (or `cmc`), `colors`,
     * `colorIdentity`, `type`, `types`, `subtypes`, `rarity`, `setCode` (or
     * `set`), `text`, `gameFormat` + `legality`, `multiverseId`, `contains`,
     * `orderBy`, `random`, `page` and `pageSize` (1-100, default 1).
     *
     * @param Card|array $params Filters, or a Card whose scalar and list attributes are used as filters.
     *
     * @return PromiseInterface<ExCollectionInterface<Card>> Keyed by uuid. Rejects with \InvalidArgumentException on a bad filter.
     *
     * @since 0.3.0
     */
    public function getCards(Card|array $params = []): PromiseInterface
    {
        if ($params instanceof Card) {
            $params = self::filtersFrom($params);
        }

        return $this->database->ready()->then(
            fn () => $this->hydrate((new CardQuery($this->database))->filter($params)->get())
        );
    }

    /**
     * Counts the card printings (or, with `unique`, the cards) a search
     * matches, ignoring `page` and `pageSize`.
     *
     * @param Card|array $params The same filters as {@see getCards()}.
     *
     * @return PromiseInterface<int>
     *
     * @since 1.1.0
     */
    public function countCards(Card|array $params = []): PromiseInterface
    {
        if ($params instanceof Card) {
            $params = self::filtersFrom($params);
        }

        return $this->database->ready()->then(
            fn () => (new CardQuery($this->database))->filter($params)->count()
        );
    }

    /**
     * Every printing of a card face, newest first: {uuid, setCode, setName,
     * number, releaseDate}. For the printing picker.
     *
     * @param Card $card
     *
     * @return PromiseInterface<array[]>
     *
     * @since 1.1.0
     */
    public function getPrintings(Card $card): PromiseInterface
    {
        return $this->database->ready()->then(fn () => $this->database->select(
            'SELECT "cards"."uuid", "cards"."setCode", "sets"."name" AS "setName", "cards"."number", "sets"."releaseDate"'
            .' FROM "cards" LEFT JOIN "sets" ON "sets"."code" = "cards"."setCode"'
            .' WHERE "cards"."name" = ? AND COALESCE("cards"."side", \'\') = ?'
            .' ORDER BY "sets"."releaseDate" DESC, CAST("cards"."number" AS INTEGER), "cards"."number"',
            [(string) $card->name, (string) ($card->side ?? '')]
        ));
    }

    /**
     * Gets card printings by their MTGJSON uuids. Unknown uuids are skipped.
     *
     * @param string[] $uuids
     *
     * @return PromiseInterface<ExCollectionInterface<Card>> One card per distinct uuid, keyed by uuid.
     *
     * @since 1.0.0
     */
    public function getCardsByUuid(array $uuids): PromiseInterface
    {
        return $this->database->ready()->then(fn () => $this->hydrate($this->rows(array_values(array_unique($uuids)))));
    }

    /**
     * @inheritDoc
     */
    protected function lookup(string $id): ?Card
    {
        $rows = $this->rows([$id]);

        return $rows ? $this->hydrate($rows, false)->first() : null;
    }

    /**
     * Reads `cards` rows by uuid, with the set name.
     *
     * @param string[] $uuids
     *
     * @return array[] Decoded rows.
     */
    protected function rows(array $uuids): array
    {
        if (! $uuids) {
            return [];
        }

        $rows = $this->database->select(
            'SELECT "cards".*, "sets"."name" AS "setName" FROM "cards" LEFT JOIN "sets" ON "sets"."code" = "cards"."setCode"'
            .' WHERE "cards"."uuid" IN ('.implode(', ', array_fill(0, count($uuids), '?')).')',
            $uuids
        );

        return array_map(fn (array $row) => $this->database->decode('cards', $row), $rows);
    }

    /**
     * Builds Card parts from decoded `cards` rows, attaching their extra data.
     *
     * @param array[] $rows  Decoded rows.
     * @param bool    $cache Whether to cache the parts.
     *
     * @return ExCollectionInterface<Card> Keyed by uuid.
     */
    protected function hydrate(array $rows, bool $cache = true): ExCollectionInterface
    {
        $collection = ($this->discord->getCollectionClass())::for(Card::class, 'uuid');
        $related = $this->related(array_values(array_unique(array_column($rows, 'uuid'))));

        foreach ($rows as $row) {
            $card = $this->factory->part(Card::class, $row + ($related[$row['uuid']] ?? []), true);

            if ($cache) {
                $this->cache->set($row['uuid'], $card);
            }

            $collection->pushItem($card);
        }

        return $collection;
    }

    /**
     * Reads the identifiers, legalities, purchase URLs, rulings and foreign
     * data of a set of cards.
     *
     * @param string[] $uuids
     *
     * @return array<string, array> Card attributes, by uuid.
     */
    protected function related(array $uuids): array
    {
        if (! $uuids) {
            return [];
        }

        $in = '('.implode(', ', array_fill(0, count($uuids), '?')).')';
        $related = [];

        foreach (self::ONE_PER_CARD as $attribute => $table) {
            foreach ($this->database->select("SELECT * FROM \"{$table}\" WHERE \"uuid\" IN {$in}", $uuids) as $row) {
                $uuid = $row['uuid'];
                $row = $this->database->decode($table, $row);
                unset($row['uuid']);

                if ($row) {
                    $related[$uuid][$attribute] = $row;
                }
            }
        }

        foreach (self::MANY_PER_CARD as $attribute => [$table, $order]) {
            foreach ($this->database->select("SELECT * FROM \"{$table}\" WHERE \"uuid\" IN {$in} ORDER BY {$order}", $uuids) as $row) {
                $uuid = $row['uuid'];
                $row = $this->database->decode($table, $row);
                unset($row['uuid']);

                $related[$uuid][$attribute][] = $row;
            }
        }

        // Prices are an extra: cards are complete without them while the
        // price build downloads, or when it is turned off.
        foreach ($this->discord->getPriceDatabase()?->prices($uuids) ?? [] as $uuid => $prices) {
            $related[$uuid]['prices'] = $prices;
        }

        return $related;
    }

    /**
     * Turns a Card's attributes into filters: scalars as they are, lists
     * as `,`-joined terms that must all match. Nested data is skipped.
     *
     * @param Card $card
     *
     * @return array
     */
    protected static function filtersFrom(Card $card): array
    {
        $filters = [];

        foreach ($card->getRawAttributes() as $key => $value) {
            if (in_array($key, ['count', 'isFoil', 'isEtched'], true)) {
                continue;
            }

            if (is_array($value)) {
                if (! array_is_list($value) || array_filter($value, fn ($item) => ! is_scalar($item))) {
                    continue;
                }
                $value = implode(',', $value);
            } elseif (! is_scalar($value)) {
                continue;
            }

            $filters[$key] = $value;
        }

        return $filters;
    }
}
