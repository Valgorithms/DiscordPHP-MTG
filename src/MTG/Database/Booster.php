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

/**
 * Opens booster packs from a set's MTGJSON booster configuration, as
 * stored in the AllPrintings build's `setBooster*` tables.
 *
 * A booster type (`play`, `draft`, `collector`, …) has weighted pack
 * layouts; each layout takes a number of picks from named sheets, and each
 * sheet holds weighted cards. Picks from one sheet do not repeat a card
 * unless the sheet runs out. A sheet asking for at least as many picks as
 * its total weight is fixed: every card comes as many times as its weight.
 * A color-balanced sheet (most common slots) first takes one mono-colored
 * card of each color, then fills the rest.
 *
 * @link https://mtgjson.com/data-models/booster/ Booster models
 *
 * @since 1.0.0
 */
class Booster
{
    /**
     * Booster types preferred when none is asked for, best first.
     *
     * @var string[]
     */
    public const PREFERENCE = ['play', 'draft', 'default', 'set', 'collector'];

    /**
     * The colors a balanced sheet covers.
     *
     * @var string[]
     */
    public const COLORS = ['W', 'U', 'B', 'R', 'G'];

    /**
     * @param Database $database An open build.
     */
    public function __construct(protected Database $database)
    {
    }

    /**
     * The booster types a set has, preferred first.
     *
     * @param string $setCode The set code, upper case.
     *
     * @return string[]
     */
    public function types(string $setCode): array
    {
        $types = array_column(
            $this->database->select('SELECT DISTINCT "boosterName" FROM "setBoosterContentWeights" WHERE "setCode" = ?', [$setCode]),
            'boosterName'
        );

        usort($types, function (string $a, string $b): int {
            $rank = fn (string $type) => ($index = array_search($type, self::PREFERENCE, true)) === false ? count(self::PREFERENCE) : $index;

            return [$rank($a), $a] <=> [$rank($b), $b];
        });

        return $types;
    }

    /**
     * Opens one pack.
     *
     * @param string $setCode The set code, upper case.
     * @param string $type    The booster type, from {@see types()}.
     *
     * @throws \InvalidArgumentException When the set has no such booster.
     *
     * @return array<array{uuid: string, foil: bool}> The pack's cards, in MTGJSON's sheet order.
     */
    public function open(string $setCode, string $type): array
    {
        $layouts = $this->database->select(
            'SELECT "boosterIndex", "boosterWeight" FROM "setBoosterContentWeights" WHERE "setCode" = ? AND "boosterName" = ?',
            [$setCode, $type]
        );

        if (! $layouts) {
            throw new \InvalidArgumentException("Set {$setCode} has no \"{$type}\" booster in MTGJSON.");
        }

        $layout = self::weighted(array_column($layouts, 'boosterWeight', 'boosterIndex'));

        $contents = $this->database->select(
            'SELECT "sheetName", "sheetPicks" FROM "setBoosterContents" WHERE "setCode" = ? AND "boosterName" = ? AND "boosterIndex" = ? ORDER BY rowid',
            [$setCode, $type, $layout]
        );

        $sheets = [];
        foreach ($this->database->select(
            'SELECT "sheetName", "sheetIsFoil", "sheetHasBalanceColors" FROM "setBoosterSheets" WHERE "setCode" = ? AND "boosterName" = ?',
            [$setCode, $type]
        ) as $sheet) {
            $sheets[$sheet['sheetName']] = ['foil' => (bool) $sheet['sheetIsFoil'], 'balance' => (bool) $sheet['sheetHasBalanceColors'], 'cards' => []];
        }

        foreach ($this->database->select(
            'SELECT "s"."sheetName", "s"."cardUuid", "s"."cardWeight", "c"."colors" FROM "setBoosterSheetCards" AS "s"'
            .' LEFT JOIN "cards" AS "c" ON "c"."uuid" = "s"."cardUuid" WHERE "s"."setCode" = ? AND "s"."boosterName" = ?',
            [$setCode, $type]
        ) as $card) {
            $sheets[$card['sheetName']]['cards'][$card['cardUuid']] = ['weight' => (int) $card['cardWeight'], 'colors' => (string) $card['colors']];
        }

        $pack = [];
        foreach ($contents as $content) {
            $sheet = $sheets[$content['sheetName']] ?? null;
            if (! $sheet || ! $sheet['cards']) {
                continue;
            }

            foreach (self::fill($sheet['cards'], (int) $content['sheetPicks'], $sheet['balance']) as $uuid) {
                $pack[] = ['uuid' => $uuid, 'foil' => $sheet['foil']];
            }
        }

        return $pack;
    }

    /**
     * Picks a sheet's cards for one pack.
     *
     * @param array<string, array{weight: int, colors: string}> $cards   The sheet, by uuid.
     * @param int                                               $picks   How many cards to take.
     * @param bool                                              $balance Whether to cover each color first.
     *
     * @return string[] The picked uuids.
     */
    public static function fill(array $cards, int $picks, bool $balance = false): array
    {
        $weights = array_map(fn (array $card) => $card['weight'], $cards);

        // A fixed sheet: take everything, each card as often as its weight.
        if ($picks >= array_sum($weights)) {
            $all = [];
            foreach ($weights as $uuid => $weight) {
                array_push($all, ...array_fill(0, $weight, (string) $uuid));
            }

            return $all;
        }

        $picked = [];

        if ($balance && $picks >= count(self::COLORS)) {
            foreach (self::COLORS as $color) {
                $mono = array_filter($weights, fn ($weight, $uuid) => $cards[$uuid]['colors'] === $color, ARRAY_FILTER_USE_BOTH);
                if ($mono) {
                    $uuid = (string) self::weighted($mono);
                    $picked[] = $uuid;
                    unset($weights[$uuid]);
                }
            }
        }

        $remaining = $weights;
        while (count($picked) < $picks) {
            if (! $remaining) {
                // The sheet ran out: let cards repeat.
                $remaining = array_map(fn (array $card) => $card['weight'], $cards);
            }

            $uuid = (string) self::weighted($remaining);
            $picked[] = $uuid;
            unset($remaining[$uuid]);
        }

        if ($balance) {
            shuffle($picked);
        }

        return $picked;
    }

    /**
     * Picks one key, with probability proportional to its weight.
     *
     * @param array<int|string, int> $weights
     *
     * @return int|string
     */
    public static function weighted(array $weights): int|string
    {
        $roll = random_int(1, max(1, array_sum($weights)));

        foreach ($weights as $key => $weight) {
            if (($roll -= $weight) <= 0) {
                return $key;
            }
        }

        return array_key_last($weights);
    }
}
