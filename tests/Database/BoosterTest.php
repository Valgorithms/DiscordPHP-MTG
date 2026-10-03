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

use MTG\Database\Booster;
use PHPUnit\Framework\TestCase;

/**
 * Booster sheet picking (pure), and opening real packs from the MTGJSON
 * build.
 *
 * @covers \MTG\Database\Booster
 */
final class BoosterTest extends TestCase
{
    public function testPicksDoNotRepeatWhileTheSheetLasts(): void
    {
        $sheet = [];
        foreach (range(1, 20) as $i) {
            $sheet["card-{$i}"] = ['weight' => $i, 'colors' => ''];
        }

        $picked = Booster::fill($sheet, 10);

        $this->assertCount(10, $picked);
        $this->assertCount(10, array_unique($picked));
    }

    public function testFixedSheetTakesEveryCardByWeight(): void
    {
        $picked = Booster::fill(['a' => ['weight' => 2, 'colors' => ''], 'b' => ['weight' => 1, 'colors' => '']], 3);

        sort($picked);
        $this->assertSame(['a', 'a', 'b'], $picked);
    }

    public function testBalancedSheetCoversEveryColor(): void
    {
        $sheet = [];
        foreach (Booster::COLORS as $color) {
            foreach (range(1, 10) as $i) {
                $sheet["{$color}-{$i}"] = ['weight' => 1, 'colors' => $color];
            }
        }
        foreach (range(1, 50) as $i) {
            $sheet["colorless-{$i}"] = ['weight' => 100, 'colors' => ''];
        }

        $colors = array_map(fn ($uuid) => explode('-', $uuid)[0], Booster::fill($sheet, 10, true));

        foreach (Booster::COLORS as $color) {
            $this->assertContains($color, $colors);
        }
    }

    public function testOpensARealPack(): void
    {
        $booster = new Booster(database());

        $types = $booster->types('KTK');
        $this->assertContains('draft', $types);
        $this->assertSame('draft', $types[0], 'Draft beats arena and prerelease boosters.');

        $pack = $booster->open('KTK', 'draft');
        $this->assertCount(15, $pack);

        $uuids = array_column($pack, 'uuid');
        $found = database()->select('SELECT COUNT(DISTINCT "uuid") AS "n" FROM "cards" WHERE "uuid" IN ('.implode(', ', array_fill(0, count($uuids), '?')).')', $uuids);
        $this->assertSame(count(array_unique($uuids)), (int) $found[0]['n'], 'Every pick is a real card.');
    }

    public function testUnknownBoosterThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new Booster(database()))->open('KTK', 'nope');
    }
}
