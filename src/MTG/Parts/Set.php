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
use Discord\Builders\Components\Container;
use Discord\Builders\Components\TextDisplay;
use Discord\Parts\Part;

/**
 * A Magic: The Gathering set — MTGJSON's Set model, without the card, token,
 * deck, booster and sealed product lists (search cards by `setCode`, and
 * open boosters with {@see \MTG\Repository\SetRepository::generateBooster()}).
 *
 * @link https://mtgjson.com/data-models/set/ Set model
 * @link https://mtgjson.com/data-models/set-list/ Set List model
 *
 * @property      int|null      $baseSetSize      The number of cards in the set, excluding promotional or special cards.
 * @property      string|null   $block            The block name the set was in.
 * @property      string|null   $code             The set code for the set.
 * @property      bool|null     $isForeignOnly    If the set is available only outside the United States of America.
 * @property      bool|null     $isFoilOnly       If the set is only available in foil.
 * @property      bool|null     $isNonFoilOnly    If the set is only available in non-foil.
 * @property      bool|null     $isOnlineOnly     If the set is only available in online game variations.
 * @property      bool|null     $isPaperOnly      If the set is available only in paper.
 * @property      bool|null     $isPartialPreview If the set is still in preview (spoiled). Preview sets do not have complete data.
 * @property      string|null   $keyruneCode      The matching Keyrune code for set image icons.
 * @property      string[]|null $languages        The languages the set was printed in.
 * @property      int|null      $mcmId            The Magic Card Market set identifier.
 * @property      int|null      $mcmIdExtras      The split Magic Card Market set identifier if a set is printed in two sets.
 * @property      string|null   $mcmName          The Magic Card Market set name.
 * @property      string|null   $mtgoCode         The set code for the set as it appears on Magic: The Gathering Online.
 * @property      string|null   $name             The name of the set.
 * @property      string|null   $parentCode       The parent set code for set variations like promotions, guild kits, etc.
 * @property      int|null      $tcgplayerGroupId The group identifier of the set on TCGplayer.
 * @property      string|null   $tokenSetCode     The tokens set code, formatted in uppercase.
 * @property      int|null      $totalSetSize     The total number of cards in the set, including promotional and related supplemental products.
 * @property      array|null    $translations     The translated set name by language.
 * @property      string|null   $type             The expansion type of the set (`core`, `expansion`, `masters`, `commander`, `promo`, …).
 * @property-read Carbon|null   $releaseDate      The release date of the set.
 *
 * @since 0.5.0
 */
class Set extends Part
{
    /**
     * @inheritDoc
     */
    protected $fillable = [
        'baseSetSize',
        'block',
        'code',
        'isForeignOnly',
        'isFoilOnly',
        'isNonFoilOnly',
        'isOnlineOnly',
        'isPaperOnly',
        'isPartialPreview',
        'keyruneCode',
        'languages',
        'mcmId',
        'mcmIdExtras',
        'mcmName',
        'mtgoCode',
        'name',
        'parentCode',
        'releaseDate',
        'tcgplayerGroupId',
        'tokenSetCode',
        'totalSetSize',
        'translations',
        'type',
    ];

    /**
     * Gets the release date of the set.
     *
     * @return Carbon|null
     *
     * @since 0.5.0
     */
    protected function getReleaseDateAttribute(): ?Carbon
    {
        return isset($this->attributes['releaseDate']) ? Carbon::parse($this->attributes['releaseDate']) : null;
    }

    /**
     * Converts the set to a container with components.
     *
     * @return Container|null
     *
     * @since 0.5.0
     */
    public function toContainer(): ?Container
    {
        if (! isset($this->attributes['name'])) {
            return null;
        }

        $components = [
            TextDisplay::new('Code: '.$this->attributes['code']),
            TextDisplay::new('Name: '.$this->attributes['name']),
        ];

        if (isset($this->attributes['type'])) {
            $components[] = TextDisplay::new('Type: '.ucwords(str_replace('_', ' ', $this->attributes['type'])));
        }

        if (isset($this->attributes['block'])) {
            $components[] = TextDisplay::new('Block: '.$this->attributes['block']);
        }

        if (isset($this->attributes['releaseDate'])) {
            $components[] = TextDisplay::new('Release Date: '.$this->attributes['releaseDate']);
        }

        if (isset($this->attributes['baseSetSize'])) {
            $components[] = TextDisplay::new('Cards: '.$this->attributes['baseSetSize'].(isset($this->attributes['totalSetSize']) ? " ({$this->attributes['totalSetSize']} with extras)" : ''));
        }

        $components[] = TextDisplay::new('Online Only: '.(! empty($this->attributes['isOnlineOnly']) ? 'Yes' : 'No'));

        return Container::new()->addComponents($components);
    }
}
