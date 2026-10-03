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
use Discord\Builders\Components\Option;
use Discord\Builders\Components\Separator;
use Discord\Builders\Components\StringSelect;
use Discord\Builders\Components\TextDisplay;
use Discord\Builders\MessageBuilder;
use Discord\Parts\Channel\Message\AllowedMentions;
use MTG\Helpers\Text;
use MTG\Parts\Card;
use MTG\Parts\Deck;

/**
 * A preconstructed deck as a Components V2 message: its commander, the main
 * board grouped by card type with counts, the sideboard, a picker to look at
 * a card, and an Export button for a decklist that MTG Arena and most deck
 * sites import.
 *
 * Custom ids (Decks module): `deck:export:<fileName>` and `deck:card` (the
 * picker, valued with a uuid).
 *
 * @since 1.1.0
 */
class DeckMessageBuilder extends MessageBuilder
{
    /**
     * The custom id prefix of deck components.
     *
     * @var string
     */
    public const PREFIX = 'deck';

    /**
     * The accent color of deck messages.
     *
     * @var int
     */
    public const ACCENT = 0x3C8D93;

    /**
     * Card types in the order a decklist groups them; a card goes under the
     * first it has.
     *
     * @var string[]
     */
    public const GROUPS = ['Creature', 'Planeswalker', 'Battle', 'Instant', 'Sorcery', 'Artifact', 'Enchantment', 'Land'];

    /**
     * The deck view.
     *
     * @param Deck $deck A deck with its cards.
     *
     * @return static
     */
    public static function deck(Deck $deck): static
    {
        $main = iterator_to_array($deck->mainBoard);
        $side = iterator_to_array($deck->sideBoard);
        $commanders = iterator_to_array($deck->commander);

        $facts = array_filter([(string) $deck->type, $deck->code ? "`{$deck->code}`" : null, $deck->releaseDate?->format('j F Y')]);
        $count = self::count($main) + self::count($commanders);

        $container = Container::new()
            ->setAccentColor(self::ACCENT)
            ->addComponent(TextDisplay::new("### {$deck->name}\n".implode(' · ', $facts)."\n-# ".Text::plural($count, 'card').($side ? ' · sideboard '.self::count($side) : '')));

        if ($commanders) {
            $container->addComponent(TextDisplay::new('**Commander:** '.implode(', ', array_map(fn (Card $card) => $card->name, $commanders))));
        }

        $container->addComponent(Separator::new());

        $budget = 3200;
        $blocks = [];
        foreach (self::groups($main) as $group => $cards) {
            $blocks[] = "**{$group}** (".self::count($cards).")\n".implode("\n", array_map(fn (Card $card) => ($card->count ?? 1).' '.$card->name, $cards));
        }
        if ($side) {
            $blocks[] = '**Sideboard** ('.self::count($side).")\n".implode("\n", array_map(fn (Card $card) => ($card->count ?? 1).' '.$card->name, $side));
        }

        $text = '';
        foreach ($blocks as $block) {
            if (mb_strlen($text."\n\n".$block) > $budget) {
                $text .= "\n\n-# …the rest is in the export.";
                break;
            }
            $text .= ($text === '' ? '' : "\n\n").$block;
        }
        $container->addComponent(TextDisplay::new($text !== '' ? $text : '_No cards listed._'));

        $message = static::new()->setIsComponentsV2Flag()->setAllowedMentions(AllowedMentions::none())->addComponent($container);

        $picks = [];
        foreach ([...$commanders, ...$main] as $card) {
            $picks[(string) $card->uuid] ??= $card;
        }
        if ($picks) {
            $select = StringSelect::new(self::PREFIX.':card')->setPlaceholder('Look at a card');
            foreach (array_slice($picks, 0, 25) as $uuid => $card) {
                $select->addOption(Option::new(Text::clip((string) $card->name, 100), $uuid)->setDescription(Text::clip((string) $card->type, 100)));
            }
            $message->addComponent(ActionRow::new()->addComponent($select));
        }

        return $message->addComponent(ActionRow::new()->addComponent(
            Button::new(Button::STYLE_SECONDARY, self::PREFIX.':export:'.$deck->fileName)->setLabel('Export decklist')
        ));
    }

    /**
     * The decklist as text, in the `count Name (SET) number` form MTG Arena
     * and most deck sites import.
     *
     * @param Deck $deck
     *
     * @return string
     */
    public static function export(Deck $deck): string
    {
        $sections = [
            'Commander' => iterator_to_array($deck->commander),
            'Deck' => iterator_to_array($deck->mainBoard),
            'Sideboard' => iterator_to_array($deck->sideBoard),
        ];

        $text = [];
        foreach ($sections as $title => $cards) {
            if (! $cards) {
                continue;
            }
            $text[] = $title;
            foreach ($cards as $card) {
                $text[] = ($card->count ?? 1).' '.$card->name.($card->setCode ? " ({$card->setCode})".($card->number ? " {$card->number}" : '') : '');
            }
            $text[] = '';
        }

        return implode("\n", $text);
    }

    /**
     * A deck as one line of a list.
     *
     * @param Deck $deck A Deck List entry.
     *
     * @return string
     */
    public static function line(Deck $deck): string
    {
        return "**{$deck->name}** · {$deck->type} · `{$deck->code}` · ".($deck->releaseDate?->format('Y') ?? '');
    }

    /**
     * Cards grouped by their first type in {@see GROUPS} order.
     *
     * @param Card[] $cards
     *
     * @return array<string, Card[]>
     */
    public static function groups(array $cards): array
    {
        $groups = [];

        foreach ($cards as $card) {
            $types = (array) ($card->types ?? []);
            $group = 'Other';
            foreach (self::GROUPS as $type) {
                if (in_array($type, $types, true)) {
                    $group = $type;
                    break;
                }
            }
            $groups[$group][] = $card;
        }

        $order = array_flip([...self::GROUPS, 'Other']);
        uksort($groups, fn ($a, $b) => $order[$a] <=> $order[$b]);

        $labels = [];
        foreach ($groups as $group => $members) {
            $labels[$group === 'Sorcery' ? 'Sorceries' : ($group === 'Other' ? 'Other' : "{$group}s")] = $members;
        }

        return $labels;
    }

    /**
     * Copies of cards, counting each entry's `count`.
     *
     * @param Card[] $cards
     *
     * @return int
     */
    public static function count(array $cards): int
    {
        return array_sum(array_map(fn (Card $card) => (int) ($card->count ?? 1), $cards));
    }
}
