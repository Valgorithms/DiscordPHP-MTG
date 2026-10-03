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
use Discord\Parts\Channel\Message;
use Discord\Parts\Interactions\Command\Command;
use Discord\Parts\Interactions\Interaction;
use Discord\Parts\OAuth\Application;
use Discord\WebSockets\Event;
use MTG\Builders\CardMessageBuilder;
use MTG\Helpers\Text;
use MTG\MTG;
use MTG\Parts\Card;
use React\Promise\PromiseInterface;

use function React\Promise\all;
use function React\Promise\resolve;

/**
 * Cards named in chat.
 *
 * **Find cards** — right-click a message → Apps → Find cards — looks up every
 * `[[Card Name]]` in it (or `[[Card Name|SET]]` for one printing), or, with
 * none, the whole message as one name. It answers only the person who asked,
 * and needs nothing special: the message comes with the interaction.
 *
 * Inline lookups — the bot answering `[[Card Name]]` in any message it can
 * read — are opt-in, because reading messages needs the privileged Message
 * Content intent: turn it on in the Developer Portal, then construct this
 * module with `inline: true` (bot.php does that for `MTG_INLINE_LOOKUPS=1`).
 *
 * @since 1.1.0
 */
final class Lookup implements Module
{
    use InteractionTrait;

    /**
     * Cards looked up from one message, at most.
     *
     * @var int
     */
    public const LIMIT = 5;

    /**
     * Cards answered inline from one message, at most.
     *
     * @var int
     */
    public const INLINE_LIMIT = 3;

    /**
     * @param bool $inline Whether to answer `[[Card Name]]` in messages (needs the Message Content intent).
     */
    public function __construct(private readonly bool $inline = false)
    {
    }

    /**
     * @inheritDoc
     */
    public function name(): string
    {
        return 'lookup';
    }

    /**
     * @inheritDoc
     */
    public function commands(MTG $mtg): array
    {
        return [CommandBuilder::new()
            // setType() before setName(): a message command's name may have spaces and capitals.
            ->setType(Command::MESSAGE)
            ->setName('Find cards')
            ->setContext([Interaction::CONTEXT_TYPE_GUILD, Interaction::CONTEXT_TYPE_BOT_DM, Interaction::CONTEXT_TYPE_PRIVATE_CHANNEL])
            ->addIntegrationType(Application::INTEGRATION_TYPE_GUILD_INSTALL)
            ->addIntegrationType(Application::INTEGRATION_TYPE_USER_INSTALL)];
    }

    /**
     * @inheritDoc
     */
    public function boot(MTG $mtg): void
    {
        $mtg->listenCommand('Find cards', fn (Interaction $interaction) => $this->find($mtg, $interaction));

        if ($this->inline) {
            $mtg->on(Event::MESSAGE_CREATE, fn (Message $message) => $this->inline($mtg, $message));
        }
    }

    /**
     * **Find cards**.
     *
     * @param MTG         $mtg
     * @param Interaction $interaction
     *
     * @return PromiseInterface
     */
    private function find(MTG $mtg, Interaction $interaction): PromiseInterface
    {
        $message = $interaction->data->resolved?->messages?->get('id', (string) ($interaction->data->target_id ?? ''));
        $content = $message instanceof Message ? (string) $message->content : '';

        $mentions = Text::cardMentions($content, self::LIMIT);
        if ($mentions === [] && ($whole = trim(strtok($content, "\n") ?: '')) !== '') {
            $mentions = [['name' => Text::clip($whole, 141), 'set' => null]];
        }

        return self::reply($mtg, $interaction, true, fn () => $mentions === []
            ? CardMessageBuilder::notice('That message has no text to look up. Name cards like `[[Lightning Bolt]]`.')
            : self::lookup($mtg, $mentions));
    }

    /**
     * Answers the `[[Card Name]]` mentions in a message.
     *
     * @param MTG     $mtg
     * @param Message $message
     */
    private function inline(MTG $mtg, Message $message): void
    {
        if ($message->author?->bot || ! str_contains((string) $message->content, '[[')) {
            return;
        }

        $mentions = Text::cardMentions((string) $message->content, self::INLINE_LIMIT);
        if ($mentions === []) {
            return;
        }

        self::lookup($mtg, $mentions)
            ->then(fn (MessageBuilder $answer) => $message->reply($answer))
            ->then(null, fn (\Throwable $e) => $mtg->logger->warning('Inline card lookup failed: '.$e->getMessage()));
    }

    /**
     * The answer for some mentioned cards: the card itself for one, a list
     * to pick from for several.
     *
     * @param MTG                                      $mtg
     * @param array<array{name: string, set: ?string}> $mentions
     *
     * @return PromiseInterface<MessageBuilder>
     */
    private static function lookup(MTG $mtg, array $mentions): PromiseInterface
    {
        return all(array_map(fn (array $mention) => Cards::find($mtg, $mention['name'], $mention['set']), $mentions))->then(function (array $found) use ($mtg, $mentions) {
            $cards = array_values(array_filter($found, fn ($card) => $card instanceof Card));
            $missing = array_map(fn (array $mention) => $mention['name'], array_filter($mentions, fn ($mention, $i) => ! $found[$i] instanceof Card, ARRAY_FILTER_USE_BOTH));
            $status = $missing ? '-# Not found: '.Text::clip(implode(', ', $missing), 300) : null;

            if ($cards === []) {
                return resolve(CardMessageBuilder::notice('No card matches '.implode(', ', array_map(fn ($name) => "**{$name}**", $missing)).'.'));
            }

            if (count($cards) === 1) {
                return $mtg->cards->getPrintings($cards[0])->then(fn (array $printings) => CardMessageBuilder::card($mtg, $cards[0], $printings, '', [], $status));
            }

            $search = $mtg->getSearchCache()->put([
                'filters' => ['uuid' => implode('|', array_map(fn (Card $card) => $card->uuid, $cards)), 'unique' => true],
                'title' => 'Cards in that message',
            ]);

            return Cards::page($mtg, $search, 1);
        });
    }
}
