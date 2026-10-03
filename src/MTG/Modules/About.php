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

use Discord\Builders\Components\ActionRow;
use Discord\Builders\Components\Button;
use Discord\Builders\Components\Container;
use Discord\Builders\Components\Separator;
use Discord\Builders\Components\TextDisplay;
use Discord\Builders\MessageBuilder;
use Discord\Discord;
use Discord\Parts\Channel\Message\AllowedMentions;
use Discord\Parts\Interactions\Interaction;
use MTG\Helpers\Text;
use MTG\MTG;
use React\Promise\PromiseInterface;

/**
 * `/about` (where the data comes from, how fresh it is, how the bot is
 * doing), `/invite` (add the bot to a server or to your account) and `/ping`.
 *
 * @since 1.1.0
 */
final class About implements Module
{
    use InteractionTrait;

    /**
     * What the bot needs in a server: View Channel, Send Messages, Embed
     * Links, Attach Files, Read Message History, Use External Emojis.
     *
     * @var int
     */
    public const PERMISSIONS = 1024 | 2048 | 16384 | 32768 | 65536 | 262144;

    /**
     * The last gateway round trip, in milliseconds.
     *
     * @var float|null
     */
    private ?float $latency = null;

    /**
     * @inheritDoc
     */
    public function name(): string
    {
        return 'about';
    }

    /**
     * @inheritDoc
     */
    public function commands(MTG $mtg): array
    {
        return [
            self::command('about', 'Where the card data comes from, and how the bot is doing.'),
            self::command('invite', 'Add the bot to a server or to your account.'),
            self::command('ping', 'Check the bot\'s gateway latency.'),
        ];
    }

    /**
     * @inheritDoc
     */
    public function boot(MTG $mtg): void
    {
        $mtg->on('heartbeat-ack', function ($time): void {
            $this->latency = (float) $time;
        });

        $mtg->listenCommand('about', fn (Interaction $i) => self::reply($mtg, $i, true, fn () => $this->about($mtg)));
        $mtg->listenCommand('invite', fn (Interaction $i) => $i->respondWithMessage(self::invite($mtg), true));
        $mtg->listenCommand('ping', fn (Interaction $i) => $i->respondWithMessage(
            MessageBuilder::new()->setContent($this->latency === null ? '🏓 Pong (no heartbeat yet).' : '🏓 Pong — gateway round trip about '.(int) round($this->latency).' ms.'),
            true
        ));
    }

    /**
     * `/about`.
     *
     * @param MTG $mtg
     *
     * @return PromiseInterface<MessageBuilder>
     */
    private function about(MTG $mtg): PromiseInterface
    {
        return $mtg->getDatabase()->ready()->then(function () use ($mtg) {
            $database = $mtg->getDatabase();
            $counts = $database->select('SELECT (SELECT COUNT(DISTINCT "name") FROM "cards") AS "cards", (SELECT COUNT(*) FROM "cards") AS "printings", (SELECT COUNT(*) FROM "sets") AS "sets"')[0];
            $prices = $mtg->getPriceDatabase();

            $data = [
                '**Cards:** '.number_format((int) $counts['cards']).' ('.number_format((int) $counts['printings']).' printings) in '.number_format((int) $counts['sets']).' sets',
                '**MTGJSON build:** '.($database->getVersion() ?? 'unknown').' ('.($database->getDate() ?? '?').')',
                '**Prices:** '.match (true) {
                    $prices === null => 'off',
                    $prices->isOpen() => 'from '.($prices->getDate() ?? '?'),
                    default => 'not available yet',
                },
            ];

            $bot = [
                '**Servers:** '.number_format(count($mtg->guilds)),
                '**Up for:** '.self::duration($mtg->getUptime()),
                '**Memory:** '.round(memory_get_usage(true) / 1048576).' MB',
                '**Running:** PHP '.PHP_VERSION.' · DiscordPHP '.Discord::VERSION,
            ];

            return MessageBuilder::new()
                ->setIsComponentsV2Flag()
                ->setAllowedMentions(AllowedMentions::none())
                ->addComponent(Container::new()
                    ->setAccentColor(0xD4AF37)
                    ->addComponent(TextDisplay::new("## DiscordPHP-MTG\nMagic: The Gathering cards, sets, boosters and decks, with data from [MTGJSON](https://mtgjson.com) and images from [Scryfall](https://scryfall.com)."))
                    ->addComponent(Separator::new())
                    ->addComponent(TextDisplay::new(implode("\n", $data)))
                    ->addComponent(Separator::new())
                    ->addComponent(TextDisplay::new(implode("\n", $bot)))
                    ->addComponent(TextDisplay::new('-# Magic: The Gathering is © Wizards of the Coast. This bot is not affiliated with Wizards of the Coast.')))
                ->addComponent(ActionRow::new()
                    ->addComponent(Button::link(MTG::GITHUB)->setLabel('GitHub'))
                    ->addComponent(Button::link('https://mtgjson.com')->setLabel('MTGJSON'))
                    ->addComponent(Button::link(self::serverInvite($mtg))->setLabel('Add to a server')));
        });
    }

    /**
     * `/invite`: links to add the bot to a server, or to your account to use
     * it anywhere.
     *
     * @param MTG $mtg
     *
     * @return MessageBuilder
     */
    public static function invite(MTG $mtg): MessageBuilder
    {
        return MessageBuilder::new()
            ->setIsComponentsV2Flag()
            ->setAllowedMentions(AllowedMentions::none())
            ->addComponent(Container::new()
                ->setAccentColor(0xD4AF37)
                ->addComponent(TextDisplay::new("### Add the bot\n**To a server** — everyone there can use it, and it can answer `[[Card Name]]` if it reads messages.\n**To your account** — use its commands anywhere, even where it isn't a member.")))
            ->addComponent(ActionRow::new()
                ->addComponent(Button::link(self::serverInvite($mtg))->setLabel('Add to a server'))
                ->addComponent(Button::link(self::userInvite($mtg))->setLabel('Add to your account')));
    }

    /**
     * The server install link.
     *
     * @param MTG $mtg
     *
     * @return string
     */
    public static function serverInvite(MTG $mtg): string
    {
        return 'https://discord.com/oauth2/authorize?client_id='.self::clientId($mtg).'&scope=bot+applications.commands&permissions='.self::PERMISSIONS;
    }

    /**
     * The user install link.
     *
     * @param MTG $mtg
     *
     * @return string
     */
    public static function userInvite(MTG $mtg): string
    {
        return 'https://discord.com/oauth2/authorize?client_id='.self::clientId($mtg).'&integration_type=1&scope=applications.commands';
    }

    /**
     * "3 days, 4 hours" — the two largest units.
     *
     * @param int $seconds
     *
     * @return string
     */
    public static function duration(int $seconds): string
    {
        $units = ['day' => 86400, 'hour' => 3600, 'minute' => 60, 'second' => 1];
        $parts = [];

        foreach ($units as $unit => $size) {
            if ($seconds >= $size || ($unit === 'second' && $parts === [])) {
                $parts[] = Text::plural(intdiv($seconds, $size), $unit);
                $seconds %= $size;
            }
            if (count($parts) === 2) {
                break;
            }
        }

        return implode(', ', $parts);
    }

    /**
     * The application's id.
     *
     * @param MTG $mtg
     *
     * @return string
     */
    private static function clientId(MTG $mtg): string
    {
        return (string) ($mtg->application->id ?? $mtg->id);
    }
}
