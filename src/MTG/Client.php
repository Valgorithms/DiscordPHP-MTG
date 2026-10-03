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

namespace MTG;

use Discord\Parts\User\Client as DiscordClient;
use Discord\Repository\EmojiRepository;
use Discord\Repository\GuildRepository;
use Discord\Repository\PrivateChannelRepository;
use Discord\Repository\SoundRepository;
use Discord\Repository\StickerPackRepository;
use Discord\Repository\UserRepository;
use MTG\Repository\CardRepository;
use MTG\Repository\DeckRepository;
use MTG\Repository\SetRepository;

/**
 * The DiscordPHP {@see \Discord\Parts\User\Client} extended so the MTG
 * repositories ({@see CardRepository}, {@see SetRepository},
 * {@see DeckRepository}) hang off the same client the rest of the bot uses.
 * Held by {@see MTG} as `$client`.
 *
 * @see \Discord\Parts\User\Client The DiscordPHP client this extends
 *
 * @since 0.1.0
 */
class Client extends DiscordClient
{
    /**
     * @inheritDoc
     */
    protected $repositories = [
        'emojis' => EmojiRepository::class,
        'guilds' => GuildRepository::class,
        'private_channels' => PrivateChannelRepository::class,
        'sounds' => SoundRepository::class,
        'sticker_packs' => StickerPackRepository::class,
        'users' => UserRepository::class,
        'cards' => CardRepository::class,
        'sets' => SetRepository::class,
        'decks' => DeckRepository::class,
    ];
}
