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
use MTG\Database\Booster;
use MTG\Database\SetQuery;
use MTG\MTG;
use MTG\Parts\Card;
use MTG\Parts\Set;
use React\Promise\PromiseInterface;

use function React\Promise\reject;

/**
 * Sets from MTGJSON's AllPrintings build — search them, fetch one by code,
 * and open booster packs from their booster configurations — hydrating
 * {@see Set} parts with their name translations.
 *
 * @link https://mtgjson.com/data-models/set/ Set model
 * @link https://mtgjson.com/data-models/booster/ Booster models
 *
 * @since 0.3.0
 */
class SetRepository extends AbstractRepository
{
    use DatabaseRepositoryTrait;

    /**
     * @inheritDoc
     */
    protected $discrim = 'code';

    /**
     * MTGJSON has no per-set route that fits in memory; see {@see DatabaseRepositoryTrait}.
     *
     * @inheritDoc
     */
    protected $endpoints = [];

    /**
     * @inheritDoc
     */
    protected $class = Set::class;

    /**
     * Searches sets. See {@see SetQuery} for every filter; the common ones
     * are `name` and `block` (partial), `code` and `type` (exact), with
     * `orderBy` (default `-releaseDate`), `page` and `pageSize` (1-100,
     * default 100).
     *
     * @param Card|Set|array $params Filters, or the Card or Set whose set to find.
     *
     * @return PromiseInterface<ExCollectionInterface<Set>> Keyed by code. Rejects with \InvalidArgumentException on a bad filter.
     *
     * @since 0.5.0
     */
    public function getSets(Card|Set|array $params = []): PromiseInterface
    {
        if ($params instanceof Card) {
            $params = ['code' => (string) $params->setCode];
        } elseif ($params instanceof Set) {
            $params = ['code' => (string) $params->code];
        }

        return $this->database->ready()->then(
            fn () => $this->hydrate((new SetQuery($this->database))->filter($params)->get())
        );
    }

    /**
     * Loads every set into the repository.
     *
     * @param array $queryparams Unused.
     *
     * @return PromiseInterface<static>
     */
    public function freshen(array $queryparams = []): PromiseInterface
    {
        return $this->database->ready()->then(function () {
            $this->hydrate(array_map(
                fn (array $row) => $this->database->decode('sets', $row),
                $this->database->select('SELECT * FROM "sets"')
            ));

            return $this;
        });
    }

    /**
     * The booster types a set can open (`play`, `draft`, `collector`, …),
     * the one {@see generateBooster()} picks by default first.
     *
     * @param Set|string $set A {@see Set} or a set code (e.g. `"KTK"`).
     *
     * @return PromiseInterface<string[]> Empty when MTGJSON has no booster data for the set.
     *
     * @since 1.0.0
     */
    public function getBoosterTypes(Set|string $set): PromiseInterface
    {
        $code = strtoupper($set instanceof Set ? (string) $set->code : $set);

        return $this->database->ready()->then(fn () => (new Booster($this->database))->types($code));
    }

    /**
     * Opens a booster pack for a set: a pack layout is rolled against the
     * set's MTGJSON booster configuration and each slot is filled from its
     * weighted sheet. Cards from foil sheets have `isFoil` set.
     *
     * @param Set|string  $set  A {@see Set} or a set code (e.g. `"KTK"`).
     * @param string|null $type The booster type (see {@see getBoosterTypes()}); defaults to the set's play, draft or default booster.
     *
     * @return PromiseInterface<ExCollectionInterface<Card>> The pack. Not keyed — a pack can hold the same card twice. Never cached.
     *
     * @link https://mtgjson.com/data-models/booster/
     *
     * @since 0.10.1
     */
    public function generateBooster(Set|string $set, ?string $type = null): PromiseInterface
    {
        $code = strtoupper($set instanceof Set ? (string) $set->code : $set);

        if ($code === '') {
            return reject(new \InvalidArgumentException('A set code is required to generate a booster.'));
        }

        /** @var MTG $mtg */
        $mtg = $this->discord;

        return $this->database->ready()->then(function () use ($code, $type, $mtg) {
            $booster = new Booster($this->database);
            $type ??= $booster->types($code)[0] ?? throw new \InvalidArgumentException("Set {$code} has no booster configuration in MTGJSON.");
            $pack = $booster->open($code, $type);

            return $mtg->cards->getCardsByUuid(array_column($pack, 'uuid'))->then(function (ExCollectionInterface $cards) use ($pack) {
                $collection = ($this->discord->getCollectionClass())::for(Card::class, null);

                foreach ($pack as ['uuid' => $uuid, 'foil' => $foil]) {
                    if ($card = $cards->get('uuid', $uuid)) {
                        $card = clone $card;
                        if ($foil) {
                            $card->isFoil = true;
                        }
                        $collection->pushItem($card);
                    }
                }

                return $collection;
            });
        });
    }

    /**
     * @inheritDoc
     */
    protected function lookup(string $id): ?Set
    {
        $rows = $this->database->select('SELECT * FROM "sets" WHERE "code" = ?', [strtoupper($id)]);

        return $rows ? $this->hydrate([$this->database->decode('sets', $rows[0])], false)->first() : null;
    }

    /**
     * Builds Set parts from decoded `sets` rows, attaching their name
     * translations.
     *
     * @param array[] $rows  Decoded rows.
     * @param bool    $cache Whether to cache the parts.
     *
     * @return ExCollectionInterface<Set> Keyed by code.
     */
    protected function hydrate(array $rows, bool $cache = true): ExCollectionInterface
    {
        $collection = ($this->discord->getCollectionClass())::for(Set::class, 'code');

        $translations = [];
        if ($codes = array_column($rows, 'code')) {
            foreach ($this->database->select(
                'SELECT "code", "language", "translation" FROM "setTranslations" WHERE "code" IN ('.implode(', ', array_fill(0, count($codes), '?')).')',
                $codes
            ) as $row) {
                if ($row['translation'] !== null && $row['translation'] !== '') {
                    $translations[$row['code']][$row['language']] = $row['translation'];
                }
            }
        }

        foreach ($rows as $row) {
            $set = $this->factory->part(Set::class, $row + ['translations' => $translations[$row['code']] ?? []], true);

            if ($cache) {
                $this->cache->set($row['code'], $set);
            }

            $collection->pushItem($set);
        }

        return $collection;
    }
}
