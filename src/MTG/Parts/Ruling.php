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
 * One entry from a {@see Card}'s `rulings` — an official clarification,
 * with the date it was issued.
 *
 * @link https://mtgjson.com/data-models/rulings/ Rulings model
 *
 * @see \MTG\Parts\Card The parent object
 *
 * @property string $date The release date in ISO 8601 format for the rule.
 * @property string $text The text ruling of the card.
 *
 * @since 0.3.0
 */
class Ruling extends Part
{
    /**
     * @inheritDoc
     */
    protected $fillable = [
        'date',
        'text',
    ];
}
