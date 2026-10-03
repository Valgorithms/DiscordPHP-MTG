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

namespace MTG\Modules;

use Discord\Builders\CommandBuilder;
use Discord\Builders\MessageBuilder;
use Discord\Parts\Interactions\Command\Command;
use Discord\Parts\Interactions\Command\Option;
use Discord\Parts\Interactions\Interaction;
use Discord\Parts\OAuth\Application;
use MTG\Builders\CardMessageBuilder;
use MTG\Helpers\Text;
use MTG\MTG;
use React\Promise\PromiseInterface;

use function React\Promise\resolve;

/**
 * What every module does with interactions: build its commands for every
 * place the bot can be used, read option values, answer autocomplete, and
 * reply after deferring — so a search that waits on the card build (or
 * fails) still answers.
 *
 * @since 1.1.0
 */
trait InteractionTrait
{
    /**
     * A chat command usable in servers, the bot's DMs and group DMs, from a
     * server install or a user install.
     *
     * @param string $name
     * @param string $description
     *
     * @return CommandBuilder
     */
    protected static function command(string $name, string $description): CommandBuilder
    {
        return CommandBuilder::new()
            ->setType(Command::CHAT_INPUT)
            ->setName($name)
            ->setDescription($description)
            ->setContext([Interaction::CONTEXT_TYPE_GUILD, Interaction::CONTEXT_TYPE_BOT_DM, Interaction::CONTEXT_TYPE_PRIVATE_CHANNEL])
            ->addIntegrationType(Application::INTEGRATION_TYPE_GUILD_INSTALL)
            ->addIntegrationType(Application::INTEGRATION_TYPE_USER_INSTALL);
    }

    /**
     * A command option.
     *
     * @param MTG    $mtg
     * @param int    $type         An {@see Option} type constant.
     * @param string $name
     * @param string $description
     * @param bool   $required
     * @param bool   $autocomplete
     *
     * @return Option
     */
    protected static function option(MTG $mtg, int $type, string $name, string $description, bool $required = false, bool $autocomplete = false): Option
    {
        $option = (new Option($mtg))->setType($type)->setName($name)->setDescription($description)->setRequired($required);

        return $autocomplete ? $option->setAutoComplete(true) : $option;
    }

    /**
     * A sub-command with its options.
     *
     * @param MTG    $mtg
     * @param string $name
     * @param string $description
     * @param Option ...$options
     *
     * @return Option
     */
    protected static function subcommand(MTG $mtg, string $name, string $description, Option ...$options): Option
    {
        $subcommand = self::option($mtg, Option::SUB_COMMAND, $name, $description);
        foreach ($options as $option) {
            $subcommand->addOption($option);
        }

        return $subcommand;
    }

    /**
     * The `hidden` option every command takes: reply only to the caller.
     *
     * @param MTG $mtg
     *
     * @return Option
     */
    protected static function hidden(MTG $mtg): Option
    {
        return self::option($mtg, Option::BOOLEAN, 'hidden', 'Only you see the answer.');
    }

    /**
     * Option values by name.
     *
     * @param iterable $options The options a command handler is given.
     *
     * @return array<string, mixed>
     */
    protected static function values(iterable $options): array
    {
        $values = [];
        foreach ($options as $option) {
            if (isset($option->name) && $option->value !== null) {
                $values[$option->name] = $option->value;
            }
        }

        return $values;
    }

    /**
     * The values typed so far into the command being autocompleted, from
     * inside its sub-command.
     *
     * @param Interaction $interaction
     *
     * @return array<string, mixed>
     */
    protected static function typed(Interaction $interaction): array
    {
        $options = $interaction->data->options ?? [];

        // Walk down through sub-command groups and sub-commands to the options.
        do {
            $nested = null;
            foreach ($options as $option) {
                if (in_array($option->type, [Option::SUB_COMMAND, Option::SUB_COMMAND_GROUP], true)) {
                    $nested = $option->options ?? [];
                    break;
                }
            }
            if ($nested !== null) {
                $options = $nested;
            }
        } while ($nested !== null);

        return self::values($options);
    }

    /**
     * Autocomplete choices from `value => label` (or a list of values).
     *
     * @param array $choices
     *
     * @return array<array{name: string, value: string}>
     */
    protected static function choices(array $choices): array
    {
        $result = [];
        foreach (array_slice($choices, 0, 25, true) as $value => $label) {
            $value = is_int($value) ? $label : $value;
            $result[] = ['name' => Text::clip((string) $label, 100), 'value' => Text::clip((string) $value, 100)];
        }

        return $result;
    }

    /**
     * Defers the reply, then answers with what `$work` resolves to. A failure
     * answers too: a bad search is the caller's to fix, anything else is
     * logged.
     *
     * @param MTG         $mtg
     * @param Interaction $interaction
     * @param bool        $hidden      Whether only the caller sees the answer.
     * @param callable    $work        Returns a MessageBuilder or a promise of one.
     *
     * @return PromiseInterface
     */
    protected static function reply(MTG $mtg, Interaction $interaction, bool $hidden, callable $work): PromiseInterface
    {
        return $interaction->acknowledgeWithResponse($hidden)
            ->then(fn () => resolve($work()))
            ->then(
                fn (MessageBuilder $message) => $interaction->updateOriginalResponse($message),
                fn (\Throwable $e) => $interaction->updateOriginalResponse(self::failure($mtg, $e))
            )
            ->then(null, fn (\Throwable $e) => $mtg->logger->warning('Could not answer an interaction: '.$e->getMessage()));
    }

    /**
     * The message for a failure.
     *
     * @param MTG        $mtg
     * @param \Throwable $e
     *
     * @return MessageBuilder
     */
    protected static function failure(MTG $mtg, \Throwable $e): MessageBuilder
    {
        if ($e instanceof \InvalidArgumentException || $e instanceof \OutOfBoundsException) {
            return CardMessageBuilder::notice('⚠️ '.Text::clip($e->getMessage(), 1000));
        }

        $mtg->logger->warning('Interaction failed: '.$e->getMessage().' ['.$e->getFile().':'.$e->getLine().']');

        return CardMessageBuilder::notice('⚠️ Something went wrong. Please try again in a moment.');
    }

    /**
     * Answers a component click with a new message, or by replacing the
     * clicked message, logging what fails.
     *
     * @param MTG         $mtg
     * @param Interaction $interaction
     * @param callable    $work        Returns a MessageBuilder or a promise of one.
     * @param bool        $update      Replace the clicked message instead of answering with a new, hidden one.
     *
     * @return PromiseInterface
     */
    protected static function answer(MTG $mtg, Interaction $interaction, callable $work, bool $update = false): PromiseInterface
    {
        return resolve(null)
            ->then(fn () => $work())
            ->then(
                fn (MessageBuilder $message) => $update ? $interaction->updateMessage($message) : $interaction->respondWithMessage($message, true),
                fn (\Throwable $e) => $interaction->respondWithMessage(self::failure($mtg, $e), true)
            )
            ->then(null, fn (\Throwable $e) => $mtg->logger->warning('Could not answer a component: '.$e->getMessage()));
    }
}
