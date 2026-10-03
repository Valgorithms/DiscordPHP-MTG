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
use Discord\Parts\Part;
use MTG\Http\Endpoint;
use MTG\Parts\Deck;
use React\Promise\PromiseInterface;

use function Discord\studly;
use function React\Promise\reject;
use function React\Promise\resolve;

/**
 * Preconstructed decks from the MTGJSON API: the deck list
 * (`DeckList.json`) to search, and one deck with its cards
 * (`decks/{fileName}.json`) to fetch, hydrating {@see Deck} parts.
 *
 * @link https://mtgjson.com/data-models/deck-list/ Deck List model
 * @link https://mtgjson.com/data-models/deck/ Deck model
 *
 * @since 1.0.0
 */
class DeckRepository extends AbstractRepository
{
    /**
     * How long a downloaded deck list is reused, in seconds.
     *
     * @var int
     */
    public const LIST_TTL = 86400;

    /**
     * @inheritDoc
     */
    protected $discrim = 'fileName';

    /**
     * @inheritDoc
     */
    protected $endpoints = [
        'all' => Endpoint::DECK_LIST,
        'get' => Endpoint::DECK,
    ];

    /**
     * @inheritDoc
     */
    protected $class = Deck::class;

    /**
     * The last deck list downloaded, and when.
     *
     * @var array{0: object[], 1: int}|null
     */
    protected ?array $list = null;

    /**
     * Searches the deck list. Matches are Deck List entries, without cards;
     * {@see fetch()} one by `fileName` for its cards.
     *
     * Filters: `name` (partial), `code` (or `setCode`), `type`, `fileName`
     * and `releaseDate` (exact, case-insensitive). `|` separates
     * alternatives.
     *
     * @param array $params Filters.
     *
     * @return PromiseInterface<ExCollectionInterface<Deck>> Keyed by fileName, newest first. Rejects with \InvalidArgumentException on an unknown filter.
     */
    public function getDecks(array $params = []): PromiseInterface
    {
        $filters = [];
        foreach ($params as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            // studly() lowercases inner words, so only snake_case keys go through it.
            $key = str_contains((string) $key, '_') ? lcfirst(studly((string) $key)) : (string) $key;
            $key = $key === 'setCode' ? 'code' : $key;

            if (! in_array($key, ['name', 'code', 'type', 'fileName', 'releaseDate'], true)) {
                return reject(new \InvalidArgumentException("Unknown deck filter \"{$key}\"."));
            }

            $filters[$key] = array_values(array_filter(array_map(
                fn ($term) => mb_strtolower(trim($term)),
                is_array($value) ? $value : explode('|', (string) $value)
            ), fn ($term) => $term !== ''));
        }

        return $this->list()->then(function (array $list) use ($filters) {
            $collection = ($this->discord->getCollectionClass())::for(Deck::class, 'fileName');

            usort($list, fn ($a, $b) => [$b->releaseDate ?? '', $a->name ?? ''] <=> [$a->releaseDate ?? '', $b->name ?? '']);

            foreach ($list as $entry) {
                foreach ($filters as $key => $terms) {
                    $value = mb_strtolower((string) ($entry->{$key} ?? ''));
                    $match = $key === 'name'
                        ? (bool) array_filter($terms, fn ($term) => str_contains($value, $term))
                        : in_array($value, $terms, true);

                    if (! $match) {
                        continue 2;
                    }
                }

                $collection->pushItem($this->factory->part(Deck::class, (array) $entry, true));
            }

            return $collection;
        });
    }

    /**
     * Gets a deck with its cards.
     *
     * @param string $id    The deck's `fileName`, e.g. `AbzanSiege_KTK`.
     * @param bool   $fresh Whether to skip the cache.
     *
     * @return PromiseInterface<Deck> Rejects with a NotFoundException for an unknown file name.
     */
    public function fetch(string $id, bool $fresh = false): PromiseInterface
    {
        if (! $fresh && ($deck = $this->offsetGet($id)) instanceof Deck && $deck->hasCards()) {
            return resolve($deck);
        }

        $endpoint = new Endpoint(Endpoint::DECK);
        $endpoint->bindAssoc(['file_name' => $id]);

        return $this->mtg_http->get($endpoint)->then(function ($response) use ($id) {
            $deck = $this->factory->part(Deck::class, ['fileName' => $id] + (array) ($response->data ?? []), true);

            return $this->cache->set($id, $deck)->then(fn () => $deck);
        });
    }

    /**
     * Refills a deck with its cards.
     *
     * @param Part  $part        The deck.
     * @param array $queryparams Unused.
     *
     * @return PromiseInterface<Deck>
     */
    public function fresh(Part $part, array $queryparams = []): PromiseInterface
    {
        return $this->fetch((string) $part->fileName, true)->then(function (Deck $fresh) use ($part) {
            $part->fill($fresh->getRawAttributes());

            return $part;
        });
    }

    /**
     * Downloads the deck list again.
     *
     * @param array $queryparams Unused.
     *
     * @return PromiseInterface<static>
     */
    public function freshen(array $queryparams = []): PromiseInterface
    {
        $this->list = null;

        return $this->list()->then(fn () => $this);
    }

    /**
     * The deck list, downloaded at most once per {@see LIST_TTL}.
     *
     * @return PromiseInterface<object[]>
     */
    protected function list(): PromiseInterface
    {
        if ($this->list !== null && time() - $this->list[1] < self::LIST_TTL) {
            return resolve($this->list[0]);
        }

        return $this->mtg_http->get(new Endpoint(Endpoint::DECK_LIST))->then(function ($response) {
            $this->list = [(array) ($response->data ?? []), time()];

            return $this->list[0];
        });
    }
}
