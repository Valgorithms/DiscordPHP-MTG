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
 * One entry of a {@see Card}'s `legalities` — its status in one format.
 * MTGJSON keys legalities by format (`{"commander": "Legal"}`); each pair
 * becomes one of these. Formats where the card has no status are left out.
 *
 * @link https://mtgjson.com/data-models/legalities/ Legalities model
 *
 * @see \MTG\Parts\Card The parent object
 *
 * @property string $format   The format, as MTGJSON names it (`commander`, `paupercommander`, `standard`, …).
 * @property string $legality The card's status in the format (`Legal`, `Banned`, `Restricted`, `Not Legal`).
 *
 * @since 0.3.0
 */
class Legality extends Part
{
    /**
     * @inheritDoc
     */
    protected $fillable = [
        'format',
        'legality',
    ];
}
