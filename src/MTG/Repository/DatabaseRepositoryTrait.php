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

use Discord\Parts\Part;
use MTG\Database\Database;
use React\Promise\PromiseInterface;

use function React\Promise\reject;
use function React\Promise\resolve;

/**
 * `fetch()`, `fresh()` and `freshen()` for repositories answered from the
 * local MTGJSON build instead of an HTTP endpoint. MTGJSON serves cards and
 * sets only inside whole files, so there is no per-item route to call.
 *
 * @property Database $database The local MTGJSON build.
 *
 * @see CardRepository
 * @see SetRepository
 *
 * @since 1.0.0
 */
trait DatabaseRepositoryTrait
{
    /**
     * Looks one part up in the open build.
     *
     * @param string $id The discriminator value.
     *
     * @return Part|null Null when the build has no such item.
     */
    abstract protected function lookup(string $id): ?Part;

    /**
     * Gets a part from the repository or the MTGJSON build.
     *
     * @param string $id    The discriminator value to search for.
     * @param bool   $fresh Whether to skip the cache.
     *
     * @return PromiseInterface<Part> Rejects with \OutOfBoundsException when the build has no such item.
     */
    public function fetch(string $id, bool $fresh = false): PromiseInterface
    {
        if (! $fresh) {
            if ($part = $this->offsetGet($id)) {
                return resolve($part);
            }

            return $this->cache->get($id)->then(fn ($part) => $part ?? $this->fetch($id, true));
        }

        return $this->database->ready()->then(function () use ($id) {
            if (! $part = $this->lookup($id)) {
                throw new \OutOfBoundsException("The MTGJSON build has no {$this->discrim} \"{$id}\".");
            }

            return $this->cache->set($id, $part)->then(fn () => $part);
        });
    }

    /**
     * Refills a part from the MTGJSON build.
     *
     * @param Part  $part        The part to refresh.
     * @param array $queryparams Unused; kept for the upstream signature.
     *
     * @return PromiseInterface<Part>
     */
    public function fresh(Part $part, array $queryparams = []): PromiseInterface
    {
        return $this->fetch((string) $part->{$this->discrim}, true)->then(function (Part $fresh) use ($part) {
            $part->fill($fresh->getRawAttributes());

            return $part;
        });
    }

    /**
     * Rejects: the repository is too large to load whole; search it instead.
     *
     * @param array $queryparams Unused.
     *
     * @return PromiseInterface Rejects with \BadMethodCallException.
     */
    public function freshen(array $queryparams = []): PromiseInterface
    {
        return reject(new \BadMethodCallException(static::class.' cannot be freshened; search it instead.'));
    }
}
