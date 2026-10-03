<?php

/*
 * This file is a part of the DiscordPHP-MTG project.
 *
 * Copyright (c) 2025-present Valithor Obsidion <valithor@discordphp.org>
 *
 * This file is subject to the MIT license that is bundled
 * with this source code in the LICENSE.md file.
 */

use MTG\MTG;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use React\EventLoop\Loop;

class MTGSingleton
{
    private static $mtg;

    /**
     * @return MTG
     */
    public static function get()
    {
        if (! self::$mtg) {
            self::new_cache();
        }

        return self::$mtg;
    }

    private static function new_cache()
    {
        $loop = Loop::get();

        // Redis when it is running; DiscordPHP's in-memory cache otherwise.
        $cache = null;
        if ($socket = @fsockopen('localhost', 6379, $errno, $errstr, 0.2)) {
            fclose($socket);
            $redis = (new Clue\React\Redis\Factory($loop))->createLazyClient('localhost:6379');
            $cache = new WyriHaximus\React\Cache\Redis($redis);
        }

        //$cache = new WyriHaximus\React\Cache\Filesystem(React\Filesystem\Filesystem::create($loop), getenv('RUNNER_TEMP').DIRECTORY_SEPARATOR);

        //$memcached = new \Memcached();
        //$memcached->addServer('localhost', 11211);
        //$psr6Cache = new \Symfony\Component\Cache\Adapter\MemcachedAdapter($memcached, 'dphp', 0);
        //$cache = new RedisPsr16($psr6Cache);

        $logger = new Logger('MTGPHP-UnitTests');
        $handler = new StreamHandler(fopen(__DIR__.'/../phpunit.log', 'w'));
        $formatter = new LineFormatter(null, null, true, true);
        $handler->setFormatter($formatter);
        $logger->pushHandler($handler);

        $mtg = new MTG(array_filter([
            'token' => getenv('MTG_TOKEN'),
            'loop' => $loop,
            'logger' => $logger,
            'cache' => $cache,
            // Opened on first use, so a build the Database tests already downloaded is reused.
            'mtgjson' => ['database' => mtgjsonDatabasePath(), 'refresh_interval' => 0, 'preload' => false],
        ], fn ($option) => $option !== null));

        $e = null;

        $timer = $mtg->getLoop()->addTimer(10, function () use (&$e) {
            $e = new Exception('Timed out trying to connect to MTG.');
        });

        $mtg->on('ready', function (MTG $mtg) use ($timer) {
            $mtg->getLoop()->cancelTimer($timer);
            $mtg->getLoop()->stop();
        });

        self::$mtg = $mtg;

        $mtg->run();

        if ($e !== null) {
            throw $e;
        }
    }

    private static function new()
    {
        $logger = new Logger('MTGPHP-UnitTests');
        $handler = new StreamHandler(fopen(__DIR__.'/../phpunit.log', 'w'));
        $formatter = new LineFormatter(null, null, true, true);
        $handler->setFormatter($formatter);
        $logger->pushHandler($handler);

        $mtg = new MTG([
            'token' => getenv('MTG_TOKEN'),
            'logger' => $logger,
        ]);

        $e = null;

        $timer = $mtg->getLoop()->addTimer(10, function () use (&$e) {
            $e = new Exception('Timed out trying to connect to MTG.');
        });

        $mtg->on('ready', function (MTG $mtg) use ($timer) {
            $mtg->getLoop()->cancelTimer($timer);
            $mtg->getLoop()->stop();
        });

        $mtg->getLoop()->run();

        if ($e !== null) {
            throw $e;
        }

        self::$mtg = $mtg;
    }
}
