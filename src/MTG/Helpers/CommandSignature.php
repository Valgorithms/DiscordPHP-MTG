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

namespace MTG\Helpers;

/**
 * The parts of an application command that matter to a user, in one
 * canonical shape — so a command built in code can be compared with the one
 * Discord has registered, and re-registered only when it changed.
 *
 * Both sides are plain arrays: a `CommandBuilder::jsonSerialize()`, or a
 * registered `Command` part round-tripped through JSON. Defaults are filled in
 * (an option is not required unless it says so), localizations are ignored.
 *
 * @see \MTG\MTG::defineCommand()
 *
 * @since 1.1.0
 */
final class CommandSignature
{
    /**
     * The canonical signature of a command.
     *
     * @param array $command
     *
     * @return array
     */
    public static function of(array $command): array
    {
        return [
            'type' => (int) ($command['type'] ?? 1),
            'name' => (string) ($command['name'] ?? ''),
            'description' => (string) ($command['description'] ?? ''),
            'options' => self::options((array) ($command['options'] ?? [])),
            'contexts' => self::sorted($command['contexts'] ?? null),
            'integration_types' => self::sorted($command['integration_types'] ?? null),
            'nsfw' => (bool) ($command['nsfw'] ?? false),
            'default_member_permissions' => isset($command['default_member_permissions']) ? (string) $command['default_member_permissions'] : null,
        ];
    }

    /**
     * Whether two commands are the same to a user.
     *
     * @param array $a
     * @param array $b
     *
     * @return bool
     */
    public static function same(array $a, array $b): bool
    {
        return self::of($a) === self::of($b);
    }

    /**
     * @param array $options
     *
     * @return array
     */
    private static function options(array $options): array
    {
        $canonical = [];

        foreach ($options as $option) {
            $option = (array) $option;
            $canonical[] = [
                'type' => (int) ($option['type'] ?? 0),
                'name' => (string) ($option['name'] ?? ''),
                'description' => (string) ($option['description'] ?? ''),
                'required' => (bool) ($option['required'] ?? false),
                'autocomplete' => (bool) ($option['autocomplete'] ?? false),
                'choices' => array_map(
                    fn ($choice) => [(string) (((array) $choice)['name'] ?? ''), (string) (((array) $choice)['value'] ?? '')],
                    array_values((array) ($option['choices'] ?? []))
                ),
                'channel_types' => self::sorted($option['channel_types'] ?? null),
                'min_value' => isset($option['min_value']) ? (float) $option['min_value'] : null,
                'max_value' => isset($option['max_value']) ? (float) $option['max_value'] : null,
                'min_length' => isset($option['min_length']) ? (int) $option['min_length'] : null,
                'max_length' => isset($option['max_length']) ? (int) $option['max_length'] : null,
                'options' => self::options((array) ($option['options'] ?? [])),
            ];
        }

        return $canonical;
    }

    /**
     * A list of numbers, sorted; null and empty are the same.
     *
     * @param mixed $values
     *
     * @return int[]|null
     */
    private static function sorted(mixed $values): ?array
    {
        if ($values === null || $values === []) {
            return null;
        }

        $values = array_map('intval', array_values((array) $values));
        sort($values);

        return $values;
    }
}
