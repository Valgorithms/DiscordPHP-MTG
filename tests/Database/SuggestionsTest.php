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

use MTG\Database\Suggestions;
use PHPUnit\Framework\TestCase;

/**
 * Autocomplete answers against the real MTGJSON build.
 *
 * @covers \MTG\Database\Suggestions
 */
final class SuggestionsTest extends TestCase
{
    private static ?Suggestions $suggestions = null;

    private function suggestions(): Suggestions
    {
        return self::$suggestions ??= new Suggestions(database());
    }

    public function testCardNamesRankPrefixMatchesFirstAndStayFast(): void
    {
        $this->suggestions()->cardNames('warm up');

        $started = microtime(true);
        $names = $this->suggestions()->cardNames('lightning bo');
        $this->assertLessThan(0.1, microtime(true) - $started, 'Fast enough for every keystroke.');

        $this->assertSame('Lightning Bolt', $names[0]);
        $this->assertLessThanOrEqual(25, count($this->suggestions()->cardNames('a')));
        $this->assertContains('Black Lotus', $this->suggestions()->cardNames('lotus'));
        $this->assertSame([], $this->suggestions()->cardNames('   '));
    }

    public function testSets(): void
    {
        $this->assertSame('KTK', array_key_first($this->suggestions()->sets('ktk')), 'An exact code comes first.');
        $this->assertSame('Khans of Tarkir (KTK, 2014)', $this->suggestions()->sets('khans')['KTK']);

        foreach (array_keys($this->suggestions()->sets('', true)) as $code) {
            $this->assertTrue($this->suggestions()->set($code)['boosters']);
        }

        $printed = $this->suggestions()->printingSets('Sarkhan, the Dragonspeaker');
        $this->assertArrayHasKey('KTK', $printed);
        $this->assertArrayNotHasKey('LEA', $printed);
        $this->assertContains('expansion', $this->suggestions()->setTypes('exp'));
    }

    public function testKeywordsAndFormats(): void
    {
        $this->assertSame('Flying', $this->suggestions()->keywords('flyi')[0]);
        $this->assertSame(['pauper' => 'Pauper', 'paupercommander' => 'Pauper Commander'], $this->suggestions()->formats('pauper'));
    }
}
