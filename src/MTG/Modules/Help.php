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

use Discord\Builders\Components\Container;
use Discord\Builders\Components\Separator;
use Discord\Builders\Components\TextDisplay;
use Discord\Builders\MessageBuilder;
use Discord\Parts\Channel\Message\AllowedMentions;
use Discord\Parts\Interactions\Interaction;
use MTG\MTG;
use React\Promise\PromiseInterface;

/**
 * `/help` — a guide to every command, as one Components V2 message, only to
 * the person who asked. The guide is {@see SECTIONS}, so it is tested and
 * kept beside the commands it describes.
 *
 * @since 1.1.0
 */
final class Help implements Module
{
    use InteractionTrait;

    /**
     * The guide, in order: a title and its lines.
     *
     * @var list<array{title: string, lines: list<string>}>
     */
    public const SECTIONS = [
        [
            'title' => 'Cards',
            'lines' => [
                '`/card show <name> [set]` — a card: its art, rules text, today\'s price and its set. Buttons open its **rulings**, **legality**, **prices**, **foreign names**, full **image** and raw **JSON**; **Flip** turns a double-faced card; a picker switches to another printing; links go to Scryfall, EDHREC and stores.',
                '`/card search` — cards by any mix of name, type, rules text, colors, color identity (`UR`, `W,U,B`), mana value (`3`, `>=5`), rarity, set, format legality, keyword, artist, power and toughness. Pages of results; pick one to open it.',
                '`/card random [filters]` — a random card, with 🎲 **Another**.',
                '`/card prices <name> [set]` — today\'s prices from TCGplayer, Card Kingdom, Cardmarket, Mana Pool and Cardhoarder, retail and buylist.',
                '`/card_search` — the original search, still here.',
            ],
        ],
        [
            'title' => 'Sets, boosters and decks',
            'lines' => [
                '`/set show <set>` · `/set search [name] [type] [block] [year]` — a set\'s size, dates and boosters, with buttons to browse its cards, open a booster and list its decks.',
                '`/booster <set> [type]` — open a pack, with the real product\'s odds: play, draft, collector and more. **Open another**, **Images**, and the pack\'s value.',
                '`/deck show <deck>` · `/deck search [name] [set] [type]` — preconstructed decks with their cards, and **Export decklist** for MTG Arena or Moxfield.',
            ],
        ],
        [
            'title' => 'In chat',
            'lines' => [
                'Right-click a message → Apps → **Find cards** — looks up every `[[Card Name]]` in it (or `[[Card Name|SET]]`), or the whole message as one name.',
                'If the bot is set up to read messages, it also answers `[[Card Name]]` wherever you write it.',
            ],
        ],
        [
            'title' => 'Everything else',
            'lines' => [
                '`/help` — this guide. `/about` — where the data comes from and how fresh it is. `/invite` — add the bot to a server or to your account. `/ping` — gateway latency.',
                'Every command takes `hidden:true` to answer only you. Names, sets, keywords and formats autocomplete as you type.',
            ],
        ],
    ];

    /**
     * @inheritDoc
     */
    public function name(): string
    {
        return 'help';
    }

    /**
     * @inheritDoc
     */
    public function commands(MTG $mtg): array
    {
        return [self::command('help', 'What this bot can do.')];
    }

    /**
     * @inheritDoc
     */
    public function boot(MTG $mtg): void
    {
        $mtg->listenCommand('help', fn (Interaction $interaction) => $this->show($interaction));
    }

    /**
     * The guide as a message.
     *
     * @return MessageBuilder
     */
    public static function guide(): MessageBuilder
    {
        $container = Container::new()
            ->setAccentColor(0xD4AF37)
            ->addComponent(TextDisplay::new("## Magic: The Gathering\nCard data from MTGJSON, refreshed daily. Options in `[brackets]` are optional."));

        foreach (self::SECTIONS as $section) {
            $container->addComponent(Separator::new());
            $container->addComponent(TextDisplay::new("### {$section['title']}\n".implode("\n", array_map(fn (string $line) => "- {$line}", $section['lines']))));
        }

        return MessageBuilder::new()->setIsComponentsV2Flag()->setAllowedMentions(AllowedMentions::none())->addComponent($container);
    }

    /**
     * `/help`.
     *
     * @param Interaction $interaction
     *
     * @return PromiseInterface
     */
    private function show(Interaction $interaction): PromiseInterface
    {
        return $interaction->respondWithMessage(self::guide(), true);
    }
}
