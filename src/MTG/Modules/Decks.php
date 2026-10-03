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
use Discord\Parts\Channel\Message\AllowedMentions;
use Discord\Parts\Interactions\Command\Option;
use Discord\Parts\Interactions\Interaction;
use Discord\WebSockets\Event;
use MTG\Builders\CardMessageBuilder;
use MTG\Builders\DeckMessageBuilder;
use MTG\Builders\ListMessageBuilder;
use MTG\MTG;
use MTG\Parts\Card;
use MTG\Parts\Deck;
use React\Promise\PromiseInterface;

use function React\Promise\resolve;

/**
 * Preconstructed decks: `/deck show` and `/deck search`, over MTGJSON's deck
 * list and deck files.
 *
 * A deck answers with its commander and its cards grouped by type, a picker
 * to look at any card, and **Export decklist** for a text file MTG Arena and
 * deck sites import. The set view's **Decks** button lists a set's decks
 * (`deck:set:<code>`).
 *
 * @since 1.1.0
 */
final class Decks implements Module
{
    use InteractionTrait;

    /**
     * @inheritDoc
     */
    public function name(): string
    {
        return 'decks';
    }

    /**
     * @inheritDoc
     */
    public function commands(MTG $mtg): array
    {
        return [self::command('deck', 'Look up preconstructed Magic: The Gathering decks.')
            ->addOption(self::subcommand(
                $mtg,
                'show',
                'Show a deck and its cards.',
                self::option($mtg, Option::STRING, 'deck', 'The deck\'s name.', true, true),
                self::hidden($mtg),
            ))
            ->addOption(self::subcommand(
                $mtg,
                'search',
                'Search for decks.',
                self::option($mtg, Option::STRING, 'name', 'Part of the name.'),
                self::option($mtg, Option::STRING, 'set', 'Set code or name.', false, true),
                self::option($mtg, Option::STRING, 'type', 'Commander Deck, Intro Pack, Challenger Deck, …', false, true),
                self::hidden($mtg),
            ))];
    }

    /**
     * @inheritDoc
     */
    public function boot(MTG $mtg): void
    {
        $suggest = function (Interaction $interaction, $option) use ($mtg) {
            $typed = (string) ($option->value ?? '');

            if (($option->name ?? '') === 'set') {
                return self::choices($mtg->getSuggestions()->sets($typed));
            }

            // The deck list comes over HTTP (cached for a day): answer when it arrives.
            $mtg->decks->getDecks([])->then(function (ExCollectionInterface $decks) use ($interaction, $option, $typed) {
                $choices = [];
                $needle = mb_strtolower(trim($typed));

                foreach ($decks as $deck) {
                    /** @var Deck $deck */
                    if (($option->name ?? '') === 'type') {
                        if ($needle === '' || str_contains(mb_strtolower((string) $deck->type), $needle)) {
                            $choices[(string) $deck->type] = (string) $deck->type;
                        }
                    } elseif ($needle === '' || str_contains(mb_strtolower((string) $deck->name), $needle)) {
                        $choices[(string) $deck->fileName] = "{$deck->name} ({$deck->code} · {$deck->type})";
                    }

                    if (count($choices) >= 25) {
                        break;
                    }
                }

                return $interaction->autoCompleteResult(self::choices($choices));
            }, fn () => $interaction->autoCompleteResult([]));

            return null;
        };

        $mtg->listenCommand(['deck', 'show'], fn (Interaction $i, $options) => $this->show($mtg, $i, self::values($options)), $suggest);
        $mtg->listenCommand(['deck', 'search'], fn (Interaction $i, $options) => $this->search($mtg, $i, self::values($options)), $suggest);

        $mtg->on(Event::INTERACTION_CREATE, function (Interaction $interaction) use ($mtg): void {
            if ($interaction->type !== Interaction::TYPE_MESSAGE_COMPONENT) {
                return;
            }

            $parts = explode(':', (string) ($interaction->data->custom_id ?? ''), 3);
            if ($parts[0] !== DeckMessageBuilder::PREFIX) {
                return;
            }

            $selected = (string) ($interaction->data->values[0] ?? '');
            [$search, $page] = array_pad(explode(':', $parts[2] ?? ''), 2, '1');

            match ($parts[1] ?? '') {
                'export' => self::answer($mtg, $interaction, fn () => $mtg->decks->fetch($parts[2] ?? '')->then(fn (Deck $deck) => self::export($deck))),
                'card' => self::answer($mtg, $interaction, fn () => $mtg->cards->fetch($selected)->then(fn (Card $card) => Cards::view($mtg, $card))),
                'set' => self::answer($mtg, $interaction, fn () => self::list($mtg, ['code' => $parts[2] ?? ''], 'Decks — '.($mtg->getSuggestions()->set($parts[2] ?? '')['name'] ?? ($parts[2] ?? '')))),
                'page' => self::answer($mtg, $interaction, fn () => self::page($mtg, $search, (int) $page), true),
                'open' => self::answer($mtg, $interaction, fn () => self::view($mtg, $selected), true),
                default => null,
            };
        });
    }

    /**
     * `/deck show`.
     *
     * @param MTG         $mtg
     * @param Interaction $interaction
     * @param array       $args
     *
     * @return PromiseInterface
     */
    private function show(MTG $mtg, Interaction $interaction, array $args): PromiseInterface
    {
        $typed = trim((string) ($args['deck'] ?? ''));

        return self::reply($mtg, $interaction, (bool) ($args['hidden'] ?? false), fn () => $mtg->decks->getDecks([])->then(function (ExCollectionInterface $decks) use ($mtg, $typed) {
            // Autocomplete gives a file name; otherwise take the best name match.
            $deck = $decks->get('fileName', $typed) ?? $decks->find(fn (Deck $deck) => strcasecmp((string) $deck->name, $typed) === 0)
                ?? $decks->find(fn (Deck $deck) => str_contains(mb_strtolower((string) $deck->name), mb_strtolower($typed)));

            return $deck ? self::view($mtg, (string) $deck->fileName) : CardMessageBuilder::notice("No deck is called **{$typed}**.");
        }));
    }

    /**
     * `/deck search`.
     *
     * @param MTG         $mtg
     * @param Interaction $interaction
     * @param array       $args
     *
     * @return PromiseInterface
     */
    private function search(MTG $mtg, Interaction $interaction, array $args): PromiseInterface
    {
        return self::reply($mtg, $interaction, (bool) ($args['hidden'] ?? false), fn () => $mtg->getDatabase()->ready()->then(function () use ($mtg, $args) {
            $filters = array_filter(['name' => $args['name'] ?? null, 'type' => $args['type'] ?? null], fn ($value) => $value !== null && $value !== '');

            // A set code, or every set whose name matches.
            if (($set = trim((string) ($args['set'] ?? ''))) !== '') {
                $matches = $mtg->getSuggestions()->set($set) !== null ? [strtoupper($set) => true] : $mtg->getSuggestions()->sets($set);
                $filters['code'] = implode('|', array_keys($matches)) ?: $set;
            }

            $title = $filters ? 'Decks — '.implode(' · ', array_map(fn ($key, $value) => "{$key}: {$value}", array_keys($filters), $filters)) : 'Every deck';

            return self::list($mtg, $filters, $title);
        }));
    }

    /**
     * Starts a deck search and shows its first page.
     *
     * @param MTG    $mtg
     * @param array  $filters
     * @param string $title
     *
     * @return PromiseInterface<MessageBuilder>
     */
    private static function list(MTG $mtg, array $filters, string $title): PromiseInterface
    {
        return self::page($mtg, $mtg->getSearchCache()->put(['filters' => $filters, 'title' => $title]), 1);
    }

    /**
     * A page of a deck search.
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

        return $mtg->decks->getDecks($state['filters'])->then(function (ExCollectionInterface $decks) use ($search, $page, $state) {
            $all = array_values(iterator_to_array($decks));
            $total = count($all);

            if ($total === 0) {
                return CardMessageBuilder::notice("### {$state['title']}\nNo decks match.");
            }

            $page = min(max(1, $page), ListMessageBuilder::pages($total));
            $lines = [];
            $choices = [];
            foreach (array_slice($all, ($page - 1) * ListMessageBuilder::PAGE_SIZE, ListMessageBuilder::PAGE_SIZE) as $deck) {
                /** @var Deck $deck */
                $lines[] = DeckMessageBuilder::line($deck);
                $choices[] = ['label' => $deck->name, 'value' => $deck->fileName, 'description' => "{$deck->type} · {$deck->code}"];
            }

            return ListMessageBuilder::page(DeckMessageBuilder::PREFIX, $search, $page, $total, $state['title'], $lines, $choices, DeckMessageBuilder::ACCENT, 'deck');
        });
    }

    /**
     * A deck's view.
     *
     * @param MTG    $mtg
     * @param string $fileName
     *
     * @return PromiseInterface<MessageBuilder>
     */
    private static function view(MTG $mtg, string $fileName): PromiseInterface
    {
        return $mtg->decks->fetch($fileName)->then(fn (Deck $deck) => DeckMessageBuilder::deck($deck));
    }

    /**
     * The decklist as a text file (a plain message: files and Components V2
     * pair badly).
     *
     * @param Deck $deck
     *
     * @return MessageBuilder
     */
    private static function export(Deck $deck): MessageBuilder
    {
        return MessageBuilder::new()
            ->setAllowedMentions(AllowedMentions::none())
            ->setContent("**{$deck->name}** — paste into MTG Arena, Moxfield or Archidekt.")
            ->addFileFromContent("{$deck->fileName}.txt", DeckMessageBuilder::export($deck));
    }
}
