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

use MTG\Database\CardQuery;
use MTG\Database\SetQuery;
use PHPUnit\Framework\TestCase;

/**
 * Card and set searches against the real MTGJSON build (downloaded to
 * var/mtgjson on first run). No Discord connection needed.
 *
 * @covers \MTG\Database\Query
 * @covers \MTG\Database\CardQuery
 * @covers \MTG\Database\SetQuery
 * @covers \MTG\Database\Database
 */
final class QueryTest extends TestCase
{
    /**
     * @param array $filters
     *
     * @return array[]
     */
    private function cards(array $filters): array
    {
        return (new CardQuery(database()))->filter($filters)->get();
    }

    public function testExactNameMatchRanksFirst(): void
    {
        $this->assertSame('Black Lotus', $this->cards(['name' => 'Black Lotus'])[0]['name']);
        $this->assertSame('Black Lotus', $this->cards(['name' => '"black lotus"'])[0]['name']);
    }

    public function testNameAlternativesAndPageSize(): void
    {
        // An exact name matches a face too (e.g. reversible "Jace Beleren // Jace Beleren").
        $names = array_map(fn (array $card) => $card['faceName'] ?? $card['name'], $this->cards(['name' => '"Jace Beleren"|"Ajani Goldmane"', 'pageSize' => 100]));

        $this->assertContains('Jace Beleren', $names);
        $this->assertContains('Ajani Goldmane', $names);
        $this->assertEmpty(array_diff(array_unique($names), ['Jace Beleren', 'Ajani Goldmane']));
    }

    public function testRowsAreDecoded(): void
    {
        $card = $this->cards(['name' => '"Sarkhan, the Dragonspeaker"', 'set' => 'ktk'])[0];

        $this->assertSame('KTK', $card['setCode']);
        $this->assertSame('Khans of Tarkir', $card['setName']);
        $this->assertSame(['R'], $card['colorIdentity']);
        $this->assertSame(['Planeswalker'], $card['types']);
        $this->assertIsArray($card['leadershipSkills']);
        $this->assertTrue($card['leadershipSkills']['oathbreaker']);
        $this->assertEquals(5, $card['manaValue']);
        $this->assertArrayNotHasKey('power', $card, 'Null columns are dropped.');
    }

    public function testListFiltersAndColorRuns(): void
    {
        foreach ($this->cards(['colorIdentity' => 'UR', 'types' => 'Creature', 'cmc' => 'gte7', 'pageSize' => 20]) as $card) {
            $this->assertContains('U', $card['colorIdentity']);
            $this->assertContains('R', $card['colorIdentity']);
            $this->assertContains('Creature', $card['types']);
            $this->assertGreaterThanOrEqual(7, $card['manaValue']);
        }

        foreach ($this->cards(['color_identity' => 'colorless', 'types' => 'Creature', 'pageSize' => 20]) as $card) {
            $this->assertSame([], $card['colorIdentity'] ?? []);
        }

        foreach ($this->cards(['colors' => 'red|blue', 'pageSize' => 20]) as $card) {
            $this->assertNotEmpty(array_intersect(['R', 'U'], $card['colors']));
        }
    }

    public function testLegacyAliases(): void
    {
        $card = $this->cards(['multiverseid' => 386650])[0];
        $this->assertSame('Sarkhan, the Dragonspeaker', $card['name']);

        $this->assertNotEmpty($this->cards(['set' => 'KTK', 'flavor' => 'Khan', 'contains' => 'imageUrl']));
    }

    public function testGameFormatAndLegality(): void
    {
        $this->assertNotEmpty($this->cards(['gameFormat' => 'Vintage', 'legality' => 'Restricted', 'name' => '"Black Lotus"']));
        $this->assertEmpty($this->cards(['gameFormat' => 'vintage', 'legality' => 'Banned|Legal', 'name' => '"Black Lotus"']));
        $this->assertEmpty($this->cards(['gameFormat' => 'Standard', 'name' => '"Black Lotus"']), 'gameFormat alone means Legal.');
        $this->assertNotEmpty($this->cards(['game_format' => 'Pauper Commander', 'legality' => 'Banned']));
    }

    public function testForeignNameSearch(): void
    {
        $this->assertSame('Sarkhan, the Dragonspeaker', $this->cards(['language' => 'German', 'name' => 'Sarkhan Drachensprecher'])[0]['name']);
    }

    public function testInvalidFiltersThrow(): void
    {
        foreach ([['bogus' => 1], ['gameFormat' => 'nope'], ['cmc' => 'abc'], ['pageSize' => 500], ['page' => 0], ['orderBy' => 'name; DROP TABLE cards']] as $filters) {
            try {
                $this->cards($filters);
                $this->fail('No exception for '.json_encode($filters));
            } catch (InvalidArgumentException $e) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testSetSearch(): void
    {
        $sets = (new SetQuery(database()))->filter(['code' => 'ktk|m19'])->get();
        $this->assertSame(['M19', 'KTK'], array_column($sets, 'code'));

        $sets = (new SetQuery(database()))->filter(['name' => 'Khans of Tarkir', 'type' => 'expansion'])->get();
        $this->assertSame('KTK', $sets[0]['code']);
        $this->assertSame('Khans of Tarkir', $sets[0]['block']);
        $this->assertContains('Japanese', $sets[0]['languages']);
    }
}
