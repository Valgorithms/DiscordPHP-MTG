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

use Discord\Http\Drivers\React;
use MTG\Database\Database;
use MTG\Http\Http;
use MTG\MTG;
use Psr\Log\NullLogger;
use React\EventLoop\Loop;
use React\Http\Browser;

const TIMEOUT = 10;

/**
 * Where the tests keep the MTGJSON build: the bot's own copy, so it is
 * downloaded once. Override with MTGJSON_DATABASE.
 */
function mtgjsonDatabasePath(): string
{
    return getenv('MTGJSON_DATABASE') ?: dirname(__DIR__).'/var/mtgjson/'.Database::FILE;
}

/**
 * An open MTGJSON build for tests that need no Discord connection,
 * downloaded on first use. Never refreshes.
 */
function database(): Database
{
    static $database = null;

    if ($database === null) {
        $loop = Loop::get();
        $logger = new NullLogger();
        $database = new Database($loop, $logger, new Http('', $loop, $logger, new React($loop)), new Browser(null, $loop), mtgjsonDatabasePath(), 0);
    }

    if (! $database->isOpen()) {
        $settled = false;
        $error = null;
        $database->ready()->then(function () use (&$settled) {
            $settled = true;
            Loop::stop();
        }, function (\Throwable $e) use (&$settled, &$error) {
            $settled = true;
            $error = $e;
            Loop::stop();
        });

        // An existing build opens synchronously; only a download needs the loop
        // (which may also carry a live Discord connection, so it never empties).
        if (! $settled) {
            Loop::run();
        }

        if ($error) {
            throw $error;
        }
    }

    return $database;
}

function wait(callable $callback, float $timeout = TIMEOUT, ?callable $timeoutFn = null)
{
    $mtg = MTGSingleton::get();

    $result = null;
    $finally = null;
    $timedOut = false;

    $mtg->getLoop()->futureTick(function () use ($callback, $mtg, &$result, &$finally) {
        $resolve = function ($x = null) use ($mtg, &$result) {
            $result = $x;
            $mtg->getLoop()->stop();
        };

        try {
            $finally = $callback($mtg, $resolve);
        } catch (\Throwable $e) {
            $resolve($e);
        }
    });

    $timeout = $mtg->getLoop()->addTimer($timeout, function () use ($mtg, &$timedOut) {
        $timedOut = true;
        $mtg->getLoop()->stop();
    });

    $mtg->getLoop()->run();
    $mtg->getLoop()->cancelTimer($timeout);

    if ($result instanceof \Throwable) {
        throw $result;
    }

    if (is_callable($finally)) {
        $finally();
    }

    if ($timedOut) {
        if ($timeoutFn != null) {
            $timeoutFn();
        } else {
            throw new \Exception('Timed out');
        }
    }

    return $result;
}

function getMockMtg(): MTG
{
    return new MTG(['token' => '', 'logger' => new NullLogger(), 'mtgjson' => ['database' => mtgjsonDatabasePath(), 'refresh_interval' => 0, 'preload' => false]]);
}
