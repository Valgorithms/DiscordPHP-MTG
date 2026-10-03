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

use Discord\Http\Drivers\React;
use MTG\Database\PriceBuilder;
use MTG\Database\PriceDatabase;
use MTG\Http\Http;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use React\EventLoop\Loop;
use React\Http\Browser;

/**
 * The daily price build: flattening MTGJSON's prices, writing the SQLite
 * file the workflow publishes, and reading it back as the bot does.
 *
 * @covers \MTG\Database\PriceBuilder
 * @covers \MTG\Database\PriceDatabase
 */
final class PriceTest extends TestCase
{
    private const DATA = [
        'card-a' => [
            'paper' => [
                'tcgplayer' => ['currency' => 'USD', 'buylist' => [], 'retail' => ['normal' => ['2026-10-02' => 0.5, '2026-10-03' => 0.63], 'foil' => ['2026-10-03' => 2.53]]],
                'cardkingdom' => ['currency' => 'USD', 'buylist' => ['normal' => ['2026-10-03' => 0.1]], 'retail' => ['normal' => ['2026-10-03' => 0.99]]],
                'cardmarket' => ['currency' => 'EUR', 'retail' => ['normal' => ['2026-10-03' => 0.53]]],
            ],
            'mtgo' => ['cardhoarder' => ['currency' => 'USD', 'retail' => ['normal' => ['2026-10-03' => 0.02]]]],
        ],
        'card-b' => ['paper' => ['tcgplayer' => ['currency' => 'USD', 'retail' => ['etched' => ['2026-10-03' => 12.0]]]]],
        'card-c' => ['paper' => ['tcgplayer' => ['currency' => 'USD', 'retail' => []]]],
    ];

    public function testFlattenKeepsTheLatestPricePerColumn(): void
    {
        [$columns, $rows] = PriceBuilder::flatten(self::DATA);

        $this->assertArrayHasKey('paper.tcgplayer.retail.normal', $columns);
        $this->assertSame('EUR', $columns['paper.cardmarket.retail.normal']['currency']);
        $this->assertSame(0.63, $rows['card-a']['paper.tcgplayer.retail.normal'], 'The newest date wins.');
        $this->assertSame(0.1, $rows['card-a']['paper.cardkingdom.buylist.normal']);
        $this->assertArrayNotHasKey('card-c', $rows, 'A card without prices has no row.');
        $this->assertSame(array_keys($columns), (function () use ($columns) {
            $keys = array_keys($columns);
            sort($keys);

            return $keys;
        })());
    }

    public function testTheBuildReadsBackNested(): void
    {
        $directory = sys_get_temp_dir().'/mtg-price-test-'.bin2hex(random_bytes(4));
        mkdir($directory);
        $path = $directory.'/'.PriceDatabase::FILE;

        try {
            $this->assertSame(2, PriceBuilder::build(self::DATA, ['date' => '2026-10-03', 'version' => '5.3.0+20261003'], $path));

            $loop = Loop::get();
            $logger = new NullLogger();
            $prices = new PriceDatabase($loop, $logger, new Http('', $loop, $logger, new React($loop)), new Browser(null, $loop), $path, 0);
            $prices->ready();

            $this->assertTrue($prices->isOpen());
            $this->assertSame('5.3.0+20261003', $prices->getVersion());

            $read = $prices->prices(['card-a', 'card-b', 'missing']);
            $this->assertSame(['card-a', 'card-b'], array_keys($read));
            $this->assertSame(0.63, $read['card-a']['paper']['tcgplayer']['retail']['normal']);
            $this->assertSame(2.53, $read['card-a']['paper']['tcgplayer']['retail']['foil']);
            $this->assertSame('EUR', $read['card-a']['paper']['cardmarket']['currency']);
            $this->assertSame(0.02, $read['card-a']['mtgo']['cardhoarder']['retail']['normal']);
            $this->assertSame(12.0, $read['card-b']['paper']['tcgplayer']['retail']['etched']);

            $prices->close();
        } finally {
            @unlink($path);
            @rmdir($directory);
        }
    }
}
