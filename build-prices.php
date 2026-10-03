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

/**
 * Builds the card price files the bot downloads: `prices.sqlite.gz` and
 * `prices.json` (its date and version), from MTGJSON's `AllPricesToday`.
 *
 * Run daily by the `prices` workflow, which publishes both as assets of the
 * `prices` release. Needs no Composer install: only PDO SQLite and zlib.
 *
 * Usage: php build-prices.php [output directory, default "build"]
 */

use MTG\Database\PriceBuilder;

is_file(__DIR__.'/vendor/autoload.php')
    ? require __DIR__.'/vendor/autoload.php'
    : require __DIR__.'/src/MTG/Database/PriceBuilder.php';

ini_set('memory_limit', '-1');

$out = rtrim($argv[1] ?? __DIR__.'/build', '/\\');
if (! is_dir($out) && ! mkdir($out, 0777, true) && ! is_dir($out)) {
    fwrite(STDERR, "Cannot create {$out}\n");
    exit(1);
}

$source = 'https://mtgjson.com/api/v5/AllPricesToday.json.gz';
$context = stream_context_create(['http' => ['header' => "User-Agent: DiscordPHP-MTG price builder (https://github.com/Valgorithms/DiscordPHP-MTG)\r\n", 'timeout' => 300]]);

$started = microtime(true);
$gz = file_get_contents($source, false, $context);
if ($gz === false || ($json = gzdecode($gz)) === false) {
    fwrite(STDERR, "Cannot download {$source}\n");
    exit(1);
}
unset($gz);

$document = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
unset($json);

$sqlite = "{$out}/prices.sqlite";
$cards = PriceBuilder::build($document['data'] ?? [], $document['meta'] ?? [], $sqlite);

file_put_contents("{$sqlite}.gz", gzencode(file_get_contents($sqlite), 9));
file_put_contents("{$out}/prices.json", json_encode([
    'date' => $document['meta']['date'] ?? null,
    'version' => $document['meta']['version'] ?? null,
    'cards' => $cards,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

printf(
    "Built prices for %d cards (MTGJSON %s) in %.1fs: %s (%.1f MB, %.1f MB gzipped)\n",
    $cards,
    $document['meta']['version'] ?? '?',
    microtime(true) - $started,
    $sqlite,
    filesize($sqlite) / 1048576,
    filesize("{$sqlite}.gz") / 1048576,
);
