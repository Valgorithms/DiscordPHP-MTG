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
use MTG\Builders\BoosterMessageBuilder;
use MTG\Builders\CardMessageBuilder;
use MTG\MTG;
use MTG\Parts\Card;
use React\Promise\PromiseInterface;

use function React\Promise\resolve;

/**
 * Opening booster packs: `/booster <set> [type]`, from MTGJSON's booster
 * configurations — the pack layouts, sheets and odds of the real product.
 *
 * A pack lists its cards with rarity, foil and price, and the pack's value;
 * **Open another** opens the same kind of pack in place, **Images** shows the
 * cards, and a picker opens any card. The set view's **Open a booster**
 * button opens the set's default booster (`pack:open:<set>:`).
 *
 * @since 1.1.0
 */
final class Boosters implements Module
{
    use InteractionTrait;

    /**
     * @inheritDoc
     */
    public function name(): string
    {
        return 'boosters';
    }

    /**
     * @inheritDoc
     */
    public function commands(MTG $mtg): array
    {
        return [self::command('booster', 'Open a Magic: The Gathering booster pack.')
            ->addOption(self::option($mtg, Option::STRING, 'set', 'The set to open.', true, true))
            ->addOption(self::option($mtg, Option::STRING, 'type', 'Play, draft, collector, set, … (default: the set\'s usual pack).', false, true))
            ->addOption(self::hidden($mtg))];
    }

    /**
     * @inheritDoc
     */
    public function boot(MTG $mtg): void
    {
        $mtg->listenCommand('booster', fn (Interaction $i, $options) => $this->open($mtg, $i, self::values($options)), function (Interaction $interaction, $option) use ($mtg) {
            $typed = (string) ($option->value ?? '');

            if (($option->name ?? '') === 'type') {
                $set = (string) (self::typed($interaction)['set'] ?? '');
                if ($set === '') {
                    return [];
                }

                // Booster types come from the build, which autocomplete must not wait for.
                $mtg->sets->getBoosterTypes($set)->then(
                    fn (array $types) => $interaction->autoCompleteResult(self::choices(array_values(array_filter($types, fn ($type) => $typed === '' || str_contains($type, strtolower($typed)))))),
                    fn () => $interaction->autoCompleteResult([])
                );

                return null;
            }

            return self::choices($mtg->getSuggestions()->sets($typed, true));
        });

        $mtg->on(Event::INTERACTION_CREATE, function (Interaction $interaction) use ($mtg): void {
            if ($interaction->type !== Interaction::TYPE_MESSAGE_COMPONENT) {
                return;
            }

            $parts = explode(':', (string) ($interaction->data->custom_id ?? ''));
            if ($parts[0] !== BoosterMessageBuilder::PREFIX) {
                return;
            }

            match ($parts[1] ?? '') {
                // From a pack: open another in its place. From a set view (no type): a new pack.
                'open' => self::answer($mtg, $interaction, fn () => self::pack($mtg, $parts[2] ?? '', $parts[3] ?? ''), ($parts[3] ?? '') !== ''),
                'images' => self::answer($mtg, $interaction, fn () => self::images($mtg, $parts[2] ?? '')),
                'card' => self::answer($mtg, $interaction, fn () => $mtg->cards->fetch((string) ($interaction->data->values[0] ?? ''))->then(fn (Card $card) => Cards::view($mtg, $card))),
                default => null,
            };
        });
    }

    /**
     * `/booster`.
     *
     * @param MTG         $mtg
     * @param Interaction $interaction
     * @param array       $args
     *
     * @return PromiseInterface
     */
    private function open(MTG $mtg, Interaction $interaction, array $args): PromiseInterface
    {
        $set = trim((string) ($args['set'] ?? ''));

        return self::reply($mtg, $interaction, (bool) ($args['hidden'] ?? false), fn () => $mtg->getDatabase()->ready()->then(function () use ($mtg, $set, $args) {
            if ($mtg->getSuggestions()->set($set) !== null) {
                return self::pack($mtg, $set, (string) ($args['type'] ?? ''));
            }

            // Not a code: the newest set by that name that has boosters.
            $matches = $mtg->getSuggestions()->sets($set, true, 1);

            return $matches
                ? self::pack($mtg, (string) array_key_first($matches), (string) ($args['type'] ?? ''))
                : CardMessageBuilder::notice("MTGJSON has no booster for **{$set}**.");
        }));
    }

    /**
     * Opens a pack.
     *
     * @param MTG    $mtg
     * @param string $code
     * @param string $type `''` for the set's default booster.
     *
     * @return PromiseInterface<MessageBuilder>
     */
    private static function pack(MTG $mtg, string $code, string $type): PromiseInterface
    {
        $code = strtoupper($code);

        return $mtg->sets->getBoosterTypes($code)->then(function (array $types) use ($mtg, $code, $type) {
            if ($types === []) {
                return CardMessageBuilder::notice("MTGJSON has no booster for `{$code}`.");
            }

            if ($type !== '' && ! in_array($type, $types, true)) {
                return CardMessageBuilder::notice("`{$code}` has no `{$type}` booster. It has: ".implode(', ', array_map(fn ($type) => "`{$type}`", $types)).'.');
            }

            $type = $type !== '' ? $type : $types[0];

            return $mtg->sets->generateBooster($code, $type)->then(function (ExCollectionInterface $pack) use ($mtg, $code, $type) {
                $cards = array_values(iterator_to_array($pack));
                $id = $mtg->getSearchCache()->put([
                    'set' => $code,
                    'type' => $type,
                    'cards' => array_map(fn (Card $card) => [$card->uuid, (bool) $card->isFoil], $cards),
                ]);

                return BoosterMessageBuilder::pack($mtg, $code, $mtg->getSuggestions()->set($code)['name'] ?? $code, $type, $cards, $id);
            });
        });
    }

    /**
     * The images of an opened pack.
     *
     * @param MTG    $mtg
     * @param string $pack
     *
     * @return PromiseInterface<MessageBuilder>
     */
    private static function images(MTG $mtg, string $pack): PromiseInterface
    {
        if (! $state = $mtg->getSearchCache()->get($pack)) {
            return resolve(CardMessageBuilder::notice('This pack has expired. Open another.'));
        }

        return $mtg->cards->getCardsByUuid(array_column($state['cards'], 0))->then(function (ExCollectionInterface $found) use ($mtg, $state) {
            $cards = [];
            foreach ($state['cards'] as [$uuid]) {
                if ($card = $found->get('uuid', $uuid)) {
                    $cards[] = $card;
                }
            }

            $name = $mtg->getSuggestions()->set($state['set'])['name'] ?? $state['set'];

            return BoosterMessageBuilder::images("{$name} — ".ucfirst($state['type']).' booster', $cards);
        });
    }
}
