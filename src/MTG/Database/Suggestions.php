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
 * Autocomplete answers for command options — card names, sets, booster
 * types, set types, keywords and formats — fast enough to run on every
 * keystroke inside Discord's three-second window.
 *
 * Card names, sets and keywords are read from the build once into memory
 * (a few megabytes) and read again when a new build is installed. Nothing is
 * suggested while the build is not open yet.
 *
 * @since 1.1.0
 */
class Suggestions
{
    /**
     * Discord's limit on autocomplete choices.
     *
     * @var int
     */
    public const LIMIT = 25;

    /**
     * The build version the lists were read from.
     *
     * @var string|null
     */
    protected ?string $version = null;

    /**
     * Distinct card names, lower case → name.
     *
     * @var array<string, string>
     */
    protected array $names = [];

    /**
     * Sets, newest first: code → {name, releaseDate, type, boosters}.
     *
     * @var array<string, array{name: string, releaseDate: ?string, type: ?string, boosters: bool}>
     */
    protected array $sets = [];

    /**
     * Distinct keywords, sorted.
     *
     * @var string[]
     */
    protected array $keywords = [];

    /**
     * @param Database $database The card build.
     */
    public function __construct(protected Database $database)
    {
    }

    /**
     * Card names matching what was typed: names starting with it first, then
     * names with a word starting with it, then any containing it; shorter
     * names first within each.
     *
     * @param string $typed
     * @param int    $limit
     *
     * @return string[]
     */
    public function cardNames(string $typed, int $limit = self::LIMIT): array
    {
        if (! $this->load()) {
            return [];
        }

        $typed = mb_strtolower(trim($typed));
        if ($typed === '') {
            return [];
        }

        $ranked = [[], [], []];
        foreach ($this->names as $lower => $name) {
            $at = strpos($lower, $typed);
            if ($at === false) {
                continue;
            }

            $tier = $at === 0 ? 0 : (preg_match('/[\s,\-\/(]/', $lower[$at - 1]) ? 1 : 2);
            $ranked[$tier][] = $name;
        }

        $names = [];
        foreach ($ranked as $tier) {
            usort($tier, fn (string $a, string $b) => [strlen($a), $a] <=> [strlen($b), $b]);
            array_push($names, ...$tier);
            if (count($names) >= $limit) {
                break;
            }
        }

        return array_slice($names, 0, $limit);
    }

    /**
     * Sets matching what was typed, by code or name, newest first. A code
     * typed exactly comes first.
     *
     * @param string $typed
     * @param bool   $boosters Only sets MTGJSON has booster configurations for.
     * @param int    $limit
     *
     * @return array<string, string> Code → label (`Name (CODE, year)`).
     */
    public function sets(string $typed, bool $boosters = false, int $limit = self::LIMIT): array
    {
        if (! $this->load()) {
            return [];
        }

        $typed = mb_strtolower(trim($typed));
        $exact = [];
        $matches = [];

        foreach ($this->sets as $code => $set) {
            if ($boosters && ! $set['boosters']) {
                continue;
            }

            if ($typed !== '' && strtolower($code) === $typed) {
                $exact[$code] = $this->setLabel($code, $set);
            } elseif ($typed === '' || str_contains(strtolower($code), $typed) || str_contains(mb_strtolower($set['name']), $typed)) {
                $matches[$code] = $this->setLabel($code, $set);
            }

            if (count($exact) + count($matches) >= $limit * 4) {
                break;
            }
        }

        return array_slice($exact + $matches, 0, $limit, true);
    }

    /**
     * The sets a card was printed in, newest first.
     *
     * @param string $name  The card's exact name.
     * @param string $typed Filters the sets by code or name.
     *
     * @return array<string, string> Code → label.
     */
    public function printingSets(string $name, string $typed = ''): array
    {
        if (! $this->load()) {
            return [];
        }

        $typed = mb_strtolower(trim($typed));
        $sets = [];

        foreach ($this->database->select('SELECT DISTINCT "setCode" FROM "cards" WHERE "name" = ?', [$name]) as $row) {
            $code = $row['setCode'];
            $set = $this->sets[$code] ?? ['name' => $code, 'releaseDate' => null, 'type' => null, 'boosters' => false];

            if ($typed === '' || str_contains(strtolower($code), $typed) || str_contains(mb_strtolower($set['name']), $typed)) {
                $sets[$code] = [$set['releaseDate'] ?? '', $this->setLabel($code, $set)];
            }
        }

        uasort($sets, fn ($a, $b) => $b[0] <=> $a[0]);

        return array_slice(array_map(fn ($set) => $set[1], $sets), 0, self::LIMIT, true);
    }

    /**
     * The set types (`expansion`, `core`, `masters`, …) matching what was typed.
     *
     * @param string $typed
     *
     * @return string[]
     */
    public function setTypes(string $typed = ''): array
    {
        if (! $this->load()) {
            return [];
        }

        $types = array_unique(array_filter(array_column($this->sets, 'type')));
        sort($types);

        return array_slice(array_values(array_filter($types, fn ($type) => $typed === '' || str_contains($type, mb_strtolower(trim($typed))))), 0, self::LIMIT);
    }

    /**
     * Keywords (keyword abilities, actions and ability words found on cards)
     * matching what was typed, prefix matches first.
     *
     * @param string $typed
     *
     * @return string[]
     */
    public function keywords(string $typed = ''): array
    {
        if (! $this->load()) {
            return [];
        }

        $typed = mb_strtolower(trim($typed));
        $prefix = [];
        $contains = [];

        foreach ($this->keywords as $keyword) {
            $at = $typed === '' ? 0 : mb_strpos(mb_strtolower($keyword), $typed);
            if ($at === 0) {
                $prefix[] = $keyword;
            } elseif ($at !== false) {
                $contains[] = $keyword;
            }
        }

        return array_slice([...$prefix, ...$contains], 0, self::LIMIT);
    }

    /**
     * The formats MTGJSON tracks legality in, matching what was typed.
     *
     * @param string $typed
     *
     * @return array<string, string> Key → friendly name.
     */
    public function formats(string $typed = ''): array
    {
        if (! $this->load()) {
            return [];
        }

        $typed = mb_strtolower(trim($typed));
        $formats = [];

        foreach (array_keys($this->database->getColumns('cardLegalities')) as $format) {
            $label = \MTG\Helpers\Text::format($format);
            if ($format !== 'uuid' && ($typed === '' || str_contains($format, $typed) || str_contains(mb_strtolower($label), $typed))) {
                $formats[$format] = $label;
            }
        }

        asort($formats);

        return array_slice($formats, 0, self::LIMIT, true);
    }

    /**
     * A set's name and release date, if the build has it.
     *
     * @param string $code
     *
     * @return array{name: string, releaseDate: ?string, type: ?string, boosters: bool}|null
     */
    public function set(string $code): ?array
    {
        return $this->load() ? ($this->sets[strtoupper($code)] ?? null) : null;
    }

    /**
     * Reads the lists, once per build.
     *
     * @return bool Whether the build is open.
     */
    protected function load(): bool
    {
        if (! $this->database->isOpen()) {
            return false;
        }

        if ($this->version === $this->database->getVersion() && $this->names !== []) {
            return true;
        }

        $names = [];
        foreach ($this->database->select('SELECT DISTINCT "name" FROM "cards"') as $row) {
            $names[mb_strtolower($row['name'])] = $row['name'];
        }

        $sets = [];
        foreach ($this->database->select(
            'SELECT "code", "name", "releaseDate", "type", EXISTS (SELECT 1 FROM "setBoosterContentWeights" AS "w" WHERE "w"."setCode" = "sets"."code") AS "boosters" FROM "sets" ORDER BY "releaseDate" DESC, "code"'
        ) as $row) {
            $sets[$row['code']] = ['name' => (string) $row['name'], 'releaseDate' => $row['releaseDate'], 'type' => $row['type'], 'boosters' => (bool) $row['boosters']];
        }

        $keywords = [];
        foreach ($this->database->select('SELECT DISTINCT "keywords" FROM "cards" WHERE "keywords" IS NOT NULL AND "keywords" != \'\'') as $row) {
            foreach (explode(', ', $row['keywords']) as $keyword) {
                $keywords[$keyword] = true;
            }
        }
        $keywords = array_keys($keywords);
        sort($keywords);

        $this->names = $names;
        $this->sets = $sets;
        $this->keywords = $keywords;
        $this->version = $this->database->getVersion();

        return true;
    }

    /**
     * @param string $code
     * @param array  $set
     *
     * @return string
     */
    protected function setLabel(string $code, array $set): string
    {
        $year = $set['releaseDate'] ? substr($set['releaseDate'], 0, 4) : null;

        return \MTG\Helpers\Text::clip("{$set['name']} ({$code}".($year ? ", {$year}" : '').')', 100);
    }
}
