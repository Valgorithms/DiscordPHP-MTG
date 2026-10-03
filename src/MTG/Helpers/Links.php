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

use MTG\Parts\Card;

/**
 * Links from a card or set to the sites players use: Scryfall, EDHREC and
 * the stores in its MTGJSON purchase URLs. Pure.
 *
 * @since 1.1.0
 */
final class Links
{
    /**
     * Stores in a card's `purchaseUrls`, in button order.
     *
     * @var array<string, string>
     */
    public const STORES = [
        'tcgplayer' => 'TCGplayer',
        'cardKingdom' => 'Card Kingdom',
        'cardmarket' => 'Cardmarket',
    ];

    /**
     * The card's Scryfall page, from its set and collector number.
     *
     * @param Card $card
     *
     * @return string|null
     */
    public static function scryfall(Card $card): ?string
    {
        $set = $card->setCode;
        $number = $card->number;

        if (! $set || ! $number) {
            return null;
        }

        return 'https://scryfall.com/card/'.rawurlencode(strtolower($set)).'/'.rawurlencode($number);
    }

    /**
     * The card's EDHREC page — only for cards with an EDHREC rank.
     *
     * @param Card $card
     *
     * @return string|null
     */
    public static function edhrec(Card $card): ?string
    {
        if ($card->edhrecRank === null || ! $card->name) {
            return null;
        }

        // EDHREC names a double-faced card by its front face; MTGJSON spells
        // names with accents (Lim-Dûl) in ASCII too.
        $name = explode(' // ', $card->asciiName ?? $card->name)[0];

        return 'https://edhrec.com/cards/'.self::slug($name);
    }

    /**
     * A set's Scryfall page.
     *
     * @param string $code
     *
     * @return string
     */
    public static function scryfallSet(string $code): string
    {
        return 'https://scryfall.com/sets/'.rawurlencode(strtolower($code));
    }

    /**
     * The stores to buy the card from: label → MTGJSON referral URL.
     *
     * @param Card $card
     *
     * @return array<string, string>
     */
    public static function stores(Card $card): array
    {
        $urls = (array) ($card->getRawAttributes()['purchaseUrls'] ?? []);
        $stores = [];

        foreach (self::STORES as $key => $label) {
            if (! empty($urls[$key])) {
                $stores[$label] = (string) $urls[$key];
            }
        }

        return $stores;
    }

    /**
     * EDHREC's URL slug for a card name: lower case, ASCII, hyphens.
     *
     * @param string $name
     *
     * @return string
     */
    public static function slug(string $name): string
    {
        // Accents off: decompose and drop the marks where intl is there,
        // otherwise the letters Magic card names use.
        $ascii = class_exists(\Normalizer::class)
            ? preg_replace('/\p{Mn}+/u', '', (string) \Normalizer::normalize($name, \Normalizer::FORM_D))
            : strtr($name, ['á' => 'a', 'à' => 'a', 'â' => 'a', 'ä' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'í' => 'i', 'î' => 'i', 'ï' => 'i', 'ó' => 'o', 'ô' => 'o', 'ö' => 'o', 'ú' => 'u', 'û' => 'u', 'ü' => 'u', 'ñ' => 'n', 'ç' => 'c']);
        $ascii = str_replace(['Æ', 'æ'], ['Ae', 'ae'], (string) $ascii);

        return trim(preg_replace('/[^a-z0-9]+/', '-', strtolower(str_replace(["'", '’'], '', $ascii))), '-');
    }
}
