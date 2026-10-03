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

use Discord\Builders\MessageBuilder;
use Discord\Helpers\ExCollectionInterface;
use MTG\Builders\BoosterMessageBuilder;
use MTG\Builders\CardMessageBuilder;
use MTG\Builders\DeckMessageBuilder;
use MTG\Builders\SetMessageBuilder;
use MTG\Modules\Cards;
use MTG\Modules\Help;
use MTG\MTG;
use MTG\Parts\Card;
use MTG\Parts\Deck;
use PHPUnit\Framework\TestCase;

/**
 * Every message the bot draws, from real data, inside Discord's limits: at
 * most 40 components, 4000 characters of text, custom ids of 100 characters
 * that are unique in their message, 25 options to a picker.
 *
 * @covers \MTG\Builders\CardMessageBuilder
 * @covers \MTG\Builders\ListMessageBuilder
 * @covers \MTG\Builders\SetMessageBuilder
 * @covers \MTG\Builders\BoosterMessageBuilder
 * @covers \MTG\Builders\DeckMessageBuilder
 * @covers \MTG\Modules\Cards::page
 * @covers \MTG\Modules\Help::guide
 */
final class BuildersTest extends TestCase
{
    private const TIMEOUT = 300;

    /**
     * Opens the price build when there is one on disk, so cards carry prices.
     */
    public static function setUpBeforeClass(): void
    {
        $prices = MTGSingleton::get()->getPriceDatabase();
        if ($prices !== null && is_file($prices->getPath())) {
            $prices->ready()->then(null, fn () => null);
        }
    }

    public function testCardViewsAndPanelsFitForEveryKindOfCard(): void
    {
        wait(function (MTG $mtg, $resolve) {
            \React\Promise\all([
                $mtg->cards->getCards(['random' => true, 'pageSize' => 100]),
                $mtg->cards->getCards(['layout' => 'split|flip|transform|modal_dfc|meld|adventure|planar|scheme|vanguard|reversible_card|saga|class|case|prototype|leveler|mutate|aftermath|prepare', 'random' => true, 'pageSize' => 100]),
                // Long rules text, many printings, many rulings.
                $mtg->cards->getCards(['name' => '"Lightning Bolt"|"Sol Ring"|"Urza, Lord High Artificer"|"Garth One-Eye"|"Hidetsugu Consumes All"|"Asmoranomardicadaistinaculdacar"', 'unique' => true, 'pageSize' => 10]),
            ])->then(function (array $batches) use ($mtg) {
                $views = [];
                foreach ($batches as $cards) {
                    foreach ($cards as $card) {
                        $views[] = Cards::view($mtg, $card, 'page.abcdef12.9')->then(function (MessageBuilder $view) use ($card) {
                            $this->assertFits($view, "card {$card->name} ({$card->setCode})");

                            foreach ([
                                CardMessageBuilder::rulings($card),
                                CardMessageBuilder::legalities($card),
                                CardMessageBuilder::prices($card),
                                CardMessageBuilder::foreignNames($card),
                                CardMessageBuilder::image($card),
                            ] as $panel) {
                                $this->assertFits($panel, "a panel of {$card->name}");
                            }
                        });
                    }
                }

                $priced = array_filter([...$batches[0]], fn (Card $card) => $card->getPrice() !== null);
                if (MTGSingleton::get()->getPriceDatabase()?->isOpen()) {
                    $this->assertNotEmpty($priced, 'Cards carry today\'s prices.');
                    $this->assertStringContainsString('💲', CardMessageBuilder::priceSummary(reset($priced)));
                }

                return \React\Promise\all($views)->then(fn () => $this->assertGreaterThan(150, count($views)));
            })->then($resolve, $resolve);
        }, self::TIMEOUT);
    }

    public function testSearchPagesFit(): void
    {
        wait(function (MTG $mtg, $resolve) {
            $search = $mtg->getSearchCache()->put(['filters' => ['types' => 'Creature', 'colorIdentity' => 'G', 'unique' => true], 'title' => 'Green creatures']);

            \React\Promise\all([Cards::page($mtg, $search, 1), Cards::page($mtg, $search, 3), Cards::page($mtg, 'expired0', 1)])->then(function (array $pages) {
                [$first, $third, $expired] = $pages;
                $this->assertFits($first, 'page 1');
                $this->assertFits($third, 'page 3');
                $this->assertStringContainsString('page 3 of', json_encode($third));
                $this->assertStringContainsString('expired', json_encode($expired));
            })->then($resolve, $resolve);
        }, self::TIMEOUT);
    }

    public function testSetsBoostersAndDecksFit(): void
    {
        wait(function (MTG $mtg, $resolve) {
            $codes = array_keys($mtg->getSuggestions()->sets('', true, 12));

            $work = [];
            foreach ($codes as $code) {
                $work[] = \React\Promise\all([$mtg->sets->fetch($code), $mtg->sets->getBoosterTypes($code), $mtg->sets->countDecks($code)])
                    ->then(fn (array $set) => $this->assertFits(SetMessageBuilder::set(...$set), "set {$code}"));
                $work[] = $mtg->sets->generateBooster($code)->then(function (ExCollectionInterface $pack) use ($mtg, $code) {
                    $cards = array_values(iterator_to_array($pack));
                    $this->assertNotEmpty($cards, "{$code} opens a pack.");
                    $this->assertFits(BoosterMessageBuilder::pack($mtg, $code, $code, 'play', $cards, 'abcdef12'), "booster {$code}");
                    $this->assertFits(BoosterMessageBuilder::images($code, $cards), "booster images {$code}");
                });
            }

            $work[] = $mtg->decks->getDecks(['type' => 'Commander Deck|Intro Pack'])->then(function (ExCollectionInterface $decks) use ($mtg) {
                $picks = array_slice(array_values(iterator_to_array($decks)), 0, 4);

                return \React\Promise\all(array_map(fn (Deck $deck) => $mtg->decks->fetch((string) $deck->fileName)->then(function (Deck $deck) {
                    $this->assertFits(DeckMessageBuilder::deck($deck), "deck {$deck->name}");
                    $export = DeckMessageBuilder::export($deck);
                    $this->assertStringContainsString("\nDeck\n", "\n{$export}");
                    $this->assertMatchesRegularExpression('/^\d+ .+ \([A-Z0-9]+\)/m', $export);
                }), $picks));
            });

            \React\Promise\all($work)->then($resolve, $resolve);
        }, self::TIMEOUT);
    }

    public function testTheGuideFits(): void
    {
        $this->assertFits(Help::guide(), 'the guide');
    }

    /**
     * Asserts a message is inside Discord's Components V2 limits.
     */
    private function assertFits(MessageBuilder $message, string $what): void
    {
        $json = json_decode(json_encode($message), true);
        $found = ['text' => 0, 'ids' => [], 'problems' => []];
        self::walk($json['components'] ?? [], $found);

        $this->assertLessThan(40, $message->countTotalComponents(), "{$what}: too many components.");
        $this->assertLessThanOrEqual(4000, $found['text'], "{$what}: too much text.");
        $this->assertSame(array_unique($found['ids']), $found['ids'], "{$what}: custom ids repeat.");
        $this->assertSame([], $found['problems'], "{$what}: ".implode('; ', $found['problems']));
    }

    private static function walk(array $components, array &$found): void
    {
        foreach ($components as $component) {
            if (! is_array($component)) {
                continue;
            }

            if (($component['type'] ?? null) === 10) {
                $found['text'] += mb_strlen((string) ($component['content'] ?? ''));
            }

            if (isset($component['custom_id'])) {
                $found['ids'][] = $component['custom_id'];
                if (strlen($component['custom_id']) > 100) {
                    $found['problems'][] = "custom id {$component['custom_id']} is too long";
                }
            }

            if (isset($component['label']) && mb_strlen((string) $component['label']) > 80) {
                $found['problems'][] = "label {$component['label']} is too long";
            }

            if (isset($component['options'])) {
                if (count($component['options']) > 25) {
                    $found['problems'][] = 'a picker has more than 25 options';
                }
                foreach ($component['options'] as $option) {
                    if (mb_strlen((string) $option['label']) > 100 || strlen((string) $option['value']) > 100 || mb_strlen((string) ($option['description'] ?? '')) > 100) {
                        $found['problems'][] = "option {$option['label']} is too long";
                    }
                }
            }

            foreach (['components', 'accessory'] as $key) {
                if (isset($component[$key])) {
                    self::walk($key === 'accessory' ? [$component[$key]] : $component[$key], $found);
                }
            }
        }
    }
}
