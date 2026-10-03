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

use function Discord\studly;

/**
 * Compiles a search — the same `key => value` filters the old
 * api.magicthegathering.io took — into SQL against the AllPrintings build.
 *
 * Filter values keep that API's conventions: `|` separates alternatives
 * (logical or) and, for fields holding several values such as `colors`, `,`
 * requires all of them (logical and), so `colors=R,G|U` means "red and green,
 * or blue". Text fields match partially and case-insensitively; a value in
 * double quotes matches exactly. Numbers accept a `gt`/`gte`/`lt`/`lte` (or
 * `>`/`>=`/`<`/`<=`) prefix.
 *
 * Column names are only ever taken from the build's own schema, so filter
 * keys cannot inject SQL; values are always bound.
 *
 * Each condition helper returns `[sql, bindings]` (`sql` is null when the
 * value held no terms), so conditions can be combined or nested before
 * {@see where()} adds them.
 *
 * @see CardQuery
 * @see SetQuery
 *
 * @since 1.0.0
 */
abstract class Query
{
    /**
     * The largest page a query may return.
     *
     * @var int
     */
    public const MAX_PAGE_SIZE = 100;

    /**
     * The primary table.
     *
     * @var string
     */
    protected string $table;

    /**
     * Joined tables, by table name, as SQL fragments.
     *
     * @var array<string, string>
     */
    protected array $joins = [];

    /**
     * Conditions, combined with `AND`.
     *
     * @var string[]
     */
    protected array $where = [];

    /**
     * Values bound to the conditions' placeholders, in order.
     *
     * @var array
     */
    protected array $bindings = [];

    /**
     * `ORDER BY` terms.
     *
     * @var string[]
     */
    protected array $order = [];

    /**
     * Values bound to the ordering's placeholders, in order.
     *
     * @var array
     */
    protected array $orderBindings = [];

    /**
     * Page size, or null for no limit.
     *
     * @var int|null
     */
    protected ?int $limit = null;

    /**
     * Rows to skip.
     *
     * @var int
     */
    protected int $offset = 0;

    /**
     * @param Database $database An open build; its schema decides which filters exist.
     */
    public function __construct(protected Database $database)
    {
    }

    /**
     * Applies a set of filters and query modifiers.
     *
     * @param array $filters `key => value`; keys may be snake_case or camelCase.
     *
     * @throws \InvalidArgumentException On an unknown key or an invalid value.
     *
     * @return static
     */
    abstract public function filter(array $filters): static;

    /**
     * The compiled statement.
     *
     * @return string
     */
    public function toSql(): string
    {
        $sql = "SELECT {$this->select()} FROM \"{$this->table}\"";

        if ($this->joins) {
            $sql .= ' '.implode(' ', $this->joins);
        }

        if ($this->where) {
            $sql .= ' WHERE '.implode(' AND ', $this->where);
        }

        if ($this->order) {
            $sql .= ' ORDER BY '.implode(', ', $this->order);
        }

        if ($this->limit !== null) {
            $sql .= " LIMIT {$this->limit} OFFSET {$this->offset}";
        }

        return $sql;
    }

    /**
     * The values bound to the compiled statement's placeholders.
     *
     * @return array
     */
    public function getBindings(): array
    {
        return array_merge($this->bindings, $this->orderBindings);
    }

    /**
     * Runs the compiled statement and decodes each row.
     *
     * @return array[]
     */
    public function get(): array
    {
        return array_map(
            fn (array $row) => $this->database->decode($this->table, $row),
            $this->database->select($this->toSql(), $this->getBindings())
        );
    }

    /**
     * The select list.
     *
     * @return string
     */
    protected function select(): string
    {
        return "\"{$this->table}\".*";
    }

    /**
     * Adds a condition.
     *
     * @param array{0: ?string, 1: array} $condition `[sql, bindings]`; a null `sql` adds nothing.
     */
    protected function where(array $condition): void
    {
        [$sql, $bindings] = $condition;

        if ($sql !== null) {
            $this->where[] = $sql;
            array_push($this->bindings, ...$bindings);
        }
    }

    /**
     * Adds an `ORDER BY` term.
     *
     * @param string $term     SQL, with `?` placeholders.
     * @param array  $bindings Values for the placeholders.
     */
    protected function orderBy(string $term, array $bindings = []): void
    {
        $this->order[] = $term;
        array_push($this->orderBindings, ...$bindings);
    }

    /**
     * Normalizes filter keys to camelCase (`color_identity` → `colorIdentity`)
     * and drops empty values.
     *
     * @param array $filters
     *
     * @return array
     */
    protected static function normalize(array $filters): array
    {
        $normalized = [];

        foreach ($filters as $key => $value) {
            if ($value === null || $value === '' || $value === []) {
                continue;
            }

            $normalized[str_contains((string) $key, '_') ? lcfirst(studly((string) $key)) : (string) $key] = $value;
        }

        return $normalized;
    }

    /**
     * Splits a filter value into alternatives (`|`), each a list of terms
     * that must all hold (`,`) when `$and` is set.
     *
     * @param mixed $value The filter value; an array is a list of alternatives.
     * @param bool  $and   Whether `,` separates required terms.
     *
     * @return string[][]
     */
    protected static function alternatives(mixed $value, bool $and = false): array
    {
        $alternatives = [];

        foreach (is_array($value) ? $value : explode('|', self::scalar($value)) as $alternative) {
            $terms = $and ? explode(',', self::scalar($alternative)) : [self::scalar($alternative)];
            $terms = array_values(array_filter(array_map('trim', $terms), fn ($term) => $term !== ''));

            if ($terms) {
                $alternatives[] = $terms;
            }
        }

        return $alternatives;
    }

    /**
     * The first term of each alternative, for single-valued fields.
     *
     * @param mixed $value
     *
     * @return string[]
     */
    protected static function terms(mixed $value): array
    {
        return array_column(self::alternatives($value), 0);
    }

    /**
     * Converts a scalar filter value to a string.
     *
     * @param mixed $value
     *
     * @throws \InvalidArgumentException When it is not scalar.
     *
     * @return string
     */
    protected static function scalar(mixed $value): string
    {
        return match (true) {
            is_bool($value) => $value ? 'true' : 'false',
            is_scalar($value), $value instanceof \Stringable => (string) $value,
            default => throw new \InvalidArgumentException('Filter values must be strings, numbers or booleans.'),
        };
    }

    /**
     * `?, ?, ?` for a list of values.
     *
     * @param array $values
     *
     * @return string
     */
    protected static function placeholders(array $values): string
    {
        return implode(', ', array_fill(0, count($values), '?'));
    }

    /**
     * Combines conditions with `OR` (or `AND`).
     *
     * @param array<array{0: ?string, 1: array}> $conditions
     * @param string                             $operator   `OR` or `AND`.
     *
     * @return array{0: ?string, 1: array}
     */
    protected static function combine(array $conditions, string $operator = 'OR'): array
    {
        $sql = [];
        $bindings = [];

        foreach ($conditions as [$condition, $values]) {
            if ($condition !== null) {
                $sql[] = $condition;
                array_push($bindings, ...$values);
            }
        }

        return $sql ? ['('.implode(" {$operator} ", $sql).')', $bindings] : [null, []];
    }

    /**
     * Partial, case-insensitive text match against one or more columns; a
     * value in double quotes matches exactly.
     *
     * @param string[] $columns Qualified column names; a match on any counts.
     * @param mixed    $value
     *
     * @return array{0: ?string, 1: array}
     */
    protected static function like(array $columns, mixed $value): array
    {
        $conditions = [];

        foreach (self::terms($value) as $term) {
            $exact = strlen($term) > 1 && $term[0] === '"' && str_ends_with($term, '"');
            $term = $exact ? substr($term, 1, -1) : $term;

            $matches = [];
            foreach ($columns as $column) {
                $matches[] = $exact
                    ? ["{$column} = ? COLLATE NOCASE", [$term]]
                    : ["{$column} LIKE ? ESCAPE '\\'", ['%'.addcslashes($term, '%_\\').'%']];
            }
            $conditions[] = self::combine($matches);
        }

        return self::combine($conditions);
    }

    /**
     * Exact, case-insensitive match.
     *
     * @param string $column Qualified column name.
     * @param mixed  $value
     *
     * @return array{0: ?string, 1: array}
     */
    protected static function equals(string $column, mixed $value): array
    {
        $terms = self::terms($value);

        return $terms ? ["{$column} COLLATE NOCASE IN (".self::placeholders($terms).')', $terms] : [null, []];
    }

    /**
     * Exact match on a column stored in one case (set codes, UUIDs), so its
     * index still applies.
     *
     * @param string   $column    Qualified column name.
     * @param mixed    $value
     * @param callable $normalize Puts each term in the stored case, e.g. `strtoupper`.
     *
     * @return array{0: ?string, 1: array}
     */
    protected static function exact(string $column, mixed $value, callable $normalize): array
    {
        $terms = array_map($normalize, self::terms($value));

        return $terms ? ["{$column} IN (".self::placeholders($terms).')', $terms] : [null, []];
    }

    /**
     * Numeric match, with an optional comparison prefix per alternative.
     *
     * @param string $column Qualified column name.
     * @param mixed  $value  E.g. `3`, `gte3`, `>=3`, `2|5`.
     *
     * @throws \InvalidArgumentException When a term is not a number.
     *
     * @return array{0: ?string, 1: array}
     */
    protected static function compare(string $column, mixed $value): array
    {
        static $operators = ['gte' => '>=', 'lte' => '<=', 'gt' => '>', 'lt' => '<', '>=' => '>=', '<=' => '<=', '>' => '>', '<' => '<', '=' => '='];

        $conditions = [];

        foreach (self::terms($value) as $term) {
            $operator = '=';
            foreach ($operators as $prefix => $sql) {
                if (str_starts_with(strtolower($term), $prefix)) {
                    $operator = $sql;
                    $term = trim(substr($term, strlen($prefix)));
                    break;
                }
            }

            if (! is_numeric($term)) {
                throw new \InvalidArgumentException("\"{$term}\" is not a number.");
            }

            $conditions[] = ["{$column} {$operator} ?", [$term + 0]];
        }

        return self::combine($conditions);
    }

    /**
     * Matches a `", "`-joined list column: every term of an alternative must
     * be in the list.
     *
     * @param string        $column Qualified column name.
     * @param mixed         $value
     * @param callable|null $map    Maps each term before matching; returning `''` matches an empty list.
     *
     * @return array{0: ?string, 1: array}
     */
    protected static function contains(string $column, mixed $value, ?callable $map = null): array
    {
        $alternatives = [];

        foreach (self::alternatives($value, true) as $terms) {
            $all = [];
            foreach ($terms as $term) {
                $term = $map ? $map($term) : $term;
                $all[] = $term === ''
                    ? ["COALESCE({$column}, '') = ''", []]
                    : ["(', ' || {$column} || ', ') LIKE ? ESCAPE '\\'", ['%, '.addcslashes($term, '%_\\').', %']];
            }
            $alternatives[] = self::combine($all, 'AND');
        }

        return self::combine($alternatives);
    }

    /**
     * Matches a `BOOLEAN` column, where the build stores false as `0` or null.
     *
     * @param string $column Qualified column name.
     * @param mixed  $value  `true`/`false`, `1`/`0`, `yes`/`no`.
     *
     * @return array{0: string, 1: array}
     */
    protected static function flag(string $column, mixed $value): array
    {
        return [self::truthy($value) ? "{$column} = 1" : "COALESCE({$column}, 0) = 0", []];
    }

    /**
     * Requires a column to hold a value.
     *
     * @param string $column Qualified column name.
     *
     * @return array{0: string, 1: array}
     */
    protected static function present(string $column): array
    {
        return ["COALESCE({$column}, '') != ''", []];
    }

    /**
     * Applies `page` and `pageSize`.
     *
     * @param mixed $page     1-based page.
     * @param mixed $pageSize Rows per page, 1-100.
     *
     * @throws \InvalidArgumentException When either is out of range.
     */
    protected function paginate(mixed $page, mixed $pageSize): void
    {
        $page = filter_var($page, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $pageSize = filter_var($pageSize, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => self::MAX_PAGE_SIZE]]);

        if ($page === false) {
            throw new \InvalidArgumentException('page must be a whole number from 1.');
        }

        if ($pageSize === false) {
            throw new \InvalidArgumentException('pageSize must be a whole number from 1 to '.self::MAX_PAGE_SIZE.'.');
        }

        $this->limit = $pageSize;
        $this->offset = ($page - 1) * $pageSize;
    }

    /**
     * Parses an `orderBy` value: a field, optionally prefixed with `-` or
     * suffixed with ` desc`/` asc`.
     *
     * @param string $value
     *
     * @return array{0: string, 1: string} The field and `ASC`/`DESC`.
     */
    protected static function direction(string $value): array
    {
        $value = trim($value);

        if (str_starts_with($value, '-')) {
            return [substr($value, 1), 'DESC'];
        }

        if (preg_match('/^(\S+)\s+(asc|desc)$/i', $value, $matches)) {
            return [$matches[1], strtoupper($matches[2])];
        }

        return [$value, 'ASC'];
    }

    /**
     * Reads a boolean-ish filter value.
     *
     * @param mixed $value
     *
     * @return bool
     */
    protected static function truthy(mixed $value): bool
    {
        return is_bool($value) ? $value : filter_var(self::scalar($value), FILTER_VALIDATE_BOOLEAN);
    }
}
