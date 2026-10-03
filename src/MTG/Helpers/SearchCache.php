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

namespace MTG\Helpers;

/**
 * Short-lived state behind a message's components — a search's filters, an
 * opened booster's cards — under an id short enough for a `custom_id`.
 *
 * A component's `custom_id` holds at most 100 characters, too few for a set of
 * search filters, so a results page carries `card:page:<id>:<page>` and the
 * filters wait here. Entries expire after {@see TTL} and the oldest go first
 * past {@see LIMIT}; a click on an expired one is told to search again. Kept
 * in memory: a restart forgets them, as it would a conversation.
 *
 * @since 1.1.0
 */
final class SearchCache
{
    /**
     * Seconds an entry is kept after it was last used.
     *
     * @var int
     */
    public const TTL = 3600;

    /**
     * Entries kept at most.
     *
     * @var int
     */
    public const LIMIT = 1000;

    /**
     * Entries by id, least recently used first.
     *
     * @var array<string, array{0: int, 1: array}>
     */
    private array $entries = [];

    /**
     * Stores state and returns its id.
     *
     * @param array $state Anything serializable.
     *
     * @return string An 8-character id.
     */
    public function put(array $state): string
    {
        $this->prune();

        do {
            $id = bin2hex(random_bytes(4));
        } while (isset($this->entries[$id]));

        $this->entries[$id] = [time(), $state];

        return $id;
    }

    /**
     * Gets state back, renewing it.
     *
     * @param string $id
     *
     * @return array|null Null once it has expired.
     */
    public function get(string $id): ?array
    {
        if (! isset($this->entries[$id]) || time() - $this->entries[$id][0] > self::TTL) {
            unset($this->entries[$id]);

            return null;
        }

        $state = $this->entries[$id][1];
        unset($this->entries[$id]);
        $this->entries[$id] = [time(), $state];

        return $state;
    }

    /**
     * Drops expired entries, then the oldest past the limit.
     */
    private function prune(): void
    {
        $now = time();

        foreach ($this->entries as $id => [$used]) {
            if ($now - $used <= self::TTL && count($this->entries) < self::LIMIT) {
                break;
            }

            unset($this->entries[$id]);
        }
    }
}
