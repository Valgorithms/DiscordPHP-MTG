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

use Discord\Builders\MessageBuilder;
use Discord\Helpers\ExCollectionInterface;
use Discord\Parts\Interactions\Command\Option;
use Discord\Parts\Interactions\Interaction;
use Discord\WebSockets\Event;
use MTG\Builders\CardMessageBuilder;
use MTG\Builders\ListMessageBuilder;
use MTG\Builders\SetMessageBuilder;
use MTG\MTG;
use MTG\Parts\Set;
use React\Promise\PromiseInterface;

use function React\Promise\all;
use function React\Promise\resolve;

/**
 * Sets: `/set show` and `/set search`.
 *
 * A set answers with what it is, its size and boosters, and buttons to
 * browse its cards (pages from the Cards module), open a booster (Boosters
 * module) and list its decks (Decks module). The card view's **Set** button
 * lands here too (`set:show:<code>`).
 *
 * @since 1.1.0
 */
final class Sets implements Module
{
    use InteractionTrait;

    /**
     * @inheritDoc
     */
    public function name(): string
    {
        return 'sets';
    }

    /**
     * @inheritDoc
     */
    public function commands(MTG $mtg): array
    {
        return [self::command('set', 'Look up Magic: The Gathering sets.')
            ->addOption(self::subcommand(
                $mtg,
                'show',
                'Show a set.',
                self::option($mtg, Option::STRING, 'set', 'Set code or name.', true, true),
                self::hidden($mtg),
            ))
            ->addOption(self::subcommand(
                $mtg,
                'search',
                'Search for sets.',
                self::option($mtg, Option::STRING, 'name', 'Part of the name.'),
                self::option($mtg, Option::STRING, 'type', 'expansion, core, masters, commander, …', false, true),
                self::option($mtg, Option::STRING, 'block', 'Part of the block name.'),
                self::option($mtg, Option::INTEGER, 'year', 'Released in this year.'),
                self::hidden($mtg),
            ))];
    }

    /**
     * @inheritDoc
     */
    public function boot(MTG $mtg): void
    {
        $suggest = fn (Interaction $interaction, $option) => match ($option->name ?? '') {
            'set' => self::choices($mtg->getSuggestions()->sets((string) $option->value)),
            'type' => self::choices($mtg->getSuggestions()->setTypes((string) $option->value)),
            default => [],
        };

        $mtg->listenCommand(['set', 'show'], fn (Interaction $i, $options) => $this->show($mtg, $i, self::values($options)), $suggest);
        $mtg->listenCommand(['set', 'search'], fn (Interaction $i, $options) => $this->search($mtg, $i, self::values($options)), $suggest);

        $mtg->on(Event::INTERACTION_CREATE, function (Interaction $interaction) use ($mtg): void {
            if ($interaction->type !== Interaction::TYPE_MESSAGE_COMPONENT) {
                return;
            }

            $parts = explode(':', (string) ($interaction->data->custom_id ?? ''));
            if ($parts[0] !== SetMessageBuilder::PREFIX) {
                return;
            }

            match ($parts[1] ?? '') {
                'show' => self::answer($mtg, $interaction, fn () => self::view($mtg, $parts[2] ?? '')),
                'cards' => self::answer($mtg, $interaction, fn () => self::cards($mtg, $parts[2] ?? '')),
                'page' => self::answer($mtg, $interaction, fn () => self::page($mtg, $parts[2] ?? '', (int) ($parts[3] ?? 1)), true),
                'open' => self::answer($mtg, $interaction, fn () => self::view($mtg, (string) ($interaction->data->values[0] ?? '')), true),
                default => null,
            };
        });
    }

    /**
     * `/set show`.
     *
     * @param MTG         $mtg
     * @param Interaction $interaction
     * @param array       $args
     *
     * @return PromiseInterface
     */
    private function show(MTG $mtg, Interaction $interaction, array $args): PromiseInterface
    {
        $typed = trim((string) ($args['set'] ?? ''));

        return self::reply($mtg, $interaction, (bool) ($args['hidden'] ?? false), fn () => $mtg->getDatabase()->ready()->then(function () use ($mtg, $typed) {
            // Autocomplete gives a code; anything else is looked up by name.
            if ($mtg->getSuggestions()->set($typed) !== null) {
                return self::view($mtg, $typed);
            }

            return $mtg->sets->getSets(['name' => $typed, 'pageSize' => 1])->then(
                fn (ExCollectionInterface $sets) => ($set = $sets->first())
                    ? self::view($mtg, (string) $set->code)
                    : CardMessageBuilder::notice("No set is called **{$typed}**.")
            );
        }));
    }

    /**
     * `/set search`.
     *
     * @param MTG         $mtg
     * @param Interaction $interaction
     * @param array       $args
     *
     * @return PromiseInterface
     */
    private function search(MTG $mtg, Interaction $interaction, array $args): PromiseInterface
    {
        $filters = array_filter([
            'name' => $args['name'] ?? null,
            'type' => $args['type'] ?? null,
            'block' => $args['block'] ?? null,
            'year' => $args['year'] ?? null,
        ], fn ($value) => $value !== null && $value !== '');

        $title = $filters ? 'Sets — '.implode(' · ', array_map(fn ($key, $value) => "{$key}: {$value}", array_keys($filters), $filters)) : 'Every set';
        $search = $mtg->getSearchCache()->put(['filters' => $filters, 'title' => $title]);

        return self::reply($mtg, $interaction, (bool) ($args['hidden'] ?? false), fn () => self::page($mtg, $search, 1));
    }

    /**
     * A set's view, with its boosters and deck count.
     *
     * @param MTG    $mtg
     * @param string $code
     *
     * @return PromiseInterface<MessageBuilder>
     */
    public static function view(MTG $mtg, string $code): PromiseInterface
    {
        return all([
            $mtg->sets->fetch(strtoupper($code)),
            $mtg->sets->getBoosterTypes($code),
            $mtg->sets->countDecks($code),
        ])->then(fn (array $results) => SetMessageBuilder::set(...$results));
    }

    /**
     * A page of a set search.
     *
     * @param MTG    $mtg
     * @param string $search
     * @param int    $page
     *
     * @return PromiseInterface<MessageBuilder>
     */
    private static function page(MTG $mtg, string $search, int $page): PromiseInterface
    {
        if (! $state = $mtg->getSearchCache()->get($search)) {
            return resolve(CardMessageBuilder::notice('This search has expired. Run it again.'));
        }

        $page = max(1, $page);

        return all([
            $mtg->sets->countSets($state['filters']),
            $mtg->sets->getSets($state['filters'] + ['page' => $page, 'pageSize' => ListMessageBuilder::PAGE_SIZE]),
        ])->then(function (array $results) use ($search, $page, $state) {
            [$total, $sets] = $results;

            if ($total === 0) {
                return CardMessageBuilder::notice("### {$state['title']}\nNo sets match.");
            }

            $lines = [];
            $choices = [];
            foreach ($sets as $set) {
                /** @var Set $set */
                $row = ['code' => $set->code, 'name' => $set->name, 'type' => $set->type, 'releaseDate' => $set->releaseDate?->format('Y-m-d')];
                $lines[] = SetMessageBuilder::line($row);
                $choices[] = ['label' => "{$set->name} ({$set->code})", 'value' => $set->code, 'description' => trim(ucwords(str_replace('_', ' ', (string) $set->type)).' · '.$set->releaseDate?->format('Y'))];
            }

            return ListMessageBuilder::page(SetMessageBuilder::PREFIX, $search, $page, $total, $state['title'], $lines, $choices, SetMessageBuilder::ACCENT, 'set');
        });
    }

    /**
     * The first page of a set's cards, in collector number order.
     *
     * @param MTG    $mtg
     * @param string $code
     *
     * @return PromiseInterface<MessageBuilder>
     */
    private static function cards(MTG $mtg, string $code): PromiseInterface
    {
        $name = $mtg->getSuggestions()->set($code)['name'] ?? $code;
        $search = $mtg->getSearchCache()->put([
            'filters' => ['set' => strtoupper($code), 'orderBy' => 'number', 'unique' => true],
            'title' => "{$name} ({$code})",
        ]);

        return Cards::page($mtg, $search, 1);
    }
}
