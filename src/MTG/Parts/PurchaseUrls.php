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

namespace MTG\Parts;

use Discord\Parts\Part;

/**
 * A {@see Card}'s `purchaseUrls` — links to buy it, through MTGJSON's
 * referral redirects.
 *
 * @link https://mtgjson.com/data-models/purchase-urls/ Purchase Urls model
 *
 * @see \MTG\Parts\Card The parent object
 *
 * @property string|null $cardKingdom              URL to purchase a product on Card Kingdom.
 * @property string|null $cardKingdomEtched        URL to purchase an etched product on Card Kingdom.
 * @property string|null $cardKingdomFoil          URL to purchase a foil product on Card Kingdom.
 * @property string|null $cardmarket               URL to purchase a product on Cardmarket.
 * @property string|null $cardmarketFoil           URL to purchase a foil product on Cardmarket.
 * @property string|null $tcgplayer                URL to purchase a product on TCGplayer.
 * @property string|null $tcgplayerAlternativeFoil URL to purchase an alternative foil product on TCGplayer.
 * @property string|null $tcgplayerEtched          URL to purchase an etched product on TCGplayer.
 *
 * @since 1.0.0
 */
class PurchaseUrls extends Part
{
    /**
     * @inheritDoc
     */
    protected $fillable = [
        'cardKingdom',
        'cardKingdomEtched',
        'cardKingdomFoil',
        'cardmarket',
        'cardmarketFoil',
        'tcgplayer',
        'tcgplayerAlternativeFoil',
        'tcgplayerEtched',
    ];
}
