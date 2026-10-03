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
 * A {@see Card}'s `leadershipSkills` — the formats it can be your
 * commander in.
 *
 * @link https://mtgjson.com/data-models/leadership-skills/ Leadership Skills model
 *
 * @see \MTG\Parts\Card The parent object
 *
 * @property bool|null $brawl            If the card can be your commander in the Brawl format.
 * @property bool|null $commander        If the card can be your commander in the Commander/EDH format.
 * @property bool|null $oathbreaker      If the card can be your commander in the Oathbreaker format.
 * @property bool|null $pauper_commander If the card can be your commander in the Pauper Commander format.
 * @property bool|null $predh            If the card can be your commander in the PreDH format.
 *
 * @since 1.0.0
 */
class LeadershipSkills extends Part
{
    /**
     * @inheritDoc
     */
    protected $fillable = [
        'brawl',
        'commander',
        'oathbreaker',
        'pauper_commander',
        'predh',
    ];
}
