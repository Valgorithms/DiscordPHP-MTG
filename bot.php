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

namespace MTG;

use Discord\Parts\Channel\Channel;
use Discord\Parts\User\Activity;
use Discord\Parts\User\User;
use Discord\WebSockets\Intents;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use MTG\Modules\About;
use MTG\Modules\Boosters;
use MTG\Modules\Cards;
use MTG\Modules\Decks;
use MTG\Modules\Help;
use MTG\Modules\Lookup;
use MTG\Modules\Sets;

use function React\Async\async;
use function React\Promise\set_rejection_handler;

ini_set('display_errors', 1);
error_reporting(E_ALL);

set_time_limit(0);
ignore_user_abort(true);
ini_set('max_execution_time', 0);
ini_set('memory_limit', '-1'); // Unlimited memory usage

/**
 * The project base directory. Works when run as `php bot.php` from the repo, and
 * when run as a phpacker/phpmicro binary (which lands nested under
 * `bin/build/<name>/<platform>/`) launched directly or from a shortcut, from any
 * working directory: walk up from the real executable path, then the working
 * directory, to the first ancestor with `vendor/autoload.php` or a `.env`.
 */
$baseDir = (static function (): string {
    $seen = [];
    foreach ([\Phar::running(false) ?: null, __FILE__, \getcwd() ?: null] as $start) {
        if ($start === null) {
            continue;
        }
        $dir = \is_dir($start) ? $start : \dirname((string) \preg_replace('#^phar://#', '', $start));
        for ($i = 0; $i < 12; $i++) {
            if (isset($seen[$dir])) {
                break;
            }
            $seen[$dir] = true;
            if (\is_file($dir.'/vendor/autoload.php') || \is_file($dir.'/.env')) {
                return $dir;
            }
            if (($up = \dirname($dir)) === $dir) {
                break;
            }
            $dir = $up;
        }
    }

    return \getcwd() ?: __DIR__;
})();

$autoload_path = file_exists(__DIR__.'/vendor/autoload.php') ? __DIR__.'/vendor/autoload.php'
    : (file_exists($baseDir.'/vendor/autoload.php') ? $baseDir.'/vendor/autoload.php' : null);
$autoload_path ? require ($autoload_path) : throw new \Exception('Composer autoloader not found. Run `composer install`, or keep the binary inside the project directory.');

/**
 * Minimal `KEY=value` .env loader (no dependency). The real environment wins.
 */
function loadEnv(string $filePath): void
{
    if (! file_exists($filePath)) {
        throw new \Exception('The .env file does not exist.');
    }

    $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $trimmedLines = array_map('trim', $lines);
    $filteredLines = array_filter($trimmedLines, fn ($line) => $line && ! str_starts_with($line, '#') && str_contains($line, '='));

    array_walk($filteredLines, function ($line) {
        [$name, $value] = array_map('trim', explode('=', $line, 2));
        if (! array_key_exists($name, $_ENV) && getenv($name) === false) {
            putenv(sprintf('%s=%s', $name, $value));
        }
    });
}

$env_path = file_exists($baseDir.'/.env') ? $baseDir.'/.env'
    : (file_exists(getcwd().'/.env') ? getcwd().'/.env' : null);
$env_path ? loadEnv($env_path) : throw new \Exception('The .env file does not exist. Create one in the project directory ('.$baseDir.').');

$technician_id = getenv('technician_id') ?: '116927250145869826'; // Default to Valithor Obsidion's ID

try {
    $level = Level::fromName(getenv('LOG_LEVEL') ?: 'debug');
} catch (\UnhandledMatchError) {
    $level = Level::Debug;
}
$streamHandler = new StreamHandler('php://stdout', $level);
$streamHandler->setFormatter(new LineFormatter(null, null, true, true, true));
$logger = new Logger('MTGCARDINFOBOT', [$streamHandler]);
set_rejection_handler(function (\Throwable $e) use ($logger): void {
    $logger->warning("Unhandled Promise Rejection: {$e->getMessage()} [{$e->getFile()}:{$e->getLine()}] ".str_replace('#', '\n#', $e->getTraceAsString()));
});

// `[[Card Name]]` in chat needs the privileged Message Content intent: turn it
// on for the application in the Developer Portal before setting this, or the
// gateway refuses the connection.
$inline = filter_var(getenv('MTG_INLINE_LOOKUPS') ?: false, FILTER_VALIDATE_BOOLEAN);

$mtg = new MTG([
    'logger' => $logger,
    'socket_options' => [
        'dns' => '8.8.8.8',
    ],
    'token' => getenv('TOKEN'),
    'intents' => Intents::getDefaultIntents() | ($inline ? Intents::MESSAGE_CONTENT : 0),
    'useTransportCompression' => false, // Disable zlib-stream
    'usePayloadCompression' => true, // RFC1950 2.2
    'disableVoiceClient' => true, // Disable voice client
    'mtgjson' => [
        // MTGJSON's AllPrintings SQLite build (~700 MB), downloaded on first run and refreshed daily;
        // today's prices (~12 MB) are kept beside it.
        'database' => getenv('MTGJSON_DATABASE') ?: $baseDir.'/var/mtgjson/AllPrintings.sqlite',
        'prices' => in_array($prices = getenv('MTG_PRICES'), [false, ''], true) || filter_var($prices, FILTER_VALIDATE_BOOLEAN),
    ],
]);

// Features, booted in this order once the gateway and the application are ready.
$mtg
    ->addModule(new Cards())
    ->addModule(new Sets())
    ->addModule(new Boosters())
    ->addModule(new Decks())
    ->addModule(new Lookup($inline))
    ->addModule(new Help())
    ->addModule(new About());

$mtg->once('init', fn (MTG $mtg) => $mtg->updatePresence(new Activity($mtg, [
    'name' => 'Magic: The Gathering',
    'type' => Activity::TYPE_PLAYING,
])));

set_error_handler(async(function (int $errno, string $errstr, ?string $errfile, ?int $errline) use (&$mtg, $logger, $technician_id) {
    $logger->error($msg = sprintf("[%d] Fatal error on `%s:%d`: %s\nBacktrace:\n```\n%s\n```", $errno, $errfile, $errline, $errstr, implode("\n", array_map(fn ($trace) => ($trace['file'] ?? '').':'.($trace['line'] ?? '').($trace['function'] ?? ''), debug_backtrace()))));
    if (getenv('TESTING') || ! $mtg instanceof MTG) {
        return;
    }
    $mtg->users->fetch($technician_id)
        ->then(fn (User $user) => $user->getPrivateChannel())
        ->then(fn (Channel $channel) => $channel->sendMessage(MTG::createBuilder()->setContent(substr($msg, 0, 2000))));
}));

$mtg->run();
