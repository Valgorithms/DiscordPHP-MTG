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

namespace MTG\Helpers;

/**
 * Small pure text helpers for rendering Magic data into Discord messages.
 *
 * @since 1.1.0
 */
final class Text
{
    /**
     * Friendly names for MTGJSON's format keys; anything else is title-cased.
     *
     * @var array<string, string>
     */
    public const FORMATS = [
        'alchemy' => 'Alchemy',
        'brawl' => 'Brawl',
        'commander' => 'Commander',
        'competitivebrawl' => 'Competitive Brawl',
        'duel' => 'Duel Commander',
        'future' => 'Future Standard',
        'gladiator' => 'Gladiator',
        'historic' => 'Historic',
        'legacy' => 'Legacy',
        'modern' => 'Modern',
        'oathbreaker' => 'Oathbreaker',
        'oldschool' => 'Old School',
        'pauper' => 'Pauper',
        'paupercommander' => 'Pauper Commander',
        'penny' => 'Penny Dreadful',
        'pioneer' => 'Pioneer',
        'predh' => 'PreDH',
        'premodern' => 'Premodern',
        'standard' => 'Standard',
        'standardbrawl' => 'Standard Brawl',
        'timeless' => 'Timeless',
        'vintage' => 'Vintage',
    ];

    /**
     * Friendly names for MTGJSON's price providers.
     *
     * @var array<string, string>
     */
    public const PROVIDERS = [
        'tcgplayer' => 'TCGplayer',
        'cardkingdom' => 'Card Kingdom',
        'cardmarket' => 'Cardmarket',
        'manapool' => 'Mana Pool',
        'cardhoarder' => 'Cardhoarder (MTGO)',
    ];

    /**
     * Rarity symbols, in the colors of the set symbol.
     *
     * @var array<string, string>
     */
    public const RARITIES = [
        'common' => '⚫',
        'uncommon' => '⚪',
        'rare' => '🟡',
        'mythic' => '🟠',
        'special' => '🟣',
        'bonus' => '🟣',
    ];

    /**
     * Shortens text to a length, with an ellipsis when it was cut.
     *
     * @param string $text
     * @param int    $length Characters, including the ellipsis.
     *
     * @return string
     */
    public static function clip(string $text, int $length): string
    {
        return mb_strlen($text) <= $length ? $text : rtrim(mb_substr($text, 0, max(0, $length - 1))).'…';
    }

    /**
     * A format's friendly name.
     *
     * @param string $format MTGJSON's key, e.g. `paupercommander`.
     *
     * @return string
     */
    public static function format(string $format): string
    {
        return self::FORMATS[$format] ?? ucwords(str_replace('_', ' ', $format));
    }

    /**
     * A price with its currency symbol, e.g. `$0.63` or `€1.71`.
     *
     * @param float       $price
     * @param string|null $currency `USD`, `EUR`, …
     *
     * @return string
     */
    public static function money(float $price, ?string $currency): string
    {
        $amount = number_format($price, 2);

        return match ($currency) {
            'USD', null => "\${$amount}",
            'EUR' => "€{$amount}",
            'GBP' => "£{$amount}",
            default => "{$amount} {$currency}",
        };
    }

    /**
     * "1 card", "3 cards".
     *
     * @param int    $count
     * @param string $singular
     * @param string $plural   Defaults to the singular plus `s`.
     *
     * @return string
     */
    public static function plural(int $count, string $singular, ?string $plural = null): string
    {
        return number_format($count).' '.($count === 1 ? $singular : ($plural ?? "{$singular}s"));
    }

    /**
     * The card references in a message: `[[Name]]`, or `[[Name|SET]]` for a
     * printing from one set. At most `$limit`, duplicates dropped.
     *
     * @param string $content
     * @param int    $limit
     *
     * @return array<array{name: string, set: ?string}>
     */
    public static function cardMentions(string $content, int $limit = 5): array
    {
        if (! preg_match_all('/\[\[([^\[\]|]{1,141})(?:\|([A-Za-z0-9]{2,6}))?\]\]/u', $content, $matches, PREG_SET_ORDER)) {
            return [];
        }

        $mentions = [];
        foreach ($matches as $match) {
            $name = trim($match[1]);
            $set = isset($match[2]) && $match[2] !== '' ? strtoupper($match[2]) : null;
            $key = mb_strtolower($name).'|'.$set;

            if ($name !== '' && ! isset($mentions[$key])) {
                $mentions[$key] = ['name' => $name, 'set' => $set];
            }

            if (count($mentions) >= $limit) {
                break;
            }
        }

        return array_values($mentions);
    }
}
