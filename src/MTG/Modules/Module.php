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

namespace MTG\Modules;

use Discord\Builders\CommandBuilder;
use MTG\MTG;

/**
 * A self-contained bot feature, as in Tutelar: it declares its own
 * application commands ({@see commands()}, which the client registers or
 * updates), listens for them, and routes clicks on the components of the
 * messages it sends, from {@see boot()}.
 *
 * Component custom ids are stable `prefix:action:args` strings handled by one
 * `INTERACTION_CREATE` listener per module, so the buttons on a message keep
 * working after a restart and never stack listeners.
 *
 * @since 1.1.0
 */
interface Module
{
    /**
     * Stable short name, for logs.
     *
     * @return string
     */
    public function name(): string;

    /**
     * The application commands the module answers, as they should be
     * registered. The client creates missing ones and updates changed ones.
     *
     * @param MTG $mtg
     *
     * @return CommandBuilder[]
     */
    public function commands(MTG $mtg): array;

    /**
     * Registers commands and listeners. Called once, after the gateway and
     * the application are ready.
     *
     * @param MTG $mtg
     */
    public function boot(MTG $mtg): void;
}
