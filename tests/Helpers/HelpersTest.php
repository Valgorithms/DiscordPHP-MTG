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

use MTG\Builders\DeckMessageBuilder;
use MTG\Builders\ListMessageBuilder;
use MTG\Helpers\CommandSignature;
use MTG\Helpers\Links;
use MTG\Helpers\SearchCache;
use MTG\Helpers\Text;
use MTG\Modules\About;
use MTG\Modules\Cards;
use PHPUnit\Framework\TestCase;

/**
 * The pure helpers behind the bot's messages and commands.
 *
 * @covers \MTG\Helpers\CommandSignature
 * @covers \MTG\Helpers\Text
 * @covers \MTG\Helpers\SearchCache
 * @covers \MTG\Helpers\Links
 * @covers \MTG\Builders\ListMessageBuilder::pages
 * @covers \MTG\Modules\About::duration
 * @covers \MTG\Modules\Cards::describe
 */
final class HelpersTest extends TestCase
{
    public function testCommandSignatureIgnoresDefaultsAndOrderOfLists(): void
    {
        $built = ['name' => 'card', 'description' => 'Cards.', 'contexts' => [2, 0, 1], 'integration_types' => [1, 0], 'options' => [
            ['type' => 1, 'name' => 'show', 'description' => 'Show.', 'options' => [['type' => 3, 'name' => 'name', 'description' => 'Name.', 'required' => true, 'autocomplete' => true]]],
        ]];
        $registered = ['id' => '1', 'type' => 1, 'application_id' => '2', 'name' => 'card', 'description' => 'Cards.', 'contexts' => [0, 1, 2], 'integration_types' => [0, 1], 'nsfw' => false, 'default_member_permissions' => null, 'options' => [
            ['type' => 1, 'name' => 'show', 'description' => 'Show.', 'required' => false, 'options' => [['type' => 3, 'name' => 'name', 'description' => 'Name.', 'required' => true, 'autocomplete' => true, 'choices' => null]]],
        ]];

        $this->assertTrue(CommandSignature::same($built, $registered));

        $registered['options'][0]['options'][0]['description'] = 'Old.';
        $this->assertFalse(CommandSignature::same($built, $registered), 'A changed description is an update.');
    }

    public function testCardMentions(): void
    {
        $this->assertSame([
            ['name' => 'Lightning Bolt', 'set' => null],
            ['name' => 'Black Lotus', 'set' => 'LEA'],
        ], Text::cardMentions('Play [[Lightning Bolt]] and [[Black Lotus|lea]], not [[lightning bolt]] again.'));

        $this->assertSame([], Text::cardMentions('No [brackets] here, nor [[]].'));
        $this->assertCount(2, Text::cardMentions('[[A]] [[B]] [[C]]', 2));
    }

    public function testText(): void
    {
        $this->assertSame('$0.63', Text::money(0.63, 'USD'));
        $this->assertSame('€1,234.50', Text::money(1234.5, 'EUR'));
        $this->assertSame('1 card', Text::plural(1, 'card'));
        $this->assertSame('2 sorceries', Text::plural(2, 'sorcery', 'sorceries'));
        $this->assertSame('Pauper Commander', Text::format('paupercommander'));
        $this->assertSame('Brand New', Text::format('brand_new'));
        $this->assertSame('abcd…', Text::clip('abcdefgh', 5));
        $this->assertSame('abc', Text::clip('abc', 5));
    }

    public function testSearchCacheKeepsStateUnderAShortId(): void
    {
        $cache = new SearchCache();
        $id = $cache->put(['filters' => ['name' => 'lotus']]);

        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}$/', $id);
        $this->assertSame(['filters' => ['name' => 'lotus']], $cache->get($id));
        $this->assertNull($cache->get('ffffffff'));
        $this->assertLessThanOrEqual(100, strlen("card:page:{$id}:999"), 'Fits a custom id.');
    }

    public function testEdhrecSlug(): void
    {
        $this->assertSame('sarkhan-the-dragonspeaker', Links::slug('Sarkhan, the Dragonspeaker'));
        $this->assertSame('jaces-ingenuity', Links::slug("Jace's Ingenuity"));
        $this->assertSame('lim-dul-the-necromancer', Links::slug('Lim-Dûl the Necromancer'));
    }

    public function testSmallFormatting(): void
    {
        $this->assertSame(1, ListMessageBuilder::pages(0));
        $this->assertSame(3, ListMessageBuilder::pages(21));
        $this->assertSame('3 days, 4 hours', About::duration(3 * 86400 + 4 * 3600 + 59));
        $this->assertSame('0 seconds', About::duration(0));
        $this->assertSame('Every card', Cards::describe(['hidden' => true]));
        $this->assertSame('Cards — type: Elf · set: KTK', Cards::describe(['type' => 'Elf', 'set' => 'KTK', 'order' => 'Name']));
        $this->assertSame([], DeckMessageBuilder::groups([]));
    }
}
