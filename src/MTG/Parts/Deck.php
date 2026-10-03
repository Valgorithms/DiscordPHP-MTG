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

namespace MTG\Parts;

use Carbon\Carbon;
use Discord\Helpers\ExCollectionInterface;
use Discord\Parts\Part;

/**
 * A preconstructed deck — MTGJSON's Deck model, or a Deck List entry (no
 * cards) when it comes from {@see \MTG\Repository\DeckRepository::getDecks()}.
 * Cards in a deck are {@see Card}s with `count` (and `isFoil`/`isEtched`).
 *
 * @link https://mtgjson.com/data-models/deck/ Deck model
 * @link https://mtgjson.com/data-models/deck-list/ Deck List model
 *
 * @property      string|null                 $code               The printing set code for the deck.
 * @property      string|null                 $fileName           The file name for the deck: `decks/{fileName}.json`. Deck List only.
 * @property      string|null                 $name               The name of the deck.
 * @property      string[]|null               $sealedProductUuids The sealed product UUIDs the deck comes in.
 * @property      string[]|null               $sourceSetCodes     The set codes the deck's cards come from.
 * @property      string|null                 $type               The type of deck (`Commander Deck`, `Intro Pack`, `Theme Deck`, …).
 * @property-read ExCollectionInterface<Card> $commander          The commander(s) of the deck.
 * @property-read ExCollectionInterface<Card> $displayCommander   The card(s) displayed on the front of the deck box.
 * @property-read ExCollectionInterface<Card> $mainBoard          The cards in the main board.
 * @property-read ExCollectionInterface<Card> $planes             The planes, for Planechase decks.
 * @property-read Carbon|null                 $releaseDate        The release date of the deck.
 * @property-read ExCollectionInterface<Card> $schemes            The schemes, for Archenemy decks.
 * @property-read ExCollectionInterface<Card> $sideBoard          The cards in the side board.
 * @property-read ExCollectionInterface<Card> $tokens             The tokens the deck comes with.
 *
 * @since 1.0.0
 */
class Deck extends Part
{
    /**
     * @inheritDoc
     */
    protected $fillable = [
        'code',
        'commander',
        'displayCommander',
        'fileName',
        'mainBoard',
        'name',
        'planes',
        'releaseDate',
        'schemes',
        'sealedProductUuids',
        'sideBoard',
        'sourceSetCodes',
        'tokens',
        'type',
    ];

    /**
     * Whether the deck carries its cards (a Deck), or only its summary (a
     * Deck List entry).
     *
     * @return bool
     */
    public function hasCards(): bool
    {
        return array_key_exists('mainBoard', $this->attributes);
    }

    /**
     * Gets the release date of the deck.
     *
     * @return Carbon|null
     */
    protected function getReleaseDateAttribute(): ?Carbon
    {
        return isset($this->attributes['releaseDate']) ? Carbon::parse($this->attributes['releaseDate']) : null;
    }

    /**
     * @return ExCollectionInterface<Card>
     */
    protected function getCommanderAttribute(): ExCollectionInterface
    {
        return $this->cards('commander');
    }

    /**
     * @return ExCollectionInterface<Card>
     */
    protected function getDisplayCommanderAttribute(): ExCollectionInterface
    {
        return $this->cards('displayCommander');
    }

    /**
     * @return ExCollectionInterface<Card>
     */
    protected function getMainBoardAttribute(): ExCollectionInterface
    {
        return $this->cards('mainBoard');
    }

    /**
     * @return ExCollectionInterface<Card>
     */
    protected function getPlanesAttribute(): ExCollectionInterface
    {
        return $this->cards('planes');
    }

    /**
     * @return ExCollectionInterface<Card>
     */
    protected function getSchemesAttribute(): ExCollectionInterface
    {
        return $this->cards('schemes');
    }

    /**
     * @return ExCollectionInterface<Card>
     */
    protected function getSideBoardAttribute(): ExCollectionInterface
    {
        return $this->cards('sideBoard');
    }

    /**
     * @return ExCollectionInterface<Card>
     */
    protected function getTokensAttribute(): ExCollectionInterface
    {
        return $this->cards('tokens');
    }

    /**
     * Builds one of the deck's card lists. Not keyed by uuid: a deck can
     * hold the same printing as foil and non-foil.
     *
     * @param string $key The attribute.
     *
     * @return ExCollectionInterface<Card>
     */
    protected function cards(string $key): ExCollectionInterface
    {
        $collection = ($this->discord->getCollectionClass())::for(Card::class, null);

        foreach ((array) ($this->attributes[$key] ?? []) as $card) {
            $collection->pushItem($card instanceof Card ? $card : $this->factory->part(Card::class, (array) $card, true));
        }

        return $collection;
    }
}
