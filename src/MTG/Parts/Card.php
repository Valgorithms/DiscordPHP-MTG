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

use Carbon\Carbon;
use Discord\Builders\Components\Button;
use Discord\Builders\Components\Container;
use Discord\Builders\Components\MediaGallery;
use Discord\Builders\Components\Section;
use Discord\Builders\Components\Separator;
use Discord\Builders\Components\TextDisplay;
use Discord\Helpers\ExCollectionInterface;
use Discord\Parts\Embed\Embed;
use Discord\Parts\Interactions\Interaction;
use Discord\Parts\Part;
use MTG\MTG;

/**
 * One face of one printing of a Magic: The Gathering card — MTGJSON's Card
 * (Set) model, plus the Card (Deck) fields when it comes from a deck.
 * Multi-face cards (split, transform, modal DFC, adventure, …) have one Card
 * per face, sharing `name` and told apart by `faceName` and `side`.
 *
 * @link https://mtgjson.com/data-models/card/card-set/ Card (Set) model
 * @link https://mtgjson.com/data-models/card/card-deck/ Card (Deck) model
 *
 * @property string|null   $artist                  Name of the artist that illustrated the card art.
 * @property string[]|null $artistIds               Identifiers of the artists.
 * @property string|null   $asciiName               The ASCII (Basic/128) code formatted card name with no special unicode characters.
 * @property string[]|null $attractionLights        A list of attraction lights found on a card, available only to "Attraction" subtypes.
 * @property string[]|null $availability            A list of the card's available printing types (`arena`, `dreamcast`, `mtgo`, `paper`, `shandalar`).
 * @property string[]|null $boosterTypes            The types of booster packs the card is found in (`default`, `deck`).
 * @property string|null   $borderColor             The color of the card border (`black`, `borderless`, `gold`, `silver`, `white`).
 * @property string|null   $cardParts               The names of the cards that meld into this card (comma-joined in the SQLite build).
 * @property string[]|null $colorIdentity           A list of all the colors found in `manaCost`, `colorIndicator` and `text`, as letters (`W`, `U`, `B`, `R`, `G`).
 * @property string[]|null $colorIndicator          A list of the colors of the color indicator, as letters.
 * @property string[]|null $colors                  A list of all the colors in `manaCost` and `colorIndicator`, as letters.
 * @property string|null   $defense                 The defense of the card. Used on battle cards.
 * @property string|null   $duelDeck                The duel deck the card is in (`a` or `b`).
 * @property int|null      $edhrecRank              The card rank on EDHRec.
 * @property float|null    $edhrecSaltiness         The card saltiness score on EDHRec.
 * @property float|null    $faceConvertedManaCost   The converted mana cost of the face of either half or part of the card. Deprecated by MTGJSON; use `faceManaValue`.
 * @property string|null   $faceFlavorName          The flavor name on the face of the card.
 * @property float|null    $faceManaValue           The mana value of the face of either half or part of the card.
 * @property string|null   $faceName                The name on the face of the card.
 * @property string|null   $facePrintedName         The name on the face of the card as printed.
 * @property string[]|null $finishes                The finishes of the card (`etched`, `foil`, `nonfoil`, `signed`).
 * @property string|null   $flavorName              The promotional card name printed above the true card name on special cards that has no game function.
 * @property string|null   $flavorText              The italicized text found below the rules text that has no game function.
 * @property string[]|null $frameEffects            The visual frame effects (`colorshifted`, `extendedart`, `legendary`, `showcase`, …).
 * @property string|null   $frameVersion            The version of the card frame style (`1993`, `1997`, `2003`, `2015`, `future`).
 * @property string|null   $hand                    The starting maximum hand size total modifier. A `+` or `-` character precedes an integer.
 * @property bool|null     $hasAlternativeDeckLimit If the card allows a value other than 4 copies in a deck.
 * @property bool|null     $hasContentWarning       If the card marked by Wizards of the Coast for having sensitive content.
 * @property bool|null     $isAlternative           If the card has some kind of alternative variation to its printed counterpart.
 * @property bool|null     $isFullArt               If the card has full artwork.
 * @property bool|null     $isFunny                 If the card is part of a funny set, such as an Un-set.
 * @property bool|null     $isGameChanger           If the card is on the Commander Game Changers list.
 * @property bool|null     $isOnlineOnly            If the card is only available in online game variations.
 * @property bool|null     $isOversized             If the card is oversized.
 * @property bool|null     $isPromo                 If the card is a promotional printing.
 * @property bool|null     $isRebalanced            If the card is rebalanced for the Alchemy play format.
 * @property bool|null     $isReprint               If the card has been reprinted.
 * @property bool|null     $isReserved              If the card is on the Magic: The Gathering Reserved List.
 * @property bool|null     $isStorySpotlight        If the card is a Story Spotlight card.
 * @property bool|null     $isTextless              If the card does not have a text box.
 * @property bool|null     $isTimeshifted           If the card is "timeshifted", a feature of certain sets where a card has a different frameVersion.
 * @property string[]|null $keywords                A list of keywords found on the card.
 * @property string|null   $language                The language the card is printed in.
 * @property string|null   $layout                  The type of card layout (`normal`, `split`, `flip`, `transform`, `modal_dfc`, `meld`, `adventure`, `saga`, `class`, …).
 * @property string|null   $life                    The starting life total modifier. A `+` or `-` character precedes an integer.
 * @property string|null   $loyalty                 The starting loyalty value of the card. Used on planeswalker cards.
 * @property string|null   $manaCost                The mana cost of the card wrapped in brackets for each value, e.g. `{1}{U}{B}{R}`.
 * @property float|null    $manaValue               The total amount of mana needed to cast the card.
 * @property string|null   $name                    The name of the card. Cards with multiple faces are given all names in convention of `{name} // {name}`.
 * @property string|null   $number                  The number of the card. Can be prefixed or suffixed with a `*` or other characters for promotional sets.
 * @property string[]|null $originalPrintings       A list of card UUIDs to original printings of the card if this card is somehow different from its original.
 * @property string|null   $originalText            The text on the card as originally printed.
 * @property string|null   $originalType            The type of the card as originally printed. Includes any supertypes and subtypes.
 * @property string[]|null $otherFaceIds            A list of card UUIDs to this card's counterparts, such as transformed or melded faces.
 * @property string|null   $power                   The power of the card.
 * @property string|null   $printedName             The name of the card as printed, for non-English printings.
 * @property string|null   $printedText             The text of the card as printed, for non-English printings.
 * @property string|null   $printedType             The type of the card as printed, for non-English printings.
 * @property string[]|null $printings               A list of set printing codes the card was printed in, formatted in uppercase.
 * @property string[]|null $producedMana            A list of the colors of mana the card can produce.
 * @property string[]|null $promoTypes              A list of promotional types for a card (`boosterfun`, `buyabox`, `prerelease`, …).
 * @property string|null   $rarity                  The card printing rarity (`common`, `uncommon`, `rare`, `mythic`, `special`, `bonus`).
 * @property string[]|null $rebalancedPrintings     A list of card UUIDs to printings that are rebalanced versions of this card.
 * @property array|null    $relatedCards            Raw related cards: `reverseRelated` names, `spellbook` names, `tokens` UUIDs.
 * @property string|null   $securityStamp           The security stamp printed on the card (`acorn`, `arena`, `circle`, `heart`, `oval`, `triangle`).
 * @property string|null   $setCode                 The printing set code that the card is from.
 * @property string|null   $setName                 The name of the printing set (joined from the set; not an MTGJSON card field).
 * @property string|null   $side                    The identifier of the card side (`a`, `b`, `c`, `d`, `e`). Used on cards with multiple faces.
 * @property string|null   $signature               The name of the signature on the card.
 * @property array|null    $skuIds                  TCGplayer SKU identifiers, by finish.
 * @property array|null    $sourceProducts          A list of sealed product UUIDs that contain this card, by finish.
 * @property string|null   $subsets                 The names of the subset printings a card is in (comma-joined in the SQLite build).
 * @property string[]|null $subtypes                A list of card subtypes found after em-dash.
 * @property string[]|null $supertypes              A list of card supertypes found before em-dash.
 * @property string|null   $text                    The rules text of the card.
 * @property string|null   $toughness               The toughness of the card.
 * @property string|null   $type                    Type of the card as visible, including any supertypes and subtypes.
 * @property string[]|null $types                   A list of all card types of the card, including Un-sets and gameplay variants.
 * @property string|null   $uuid                    The universal unique identifier (v5) generated by MTGJSON. Each entry is unique.
 * @property string[]|null $variations              A list of card UUIDs of this card with alternate printings in the same set.
 * @property string|null   $watermark               The name of the watermark on the card.
 * @property int|null      $count                   The count of how many of this card exists in a relevant deck (Card (Deck) only).
 * @property bool|null     $isFoil                  If the card is foil in a deck, or came from a foil booster sheet.
 * @property bool|null     $isEtched                If the card is etched in a deck (Card (Deck) only).
 *
 * @property-read ExCollectionInterface<ForeignData> $foreignData         The card's printed names and text in other languages.
 * @property-read Identifiers|null                   $identifiers         The card's identifiers on other services.
 * @property-read ExCollectionInterface<Legality>    $legalities          The card's status in each format it has one in.
 * @property-read LeadershipSkills|null              $leadershipSkills    Which formats the card can be your commander in.
 * @property-read PurchaseUrls|null                  $purchaseUrls        Links to buy the card.
 * @property-read ExCollectionInterface<Ruling>      $rulings             Official rulings on the card.
 * @property-read Carbon|null                        $originalReleaseDate The release date of a promotional printing outside its cycle.
 * @property-read string|null                        $image_url           The card face's image on Scryfall's CDN, from `identifiers.scryfallId`.
 * @property-read Embed|null                         $image_embed         The card image as an embed, or null when the card has no Scryfall id.
 *
 * @since 0.4.0
 */
class Card extends Part
{
    /**
     * Layouts that only render well as an image.
     *
     * @var string[]
     */
    public const VISUAL_LAYOUTS = ['planar', 'scheme', 'vanguard', 'token', 'double_faced_token', 'emblem', 'art_series'];

    /**
     * Layouts whose later sides are printed on the back of the same card.
     *
     * @var string[]
     */
    public const DOUBLE_FACED_LAYOUTS = ['transform', 'modal_dfc', 'reversible_card', 'double_faced_token', 'art_series'];

    /**
     * The Scryfall image size used for `image_url`.
     *
     * @var string
     */
    public const IMAGE_SIZE = 'normal';

    /**
     * Discord's limit on a message's content.
     *
     * @var int
     */
    public const MAX_CONTENT_LENGTH = 2000;

    /**
     * @inheritDoc
     */
    protected $fillable = [
        'artist',
        'artistIds',
        'asciiName',
        'attractionLights',
        'availability',
        'boosterTypes',
        'borderColor',
        'cardParts',
        'colorIdentity',
        'colorIndicator',
        'colors',
        'defense',
        'duelDeck',
        'edhrecRank',
        'edhrecSaltiness',
        'faceConvertedManaCost',
        'faceFlavorName',
        'faceManaValue',
        'faceName',
        'facePrintedName',
        'finishes',
        'flavorName',
        'flavorText',
        'foreignData',
        'frameEffects',
        'frameVersion',
        'hand',
        'hasAlternativeDeckLimit',
        'hasContentWarning',
        'identifiers',
        'isAlternative',
        'isFullArt',
        'isFunny',
        'isGameChanger',
        'isOnlineOnly',
        'isOversized',
        'isPromo',
        'isRebalanced',
        'isReprint',
        'isReserved',
        'isStorySpotlight',
        'isTextless',
        'isTimeshifted',
        'keywords',
        'language',
        'layout',
        'leadershipSkills',
        'legalities',
        'life',
        'loyalty',
        'manaCost',
        'manaValue',
        'name',
        'number',
        'originalPrintings',
        'originalReleaseDate',
        'originalText',
        'originalType',
        'otherFaceIds',
        'power',
        'printedName',
        'printedText',
        'printedType',
        'printings',
        'producedMana',
        'promoTypes',
        'purchaseUrls',
        'rarity',
        'rebalancedPrintings',
        'relatedCards',
        'rulings',
        'securityStamp',
        'setCode',
        'setName',
        'side',
        'signature',
        'skuIds',
        'sourceProducts',
        'subsets',
        'subtypes',
        'supertypes',
        'text',
        'toughness',
        'type',
        'types',
        'uuid',
        'variations',
        'watermark',

        // Card (Deck)
        'count',
        'isFoil',
        'isEtched',
    ];

    /**
     * @inheritDoc
     */
    protected $visible = ['image_url'];

    /**
     * Gets the card's printed names and text in other languages.
     *
     * @return ExCollectionInterface<ForeignData>
     *
     * @since 1.0.0
     */
    protected function getForeignDataAttribute(): ExCollectionInterface
    {
        return $this->listOf(ForeignData::class, 'foreignData');
    }

    /**
     * Gets the card's identifiers on other services.
     *
     * @return Identifiers|null
     *
     * @since 1.0.0
     */
    protected function getIdentifiersAttribute(): ?Identifiers
    {
        return empty($this->attributes['identifiers'])
            ? null
            : $this->factory->part(Identifiers::class, (array) $this->attributes['identifiers'], true);
    }

    /**
     * Gets the card's status in each format it has one in. MTGJSON keys
     * legalities by format; each entry becomes a {@see Legality}.
     *
     * @return ExCollectionInterface<Legality>
     *
     * @since 0.3.0
     */
    protected function getLegalitiesAttribute(): ExCollectionInterface
    {
        $collection = ($this->discord->getCollectionClass())::for(Legality::class, 'format');

        foreach ((array) ($this->attributes['legalities'] ?? []) as $format => $legality) {
            if ($legality !== null) {
                $collection->pushItem($this->factory->part(Legality::class, ['format' => $format, 'legality' => $legality], true));
            }
        }

        return $collection;
    }

    /**
     * Gets which formats the card can be your commander in.
     *
     * @return LeadershipSkills|null
     *
     * @since 1.0.0
     */
    protected function getLeadershipSkillsAttribute(): ?LeadershipSkills
    {
        return empty($this->attributes['leadershipSkills'])
            ? null
            : $this->factory->part(LeadershipSkills::class, (array) $this->attributes['leadershipSkills'], true);
    }

    /**
     * Gets links to buy the card.
     *
     * @return PurchaseUrls|null
     *
     * @since 1.0.0
     */
    protected function getPurchaseUrlsAttribute(): ?PurchaseUrls
    {
        return empty($this->attributes['purchaseUrls'])
            ? null
            : $this->factory->part(PurchaseUrls::class, (array) $this->attributes['purchaseUrls'], true);
    }

    /**
     * Gets the official rulings on the card.
     *
     * @return ExCollectionInterface<Ruling>
     *
     * @since 0.3.0
     */
    protected function getRulingsAttribute(): ExCollectionInterface
    {
        return $this->listOf(Ruling::class, 'rulings');
    }

    /**
     * Gets the original release date of a promotional printing.
     *
     * @return Carbon|null
     *
     * @since 1.0.0
     */
    protected function getOriginalReleaseDateAttribute(): ?Carbon
    {
        return isset($this->attributes['originalReleaseDate']) ? Carbon::parse($this->attributes['originalReleaseDate']) : null;
    }

    /**
     * Gets the card face's image on Scryfall's CDN. The back face of a
     * double-faced card shares its front's Scryfall id.
     *
     * @return string|null
     *
     * @since 1.0.0
     */
    protected function getImageUrlAttribute(): ?string
    {
        $id = ((array) ($this->attributes['identifiers'] ?? []))['scryfallId'] ?? null;

        if (! is_string($id) || strlen($id) < 2) {
            return null;
        }

        $face = in_array($this->attributes['layout'] ?? null, self::DOUBLE_FACED_LAYOUTS, true) && ! in_array($this->attributes['side'] ?? 'a', ['a', null], true)
            ? 'back'
            : 'front';

        return 'https://cards.scryfall.io/'.self::IMAGE_SIZE."/{$face}/{$id[0]}/{$id[1]}/{$id}.jpg";
    }

    /**
     * Generates an Embed object for the card's image.
     *
     * @return Embed|null
     *
     * @since 0.4.0
     */
    protected function getImageEmbedAttribute(): ?Embed
    {
        if (! $url = $this->image_url) {
            return null;
        }

        return (new Embed($this->discord))
            ->setTitle($this->faceName ?? $this->name ?? 'Untitled')
            ->setImage($url);
    }

    /**
     * Builds a collection of parts from a list attribute.
     *
     * @param string $class The part class.
     * @param string $key   The attribute.
     *
     * @return ExCollectionInterface
     */
    protected function listOf(string $class, string $key): ExCollectionInterface
    {
        $collection = ($this->discord->getCollectionClass())::for($class, null);

        foreach ((array) ($this->attributes[$key] ?? []) as $item) {
            $collection->pushItem($this->factory->part($class, (array) $item, true));
        }

        return $collection;
    }

    /**
     * Converts the card to a container with components.
     *
     * @param Interaction|null $interaction The interaction the container answers, for the set button.
     *
     * @return Container|null
     *
     * @since 0.3.0
     */
    public function toContainer(?Interaction $interaction = null): ?Container
    {
        if (! isset($this->attributes['name'])) {
            return null;
        }

        // Only the purely visual layouts (planes, schemes, tokens, …) render
        // as just their image, and only if the card has one.
        if (in_array($this->attributes['layout'] ?? null, self::VISUAL_LAYOUTS, true) && ($url = $this->image_url)) {
            return Container::new()->addComponent(MediaGallery::new()->addItem($url));
        }

        return $this->normalLayoutContainer($interaction);
    }

    /**
     * Builds and returns a Container representing the normal layout for a Magic: The Gathering card.
     *
     * @param Interaction|null $interaction The interaction the container answers, for the set button.
     *
     * @return Container
     *
     * @since 0.4.0
     */
    public function normalLayoutContainer(?Interaction $interaction = null): Container
    {
        /** @var MTG $mtg */
        $mtg = $this->discord;

        $ci_emoji = implode('', array_filter(array_map(
            fn (string $color) => $mtg->emojis->get('name', 'CI_'.$color.'_'),
            (array) ($this->attributes['colorIdentity'] ?? [])
        )));
        $mana_cost = $mtg->encapsulatedSymbolsToEmojis($this->attributes['manaCost'] ?? '');
        $name = $this->attributes['faceName'] ?? $this->attributes['name'];

        $components = [TextDisplay::new(trim("{$ci_emoji} {$name} {$mana_cost}"))];

        $type_text = $this->attributes['type'] ?? trim(implode(' ', [
            implode(' ', (array) ($this->attributes['supertypes'] ?? [])),
            implode(' ', (array) ($this->attributes['types'] ?? [])),
            empty($this->attributes['subtypes']) ? '' : '— '.implode(' ', (array) $this->attributes['subtypes']),
        ]));
        if (isset($this->attributes['rarity'])) {
            $type_text .= ' ('.ucfirst($this->attributes['rarity']).')';
        }
        if ($type_text !== '') {
            $components[] = Separator::new();
            $components[] = ($button = $this->getSetButton($interaction))
                ? Section::new()->addComponent(TextDisplay::new($type_text))->setAccessory($button)
                : TextDisplay::new($type_text);
        }

        if (isset($this->attributes['text'])) {
            $components[] = Separator::new();
            $components[] = TextDisplay::new($mtg->encapsulatedSymbolsToEmojis($this->attributes['text']));
        }

        if (isset($this->attributes['power'], $this->attributes['toughness'])) {
            $components[] = Separator::new();
            $components[] = TextDisplay::new('('.str_replace('*', '\*', $this->attributes['power']).'/'.str_replace('*', '\*', $this->attributes['toughness']).')');
        }
        if (isset($this->attributes['loyalty'])) {
            $components[] = Separator::new();
            $components[] = TextDisplay::new("[{$this->attributes['loyalty']}]");
        }
        if (isset($this->attributes['defense'])) {
            $components[] = Separator::new();
            $components[] = TextDisplay::new("<{$this->attributes['defense']}>");
        }

        return Container::new()->addComponents($components);
    }

    /**
     * Gets a button to view the raw JSON of the card.
     *
     * @param Interaction $interaction
     *
     * @return Button
     *
     * @since 0.5.0
     */
    public function getJsonButton(Interaction $interaction): Button
    {
        return Button::new(Button::STYLE_SECONDARY, "JSON_{$this->uuid}")
            ->setLabel('JSON')
            ->setListener(
                fn () => $interaction->sendFollowUpMessage(
                    MTG::createBuilder()->addFileFromContent("{$this->uuid}.json", json_encode($this, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
                    true
                ),
                $this->getDiscord(),
                true, // One-time listener
                300 // delete listener after 5 minutes
            );
    }

    /**
     * Gets a button to view the image of the card.
     *
     * @param Interaction $interaction
     *
     * @return Button|null
     *
     * @since 0.5.0
     */
    public function getViewImageButton(Interaction $interaction): ?Button
    {
        if (! $embed = $this->image_embed) {
            return null;
        }

        return Button::new(Button::STYLE_SECONDARY, "VIEW_IMAGE_{$this->uuid}")
            ->setLabel('View Image')
            ->setListener(
                fn () => $interaction->sendFollowUpMessage(MTG::createBuilder()->addEmbed($embed), true),
                $this->getDiscord(),
                true, // One-time listener
                300 // delete listener after 5 minutes
            );
    }

    /**
     * Gets a button to view the set the card belongs to.
     *
     * @param Interaction|null $interaction The interaction to answer; without one the button is disabled.
     *
     * @return Button|null
     *
     * @since 0.5.0
     */
    public function getSetButton(?Interaction $interaction = null): ?Button
    {
        if (! isset($this->attributes['setCode'])) {
            return null;
        }

        /** @var MTG $mtg */
        $mtg = $this->discord;
        $code = $this->attributes['setCode'];

        $button = Button::new(Button::STYLE_SECONDARY, "SET_{$code}_{$this->uuid}")
            ->setLabel(substr(isset($this->attributes['setName']) ? "{$code} - {$this->attributes['setName']}" : $code, 0, 80));

        if (! $interaction) {
            return $button->setDisabled(true);
        }

        return $button->setListener(
            fn () => $mtg->sets->fetch($code)->then(
                fn (Set $set) => $interaction->sendFollowUpMessage(MTG::createBuilder()->addComponent($set->toContainer()), true),
                fn () => $interaction->sendFollowUpMessage(MTG::createBuilder()->setContent("Set {$code} was not found."), true),
            ),
            $this->getDiscord(),
            true, // One-time listener
            300 // delete listener after 5 minutes
        );
    }

    /**
     * Gets a button to view the foreign names for the card.
     *
     * @param Interaction $interaction
     *
     * @return Button|null
     *
     * @since 0.7.0
     */
    public function getForeignNamesButton(Interaction $interaction): ?Button
    {
        $names = [];
        foreach ($this->foreignData as $foreign) {
            /** @var ForeignData $foreign */
            if (isset($foreign->language, $foreign->name)) {
                $names[$foreign->language][$foreign->faceName ?? $foreign->name] = true;
            }
        }

        if (! $names) {
            return null;
        }

        return $this->textButton(
            $interaction,
            "FOREIGN_NAMES_{$this->uuid}",
            'Foreign Names',
            implode(PHP_EOL, array_map(fn ($language, $names) => "{$language}: ".implode(', ', array_keys($names)), array_keys($names), $names)),
            'foreign-names.txt'
        );
    }

    /**
     * Gets a button to view the legal formats for the card.
     *
     * @param Interaction $interaction
     *
     * @return Button|null
     *
     * @since 0.6.0
     */
    public function getLegalitiesButton(Interaction $interaction): ?Button
    {
        $formats = [];
        foreach ($this->legalities as $legality) {
            /** @var Legality $legality */
            $formats[$legality->legality][] = ucfirst($legality->format);
        }

        if (! $formats) {
            return null;
        }

        return $this->textButton(
            $interaction,
            "LEGALITIES_{$this->uuid}",
            'Legalities',
            implode(PHP_EOL, array_map(fn ($legality, $formats) => "{$legality}: ".implode(', ', $formats), array_keys($formats), $formats)),
            'legalities.txt'
        );
    }

    /**
     * Builds a button that, when clicked, replies with this card's rulings
     * grouped by date. Returns null when the card has no rulings.
     *
     * @param Interaction $interaction The interaction the button will belong to.
     *
     * @return Button|null
     */
    public function getRulingsButton(Interaction $interaction): ?Button
    {
        $rulings = [];
        foreach ($this->rulings as $ruling) {
            /** @var Ruling $ruling */
            $rulings[$ruling->date][] = $ruling->text;
        }

        if (! $rulings) {
            return null;
        }

        return $this->textButton(
            $interaction,
            "RULINGS_{$this->uuid}",
            'Rulings',
            ltrim(implode(PHP_EOL, array_map(fn ($date, $texts) => PHP_EOL."{$date}:".PHP_EOL.'- '.implode(PHP_EOL.'- ', $texts), array_keys($rulings), $rulings))),
            'rulings.txt'
        );
    }

    /**
     * A button that replies with a block of text — as a file when it is
     * longer than a message can hold.
     *
     * @param Interaction $interaction The interaction to answer.
     * @param string      $id          The button's custom id.
     * @param string      $label       The button's label.
     * @param string      $text        The reply.
     * @param string      $filename    The file name used when the reply is too long.
     *
     * @return Button
     */
    protected function textButton(Interaction $interaction, string $id, string $label, string $text, string $filename): Button
    {
        return Button::new(Button::STYLE_SECONDARY, $id)
            ->setLabel($label)
            ->setListener(
                fn () => $interaction->sendFollowUpMessage(
                    mb_strlen($text) > self::MAX_CONTENT_LENGTH
                        ? MTG::createBuilder()->addFileFromContent($filename, $text)
                        : MTG::createBuilder()->setContent($text),
                    true
                ),
                $this->getDiscord(),
                true, // One-time listener
                300 // delete listener after 5 minutes
            );
    }
}
