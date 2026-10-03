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
use Discord\Builders\Components\Section;
use Discord\Builders\Components\Separator;
use Discord\Builders\Components\StringSelect;
use Discord\Builders\Components\TextDisplay;
use Discord\Builders\Components\Thumbnail;
use Discord\Builders\MessageBuilder;
use Discord\Parts\Channel\Message\AllowedMentions;
use MTG\Helpers\Links;
use MTG\Helpers\Text;
use MTG\MTG;
use MTG\Parts\Card;
use MTG\Parts\ForeignData;
use MTG\Parts\Legality;
use MTG\Parts\Ruling;

/**
 * Components V2 messages about one card: the card itself — art, rules text,
 * today's price, its set, and buttons for everything else — and the panels
 * those buttons open (rulings, legality, prices, foreign names, images,
 * JSON).
 *
 * Buttons carry stable custom ids, routed by the Cards module:
 * `card:<action>:<uuid>[:<context>]`, where the context (`page.<search>.<n>`
 * or `random.<search>`) is how the card was reached, kept so the view can
 * offer its way back after a flip or a change of printing.
 *
 * @see \MTG\Modules\Cards
 *
 * @since 1.1.0
 */
class CardMessageBuilder extends MessageBuilder
{
    /**
     * The custom id prefix of every card component.
     *
     * @var string
     */
    public const PREFIX = 'card';

    /**
     * Accent colors by color identity; several colors are gold.
     *
     * @var array<string, int>
     */
    public const ACCENTS = [
        'W' => 0xF0E6C0,
        'U' => 0x2F7FC1,
        'B' => 0x5A4A5E,
        'R' => 0xD3402A,
        'G' => 0x2E8B4A,
        'multicolor' => 0xD4AF37,
        'colorless' => 0x9EA4A8,
    ];

    /**
     * Budget for the rules text, before mana symbols become emoji.
     *
     * @var int
     */
    public const TEXT_LIMIT = 1500;

    /**
     * A Components V2 message that mentions nobody.
     *
     * @return static
     */
    public static function panel(): static
    {
        return static::new()->setIsComponentsV2Flag()->setAllowedMentions(AllowedMentions::none());
    }

    /**
     * The card view.
     *
     * @param MTG         $mtg        For mana symbol emoji.
     * @param Card        $card
     * @param array[]     $printings  Other printings of the card for the picker: {uuid, setCode, setName, number, releaseDate}, newest first.
     * @param string      $context    How the card was reached (see the class description), or `''`.
     * @param Button[]    $navigation Extra buttons for the last row, e.g. "Back to results".
     * @param string|null $status     A line shown above the card.
     *
     * @return static
     */
    public static function card(MTG $mtg, Card $card, array $printings = [], string $context = '', array $navigation = [], ?string $status = null): static
    {
        $uuid = (string) $card->uuid;
        $id = fn (string $action, string $arg = '') => self::PREFIX.":{$action}:".($arg !== '' ? $arg : $uuid).($context !== '' ? ":{$context}" : '');

        $container = Container::new()->setAccentColor(self::accent($card));

        if ($status !== null) {
            $container->addComponent(TextDisplay::new($status));
        }

        $body = [TextDisplay::new(self::heading($mtg, $card)), TextDisplay::new(self::typeLine($card))];
        if (($rules = self::rules($mtg, $card)) !== '') {
            $body[] = TextDisplay::new($rules);
        }

        if ($image = $card->image_url) {
            $section = Section::new()->setAccessory(Thumbnail::new($image)->setDescription(Text::clip((string) $card->name, 1024)));
            foreach ($body as $text) {
                $section->addComponent($text);
            }
            $container->addComponent($section);
        } else {
            $container->addComponents($body);
        }

        $container->addComponent(Separator::new());

        if ($flavor = $card->flavorText) {
            $container->addComponent(TextDisplay::new(self::quote(Text::clip($flavor, 500), true)));
        }

        if (($prices = self::priceSummary($card)) !== '') {
            $container->addComponent(TextDisplay::new($prices));
        }

        $printing = TextDisplay::new(self::printingLine($card));
        $container->addComponent($card->setCode
            ? Section::new()->addComponent($printing)->setAccessory(
                Button::new(Button::STYLE_SECONDARY, "set:show:{$card->setCode}")->setLabel('Set')
            )
            : $printing);

        $message = static::panel()->addComponent($container);

        $actions = ActionRow::new();
        if ($card->otherFaceIds) {
            $actions->addComponent(Button::new(Button::STYLE_PRIMARY, $id('flip'))->setLabel('Flip')->setEmoji('🔄'));
        }
        if ($image) {
            $actions->addComponent(Button::new(Button::STYLE_SECONDARY, $id('image'))->setLabel('Image'));
        }
        if (($rulings = count((array) ($card->getRawAttributes()['rulings'] ?? []))) > 0) {
            $actions->addComponent(Button::new(Button::STYLE_SECONDARY, $id('rulings'))->setLabel("Rulings ({$rulings})"));
        }
        if (! empty($card->getRawAttributes()['legalities'])) {
            $actions->addComponent(Button::new(Button::STYLE_SECONDARY, $id('legal'))->setLabel('Legality'));
        }
        if (! empty($card->getRawAttributes()['prices'])) {
            $actions->addComponent(Button::new(Button::STYLE_SECONDARY, $id('prices'))->setLabel('Prices'));
        }
        if ($actions->getComponents()) {
            $message->addComponent($actions);
        }

        if (count($printings) > 1) {
            $message->addComponent(ActionRow::new()->addComponent(self::printingPicker($card, $printings, $context)));
        }

        $links = ActionRow::new();
        foreach (array_filter(['Scryfall' => Links::scryfall($card), 'EDHREC' => Links::edhrec($card)] + Links::stores($card)) as $label => $url) {
            $links->addComponent(Button::link($url)->setLabel($label));
        }
        if ($links->getComponents()) {
            $message->addComponent($links);
        }

        $more = ActionRow::new();
        if (! empty($card->getRawAttributes()['foreignData'])) {
            $more->addComponent(Button::new(Button::STYLE_SECONDARY, $id('foreign'))->setLabel('Foreign names'));
        }
        $more->addComponent(Button::new(Button::STYLE_SECONDARY, $id('json'))->setLabel('JSON'));
        foreach (array_slice($navigation, 0, 3) as $button) {
            $more->addComponent($button);
        }
        $message->addComponent($more);

        return $message;
    }

    /**
     * A one-line summary of the card for lists: name, mana cost, type, set.
     *
     * @param MTG  $mtg
     * @param Card $card
     *
     * @return string
     */
    public static function summary(MTG $mtg, Card $card): string
    {
        $mana = $mtg->encapsulatedSymbolsToEmojis((string) $card->manaCost);
        $type = Text::clip((string) $card->type, 60);

        return trim("**{$card->name}** {$mana}")." — {$type} · `{$card->setCode}`";
    }

    /**
     * The card's rulings, newest last, grouped by date.
     *
     * @param Card $card
     *
     * @return static
     */
    public static function rulings(Card $card): static
    {
        $lines = [];
        foreach ($card->rulings as $ruling) {
            /** @var Ruling $ruling */
            $lines[$ruling->date][] = '- '.$ruling->text;
        }

        $text = '';
        $shown = 0;
        $total = array_sum(array_map('count', $lines));
        foreach ($lines as $date => $rulings) {
            $block = "\n**{$date}**\n".implode("\n", $rulings);
            if (mb_strlen($text.$block) > 3600) {
                break;
            }
            $text .= $block;
            $shown += count($rulings);
        }

        if ($shown < $total) {
            $text .= "\n\n-# …and ".Text::plural($total - $shown, 'more ruling').'. See them all on Scryfall.';
        }

        return self::detail($card, 'Rulings', trim($text) ?: '_No rulings._');
    }

    /**
     * Where the card is legal, banned and restricted.
     *
     * @param Card $card
     *
     * @return static
     */
    public static function legalities(Card $card): static
    {
        $statuses = [];
        foreach ($card->legalities as $legality) {
            /** @var Legality $legality */
            $statuses[$legality->legality][] = Text::format($legality->format);
        }

        $icons = ['Legal' => '✅', 'Restricted' => '⚠️', 'Banned' => '⛔', 'Not Legal' => '❌'];
        $lines = [];
        foreach ($icons as $status => $icon) {
            if (isset($statuses[$status])) {
                sort($statuses[$status]);
                $lines[] = "{$icon} **{$status}:** ".implode(', ', $statuses[$status]);
            }
        }

        return self::detail($card, 'Legality', $lines ? implode("\n", $lines) : '_Not legal in any tracked format._');
    }

    /**
     * Today's prices at every store, retail and buylist.
     *
     * @param Card $card
     *
     * @return static
     */
    public static function prices(Card $card): static
    {
        $lines = [];

        foreach ((array) ($card->prices ?? []) as $medium => $providers) {
            foreach ((array) $providers as $provider => $list) {
                $name = Text::PROVIDERS[$provider] ?? ucfirst((string) $provider);
                foreach (['retail' => '', 'buylist' => ' (buys at)'] as $kind => $suffix) {
                    if (empty($list[$kind])) {
                        continue;
                    }
                    $points = [];
                    foreach (['normal', 'foil', 'etched'] as $finish) {
                        if (isset($list[$kind][$finish])) {
                            $points[] = ($finish === 'normal' ? '' : "{$finish} ").Text::money((float) $list[$kind][$finish], $list['currency'] ?? null);
                        }
                    }
                    $lines[] = "**{$name}**{$suffix} — ".implode(' · ', $points);
                }
            }
        }

        $text = $lines ? implode("\n", $lines)."\n\n-# Today's prices from MTGJSON's partners." : '_No prices today._';

        $message = self::detail($card, 'Prices', $text);
        $stores = ActionRow::new();
        foreach (Links::stores($card) as $label => $url) {
            $stores->addComponent(Button::link($url)->setLabel("Buy on {$label}"));
        }
        if ($stores->getComponents()) {
            $message->addComponent($stores);
        }

        return $message;
    }

    /**
     * The card's names in other languages.
     *
     * @param Card $card
     *
     * @return static
     */
    public static function foreignNames(Card $card): static
    {
        $names = [];
        foreach ($card->foreignData as $foreign) {
            /** @var ForeignData $foreign */
            if (isset($foreign->language, $foreign->name)) {
                $names[$foreign->language][$foreign->faceName ?? $foreign->name] = true;
            }
        }

        $lines = array_map(fn ($language, $printed) => "**{$language}:** ".implode(', ', array_keys($printed)), array_keys($names), $names);

        return self::detail($card, 'Foreign names', $lines ? Text::clip(implode("\n", $lines), 3800) : '_No foreign printings._');
    }

    /**
     * The card's image, both faces of a double-faced card.
     *
     * @param Card $card
     *
     * @return static
     */
    public static function image(Card $card): static
    {
        $gallery = MediaGallery::new();
        foreach (self::faceImages($card) as $url) {
            $gallery->addItem($url, Text::clip((string) $card->name, 1024));
        }

        return static::panel()->addComponent(
            Container::new()->setAccentColor(self::accent($card))->addComponent(TextDisplay::new("### {$card->name}"))->addComponent($gallery)
        );
    }

    /**
     * The card as MTGJSON JSON, attached as a file (a plain message: files
     * and Components V2 pair badly).
     *
     * @param Card $card
     *
     * @return static
     */
    public static function json(Card $card): static
    {
        return static::new()
            ->setAllowedMentions(AllowedMentions::none())
            ->addFileFromContent("{$card->uuid}.json", json_encode($card, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * A short notice, e.g. "nothing found".
     *
     * @param string $text
     *
     * @return static
     */
    public static function notice(string $text): static
    {
        return static::panel()->addComponent(Container::new()->setAccentColor(self::ACCENTS['colorless'])->addComponent(TextDisplay::new($text)));
    }

    /**
     * The accent color for the card's color identity.
     *
     * @param Card $card
     *
     * @return int
     */
    public static function accent(Card $card): int
    {
        $identity = (array) ($card->colorIdentity ?? []);

        return match (count($identity)) {
            0 => self::ACCENTS['colorless'],
            1 => self::ACCENTS[$identity[0]] ?? self::ACCENTS['colorless'],
            default => self::ACCENTS['multicolor'],
        };
    }

    /**
     * Today's main prices in one line: TCGplayer, Card Kingdom and Cardmarket,
     * with foil where there is one.
     *
     * @param Card $card
     *
     * @return string Empty when the card has no prices.
     */
    public static function priceSummary(Card $card): string
    {
        $parts = [];

        foreach (['tcgplayer', 'cardkingdom', 'cardmarket'] as $provider) {
            $normal = $card->getPrice($provider);
            $foil = $card->getPrice($provider, 'foil');
            $currency = $card->getPriceCurrency($provider);

            if ($normal === null && $foil === null) {
                continue;
            }

            $part = Text::PROVIDERS[$provider].' '.($normal !== null ? Text::money($normal, $currency) : '—');
            if ($foil !== null) {
                $part .= ' (foil '.Text::money($foil, $currency).')';
            }
            $parts[] = $part;
        }

        return $parts ? '💲 '.implode(' · ', $parts) : '';
    }

    /**
     * The image URLs of each face printed on the card.
     *
     * @param Card $card
     *
     * @return string[]
     */
    public static function faceImages(Card $card): array
    {
        if (! $url = $card->image_url) {
            return [];
        }

        if (! in_array($card->layout, Card::DOUBLE_FACED_LAYOUTS, true)) {
            return [$url];
        }

        return [
            str_replace('/back/', '/front/', $url),
            str_replace('/front/', '/back/', $url),
        ];
    }

    /**
     * "### {identity} Name {mana}", with the face name for one face of a
     * multi-face card.
     *
     * @param MTG  $mtg
     * @param Card $card
     *
     * @return string
     */
    protected static function heading(MTG $mtg, Card $card): string
    {
        $identity = implode('', array_filter(array_map(
            fn (string $color) => $mtg->emojis->get('name', "CI_{$color}_"),
            (array) ($card->colorIdentity ?? [])
        )));
        $mana = $mtg->encapsulatedSymbolsToEmojis((string) $card->manaCost);
        $heading = '### '.implode(' ', array_filter([$identity, $card->faceName ?? $card->name, $mana], fn ($part) => $part !== ''));

        if ($card->faceName && $card->faceName !== $card->name) {
            $heading .= "\n-# {$card->name}";
        }

        return $heading;
    }

    /**
     * The type line and rarity.
     *
     * @param Card $card
     *
     * @return string
     */
    protected static function typeLine(Card $card): string
    {
        $type = $card->type ?? trim(implode(' ', (array) $card->supertypes).' '.implode(' ', (array) $card->types));
        $rarity = $card->rarity ? ' · '.(Text::RARITIES[$card->rarity] ?? '').' '.ucfirst($card->rarity) : '';

        return "**{$type}**{$rarity}";
    }

    /**
     * Rules text, then power/toughness, loyalty or defense.
     *
     * @param MTG  $mtg
     * @param Card $card
     *
     * @return string
     */
    protected static function rules(MTG $mtg, Card $card): string
    {
        $text = $mtg->encapsulatedSymbolsToEmojis(Text::clip((string) $card->text, self::TEXT_LIMIT));

        $stats = match (true) {
            $card->power !== null && $card->toughness !== null => str_replace('*', '\*', "**{$card->power}/{$card->toughness}**"),
            $card->loyalty !== null => "Loyalty **{$card->loyalty}**",
            $card->defense !== null => "Defense **{$card->defense}**",
            default => '',
        };

        return trim($text.($stats !== '' ? "\n\n{$stats}" : ''));
    }

    /**
     * "-# Set Name (CODE) · #number · Illus. Artist · Language".
     *
     * @param Card $card
     *
     * @return string
     */
    protected static function printingLine(Card $card): string
    {
        $parts = [($card->setName ?? $card->setCode)." ({$card->setCode})"];
        if ($card->number) {
            $parts[] = "#{$card->number}";
        }
        if ($card->artist) {
            $parts[] = "Illus. {$card->artist}";
        }
        if ($card->language && $card->language !== 'English') {
            $parts[] = $card->language;
        }
        if ($card->isFoil) {
            $parts[] = '✨ Foil';
        }

        return '-# '.implode(' · ', $parts);
    }

    /**
     * The other-printings picker; the shown printing is preselected.
     *
     * @param Card    $card
     * @param array[] $printings
     * @param string  $context
     *
     * @return StringSelect
     */
    protected static function printingPicker(Card $card, array $printings, string $context): StringSelect
    {
        $select = StringSelect::new(self::PREFIX.':print'.($context !== '' ? ":{$context}" : ''))
            ->setPlaceholder('Other printings ('.count($printings).')');

        foreach (array_slice($printings, 0, 25) as $printing) {
            $year = isset($printing['releaseDate']) ? substr((string) $printing['releaseDate'], 0, 4) : '';
            $option = Option::new(Text::clip("{$printing['setName']} ({$printing['setCode']})", 100), (string) $printing['uuid'])
                ->setDescription(Text::clip(trim('#'.($printing['number'] ?? '?')." {$year}"), 100));
            if ($printing['uuid'] === $card->uuid) {
                $option->setDefault();
            }
            $select->addOption($option);
        }

        return $select;
    }

    /**
     * A titled panel about the card.
     *
     * @param Card   $card
     * @param string $title
     * @param string $text
     *
     * @return static
     */
    protected static function detail(Card $card, string $title, string $text): static
    {
        return static::panel()->addComponent(
            Container::new()
                ->setAccentColor(self::accent($card))
                ->addComponent(TextDisplay::new("### {$title} — {$card->name}\n-# ".($card->setName ?? $card->setCode)." ({$card->setCode})"))
                ->addComponent(Separator::new())
                ->addComponent(TextDisplay::new(Text::clip($text, 3800)))
        );
    }

    /**
     * Quotes text, line by line.
     *
     * @param string $text
     * @param bool   $italic
     *
     * @return string
     */
    protected static function quote(string $text, bool $italic = false): string
    {
        return implode("\n", array_map(fn (string $line) => '> '.($italic && trim($line) !== '' ? '*'.trim($line).'*' : $line), explode("\n", $text)));
    }
}
