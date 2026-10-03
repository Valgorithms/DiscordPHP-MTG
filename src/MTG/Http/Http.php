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

namespace MTG\Http;

use Discord\Http\DriverInterface;
use Discord\Http\Endpoint;
use Discord\Http\HttpInterface;
use Discord\Http\HttpTrait;
use Discord\Http\Bucket;
use Discord\Http\Ratelimit;
use Psr\Log\LoggerInterface;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use SplQueue;

/**
 * HTTP client for the MTGJSON v5 API, built the same way DiscordPHP talks to
 * `discord.com` (buckets, driver, retry) but pointed at
 * `mtgjson.com/api/v5`. The API is read-only and unauthenticated, so no token
 * is required. Responses are MTGJSON's `{"meta": {...}, "data": ...}`
 * wrapper, decoded.
 *
 * Requests are buffered (16 MiB by default in react/http), which covers every
 * small file; the bulk files are streamed separately by
 * {@see \MTG\Database\Database}.
 *
 * @link https://mtgjson.com/getting-started/
 *
 * @see \Discord\Http\Http The DiscordPHP transport this mirrors
 *
 * @author Valithor Obsidion <valithor@discordphp.org>
 *
 * @since 0.1.0
 */
class Http implements HttpInterface
{
    use HttpTrait;

    /**
     * MTG Http version.
     *
     * @var string
     */
    public const VERSION = 'v1.0.0';

    /**
     * Current MTGJSON API version.
     *
     * @var string
     */
    public const HTTP_API_VERSION = 5;

    /**
     * MTGJSON API base URL.
     *
     * @var string
     */
    public const BASE_URL = 'https://mtgjson.com/api/v'.self::HTTP_API_VERSION;

    /**
     * Authentication token. Empty for MTGJSON, which is unauthenticated —
     * kept only so the shared {@see HttpTrait} plumbing has something to read.
     *
     * @var string
     */
    private $token;

    /**
     * Logger for HTTP requests.
     *
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * HTTP driver.
     *
     * @var DriverInterface
     */
    protected $driver;

    /**
     * ReactPHP event loop.
     *
     * @var LoopInterface
     */
    protected $loop;

    /**
     * Array of request buckets.
     *
     * @var Bucket[]
     */
    protected $buckets = [];

    /**
     * The current rate-limit.
     *
     * @var RateLimit
     */
    protected $rateLimit;

    /**
     * Timer that resets the current global rate-limit.
     *
     * @var TimerInterface
     */
    protected $rateLimitReset;

    /**
     * Request queue to prevent API
     * overload.
     *
     * @var SplQueue
     */
    protected $queue;

    /**
     * Request queue to prevent API
     * overload.
     *
     * @var SplQueue
     */
    protected $unboundQueue;

    /**
     * Number of requests that are waiting for a response.
     *
     * @var int
     */
    protected $waiting = 0;

    /**
     * Whether react/promise v3 is used, if false, using v2.
     */
    protected $promiseV3 = true;

    /**
     * Http wrapper constructor.
     *
     * @param string               $token  Unused by MTGJSON; pass `''`.
     * @param LoopInterface        $loop
     * @param LoggerInterface      $logger
     * @param DriverInterface|null $driver
     */
    public function __construct(string $token, LoopInterface $loop, LoggerInterface $logger, ?DriverInterface $driver = null)
    {
        $this->token = $token;
        $this->loop = $loop;
        $this->logger = $logger;
        $this->driver = $driver;
        $this->queue = new SplQueue();
        $this->unboundQueue = new SplQueue();
    }

    /**
     * Identifies this client to MTGJSON as DiscordPHP-MTG rather than
     * borrowing the generic DiscordPHP-HTTP agent string.
     */
    public function getUserAgent(): string
    {
        return 'DiscordPHP-MTG (https://github.com/discord-php/DiscordPHP-MTG, '.self::VERSION.')';
    }

    /**
     * Builds and queues a request.
     *
     * @param string   $method
     * @param Endpoint $url
     * @param mixed    $content
     * @param array    $headers
     *
     * @return PromiseInterface
     */
    public function queueRequest(string $method, Endpoint $url, $content, array $headers = []): PromiseInterface
    {
        $deferred = new Deferred();

        if (is_null($this->driver)) {
            $deferred->reject(new \Exception('HTTP driver is missing.'));

            return $deferred->promise();
        }

        $baseHeaders = ['User-Agent' => $this->getUserAgent()];

        // MTGJSON is unauthenticated; only send a token when one is actually
        // present so the Discord bot token never leaks to a third party.
        if ($this->token !== '') {
            $baseHeaders['Authorization'] = $this->token;
        }

        if (! is_null($content) && ! isset($headers['Content-Type'])) {
            $baseHeaders = array_merge($baseHeaders, $this->guessContent($content));
        }

        // Caller-supplied headers win over the defaults.
        $headers = array_merge($baseHeaders, $headers);

        $request = new Request($deferred, $method, $url, $content ?? '', $headers);
        $this->sortIntoBucket($request);

        return $deferred->promise();
    }
}
