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
 * A set search against the AllPrintings build's `sets` table.
 *
 * Every `sets` column is a filter: `name`, `block` and `mcmName` match
 * partially, `languages` by membership, numbers with comparisons, `is*`
 * columns as booleans, and the rest (`code`, `type`, `parentCode`,
 * `releaseDate`, …) exactly. `orderBy`, `page` and `pageSize` (1-100,
 * default 100) work as for cards. Unordered results are newest first.
 *
 * @link https://mtgjson.com/data-models/set/ Set model
 *
 * @since 1.0.0
 */
class SetQuery extends Query
{
    /**
     * Text columns matched partially rather than exactly.
     *
     * @var string[]
     */
    public const PARTIAL = ['name', 'block', 'mcmName'];

    /**
     * Set code columns, stored upper case and matched exactly.
     *
     * @var string[]
     */
    public const CODES = ['code', 'keyruneCode', 'mtgoCode', 'parentCode', 'tokenSetCode'];

    /**
     * @inheritDoc
     */
    protected string $table = 'sets';

    /**
     * @inheritDoc
     */
    public function filter(array $filters): static
    {
        $filters = self::normalize($filters);
        $columns = $this->database->getColumns('sets');

        $page = $filters['page'] ?? 1;
        $pageSize = $filters['pageSize'] ?? self::MAX_PAGE_SIZE;
        $orderBy = $filters['orderBy'] ?? '-releaseDate';
        unset($filters['page'], $filters['pageSize'], $filters['orderBy']);

        foreach ($filters as $field => $value) {
            if (! isset($columns[$field])) {
                throw new \InvalidArgumentException("Unknown set filter \"{$field}\".");
            }

            $column = "\"sets\".\"{$field}\"";

            $this->where(match (true) {
                in_array($field, self::CODES, true) => self::exact($column, $value, 'strtoupper'),
                in_array($field, Database::LIST_COLUMNS['sets'], true) => self::contains($column, $value),
                $columns[$field] === 'BOOLEAN' => self::flag($column, $value),
                $columns[$field] === 'INTEGER' => self::compare($column, $value),
                in_array($field, self::PARTIAL, true) => self::like([$column], $value),
                default => self::equals($column, $value),
            });
        }

        [$field, $direction] = self::direction((string) $orderBy);
        if (! isset($columns[$field])) {
            throw new \InvalidArgumentException("Cannot order by unknown field \"{$field}\".");
        }

        $this->orderBy("\"sets\".\"{$field}\" {$direction}");
        $this->orderBy('"sets"."code"');
        $this->paginate($page, $pageSize);

        return $this;
    }
}
