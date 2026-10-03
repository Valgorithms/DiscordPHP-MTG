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
use Discord\Builders\Components\Button;
use Discord\Builders\MessageBuilder;
use Discord\Helpers\ExCollectionInterface;
use Discord\Parts\Interactions\Command\Choice;
use Discord\Parts\Interactions\Command\Option;
use Discord\Parts\Interactions\Interaction;
use Discord\WebSockets\Event;
use MTG\Builders\CardMessageBuilder;
use MTG\Builders\ListMessageBuilder;
use MTG\Helpers\Text;
use MTG\MTG;
use MTG\Parts\Card;
use React\Promise\PromiseInterface;

use function React\Promise\all;
use function React\Promise\resolve;

/**
 * Card lookups: `/card show|search|random|prices`, and the original
 * `/card_search`.
 *
 * A card answers as a Components V2 view (see {@see CardMessageBuilder}) with
 * buttons for its rulings, legality, prices, foreign names, image and JSON, a
 * picker for its other printings, and links to Scryfall, EDHREC and stores. A
 * search answers with pages of cards to open; an opened card keeps a way back
 * to its page. Card and set options autocomplete.
 *
 * Every `card:` component is routed by one listener. Buttons that only need
 * a uuid keep working after a restart; a search's pages expire with the
 * search cache.
 *
 * @since 1.1.0
 */
final class Cards implements Module
{
    use InteractionTrait;

    /**
     * Sort orders `/card search` offers: label → `orderBy`.
     *
     * @var array<string, string>
     */
    public const ORDERS = [
        'Name' => 'name',
        'Mana value' => 'manaValue',
        'Newest' => '-releaseDate',
        'Oldest' => 'releaseDate',
        'Popularity (EDHREC)' => 'edhrecRank',
    ];

    /**
     * Rarities `/card search` and `/card random` offer.
     *
     * @var string[]
     */
    public const RARITIES = ['common', 'uncommon', 'rare', 'mythic', 'special', 'bonus'];

    /**
     * @inheritDoc
     */
    public function name(): string
    {
        return 'cards';
    }

    /**
     * @inheritDoc
     */
    public function commands(MTG $mtg): array
    {
        return [$this->definition($mtg), $this->legacyDefinition($mtg)];
    }

    /**
     * @inheritDoc
     */
    public function boot(MTG $mtg): void
    {
        // The application's emoji hold the mana and color symbols cards are drawn with.
        $mtg->emojis->freshen()->then(null, fn (\Throwable $e) => $mtg->logger->warning('Could not load the application emoji: '.$e->getMessage()));

        $suggest = fn (Interaction $interaction, $option) => $this->suggest($mtg, $interaction, $option);

        $mtg->listenCommand(['card', 'show'], fn (Interaction $i, $options) => $this->show($mtg, $i, self::values($options)), $suggest);
        $mtg->listenCommand(['card', 'search'], fn (Interaction $i, $options) => $this->search($mtg, $i, self::values($options)), $suggest);
        $mtg->listenCommand(['card', 'random'], fn (Interaction $i, $options) => $this->random($mtg, $i, self::values($options)), $suggest);
        $mtg->listenCommand(['card', 'prices'], fn (Interaction $i, $options) => $this->prices($mtg, $i, self::values($options)), $suggest);
        $mtg->listenCommand('card_search', fn (Interaction $i, $options) => $this->legacy($mtg, $i, self::values($options)));

        $mtg->on(Event::INTERACTION_CREATE, function (Interaction $interaction) use ($mtg): void {
            if ($interaction->type !== Interaction::TYPE_MESSAGE_COMPONENT) {
                return;
            }

            $parts = explode(':', (string) ($interaction->data->custom_id ?? ''));
            if ($parts[0] === CardMessageBuilder::PREFIX) {
                $this->component($mtg, $interaction, $parts);
            }
        });
    }

    // --- commands ---------------------------------------------------------

    /**
     * `/card show`: one card, by name, optionally from one set.
     *
     * @param MTG         $mtg
     * @param Interaction $interaction
     * @param array       $args
     *
     * @return PromiseInterface
     */
    private function show(MTG $mtg, Interaction $interaction, array $args): PromiseInterface
    {
        $name = trim((string) ($args['name'] ?? ''));

        return self::reply($mtg, $interaction, (bool) ($args['hidden'] ?? false), fn () => self::find($mtg, $name, $args['set'] ?? null)->then(
            fn (?Card $card) => $card
                ? self::view($mtg, $card)
                : CardMessageBuilder::notice("No card is called **{$name}**".(isset($args['set']) ? " in `{$args['set']}`" : '').'. Try `/card search`.')
        ));
    }

    /**
     * `/card search`: pages of matching cards, one row per card.
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
            $filters = self::filters($mtg, $args);
            $filters['orderBy'] = self::ORDERS[$args['order'] ?? ''] ?? null;
            $filters['unique'] = true;

            $search = $mtg->getSearchCache()->put([
                'filters' => array_filter($filters, fn ($value) => $value !== null),
                'title' => self::describe($args),
            ]);

            return self::page($mtg, $search, 1);
        }));
    }

    /**
     * `/card random`: a random card, optionally within filters, with a button
     * for another.
     *
     * @param MTG         $mtg
     * @param Interaction $interaction
     * @param array       $args
     *
     * @return PromiseInterface
     */
    private function random(MTG $mtg, Interaction $interaction, array $args): PromiseInterface
    {
        return self::reply($mtg, $interaction, (bool) ($args['hidden'] ?? false), fn () => $mtg->getDatabase()->ready()->then(fn () => self::roll(
            $mtg,
            $mtg->getSearchCache()->put(['filters' => array_filter(self::filters($mtg, $args), fn ($value) => $value !== null), 'title' => 'Random card'])
        )));
    }

    /**
     * `/card prices`: today's prices for a card.
     *
     * @param MTG         $mtg
     * @param Interaction $interaction
     * @param array       $args
     *
     * @return PromiseInterface
     */
    private function prices(MTG $mtg, Interaction $interaction, array $args): PromiseInterface
    {
        $name = trim((string) ($args['name'] ?? ''));

        return self::reply($mtg, $interaction, (bool) ($args['hidden'] ?? false), fn () => self::find($mtg, $name, $args['set'] ?? null)->then(
            fn (?Card $card) => $card ? CardMessageBuilder::prices($card) : CardMessageBuilder::notice("No card is called **{$name}**.")
        ));
    }

    /**
     * `/card_search`, as it always worked: the best match for the old API's
     * filters, privately.
     *
     * @param MTG         $mtg
     * @param Interaction $interaction
     * @param array       $args
     *
     * @return PromiseInterface
     */
    private function legacy(MTG $mtg, Interaction $interaction, array $args): PromiseInterface
    {
        return self::reply($mtg, $interaction, true, fn () => $mtg->cards->getCards($args + ['pageSize' => 1])->then(
            fn (ExCollectionInterface $cards) => ($card = $cards->first())
                ? self::view($mtg, $card)
                : CardMessageBuilder::notice('No card matches that search. `/card search` has more filters.')
        ));
    }

    // --- components -------------------------------------------------------

    /**
     * Routes a click on a card component.
     *
     * @param MTG         $mtg
     * @param Interaction $interaction
     * @param string[]    $parts       The custom id, split on `:`.
     */
    private function component(MTG $mtg, Interaction $interaction, array $parts): void
    {
        $action = $parts[1] ?? '';
        $selected = (string) ($interaction->data->values[0] ?? '');

        match ($action) {
            'rulings' => self::answer($mtg, $interaction, fn () => self::card($mtg, $parts[2] ?? '')->then(fn (Card $card) => CardMessageBuilder::rulings($card))),
            'legal' => self::answer($mtg, $interaction, fn () => self::card($mtg, $parts[2] ?? '')->then(fn (Card $card) => CardMessageBuilder::legalities($card))),
            'prices' => self::answer($mtg, $interaction, fn () => self::card($mtg, $parts[2] ?? '')->then(fn (Card $card) => CardMessageBuilder::prices($card))),
            'foreign' => self::answer($mtg, $interaction, fn () => self::card($mtg, $parts[2] ?? '')->then(fn (Card $card) => CardMessageBuilder::foreignNames($card))),
            'image' => self::answer($mtg, $interaction, fn () => self::card($mtg, $parts[2] ?? '')->then(fn (Card $card) => CardMessageBuilder::image($card))),
            'json' => self::answer($mtg, $interaction, fn () => self::card($mtg, $parts[2] ?? '')->then(fn (Card $card) => CardMessageBuilder::json($card))),
            'flip' => self::answer($mtg, $interaction, fn () => self::flip($mtg, $parts[2] ?? '', $parts[3] ?? ''), true),
            'print' => self::answer($mtg, $interaction, fn () => self::card($mtg, $selected)->then(fn (Card $card) => self::view($mtg, $card, $parts[2] ?? '')), true),
            'open' => self::answer($mtg, $interaction, fn () => self::card($mtg, $selected)->then(fn (Card $card) => self::view($mtg, $card, 'page.'.($parts[2] ?? '').'.'.($parts[3] ?? '1'))), true),
            'page' => self::answer($mtg, $interaction, fn () => self::page($mtg, $parts[2] ?? '', (int) ($parts[3] ?? 1)), true),
            'random' => self::answer($mtg, $interaction, fn () => self::roll($mtg, $parts[2] ?? ''), true),
            default => null,
        };
    }

    // --- shared views -----------------------------------------------------

    /**
     * A card view, with its printings and the way back to how it was reached.
     *
     * @param MTG    $mtg
     * @param Card   $card
     * @param string $context `page.<search>.<n>`, `random.<search>` or `''`.
     *
     * @return PromiseInterface<MessageBuilder>
     */
    public static function view(MTG $mtg, Card $card, string $context = ''): PromiseInterface
    {
        return $mtg->cards->getPrintings($card)->then(fn (array $printings) => CardMessageBuilder::card(
            $mtg,
            $card,
            self::withCurrent($card, $printings),
            $context,
            self::navigation($context),
        ));
    }

    /**
     * Finds a card by exact name, then by part of its name, optionally in
     * one set.
     *
     * @param MTG         $mtg
     * @param string      $name
     * @param string|null $set
     *
     * @return PromiseInterface<?Card>
     */
    public static function find(MTG $mtg, string $name, ?string $set = null): PromiseInterface
    {
        if ($name === '') {
            return resolve(null);
        }

        $exact = '"'.str_replace('"', '', $name).'"';

        // The set filter needs the build open to tell a code from a name.
        return $mtg->getDatabase()->ready()->then(function () use ($mtg, $name, $set, $exact) {
            $filters = self::setFilter($mtg, $set) + ['pageSize' => 1];

            return $mtg->cards->getCards(['name' => $exact] + $filters)->then(
                fn (ExCollectionInterface $cards) => $cards->first() ?? $mtg->cards->getCards(['name' => $name] + $filters)->then(
                    fn (ExCollectionInterface $cards) => $cards->first()
                )
            );
        });
    }

    /**
     * A page of a search.
     *
     * @param MTG    $mtg
     * @param string $search
     * @param int    $page
     *
     * @return PromiseInterface<MessageBuilder>
     */
    public static function page(MTG $mtg, string $search, int $page): PromiseInterface
    {
        if (! $state = $mtg->getSearchCache()->get($search)) {
            return resolve(CardMessageBuilder::notice('This search has expired. Run it again.'));
        }

        $filters = $state['filters'];
        $page = max(1, $page);

        return all([
            $mtg->cards->countCards($filters),
            $mtg->cards->getCards($filters + ['page' => $page, 'pageSize' => ListMessageBuilder::PAGE_SIZE]),
        ])->then(function (array $results) use ($mtg, $search, $page, $state) {
            [$total, $cards] = $results;

            if ($total === 0) {
                return CardMessageBuilder::notice("### {$state['title']}\nNo cards match.");
            }

            $lines = [];
            $choices = [];
            foreach ($cards as $card) {
                /** @var Card $card */
                $lines[] = CardMessageBuilder::summary($mtg, $card);
                $choices[] = ['label' => $card->name, 'value' => $card->uuid, 'description' => Text::clip((string) $card->type, 80)." · {$card->setCode}"];
            }

            return ListMessageBuilder::page(CardMessageBuilder::PREFIX, $search, $page, $total, $state['title'], $lines, $choices, CardMessageBuilder::ACCENTS['multicolor'], 'card');
        });
    }

    /**
     * A random card within a search's filters.
     *
     * @param MTG    $mtg
     * @param string $search
     *
     * @return PromiseInterface<MessageBuilder>
     */
    private static function roll(MTG $mtg, string $search): PromiseInterface
    {
        if (! $state = $mtg->getSearchCache()->get($search)) {
            return resolve(CardMessageBuilder::notice('This search has expired. Run it again.'));
        }

        return $mtg->cards->getCards($state['filters'] + ['random' => true, 'pageSize' => 1])->then(
            fn (ExCollectionInterface $cards) => ($card = $cards->first())
                ? self::view($mtg, $card, "random.{$search}")
                : CardMessageBuilder::notice('No card matches those filters.')
        );
    }

    /**
     * The card's next face (the other side of a double-faced card, or the
     * next part of a split or meld card).
     *
     * @param MTG    $mtg
     * @param string $uuid
     * @param string $context
     *
     * @return PromiseInterface<MessageBuilder>
     */
    private static function flip(MTG $mtg, string $uuid, string $context): PromiseInterface
    {
        return self::card($mtg, $uuid)->then(function (Card $card) use ($mtg, $context) {
            $faces = (array) ($card->otherFaceIds ?? []);
            if ($faces === []) {
                return self::view($mtg, $card, $context);
            }

            return $mtg->cards->getCardsByUuid($faces)->then(function (ExCollectionInterface $others) use ($mtg, $card, $context) {
                $sides = array_values(array_filter([...iterator_to_array($others), $card], fn ($face) => $face instanceof Card));
                usort($sides, fn (Card $a, Card $b) => strcmp((string) $a->side, (string) $b->side));

                foreach ($sides as $i => $face) {
                    if ($face->uuid === $card->uuid) {
                        return self::view($mtg, $sides[($i + 1) % count($sides)], $context);
                    }
                }

                return self::view($mtg, $card, $context);
            });
        });
    }

    /**
     * A card by uuid.
     *
     * @param MTG    $mtg
     * @param string $uuid
     *
     * @return PromiseInterface<Card>
     */
    private static function card(MTG $mtg, string $uuid): PromiseInterface
    {
        return $mtg->cards->fetch($uuid);
    }

    /**
     * The buttons back to how a card was reached.
     *
     * @param string $context
     *
     * @return Button[]
     */
    private static function navigation(string $context): array
    {
        $parts = explode('.', $context);

        return match ($parts[0]) {
            'page' => [Button::new(Button::STYLE_SECONDARY, CardMessageBuilder::PREFIX.":page:{$parts[1]}:".($parts[2] ?? '1'))->setLabel('Back to results')],
            'random' => [Button::new(Button::STYLE_PRIMARY, CardMessageBuilder::PREFIX.":random:{$parts[1]}")->setLabel('Another')->setEmoji('🎲')],
            default => [],
        };
    }

    /**
     * The printings for the picker, with the shown one among the 25 offered.
     *
     * @param Card    $card
     * @param array[] $printings
     *
     * @return array[]
     */
    private static function withCurrent(Card $card, array $printings): array
    {
        $uuids = array_column($printings, 'uuid');
        $at = array_search($card->uuid, $uuids, true);

        if ($at !== false && $at >= 25) {
            $current = $printings[$at];
            unset($printings[$at]);
            array_unshift($printings, $current);
        }

        return array_values($printings);
    }

    // --- options ----------------------------------------------------------

    /**
     * The filter for a set option: its code when it is one (autocomplete
     * gives codes), otherwise part of a set name.
     *
     * @param MTG         $mtg
     * @param string|null $set
     *
     * @return array
     */
    public static function setFilter(MTG $mtg, ?string $set): array
    {
        $set = trim((string) $set);

        return match (true) {
            $set === '' => [],
            $mtg->getSuggestions()->set($set) !== null => ['set' => strtoupper($set)],
            default => ['setName' => $set],
        };
    }

    /**
     * Search filters from command options.
     *
     * @param MTG   $mtg
     * @param array $args
     *
     * @return array
     */
    private static function filters(MTG $mtg, array $args): array
    {
        return self::setFilter($mtg, $args['set'] ?? null) + [
            'name' => $args['name'] ?? null,
            'type' => $args['type'] ?? null,
            'text' => $args['text'] ?? null,
            'colors' => $args['colors'] ?? null,
            'colorIdentity' => $args['identity'] ?? null,
            'manaValue' => $args['mana_value'] ?? null,
            'rarity' => $args['rarity'] ?? null,
            'gameFormat' => $args['format'] ?? null,
            'legality' => $args['legality'] ?? null,
            'keywords' => $args['keyword'] ?? null,
            'artist' => $args['artist'] ?? null,
            'power' => $args['power'] ?? null,
            'toughness' => $args['toughness'] ?? null,
        ];
    }

    /**
     * A search's title, from its options: `type:Elf · set:KTK`.
     *
     * @param array $args
     *
     * @return string
     */
    public static function describe(array $args): string
    {
        unset($args['hidden'], $args['order']);

        if ($args === []) {
            return 'Every card';
        }

        return Text::clip('Cards — '.implode(' · ', array_map(fn ($key, $value) => "{$key}: {$value}", array_keys($args), $args)), 200);
    }

    /**
     * Autocomplete for every `/card` sub-command.
     *
     * @param MTG         $mtg
     * @param Interaction $interaction
     * @param Option|null $option      The focused option.
     *
     * @return array
     */
    private function suggest(MTG $mtg, Interaction $interaction, $option): array
    {
        $typed = (string) ($option->value ?? '');
        $suggestions = $mtg->getSuggestions();

        if (($option->name ?? '') === 'set') {
            // With a card name typed, offer the sets that card was printed in.
            $name = (string) (self::typed($interaction)['name'] ?? '');
            $printings = $name !== '' ? $suggestions->printingSets($name, $typed) : [];

            return self::choices($printings ?: $suggestions->sets($typed));
        }

        return match ($option->name ?? '') {
            'name' => self::choices($suggestions->cardNames($typed)),
            'keyword' => self::choices($suggestions->keywords($typed)),
            'format' => self::choices($suggestions->formats($typed)),
            default => [],
        };
    }

    // --- definitions ------------------------------------------------------

    /**
     * `/card` and its sub-commands.
     *
     * @param MTG $mtg
     *
     * @return CommandBuilder
     */
    private function definition(MTG $mtg): CommandBuilder
    {
        $choices = function (Option $option, array $choices) use ($mtg): Option {
            foreach ($choices as $value => $label) {
                $option->addChoice(Choice::new($mtg, $label, $value));
            }

            return $option;
        };
        $rarity = fn () => $choices(self::option($mtg, Option::STRING, 'rarity', 'Common, uncommon, rare, mythic, …'), array_combine(self::RARITIES, array_map('ucfirst', self::RARITIES)));
        $order = $choices(self::option($mtg, Option::STRING, 'order', 'How to sort the results.'), array_combine(array_keys(self::ORDERS), array_keys(self::ORDERS)));
        $legality = $choices(self::option($mtg, Option::STRING, 'legality', 'With format: its status there (default Legal).'), ['Legal' => 'Legal', 'Banned' => 'Banned', 'Restricted' => 'Restricted', 'Not Legal' => 'Not Legal']);

        return self::command('card', 'Look up Magic: The Gathering cards.')
            ->addOption(self::subcommand(
                $mtg,
                'show',
                'Show a card.',
                self::option($mtg, Option::STRING, 'name', 'The card\'s name.', true, true),
                self::option($mtg, Option::STRING, 'set', 'A printing from this set.', false, true),
                self::hidden($mtg),
            ))
            ->addOption(self::subcommand(
                $mtg,
                'search',
                'Search for cards.',
                self::option($mtg, Option::STRING, 'name', 'Part of the name; | for or, "quotes" for exact.', false, true),
                self::option($mtg, Option::STRING, 'type', 'Part of the type line, e.g. Legendary Creature, Elf.'),
                self::option($mtg, Option::STRING, 'text', 'Part of the rules text.'),
                self::option($mtg, Option::STRING, 'colors', 'W, U, B, R, G or C; comma for and, | for or: U,R|G.'),
                self::option($mtg, Option::STRING, 'identity', 'Commander color identity, e.g. UR or W,U,B.'),
                self::option($mtg, Option::STRING, 'mana_value', 'Mana value, e.g. 3, >=5, <2.'),
                $rarity(),
                self::option($mtg, Option::STRING, 'set', 'Set code or name.', false, true),
                self::option($mtg, Option::STRING, 'format', 'Legal in this format.', false, true),
                $legality,
                self::option($mtg, Option::STRING, 'keyword', 'A keyword, e.g. Flying, Ward, Landfall.', false, true),
                self::option($mtg, Option::STRING, 'artist', 'Part of the artist\'s name.'),
                self::option($mtg, Option::STRING, 'power', 'Power, e.g. 3 or *.'),
                self::option($mtg, Option::STRING, 'toughness', 'Toughness, e.g. 3 or *.'),
                $order,
                self::hidden($mtg),
            ))
            ->addOption(self::subcommand(
                $mtg,
                'random',
                'A random card, optionally within filters.',
                self::option($mtg, Option::STRING, 'type', 'Part of the type line.'),
                self::option($mtg, Option::STRING, 'colors', 'W, U, B, R, G or C.'),
                self::option($mtg, Option::STRING, 'identity', 'Commander color identity.'),
                $rarity(),
                self::option($mtg, Option::STRING, 'set', 'Set code or name.', false, true),
                self::option($mtg, Option::STRING, 'format', 'Legal in this format.', false, true),
                self::hidden($mtg),
            ))
            ->addOption(self::subcommand(
                $mtg,
                'prices',
                'Today\'s prices for a card.',
                self::option($mtg, Option::STRING, 'name', 'The card\'s name.', true, true),
                self::option($mtg, Option::STRING, 'set', 'A printing from this set.', false, true),
                self::hidden($mtg),
            ));
    }

    /**
     * `/card_search`, the bot's original command, kept for servers that use it.
     *
     * @param MTG $mtg
     *
     * @return CommandBuilder
     */
    private function legacyDefinition(MTG $mtg): CommandBuilder
    {
        return self::command('card_search', 'Find a card by any of these (data from MTGJSON). /card has more.')
            ->addOption(self::option($mtg, Option::STRING, 'name', 'Part of a name; | for alternatives, "quotes" for exact: nissa, worldwaker|jace'))
            ->addOption(self::option($mtg, Option::INTEGER, 'cmc', 'Mana value.'))
            ->addOption(self::option($mtg, Option::STRING, 'color_identity', 'W, U, B, R, G or C; comma for and, | for or: U,R|G.'))
            ->addOption(self::option($mtg, Option::STRING, 'types', 'Creature, Instant, Enchantment.'))
            ->addOption(self::option($mtg, Option::STRING, 'subtypes', 'Elf, Goblin, Dragon.'))
            ->addOption(self::option($mtg, Option::STRING, 'game_format', 'Standard, Pioneer, Modern, Legacy, Vintage, Pauper, Commander, …'))
            ->addOption(self::option($mtg, Option::STRING, 'contains', 'Only cards with these fields, e.g. flavorText,power or imageUrl.'))
            ->addOption(self::option($mtg, Option::INTEGER, 'multiverseid', 'The multiverse ID of the card.'))
            ->addOption(self::option($mtg, Option::STRING, 'legality', 'Legal, Banned or Restricted.'));
    }
}
