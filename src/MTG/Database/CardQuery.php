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
 * A card search against the AllPrintings build's `cards` table, joined to
 * `sets` (for `setName`), `cardIdentifiers` and `cardLegalities` as filters
 * need them. One row per card face per printing, as in MTGJSON.
 *
 * Every `cards` column is a filter, matched by its kind: text columns
 * (`name`, `type`, `text`, `flavorText`, `artist`, …) partially, list columns
 * (`colors`, `colorIdentity`, `types`, `subtypes`, `keywords`, …) by
 * membership, numeric columns (`manaValue`, `edhrecRank`, …) with
 * comparisons, `is*`/`has*` columns as booleans, and the rest exactly. Every
 * `cardIdentifiers` column (`multiverseId`, `scryfallId`, `mtgArenaId`, …)
 * matches exactly. Besides those:
 *
 * - `setName`, `block` — partial match on the printing's set.
 * - `gameFormat` — legal in a format (`commander`, `Pauper Commander`, …);
 *   `legality` (`Legal`, `Banned`, `Restricted`, `Not Legal`) changes the
 *   status, and on its own matches any format.
 * - `language` — the printing's language; with `name`, a non-English
 *   language searches that language's printed names instead.
 * - `contains` — fields that must hold a value (`flavorText,power`), plus
 *   `rulings` and `foreignData`.
 * - `orderBy` (a column, `-name` or `name desc`), `random`, `page`, `pageSize`
 *   (1-100, default 1).
 *
 * The parameter names of the old api.magicthegathering.io (`cmc`, `set`,
 * `flavor`, `id`, `multiverseid`, `imageUrl`, …) are accepted as aliases.
 * Colors may be given as letters (`W`, `U`, `B`, `R`, `G`, `C` for
 * colorless), runs of letters (`UR`) or names.
 *
 * Without `orderBy` or `random`, exact name matches come first, then shorter
 * names, then the most regular printing: English, from a regular set, not a
 * promo, paper, newest first.
 *
 * @link https://mtgjson.com/data-models/card/card-set/ Card (Set) model
 *
 * @since 1.0.0
 */
class CardQuery extends Query
{
    /**
     * Old api.magicthegathering.io parameter names, mapped to MTGJSON's.
     *
     * @var array<string, string>
     */
    public const ALIASES = [
        'cmc' => 'manaValue',
        'convertedManaCost' => 'manaValue',
        'set' => 'setCode',
        'flavor' => 'flavorText',
        'id' => 'uuid',
        'multiverseid' => 'multiverseId',
        'border' => 'borderColor',
        'reserved' => 'isReserved',
        'timeshifted' => 'isTimeshifted',
        'imageUrl' => 'scryfallId',
        'foreignNames' => 'foreignData',
    ];

    /**
     * Text columns matched partially rather than exactly.
     *
     * @var string[]
     */
    public const PARTIAL = [
        'artist',
        'asciiName',
        'faceFlavorName',
        'faceName',
        'facePrintedName',
        'flavorName',
        'flavorText',
        'originalText',
        'originalType',
        'printedName',
        'printedText',
        'printedType',
        'text',
        'type',
    ];

    /**
     * List columns holding colors, which accept color names.
     *
     * @var string[]
     */
    public const COLOR_COLUMNS = ['colors', 'colorIdentity', 'colorIndicator', 'producedMana'];

    /**
     * Color names and letters, mapped to MTGJSON's letters; colorless maps
     * to an empty list.
     *
     * @var array<string, string>
     */
    public const COLORS = [
        'w' => 'W', 'white' => 'W',
        'u' => 'U', 'blue' => 'U',
        'b' => 'B', 'black' => 'B',
        'r' => 'R', 'red' => 'R',
        'g' => 'G', 'green' => 'G',
        'c' => '', 'colorless' => '',
    ];

    /**
     * Set types whose printings are preferred when results are not ordered
     * explicitly.
     *
     * @var string[]
     */
    public const REGULAR_SET_TYPES = [
        'core', 'expansion', 'masters', 'draft_innovation', 'commander', 'starter',
        'duel_deck', 'planechase', 'archenemy', 'from_the_vault', 'spellbook',
        'premium_deck', 'arsenal', 'box', 'eternal',
    ];

    /**
     * @inheritDoc
     */
    protected string $table = 'cards';

    /**
     * The name alternatives searched for, used to rank exact matches first.
     *
     * @var string[]
     */
    protected array $names = [];

    /**
     * @inheritDoc
     */
    public function filter(array $filters): static
    {
        $filters = self::normalize($filters);
        foreach (self::ALIASES as $alias => $field) {
            if (array_key_exists($alias, $filters)) {
                $filters[$field] ??= $filters[$alias];
                unset($filters[$alias]);
            }
        }

        $this->joins['sets'] = 'LEFT JOIN "sets" ON "sets"."code" = "cards"."setCode"';

        $cards = $this->database->getColumns('cards');
        $identifiers = $this->database->getColumns('cardIdentifiers');
        unset($identifiers['uuid']);

        $this->nameAndLanguage($filters['name'] ?? null, $filters['language'] ?? null);
        $this->legality($filters['gameFormat'] ?? null, $filters['legality'] ?? null);

        $page = $filters['page'] ?? 1;
        $pageSize = $filters['pageSize'] ?? 1;
        $orderBy = $filters['orderBy'] ?? null;
        $random = isset($filters['random']) && self::truthy($filters['random']);
        $contains = $filters['contains'] ?? null;

        unset(
            $filters['name'], $filters['language'], $filters['gameFormat'], $filters['legality'],
            $filters['page'], $filters['pageSize'], $filters['orderBy'], $filters['random'], $filters['contains'],
        );

        foreach ($filters as $field => $value) {
            $this->where(match (true) {
                $field === 'setName' => self::like(['"sets"."name"'], $value),
                $field === 'block' => self::like(['"sets"."block"'], $value),
                isset($cards[$field]) => self::column($field, $cards[$field], $value),
                isset($identifiers[$field]) => self::equals($this->identifier($field), $value),
                default => throw new \InvalidArgumentException("Unknown card filter \"{$field}\"."),
            });
        }

        if ($contains !== null) {
            $this->has($contains, $cards, $identifiers);
        }

        $this->paginate($page, $pageSize);

        if ($random) {
            $this->orderBy('RANDOM()');
        } elseif ($orderBy !== null) {
            $this->sort((string) $orderBy, $cards);
        } else {
            $this->rank();
        }

        return $this;
    }

    /**
     * @inheritDoc
     */
    protected function select(): string
    {
        return '"cards".*, "sets"."name" AS "setName"';
    }

    /**
     * The condition for one `cards` column, by its kind.
     *
     * @param string $column The column.
     * @param string $type   Its declared type.
     * @param mixed  $value
     *
     * @return array{0: ?string, 1: array}
     */
    protected static function column(string $column, string $type, mixed $value): array
    {
        $qualified = "\"cards\".\"{$column}\"";

        return match (true) {
            $column === 'setCode' => self::exact($qualified, $value, 'strtoupper'),
            $column === 'uuid' => self::exact($qualified, $value, 'strtolower'),
            in_array($column, self::COLOR_COLUMNS, true) => self::contains($qualified, self::colorRuns($value), fn (string $term) => self::COLORS[strtolower($term)] ?? $term),
            in_array($column, Database::LIST_COLUMNS['cards'], true) => self::contains($qualified, $value),
            in_array($column, Database::JSON_COLUMNS['cards'], true) => self::like([$qualified], $value),
            $type === 'BOOLEAN' => self::flag($qualified, $value),
            $type === 'INTEGER', $type === 'REAL' => self::compare($qualified, $value),
            in_array($column, self::PARTIAL, true) => self::like([$qualified], $value),
            default => self::equals($qualified, $value),
        };
    }

    /**
     * Splits runs of color letters (`UR`, `wubrg`) into separate required
     * terms (`U,R`), leaving color names alone.
     *
     * @param mixed $value
     *
     * @return mixed
     */
    protected static function colorRuns(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map(self::colorRuns(...), $value);
        }

        return preg_replace_callback(
            '/(?<![a-z])[wubrgc]{2,6}(?![a-z])/i',
            fn (array $match) => implode(',', str_split($match[0])),
            self::scalar($value)
        );
    }

    /**
     * Joins `cardIdentifiers` and qualifies one of its columns.
     *
     * @param string $column The column.
     *
     * @return string The qualified column.
     */
    protected function identifier(string $column): string
    {
        $this->joins['cardIdentifiers'] = 'LEFT JOIN "cardIdentifiers" ON "cardIdentifiers"."uuid" = "cards"."uuid"';

        return "\"cardIdentifiers\".\"{$column}\"";
    }

    /**
     * Filters on name and the printing's language. With a non-English
     * language, the name is searched among that language's printed names.
     *
     * @param mixed $name
     * @param mixed $language
     */
    protected function nameAndLanguage(mixed $name, mixed $language): void
    {
        if ($name !== null) {
            $this->names = array_map(fn (string $term) => trim($term, '"'), self::terms($name));
        }

        if ($language === null || self::isEnglish($language)) {
            if ($language !== null) {
                $this->where(self::equals('"cards"."language"', $language));
            }
            if ($name !== null) {
                $this->where(self::like(['"cards"."name"', '"cards"."faceName"', '"cards"."asciiName"'], $name));
            }

            return;
        }

        $languages = self::terms($language);
        [$names, $nameBindings] = $name !== null
            ? self::like(['"cardForeignData"."name"', '"cardForeignData"."faceName"'], $name)
            : [null, []];

        // One pass over the foreign names, rather than a correlated lookup per card.
        $foreign = '"cards"."uuid" IN (SELECT "cardForeignData"."uuid" FROM "cardForeignData"'
            .' WHERE "cardForeignData"."language" COLLATE NOCASE IN ('.self::placeholders($languages).')'
            .($names !== null ? " AND {$names}" : '').')';

        $this->where($names !== null
            // Searching a foreign name: the card must have been printed under it.
            ? [$foreign, [...$languages, ...$nameBindings]]
            // Otherwise a foreign-only printing carries the language itself.
            : ['("cards"."language" COLLATE NOCASE IN ('.self::placeholders($languages).") OR {$foreign})", [...$languages, ...$languages]]);
    }

    /**
     * Filters on format legality.
     *
     * @param mixed $format   A format name (`commander`, `Pauper Commander`, …), or `|`-separated names.
     * @param mixed $legality `Legal` (default), `Banned`, `Restricted` or `Not Legal`.
     *
     * @throws \InvalidArgumentException On an unknown format.
     */
    protected function legality(mixed $format, mixed $legality): void
    {
        if ($format === null && $legality === null) {
            return;
        }

        $columns = $this->database->getColumns('cardLegalities');
        unset($columns['uuid']);

        $formats = $format === null
            ? array_keys($columns)
            : array_map(fn (string $name) => strtolower(preg_replace('/[\s_-]+/', '', $name)), self::terms($format));

        $statuses = $legality === null ? ['Legal'] : self::terms($legality);

        $this->joins['cardLegalities'] = 'LEFT JOIN "cardLegalities" ON "cardLegalities"."uuid" = "cards"."uuid"';

        $conditions = [];
        foreach ($formats as $name) {
            if (! isset($columns[$name])) {
                throw new \InvalidArgumentException("Unknown game format \"{$name}\"; expected one of ".implode(', ', array_keys($columns)).'.');
            }

            $conditions[] = ["COALESCE(\"cardLegalities\".\"{$name}\", 'Not Legal') COLLATE NOCASE IN (".self::placeholders($statuses).')', $statuses];
        }

        $this->where(self::combine($conditions));
    }

    /**
     * Requires fields to hold a value.
     *
     * @param mixed $value       Field names, `,`-separated.
     * @param array $cards       The `cards` columns.
     * @param array $identifiers The `cardIdentifiers` columns.
     *
     * @throws \InvalidArgumentException On an unknown field.
     */
    protected function has(mixed $value, array $cards, array $identifiers): void
    {
        foreach (self::alternatives($value, true)[0] ?? [] as $field) {
            $field = self::ALIASES[$field] ?? $field;

            $this->where(match (true) {
                $field === 'rulings' => ['EXISTS (SELECT 1 FROM "cardRulings" WHERE "cardRulings"."uuid" = "cards"."uuid")', []],
                $field === 'foreignData' => ['EXISTS (SELECT 1 FROM "cardForeignData" WHERE "cardForeignData"."uuid" = "cards"."uuid")', []],
                isset($cards[$field]) => self::present("\"cards\".\"{$field}\""),
                isset($identifiers[$field]) => self::present($this->identifier($field)),
                default => throw new \InvalidArgumentException("Unknown field \"{$field}\" in contains."),
            });
        }
    }

    /**
     * Orders by an explicit field.
     *
     * @param string $value A column, `-column` or `column desc`.
     * @param array  $cards The `cards` columns.
     *
     * @throws \InvalidArgumentException On an unknown field.
     */
    protected function sort(string $value, array $cards): void
    {
        [$field, $direction] = self::direction($value);
        $field = self::ALIASES[$field] ?? $field;

        $column = match (true) {
            $field === 'setName' => '"sets"."name"',
            $field === 'releaseDate' => '"sets"."releaseDate"',
            isset($cards[$field]) => "\"cards\".\"{$field}\"",
            default => throw new \InvalidArgumentException("Cannot order by unknown field \"{$field}\"."),
        };

        $this->orderBy("{$column} {$direction}");
        $this->orderBy('"cards"."uuid"');
    }

    /**
     * The default ranking: exact name matches, shorter names, then the most
     * regular printing, newest first.
     */
    protected function rank(): void
    {
        if ($this->names) {
            $names = self::placeholders($this->names);
            $this->orderBy(
                "CASE WHEN \"cards\".\"name\" COLLATE NOCASE IN ({$names}) OR \"cards\".\"faceName\" COLLATE NOCASE IN ({$names}) THEN 0 ELSE 1 END",
                [...$this->names, ...$this->names]
            );
            $this->orderBy('length("cards"."name")');
        }

        $regular = implode(', ', array_map(fn (string $type) => "'{$type}'", self::REGULAR_SET_TYPES));

        $this->orderBy("CASE WHEN \"cards\".\"language\" = 'English' THEN 0 ELSE 1 END");
        $this->orderBy("CASE WHEN \"sets\".\"type\" IN ({$regular}) THEN 0 ELSE 1 END");
        $this->orderBy('COALESCE("cards"."isPromo", 0)');
        $this->orderBy('COALESCE("cards"."isOnlineOnly", 0)');
        $this->orderBy('COALESCE("cards"."isFunny", 0)');
        $this->orderBy('"sets"."releaseDate" DESC');
        $this->orderBy('COALESCE("cards"."side", \'\')');
        $this->orderBy('"cards"."uuid"');
    }

    /**
     * Whether a language filter asks only for English.
     *
     * @param mixed $language
     *
     * @return bool
     */
    protected static function isEnglish(mixed $language): bool
    {
        return array_map('strtolower', self::terms($language)) === ['english'];
    }
}
