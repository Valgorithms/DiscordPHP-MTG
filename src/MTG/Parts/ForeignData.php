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

use Discord\Parts\Part;

/**
 * One entry from a {@see Card}'s `foreignData` — the card as printed in
 * another language. Not available for all sets.
 *
 * @link https://mtgjson.com/data-models/foreign-data/ Foreign Data model
 *
 * @see \MTG\Parts\Card The parent object
 *
 * @property string|null $faceName     The foreign name on the face of the card.
 * @property string|null $flavorText   The foreign flavor text of the card.
 * @property array|null  $identifiers  The foreign printing's identifiers (`multiverseId`, `scryfallId`).
 * @property string|null $language     The foreign language of the card.
 * @property int|null    $multiverseId The multiverse identifier of the card. Deprecated by MTGJSON; use `identifiers`.
 * @property string|null $name         The foreign name of the card.
 * @property array|null  $skuIds       TCGplayer SKU identifiers of the foreign printing, by finish.
 * @property string|null $text         The foreign text ability of the card.
 * @property string|null $type         The foreign type of the card. Includes any supertypes and subtypes.
 * @property string|null $uuid         The foreign printing's own UUID (absent in the SQLite build).
 *
 * @since 1.0.0
 */
class ForeignData extends Part
{
    /**
     * @inheritDoc
     */
    protected $fillable = [
        'faceName',
        'flavorText',
        'identifiers',
        'language',
        'multiverseId',
        'name',
        'skuIds',
        'text',
        'type',
        'uuid',
    ];
}
