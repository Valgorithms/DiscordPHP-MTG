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

use PDO;

/**
 * Turns MTGJSON's `AllPricesToday.json` into the small SQLite build
 * {@see PriceDatabase} reads.
 *
 * MTGJSON publishes a day of prices as one 53 MB JSON document; decoding it
 * takes close to a gigabyte, which is no job for a long-running bot. The
 * `prices` GitHub Actions workflow runs this once a day instead
 * (`build-prices.php`) and publishes the result as a release asset.
 *
 * The build has one row per card printing in `prices`, keyed by `uuid`, with
 * one `REAL` column per `medium.provider.kind.finish` that has any price
 * (`paper.tcgplayer.retail.normal`, `paper.cardkingdom.buylist.foil`,
 * `mtgo.cardhoarder.retail.normal`, …). `priceColumns` says what each column
 * is and its currency, so a provider MTGJSON adds later needs no code change.
 * `meta` carries MTGJSON's build date and version.
 *
 * Deliberately free of dependencies, so the workflow can run it without a
 * Composer install.
 *
 * @link https://mtgjson.com/data-models/price/price-formats/ Price Formats model
 *
 * @since 1.1.0
 */
final class PriceBuilder
{
    /**
     * Which price lists to keep, in column order.
     *
     * @var string[]
     */
    public const KINDS = ['retail', 'buylist'];

    /**
     * Flattens MTGJSON's price data to one row of latest prices per card.
     *
     * @param array $data `AllPricesToday.json`'s `data`: uuid → medium → provider → {currency, retail, buylist}.
     *
     * @return array{0: array<string, array{medium: string, provider: string, kind: string, finish: string, currency: ?string}>, 1: array<string, array<string, float>>}
     *                                                                                                                                                                   The columns, sorted, and the rows by uuid.
     */
    public static function flatten(array $data): array
    {
        $columns = [];
        $rows = [];

        foreach ($data as $uuid => $media) {
            $row = [];

            foreach ((array) $media as $medium => $providers) {
                foreach ((array) $providers as $provider => $list) {
                    $list = (array) $list;

                    foreach (self::KINDS as $kind) {
                        foreach ((array) ($list[$kind] ?? []) as $finish => $points) {
                            $points = (array) $points;
                            if ($points === []) {
                                continue;
                            }

                            // Today's file holds one date per point; keep the latest regardless.
                            ksort($points);
                            $column = "{$medium}.{$provider}.{$kind}.{$finish}";
                            $columns[$column] ??= [
                                'medium' => (string) $medium,
                                'provider' => (string) $provider,
                                'kind' => $kind,
                                'finish' => (string) $finish,
                                'currency' => isset($list['currency']) ? (string) $list['currency'] : null,
                            ];
                            $row[$column] = (float) end($points);
                        }
                    }
                }
            }

            if ($row !== []) {
                $rows[(string) $uuid] = $row;
            }
        }

        ksort($columns);

        return [$columns, $rows];
    }

    /**
     * Writes the price build.
     *
     * @param array  $data MTGJSON's price `data`.
     * @param array  $meta MTGJSON's `meta` (`date`, `version`).
     * @param string $path The SQLite file to create; an existing one is replaced.
     *
     * @return int The number of cards with a price.
     */
    public static function build(array $data, array $meta, string $path): int
    {
        [$columns, $rows] = self::flatten($data);

        if (is_file($path)) {
            unlink($path);
        }

        $pdo = new PDO('sqlite:'.$path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('PRAGMA journal_mode = OFF');
        $pdo->exec('PRAGMA synchronous = OFF');

        $pdo->exec('CREATE TABLE "meta" ("date" TEXT, "version" TEXT)');
        $pdo->prepare('INSERT INTO "meta" ("date", "version") VALUES (?, ?)')->execute([$meta['date'] ?? null, $meta['version'] ?? null]);

        $pdo->exec('CREATE TABLE "priceColumns" ("column" TEXT PRIMARY KEY, "medium" TEXT, "provider" TEXT, "kind" TEXT, "finish" TEXT, "currency" TEXT)');
        $insert = $pdo->prepare('INSERT INTO "priceColumns" VALUES (?, ?, ?, ?, ?, ?)');
        foreach ($columns as $column => $info) {
            $insert->execute([$column, $info['medium'], $info['provider'], $info['kind'], $info['finish'], $info['currency']]);
        }

        $names = array_keys($columns);
        $quoted = array_map(fn (string $column) => '"'.str_replace('"', '""', $column).'"', $names);
        $pdo->exec('CREATE TABLE "prices" ("uuid" TEXT PRIMARY KEY'.implode('', array_map(fn ($column) => ", {$column} REAL", $quoted)).') WITHOUT ROWID');

        $insert = $pdo->prepare('INSERT INTO "prices" ("uuid", '.implode(', ', $quoted).') VALUES (?'.str_repeat(', ?', count($names)).')');
        $pdo->beginTransaction();
        foreach ($rows as $uuid => $row) {
            $values = [$uuid];
            foreach ($names as $column) {
                $values[] = $row[$column] ?? null;
            }
            $insert->execute($values);
        }
        $pdo->commit();

        $pdo->exec('VACUUM');

        return count($rows);
    }
}
