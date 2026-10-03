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

use Discord\Builders\Components\Button;
use Discord\Builders\Components\Container;
use Discord\Helpers\ExCollectionInterface;
use Discord\Parts\Interactions\Interaction;
use MTG\MTG;
use MTG\Parts\Card;
use MTG\Parts\Deck;
use MTG\Parts\Identifiers;
use MTG\Parts\Legality;
use MTG\Parts\Ruling;
use MTG\Parts\Set;

/**
 * Live end-to-end tests against MTGJSON (the API and the local build) through
 * a connected client — network-bound, so they exercise the whole path rather
 * than isolating a unit.
 */
final class MTGTest extends PHPUnit\Framework\TestCase
{
    /**
     * Long enough for a first run to download the MTGJSON build.
     */
    private const DATABASE_TIMEOUT = 300;

    /**
     * @covers \MTG\Repository\CardRepository
     * @covers \MTG\Parts\Card
     */
    public function testCardInfoRetrieval()
    {
        wait(function (MTG $mtg, $resolve) {
            $mtg->cards->getCards(['name' => 'Black Lotus'])->then(function (ExCollectionInterface $cards) {
                /** @var Card $card */
                $card = $cards->first();

                $this->assertInstanceOf(Card::class, $card);
                $this->assertSame('Black Lotus', $card->name);
                $this->assertInstanceOf(Identifiers::class, $card->identifiers);
                $this->assertStringStartsWith('https://cards.scryfall.io/', $card->image_url);
                $this->assertInstanceOf(Legality::class, $card->legalities->get('format', 'vintage'));
                $this->assertSame('Restricted', $card->legalities->get('format', 'vintage')->legality);
                $this->assertNotNull($card->toContainer());
            })->then($resolve, $resolve);
        }, self::DATABASE_TIMEOUT);
    }

    /**
     * @covers \MTG\Parts\Card::toContainer
     * @covers \MTG\Parts\Card::getJsonButton
     * @covers \MTG\Parts\Card::getViewImageButton
     * @covers \MTG\Parts\Card::getSetButton
     * @covers \MTG\Parts\Card::getLegalitiesButton
     * @covers \MTG\Parts\Card::getRulingsButton
     * @covers \MTG\Parts\Card::getForeignNamesButton
     */
    public function testCardRendersForDiscord()
    {
        wait(function (MTG $mtg, $resolve) {
            $mtg->cards->getCards(['name' => '"Sarkhan, the Dragonspeaker"', 'set' => 'KTK'])->then(function (ExCollectionInterface $cards) use ($mtg) {
                /** @var Card $card */
                $card = $cards->first();
                $interaction = $mtg->getFactory()->part(Interaction::class, ['id' => '1', 'token' => 'test']);

                $this->assertInstanceOf(Container::class, $card->toContainer($interaction));
                foreach ([
                    $card->getJsonButton($interaction),
                    $card->getViewImageButton($interaction),
                    $card->getSetButton($interaction),
                    $card->getLegalitiesButton($interaction),
                    $card->getRulingsButton($interaction),
                    $card->getForeignNamesButton($interaction),
                ] as $button) {
                    $this->assertInstanceOf(Button::class, $button);
                }

                $json = json_decode(json_encode($card), true);
                $this->assertSame('Sarkhan, the Dragonspeaker', $json['name']);
                $this->assertSame($card->image_url, $json['image_url']);
                $this->assertSame('Legal', $json['legalities']['commander']['legality']);
                $this->assertSame('c58064fd-4d8b-4f54-812f-0bb1d7e2ddc2', $json['identifiers']['scryfallId']);
            })->then($resolve, $resolve);
        }, self::DATABASE_TIMEOUT);
    }

    /**
     * @covers \MTG\Repository\CardRepository::fetch
     * @covers \MTG\Repository\DatabaseRepositoryTrait
     */
    public function testCardFetchByUuid()
    {
        wait(function (MTG $mtg, $resolve) {
            $mtg->cards->fetch('1eb3ada8-f422-5524-bf92-8463cddd8051', true)->then(function (Card $card) {
                $this->assertSame('Sarkhan, the Dragonspeaker', $card->name);
                $this->assertSame('Khans of Tarkir', $card->setName);
                $this->assertInstanceOf(Ruling::class, $card->rulings->first());
                $this->assertGreaterThan(0, count($card->foreignData));
                $this->assertTrue($card->leadershipSkills->oathbreaker);
            })->then($resolve, $resolve);
        }, self::DATABASE_TIMEOUT);
    }

    /**
     * @covers \MTG\Repository\SetRepository
     * @covers \MTG\Parts\Set
     */
    public function testSetLookupByCode()
    {
        wait(function (MTG $mtg, $resolve) {
            \React\Promise\all([
                $mtg->sets->getSets(['name' => 'Khans of Tarkir', 'type' => 'expansion']),
                $mtg->sets->fetch('ktk', true),
            ])->then(function (array $results) {
                [$sets, $set] = $results;

                $this->assertInstanceOf(Set::class, $sets->first());
                $this->assertSame('KTK', $sets->first()->code);
                $this->assertSame('Khans of Tarkir', $set->name);
                $this->assertSame('2014-09-26', $set->releaseDate->format('Y-m-d'));
                $this->assertSame('Les Khans de Tarkir', $set->translations['French'] ?? null);
            })->then($resolve, $resolve);
        }, self::DATABASE_TIMEOUT);
    }

    /**
     * @covers \MTG\Repository\SetRepository::generateBooster
     */
    public function testGenerateBooster()
    {
        wait(function (MTG $mtg, $resolve) {
            $mtg->sets->generateBooster('KTK')->then(function (ExCollectionInterface $pack) {
                $this->assertCount(15, $pack);
                $this->assertInstanceOf(Card::class, $pack->first());
                foreach ($pack as $card) {
                    $this->assertContains($card->setCode, ['KTK'], 'A KTK draft booster only holds KTK cards.');
                }
            })->then($resolve, $resolve);
        }, self::DATABASE_TIMEOUT);
    }

    /**
     * @covers \MTG\Repository\DeckRepository
     * @covers \MTG\Parts\Deck
     */
    public function testDecks()
    {
        wait(function (MTG $mtg, $resolve) {
            $mtg->decks->getDecks(['code' => 'KTK', 'name' => 'Abzan'])
                ->then(function (ExCollectionInterface $decks) use ($mtg) {
                    /** @var Deck $deck */
                    $deck = $decks->first();
                    $this->assertInstanceOf(Deck::class, $deck);
                    $this->assertFalse($deck->hasCards());

                    return $mtg->decks->fetch($deck->fileName);
                })
                ->then(function (Deck $deck) {
                    $this->assertTrue($deck->hasCards());

                    $mainBoard = iterator_to_array($deck->mainBoard);
                    $this->assertNotEmpty($mainBoard);
                    $this->assertContainsOnlyInstancesOf(Card::class, $mainBoard);
                    // Entries carry a count: an intro pack lists ~40 distinct cards for 60 in all.
                    $this->assertGreaterThan(count($mainBoard), array_sum(array_map(fn (Card $card) => $card->count, $mainBoard)));
                    $this->assertNotNull($mainBoard[array_key_first($mainBoard)]->identifiers?->scryfallId);
                })
                ->then($resolve, $resolve);
        }, 30);
    }

    /**
     * DiscordPHP's own client part re-installs itself when its application
     * loads; that must not drop the MTG repositories.
     *
     * @covers \MTG\MTG::setClient
     */
    public function testPlainDiscordClientCannotReplaceTheMtgClient()
    {
        $mtg = MTGSingleton::get();

        $mtg->setClient($mtg->getFactory()->part(\Discord\Parts\User\Client::class, [], true));

        $this->assertInstanceOf(\MTG\Repository\CardRepository::class, $mtg->cards);
        $this->assertInstanceOf(\MTG\Repository\SetRepository::class, $mtg->sets);
    }

    /**
     * @covers \MTG\MTG::getTypes
     * @covers \MTG\MTG::getSubtypes
     * @covers \MTG\MTG::getSupertypes
     * @covers \MTG\MTG::getFormats
     * @covers \MTG\MTG::getKeywords
     * @covers \MTG\MTG::getMeta
     */
    public function testReferenceListsAreNonEmpty()
    {
        wait(function (MTG $mtg, $resolve) {
            \React\Promise\all([
                $mtg->getTypes(),
                $mtg->getSubtypes(),
                $mtg->getSupertypes(),
                $mtg->getFormats(),
                $mtg->getKeywords(),
                $mtg->getMeta(),
            ])->then(function (array $lists) {
                foreach ($lists as $list) {
                    $this->assertIsArray($list);
                    $this->assertNotEmpty($list);
                }
                [$types, $subtypes, $supertypes, $formats, $keywords, $meta] = $lists;
                $this->assertContains('Creature', $types);
                $this->assertContains('Elf', $subtypes);
                $this->assertContains('Legendary', $supertypes);
                $this->assertContains('commander', $formats);
                $this->assertContains('Flying', $keywords['keywordAbilities']);
                $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+/', $meta['version']);
            })->then($resolve, $resolve);
        }, self::DATABASE_TIMEOUT);
    }
}
