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

use Discord\Parts\Interactions\Command\Command;
use Discord\Parts\Interactions\Command\Option;
use MTG\Helpers\CommandSignature;
use MTG\Modules\About;
use MTG\Modules\Boosters;
use MTG\Modules\Cards;
use MTG\Modules\Decks;
use MTG\Modules\Help;
use MTG\Modules\Lookup;
use MTG\Modules\Module;
use MTG\Modules\Sets;
use PHPUnit\Framework\TestCase;

/**
 * Every module's commands, checked against Discord's rules before Discord
 * would refuse them.
 *
 * @covers \MTG\Modules\Cards::commands
 * @covers \MTG\Modules\Sets::commands
 * @covers \MTG\Modules\Boosters::commands
 * @covers \MTG\Modules\Decks::commands
 * @covers \MTG\Modules\Lookup::commands
 * @covers \MTG\Modules\Help
 * @covers \MTG\Modules\About::commands
 * @covers \MTG\Helpers\CommandSignature
 */
final class ModulesTest extends TestCase
{
    /**
     * @return Module[]
     */
    private static function modules(): array
    {
        return [new Cards(), new Sets(), new Boosters(), new Decks(), new Lookup(), new Help(), new About()];
    }

    public function testCommandsFollowDiscordsRules(): void
    {
        $mtg = MTGSingleton::get();
        $names = [];

        foreach (self::modules() as $module) {
            foreach ($module->commands($mtg) as $builder) {
                $command = $builder->jsonSerialize();
                $type = (int) ($command['type'] ?? Command::CHAT_INPUT);
                $key = "{$type}:{$command['name']}";

                $this->assertArrayNotHasKey($key, $names, "{$command['name']} is defined twice.");
                $names[$key] = true;

                if ($type === Command::CHAT_INPUT) {
                    $this->assertMatchesRegularExpression('/^[-_\p{Ll}\p{N}]{1,32}$/u', $command['name']);
                    $this->assertDescription($command['description'] ?? '', $command['name']);
                    $this->assertOptions($command['options'] ?? [], $command['name']);
                } else {
                    $this->assertLessThanOrEqual(32, mb_strlen($command['name']));
                }

                $this->assertLessThanOrEqual(4000, self::characters($command), "{$command['name']} is over Discord's 4000 characters.");
                $this->assertTrue(CommandSignature::same($command, json_decode(json_encode($command), true)), 'A command matches itself.');
            }
        }

        $this->assertArrayHasKey('1:card_search', $names, 'The original command is kept.');
        $this->assertArrayHasKey('3:Find cards', $names);
    }

    public function testTheGuideNamesEveryCommand(): void
    {
        $guide = json_encode(Help::SECTIONS);

        foreach (['/card show', '/card search', '/card random', '/card prices', '/card_search', '/set show', '/set search', '/booster', '/deck show', '/deck search', 'Find cards', '/help', '/about', '/invite', '/ping'] as $command) {
            $this->assertStringContainsString($command, $guide);
        }
    }

    private function assertDescription(string $description, string $where): void
    {
        $this->assertGreaterThanOrEqual(1, mb_strlen($description), "{$where} needs a description.");
        $this->assertLessThanOrEqual(100, mb_strlen($description), "{$where}'s description is too long.");
    }

    private function assertOptions(array $options, string $where): void
    {
        $this->assertLessThanOrEqual(25, count($options), "{$where} has more than 25 options.");

        $optional = false;
        foreach ($options as $option) {
            $option = (array) $option;
            $name = "{$where} {$option['name']}";

            $this->assertMatchesRegularExpression('/^[-_\p{Ll}\p{N}]{1,32}$/u', $option['name']);
            $this->assertDescription($option['description'] ?? '', $name);

            if (in_array($option['type'], [Option::SUB_COMMAND, Option::SUB_COMMAND_GROUP], true)) {
                $this->assertOptions($option['options'] ?? [], $name);
                continue;
            }

            if (! empty($option['required'])) {
                $this->assertFalse($optional, "{$name}: required options must come first.");
            } else {
                $optional = true;
            }

            $choices = (array) ($option['choices'] ?? []);
            $this->assertLessThanOrEqual(25, count($choices));
            $this->assertFalse(! empty($choices) && ! empty($option['autocomplete']), "{$name}: choices or autocomplete, not both.");
        }
    }

    private static function characters(array $command): int
    {
        $count = mb_strlen($command['name'] ?? '') + mb_strlen($command['description'] ?? '');
        foreach ((array) ($command['options'] ?? []) as $option) {
            $count += self::characters((array) $option);
            foreach ((array) (((array) $option)['choices'] ?? []) as $choice) {
                $count += mb_strlen((string) ((array) $choice)['name']) + mb_strlen((string) ((array) $choice)['value']);
            }
        }

        return $count;
    }
}
