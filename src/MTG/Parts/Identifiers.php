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
 * A {@see Card}'s `identifiers` — its ids on other services. Every value is
 * a string, as in MTGJSON.
 *
 * @link https://mtgjson.com/data-models/identifiers/ Identifiers model
 *
 * @see \MTG\Parts\Card The parent object
 *
 * @property string|null $cardKingdomEtchedId               Card Kingdom card identifier for etched cards.
 * @property string|null $cardKingdomFoilId                 Card Kingdom card identifier for foil cards.
 * @property string|null $cardKingdomId                     Card Kingdom card identifier for non-foil cards.
 * @property string|null $cardsphereAlternativeFoilId       Cardsphere card identifier for alternative foil cards.
 * @property string|null $cardsphereEtchedId                Cardsphere card identifier for etched cards.
 * @property string|null $cardsphereFoilId                  Cardsphere card identifier for foil cards.
 * @property string|null $cardsphereId                      Cardsphere card identifier for non-foil cards.
 * @property string|null $deckboxId                         Deckbox card identifier.
 * @property string|null $mcmId                             Card Market card identifier.
 * @property string|null $mcmMetaId                         Card Market card meta identifier.
 * @property string|null $mtgArenaId                        Magic: The Gathering Arena card identifier.
 * @property string|null $mtgjsonFoilVersionId              MTGJSON card identifier of the foil version, for non-foil cards with a separate foil printing.
 * @property string|null $mtgjsonNonFoilVersionId           MTGJSON card identifier of the non-foil version, for foil cards with a separate non-foil printing.
 * @property string|null $mtgjsonV4Id                       MTGJSON v4 card identifier.
 * @property string|null $mtgoFoilId                        Magic: The Gathering Online card identifier for foil cards.
 * @property string|null $mtgoId                            Magic: The Gathering Online card identifier.
 * @property string|null $multiverseId                      Wizards of the Coast Gatherer card identifier.
 * @property string|null $scryfallCardBackId                Scryfall card back identifier.
 * @property string|null $scryfallId                        Scryfall card identifier.
 * @property string|null $scryfallIllustrationId            Scryfall card illustration identifier.
 * @property string|null $scryfallOracleId                  Scryfall card oracle identifier.
 * @property string|null $tcgplayerAlternativeFoilProductId TCGplayer alternative foil product identifier.
 * @property string|null $tcgplayerEtchedProductId          TCGplayer etched card identifier.
 * @property string|null $tcgplayerProductId                TCGplayer card identifier.
 *
 * @since 1.0.0
 */
class Identifiers extends Part
{
    /**
     * @inheritDoc
     */
    protected $fillable = [
        'cardKingdomEtchedId',
        'cardKingdomFoilId',
        'cardKingdomId',
        'cardsphereAlternativeFoilId',
        'cardsphereEtchedId',
        'cardsphereFoilId',
        'cardsphereId',
        'deckboxId',
        'mcmId',
        'mcmMetaId',
        'mtgArenaId',
        'mtgjsonFoilVersionId',
        'mtgjsonNonFoilVersionId',
        'mtgjsonV4Id',
        'mtgoFoilId',
        'mtgoId',
        'multiverseId',
        'scryfallCardBackId',
        'scryfallId',
        'scryfallIllustrationId',
        'scryfallOracleId',
        'tcgplayerAlternativeFoilProductId',
        'tcgplayerEtchedProductId',
        'tcgplayerProductId',
    ];
}
