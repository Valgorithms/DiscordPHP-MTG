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
use Discord\Builders\Components\MediaGallery;
use Discord\Builders\Components\Option;
use Discord\Builders\Components\Separator;
use Discord\Builders\Components\StringSelect;
use Discord\Builders\Components\TextDisplay;
use Discord\Builders\MessageBuilder;
use Discord\Parts\Channel\Message\AllowedMentions;
use MTG\Helpers\Text;
use MTG\MTG;
use MTG\Parts\Card;

/**
 * An opened booster pack as a Components V2 message: every card with its
 * rarity, foil and price, the pack's value, a picker to look at a card, and
 * buttons to see the pack's images or open another.
 *
 * Custom ids (Boosters module): `pack:open:<set>:<type>`,
 * `pack:images:<pack>`, `pack:card:<pack>` (the picker, valued with a uuid),
 * where `<pack>` is the opened pack's id in the search cache.
 *
 * @since 1.1.0
 */
class BoosterMessageBuilder extends MessageBuilder
{
    /**
     * The custom id prefix of booster components.
     *
     * @var string
     */
    public const PREFIX = 'pack';

    /**
     * The accent color of booster messages.
     *
     * @var int
     */
    public const ACCENT = 0xC77D2E;

    /**
     * Images in one media gallery, at most.
     *
     * @var int
     */
    public const GALLERY_SIZE = 10;

    /**
     * The opened pack.
     *
     * @param MTG    $mtg
     * @param string $code    The set code.
     * @param string $setName
     * @param string $type    The booster type.
     * @param Card[] $cards   The pack, in MTGJSON's sheet order.
     * @param string $pack    The pack's id in the search cache.
     *
     * @return static
     */
    public static function pack(MTG $mtg, string $code, string $setName, string $type, array $cards, string $pack): static
    {
        $lines = [];
        foreach ($cards as $card) {
            $lines[] = self::line($mtg, $card);
        }

        $value = self::value($cards);
        $heading = "### {$setName} — ".ucfirst($type)." booster\n-# ".Text::plural(count($cards), 'card').($value !== null ? ' · worth about '.Text::money($value, 'USD').' on TCGplayer' : '');

        $message = static::new()
            ->setIsComponentsV2Flag()
            ->setAllowedMentions(AllowedMentions::none())
            ->addComponent(Container::new()
                ->setAccentColor(self::ACCENT)
                ->addComponent(TextDisplay::new($heading))
                ->addComponent(Separator::new())
                ->addComponent(TextDisplay::new(Text::clip(implode("\n", $lines), 3500))));

        if ($cards) {
            $select = StringSelect::new(self::PREFIX.":card:{$pack}")->setPlaceholder('Look at a card');
            $seen = [];
            foreach ($cards as $card) {
                if (isset($seen[$card->uuid]) || count($seen) >= 25) {
                    continue;
                }
                $seen[$card->uuid] = true;
                $select->addOption(Option::new(Text::clip((string) $card->name, 100), (string) $card->uuid)
                    ->setDescription(Text::clip(ucfirst((string) $card->rarity).($card->isFoil ? ' · foil' : ''), 100)));
            }
            $message->addComponent(ActionRow::new()->addComponent($select));
        }

        return $message->addComponent(ActionRow::new()
            ->addComponent(Button::new(Button::STYLE_PRIMARY, self::PREFIX.":open:{$code}:{$type}")->setLabel('Open another'))
            ->addComponent(Button::new(Button::STYLE_SECONDARY, self::PREFIX.":images:{$pack}")->setLabel('Images')));
    }

    /**
     * The pack's card images, ten to a gallery.
     *
     * @param string $title
     * @param Card[] $cards
     *
     * @return static
     */
    public static function images(string $title, array $cards): static
    {
        $container = Container::new()->setAccentColor(self::ACCENT)->addComponent(TextDisplay::new("### {$title}"));

        $urls = [];
        foreach ($cards as $card) {
            if ($url = $card->image_url) {
                $urls[] = [$url, (string) $card->name];
            }
        }

        foreach (array_chunk($urls, self::GALLERY_SIZE) as $chunk) {
            $gallery = MediaGallery::new();
            foreach ($chunk as [$url, $name]) {
                $gallery->addItem($url, Text::clip($name, 1024));
            }
            $container->addComponent($gallery);
        }

        return static::new()->setIsComponentsV2Flag()->setAllowedMentions(AllowedMentions::none())->addComponent($container);
    }

    /**
     * One card of the pack: rarity, name, mana cost, foil, price.
     *
     * @param MTG  $mtg
     * @param Card $card
     *
     * @return string
     */
    public static function line(MTG $mtg, Card $card): string
    {
        $foil = $card->isFoil ? ' ✨' : '';
        $price = $card->getPrice('tcgplayer', $card->isFoil ? 'foil' : 'normal');
        $mana = $mtg->encapsulatedSymbolsToEmojis((string) $card->manaCost);

        return trim((Text::RARITIES[$card->rarity] ?? '▫️')." {$card->name} {$mana}").$foil.($price !== null ? ' — '.Text::money($price, 'USD') : '');
    }

    /**
     * The pack's TCGplayer value, or null when no card in it has a price.
     *
     * @param Card[] $cards
     *
     * @return float|null
     */
    public static function value(array $cards): ?float
    {
        $value = null;

        foreach ($cards as $card) {
            $price = $card->getPrice('tcgplayer', $card->isFoil ? 'foil' : 'normal') ?? ($card->isFoil ? $card->getPrice() : null);
            if ($price !== null) {
                $value = ($value ?? 0.0) + $price;
            }
        }

        return $value;
    }
}
