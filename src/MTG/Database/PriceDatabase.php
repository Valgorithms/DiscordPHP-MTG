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

namespace MTG\Database;

use Psr\Http\Message\ResponseInterface;
use React\Promise\PromiseInterface;

/**
 * Today's card prices, kept current the same way as the card build: a small
 * SQLite file ({@see PriceBuilder}) that the `prices` GitHub Actions workflow
 * builds daily from MTGJSON's `AllPricesToday` and publishes as an asset of
 * the `prices` release, beside a `prices.json` holding its version.
 *
 * Prices come from MTGJSON's partners — TCGplayer, Card Kingdom, Cardmarket,
 * Mana Pool and (for MTGO) Cardhoarder — and are one day old at most.
 *
 * @link https://mtgjson.com/data-models/price/price-formats/ Price Formats model
 *
 * @since 1.1.0
 */
class PriceDatabase extends Database
{
    /**
     * @inheritDoc
     */
    public const FILE = 'prices.sqlite';

    /**
     * Where the `prices` workflow publishes the build.
     *
     * @var string
     */
    public const DEFAULT_SOURCE = 'https://github.com/Valgorithms/DiscordPHP-MTG/releases/download/prices/prices.sqlite.gz';

    /**
     * The build is keyed by uuid already.
     *
     * @var string[]
     */
    public const INDEXES = [];

    /**
     * What each price column is: column → medium, provider, kind, finish, currency.
     *
     * @var array<string, array{medium: string, provider: string, kind: string, finish: string, currency: ?string}>|null
     */
    protected ?array $priceColumns = null;

    /**
     * The gzipped build's URL; `prices.json` is read from beside it.
     *
     * @var string
     */
    protected string $source = self::DEFAULT_SOURCE;

    /**
     * Uses another published build, e.g. a fork's.
     *
     * @param string $source The `prices.sqlite.gz` URL.
     *
     * @return static
     */
    public function setSource(string $source): static
    {
        $this->source = $source;

        return $this;
    }

    /**
     * Today's prices for some cards, nested as MTGJSON nests them but with a
     * single price per point: `medium → provider → {currency, retail|buylist
     * → finish → price}`. Cards without a price are left out; nothing is
     * returned while the build is not open.
     *
     * @param string[] $uuids
     *
     * @return array<string, array> Prices, by uuid.
     */
    public function prices(array $uuids): array
    {
        if (! $this->isOpen() || $uuids === []) {
            return [];
        }

        $columns = $this->priceColumns ??= array_column(
            $this->select('SELECT * FROM "priceColumns"'),
            null,
            'column'
        );

        $prices = [];
        foreach ($this->select('SELECT * FROM "prices" WHERE "uuid" IN ('.implode(', ', array_fill(0, count($uuids), '?')).')', array_values($uuids)) as $row) {
            foreach ($row as $column => $price) {
                if ($price === null || ! isset($columns[$column])) {
                    continue;
                }

                ['medium' => $medium, 'provider' => $provider, 'kind' => $kind, 'finish' => $finish, 'currency' => $currency] = $columns[$column];
                $prices[$row['uuid']][$medium][$provider]['currency'] ??= $currency;
                $prices[$row['uuid']][$medium][$provider][$kind][$finish] = (float) $price;
            }
        }

        return $prices;
    }

    /**
     * @inheritDoc
     */
    protected function open(): void
    {
        parent::open();
        $this->priceColumns = null;
    }

    /**
     * @inheritDoc
     */
    protected function label(): string
    {
        return 'card price build';
    }

    /**
     * @inheritDoc
     */
    protected function source(): string
    {
        return $this->source;
    }

    /**
     * @inheritDoc
     */
    protected function remoteVersion(): PromiseInterface
    {
        return $this->browser
            ->get(dirname($this->source).'/prices.json', ['User-Agent' => $this->http->getUserAgent()])
            ->then(fn (ResponseInterface $response) => json_decode((string) $response->getBody(), true)['version'] ?? null);
    }
}
