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

/**
 * One page of search results as a Components V2 message: a heading, a
 * numbered list, a picker that opens an entry, and Previous/Next buttons.
 *
 * Shared by card, set and deck searches; each passes its own custom id
 * prefix, so `<prefix>:open:<search>:<page>` (the picker, valued with the
 * entry's id) and `<prefix>:page:<search>:<page>` land in its module.
 *
 * @since 1.1.0
 */
class ListMessageBuilder extends MessageBuilder
{
    /**
     * Entries per page.
     *
     * @var int
     */
    public const PAGE_SIZE = 10;

    /**
     * Pages needed for a number of entries.
     *
     * @param int $total
     *
     * @return int At least 1.
     */
    public static function pages(int $total): int
    {
        return max(1, (int) ceil($total / self::PAGE_SIZE));
    }

    /**
     * A page of results.
     *
     * @param string   $prefix  The module's custom id prefix (`card`, `set`, `deck`).
     * @param string   $search  The search's id in the {@see \MTG\Helpers\SearchCache}.
     * @param int      $page    1-based.
     * @param int      $total   Entries across every page.
     * @param string   $title   The heading.
     * @param string[] $lines   This page's entries, rendered.
     * @param array[]  $choices This page's entries for the picker: {label, value, description?}.
     * @param int      $accent  The container's accent color.
     * @param string   $noun    What an entry is, for the count ("card", "set", "deck").
     *
     * @return static
     */
    public static function page(string $prefix, string $search, int $page, int $total, string $title, array $lines, array $choices, int $accent, string $noun): static
    {
        $pages = self::pages($total);
        $first = ($page - 1) * self::PAGE_SIZE + 1;

        $numbered = [];
        foreach (array_values($lines) as $i => $line) {
            $numbered[] = '`'.str_pad((string) ($first + $i), 2, ' ', STR_PAD_LEFT).".` {$line}";
        }

        $container = Container::new()
            ->setAccentColor($accent)
            ->addComponent(TextDisplay::new("### {$title}\n-# ".Text::plural($total, $noun).($pages > 1 ? " · page {$page} of {$pages}" : '')))
            ->addComponent(Separator::new())
            ->addComponent(TextDisplay::new(Text::clip(implode("\n", $numbered), 3500)));

        $message = static::new()->setIsComponentsV2Flag()->setAllowedMentions(AllowedMentions::none())->addComponent($container);

        if ($choices) {
            $select = StringSelect::new("{$prefix}:open:{$search}:{$page}")->setPlaceholder("Open a {$noun}");
            foreach (array_slice($choices, 0, 25) as $choice) {
                $option = Option::new(Text::clip((string) $choice['label'], 100), (string) $choice['value']);
                if (! empty($choice['description'])) {
                    $option->setDescription(Text::clip((string) $choice['description'], 100));
                }
                $select->addOption($option);
            }
            $message->addComponent(ActionRow::new()->addComponent($select));
        }

        if ($pages > 1) {
            $message->addComponent(ActionRow::new()
                ->addComponent(Button::new(Button::STYLE_SECONDARY, "{$prefix}:page:{$search}:".max(1, $page - 1))->setLabel('Previous')->setDisabled($page <= 1))
                ->addComponent(Button::new(Button::STYLE_SECONDARY, "{$prefix}:page:{$search}:".min($pages, $page + 1))->setLabel('Next')->setDisabled($page >= $pages)));
        }

        return $message;
    }
}
