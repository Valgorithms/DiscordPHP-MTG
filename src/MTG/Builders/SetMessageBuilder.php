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

namespace MTG\Builders;

use Discord\Builders\Components\ActionRow;
use Discord\Builders\Components\Button;
use Discord\Builders\Components\Container;
use Discord\Builders\Components\Separator;
use Discord\Builders\Components\TextDisplay;
use Discord\Builders\MessageBuilder;
use Discord\Parts\Channel\Message\AllowedMentions;
use MTG\Helpers\Links;
use MTG\Helpers\Text;
use MTG\Parts\Set;

/**
 * A set as a Components V2 message: what it is, when it came out, how big it
 * is, what boosters it has, with buttons to browse its cards, open a booster
 * and list its decks.
 *
 * Custom ids: `set:cards:<code>` (Sets module), `pack:open:<code>:` (Boosters
 * module, default booster) and `deck:set:<code>` (Decks module).
 *
 * @since 1.1.0
 */
class SetMessageBuilder extends MessageBuilder
{
    /**
     * The custom id prefix of set components.
     *
     * @var string
     */
    public const PREFIX = 'set';

    /**
     * The accent color of set messages.
     *
     * @var int
     */
    public const ACCENT = 0x7A6FB0;

    /**
     * The set view.
     *
     * @param Set      $set
     * @param string[] $boosters The set's booster types, preferred first.
     * @param int      $decks    How many preconstructed decks MTGJSON has for it.
     *
     * @return static
     */
    public static function set(Set $set, array $boosters = [], int $decks = 0): static
    {
        $code = (string) $set->code;
        $facts = [ucwords(str_replace('_', ' ', (string) $set->type))];
        if ($set->releaseDate) {
            $facts[] = 'released '.$set->releaseDate->format('j F Y');
        }
        if ($set->block) {
            $facts[] = "{$set->block} block";
        }

        $lines = [];
        if ($set->baseSetSize) {
            $lines[] = '**Cards:** '.number_format((int) $set->baseSetSize).($set->totalSetSize && $set->totalSetSize !== $set->baseSetSize ? ' ('.number_format((int) $set->totalSetSize).' with extras)' : '');
        }
        if ($set->parentCode) {
            $lines[] = "**Part of:** `{$set->parentCode}`";
        }
        if ($boosters) {
            $lines[] = '**Boosters:** '.implode(', ', array_map(fn ($type) => "`{$type}`", $boosters));
        }
        if ($languages = (array) ($set->languages ?? [])) {
            $lines[] = '**Languages:** '.Text::clip(implode(', ', $languages), 300);
        }
        $flags = array_keys(array_filter([
            'Online only' => $set->isOnlineOnly,
            'Paper only' => $set->isPaperOnly,
            'Foil only' => $set->isFoilOnly,
            'Outside the US only' => $set->isForeignOnly,
            'Still being previewed' => $set->isPartialPreview,
        ]));
        if ($flags) {
            $lines[] = '-# '.implode(' · ', $flags);
        }

        $container = Container::new()
            ->setAccentColor(self::ACCENT)
            ->addComponent(TextDisplay::new("### {$set->name} ({$code})\n".implode(' · ', $facts)));
        if ($lines) {
            $container->addComponent(Separator::new())->addComponent(TextDisplay::new(implode("\n", $lines)));
        }

        $buttons = ActionRow::new()->addComponent(Button::new(Button::STYLE_PRIMARY, self::PREFIX.":cards:{$code}")->setLabel('Browse cards'));
        if ($boosters) {
            $buttons->addComponent(Button::new(Button::STYLE_SECONDARY, "pack:open:{$code}:")->setLabel('Open a booster'));
        }
        if ($decks > 0) {
            $buttons->addComponent(Button::new(Button::STYLE_SECONDARY, "deck:set:{$code}")->setLabel("Decks ({$decks})"));
        }
        $buttons->addComponent(Button::link(Links::scryfallSet($code))->setLabel('Scryfall'));

        return static::new()
            ->setIsComponentsV2Flag()
            ->setAllowedMentions(AllowedMentions::none())
            ->addComponent($container)
            ->addComponent($buttons);
    }

    /**
     * A set as one line of a list.
     *
     * @param array $set A decoded `sets` row.
     *
     * @return string
     */
    public static function line(array $set): string
    {
        $year = isset($set['releaseDate']) ? substr((string) $set['releaseDate'], 0, 4) : '';

        return "**{$set['name']}** `{$set['code']}` · ".ucwords(str_replace('_', ' ', (string) ($set['type'] ?? '')))." · {$year}";
    }
}
