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

namespace MTG\Database;

use MTG\Http\Endpoint;
use MTG\Http\Http;
use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
use React\Http\Browser;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use React\Stream\ReadableStreamInterface;

use function React\Promise\reject;
use function React\Promise\resolve;

/**
 * A local copy of MTGJSON's AllPrintings SQLite build, kept current from the
 * API. MTGJSON has no query endpoint — it publishes whole files — so card and
 * set searches run here instead of over HTTP.
 *
 * On first use the gzipped build (`AllPrintings.sqlite.gz`, ~250 MB) is
 * streamed and inflated straight to disk (~700 MB), verified, and swapped in.
 * Afterwards `Meta.json` is checked every refresh interval and a new build is
 * installed only when MTGJSON's version has changed. Queries run on a
 * read-only connection; the swap closes it first so it also works on Windows.
 *
 * Rows come back decoded: the SQL build's `", "`-joined list columns become
 * arrays, its JSON columns become arrays, `BOOLEAN` columns become `bool`, and
 * empty values are dropped — the same shape as MTGJSON's JSON files.
 *
 * @link https://mtgjson.com/downloads/all-files/#allprintings
 *
 * @since 1.0.0
 */
class Database
{
    /**
     * The file name of the installed build.
     *
     * @var string
     */
    public const FILE = 'AllPrintings.sqlite';

    /**
     * Seconds between `Meta.json` checks by default: MTGJSON rebuilds daily.
     *
     * @var int
     */
    public const DEFAULT_REFRESH_INTERVAL = 86400;

    /**
     * Columns the SQL build stores as `", "`-joined lists, by table.
     * `cardParts` and `subsets` are left as strings, because their values
     * can contain commas.
     *
     * @var array<string, string[]>
     */
    public const LIST_COLUMNS = [
        'cards' => [
            'artistIds',
            'attractionLights',
            'availability',
            'boosterTypes',
            'colorIdentity',
            'colorIndicator',
            'colors',
            'finishes',
            'frameEffects',
            'keywords',
            'originalPrintings',
            'otherFaceIds',
            'printings',
            'producedMana',
            'promoTypes',
            'rebalancedPrintings',
            'subtypes',
            'supertypes',
            'types',
            'variations',
        ],
        'tokens' => [
            'artistIds',
            'attractionLights',
            'availability',
            'boosterTypes',
            'colorIdentity',
            'colorIndicator',
            'colors',
            'finishes',
            'frameEffects',
            'keywords',
            'otherFaceIds',
            'producedMana',
            'promoTypes',
            'subtypes',
            'supertypes',
            'types',
        ],
        'sets' => ['languages'],
    ];

    /**
     * Columns the SQL build stores as JSON text, by table.
     *
     * @var array<string, string[]>
     */
    public const JSON_COLUMNS = [
        'cards' => ['leadershipSkills', 'relatedCards', 'skuIds', 'sourceProducts'],
        'tokens' => ['relatedCards', 'skuIds', 'sourceProducts'],
        'cardForeignData' => ['identifiers', 'skuIds'],
        'sealedProducts' => ['contents', 'identifiers', 'purchaseUrls'],
        'setDecks' => ['commander', 'displayCommander', 'mainBoard', 'planes', 'schemes', 'sealedProductUuids', 'sideBoard', 'sourceSetCodes', 'tokens'],
    ];

    /**
     * Indexes added to each build after download, for lookups MTGJSON does
     * not index itself.
     *
     * @var string[]
     */
    public const INDEXES = [
        'CREATE INDEX IF NOT EXISTS "idx_cardIdentifiers_multiverseId" ON "cardIdentifiers" ("multiverseId")',
        'CREATE INDEX IF NOT EXISTS "idx_cardIdentifiers_scryfallId" ON "cardIdentifiers" ("scryfallId")',
        'CREATE INDEX IF NOT EXISTS "idx_setBoosterContents_setCode_boosterName" ON "setBoosterContents" ("setCode", "boosterName")',
        'CREATE INDEX IF NOT EXISTS "idx_setBoosterSheetCards_setCode_boosterName" ON "setBoosterSheetCards" ("setCode", "boosterName")',
    ];

    /**
     * The open read-only connection, or null while closed.
     *
     * @var PDO|null
     */
    protected ?PDO $pdo = null;

    /**
     * The version (`meta.version`) of the open build.
     *
     * @var string|null
     */
    protected ?string $version = null;

    /**
     * The date (`meta.date`) of the open build.
     *
     * @var string|null
     */
    protected ?string $date = null;

    /**
     * Declared column types, by table, read lazily with `PRAGMA table_info`.
     *
     * @var array<string, array<string, string>>
     */
    protected array $columns = [];

    /**
     * The pending first install, shared by every caller of {@see ready()}.
     *
     * @var PromiseInterface|null
     */
    protected ?PromiseInterface $opening = null;

    /**
     * The pending refresh, shared by every caller of {@see refresh()}.
     *
     * @var PromiseInterface|null
     */
    protected ?PromiseInterface $refreshing = null;

    /**
     * The periodic refresh timer.
     *
     * @var TimerInterface|null
     */
    protected ?TimerInterface $timer = null;

    /**
     * @param LoopInterface   $loop            The event loop the download and refresh timer run on.
     * @param LoggerInterface $logger          Logger for download progress and refresh failures.
     * @param Http            $http            The MTGJSON HTTP client, used for `Meta.json`.
     * @param Browser         $browser         A browser for the streamed download (no response buffer applies to streams).
     * @param string          $path            Where the build is kept, e.g. `var/mtgjson/AllPrintings.sqlite`.
     * @param int             $refreshInterval Seconds between `Meta.json` checks; `0` never refreshes.
     */
    public function __construct(
        protected LoopInterface $loop,
        protected LoggerInterface $logger,
        protected Http $http,
        protected Browser $browser,
        protected string $path,
        protected int $refreshInterval = self::DEFAULT_REFRESH_INTERVAL,
    ) {
    }

    /**
     * Opens the build, downloading it first if there is none on disk, and
     * starts the refresh timer.
     *
     * @return PromiseInterface<PDO> The open connection.
     */
    public function ready(): PromiseInterface
    {
        if ($this->pdo !== null) {
            return resolve($this->pdo);
        }

        if ($this->opening !== null) {
            return $this->opening;
        }

        if (is_file($this->path)) {
            try {
                $this->open();
                $this->schedule();

                return resolve($this->pdo);
            } catch (\Throwable $e) {
                $this->logger->warning("{$this->label()} at {$this->path} could not be opened, downloading a new one: {$e->getMessage()}");
            }
        }

        $this->logger->info("Downloading the {$this->label()} to {$this->path}");

        return $this->opening = $this->install()->then(function () {
            $this->opening = null;
            $this->schedule();

            return $this->pdo;
        }, function (\Throwable $e) {
            $this->opening = null;

            throw $e;
        });
    }

    /**
     * Checks `Meta.json` and installs MTGJSON's current build if it is newer
     * than the open one (or always, when forced).
     *
     * @param bool $force Download even when the versions match.
     *
     * @return PromiseInterface<bool> Whether a new build was installed.
     */
    public function refresh(bool $force = false): PromiseInterface
    {
        if ($this->refreshing !== null) {
            return $this->refreshing;
        }

        return $this->refreshing = $this->remoteVersion()
            ->then(function (?string $remote) use ($force) {
                if (! $force && $remote !== null && $remote === $this->getVersion()) {
                    @touch($this->path);

                    return false;
                }

                $this->logger->info("Installing {$this->label()} ".($remote ?? 'of unknown version').' (have '.($this->getVersion() ?? 'none').')');

                return $this->install()->then(fn () => true);
            })
            ->then(function (bool $installed) {
                $this->refreshing = null;

                return $installed;
            }, function (\Throwable $e) {
                $this->refreshing = null;

                throw $e;
            });
    }

    /**
     * Runs a read query against the build, opening (or downloading) it first.
     *
     * @param string $sql    The statement, with `?` or named placeholders.
     * @param array  $params Values bound to the placeholders.
     *
     * @return PromiseInterface<array[]> The raw rows.
     */
    public function query(string $sql, array $params = []): PromiseInterface
    {
        return $this->ready()->then(fn () => $this->select($sql, $params));
    }

    /**
     * Runs a read query against the open build, synchronously. Callers must
     * have waited for {@see ready()}.
     *
     * @param string $sql    The statement, with `?` or named placeholders.
     * @param array  $params Values bound to the placeholders.
     *
     * @throws \LogicException When the build is not open.
     *
     * @return array[] The raw rows.
     */
    public function select(string $sql, array $params = []): array
    {
        if ($this->pdo === null) {
            throw new \LogicException("The {$this->label()} is not open; wait for ready() first.");
        }

        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        $statement->closeCursor();

        return $rows;
    }

    /**
     * Decodes a row of `$table` into MTGJSON's JSON shape: list and JSON
     * columns become arrays, `BOOLEAN` columns become `bool`, and null or
     * empty scalars are dropped.
     *
     * @param string $table The table the row came from.
     * @param array  $row   The raw row.
     *
     * @return array The decoded row.
     */
    public function decode(string $table, array $row): array
    {
        $types = $this->getColumns($table);
        $lists = self::LIST_COLUMNS[$table] ?? [];
        $json = self::JSON_COLUMNS[$table] ?? [];

        foreach ($row as $column => $value) {
            if ($value === null) {
                unset($row[$column]);
            } elseif (in_array($column, $lists, true)) {
                $row[$column] = $value === '' ? [] : explode(', ', (string) $value);
            } elseif (in_array($column, $json, true)) {
                $row[$column] = json_decode((string) $value, true) ?? [];
            } elseif (($types[$column] ?? null) === 'BOOLEAN') {
                $row[$column] = (bool) $value;
            } elseif ($value === '') {
                unset($row[$column]);
            }
        }

        return $row;
    }

    /**
     * The columns of a table in the open build, with their declared types.
     *
     * @param string $table The table name.
     *
     * @return array<string, string> Column name => declared type (upper case).
     */
    public function getColumns(string $table): array
    {
        if (isset($this->columns[$table])) {
            return $this->columns[$table];
        }

        if ($this->pdo === null) {
            return [];
        }

        $columns = [];
        $statement = $this->pdo->query('PRAGMA table_info('.$this->pdo->quote($table).')');
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $column) {
            $columns[$column['name']] = strtoupper((string) $column['type']);
        }
        $statement->closeCursor();

        return $this->columns[$table] = $columns;
    }

    /**
     * The version (`meta.version`) of the open build, e.g. `5.3.0+20261003`.
     *
     * @return string|null Null while no build is open.
     */
    public function getVersion(): ?string
    {
        return $this->version;
    }

    /**
     * The date (`meta.date`) of the open build, e.g. `2026-10-03`.
     *
     * @return string|null Null while no build is open.
     */
    public function getDate(): ?string
    {
        return $this->date;
    }

    /**
     * Where the build is kept.
     *
     * @return string
     */
    public function getPath(): string
    {
        return $this->path;
    }

    /**
     * Whether a build is open.
     *
     * @return bool
     */
    public function isOpen(): bool
    {
        return $this->pdo !== null;
    }

    /**
     * Closes the connection and stops the refresh timer. The next query
     * reopens it.
     */
    public function close(): void
    {
        if ($this->timer !== null) {
            $this->loop->cancelTimer($this->timer);
            $this->timer = null;
        }

        $this->disconnect();
    }

    /**
     * Opens the build at {@see $path} read-only and reads its version.
     *
     * @throws \PDOException When the file is not a usable build.
     */
    protected function open(): void
    {
        // PHP 8.4 moved the SQLite constants to Pdo\Sqlite and 8.5 deprecates the old ones.
        $pdo = new PDO('sqlite:'.$this->path, null, null, class_exists('Pdo\Sqlite') ? [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            \Pdo\Sqlite::ATTR_OPEN_FLAGS => \Pdo\Sqlite::OPEN_READONLY,
        ] : [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY,
        ]);

        $statement = $pdo->query('SELECT "version", "date" FROM "meta" LIMIT 1');
        $meta = $statement->fetch(PDO::FETCH_ASSOC) ?: [];
        $statement->closeCursor();

        $this->pdo = $pdo;
        $this->version = $meta['version'] ?? null;
        $this->date = $meta['date'] ?? null;
        $this->columns = [];
    }

    /**
     * Drops the connection so the file can be replaced.
     */
    protected function disconnect(): void
    {
        $this->pdo = null;
        $this->version = null;
        $this->date = null;
        $this->columns = [];
    }

    /**
     * What this build is called in logs and errors.
     *
     * @return string
     */
    protected function label(): string
    {
        return 'MTGJSON AllPrintings build';
    }

    /**
     * Where the gzipped build is downloaded from.
     *
     * @return string
     */
    protected function source(): string
    {
        return Http::BASE_URL.'/'.Endpoint::ALL_PRINTINGS_SQLITE_GZ;
    }

    /**
     * The version of the build currently published, from `Meta.json`.
     *
     * @return PromiseInterface<?string>
     */
    protected function remoteVersion(): PromiseInterface
    {
        return $this->http->get(new Endpoint(Endpoint::META))->then(fn ($response) => $response->data->version ?? null);
    }

    /**
     * Downloads, verifies and swaps in MTGJSON's current build.
     *
     * @return PromiseInterface<string> The installed version.
     */
    protected function install(): PromiseInterface
    {
        $directory = dirname($this->path);
        if (! is_dir($directory) && ! @mkdir($directory, 0777, true) && ! is_dir($directory)) {
            return reject(new \RuntimeException("Cannot create the {$this->label()} directory {$directory}."));
        }

        $partial = $this->path.'.part';

        return $this->download($this->source(), $partial)
            ->then(function () use ($partial): string {
                try {
                    $version = $this->prepare($partial);
                } catch (\Throwable $e) {
                    @unlink($partial);

                    throw new \RuntimeException("The downloaded {$this->label()} is not usable: ".$e->getMessage(), 0, $e);
                }

                // The live file has to be closed before it can be replaced on Windows.
                $this->disconnect();

                if (! @rename($partial, $this->path)) {
                    @unlink($partial);
                    if (is_file($this->path)) {
                        $this->open();
                    }

                    throw new \RuntimeException("Cannot move the {$this->label()} into place at {$this->path}.");
                }

                $this->open();
                $this->logger->info("{$this->label()} {$version} installed at {$this->path}");

                return $version;
            });
    }

    /**
     * Checks a downloaded build and adds the extra indexes.
     *
     * @param string $file The downloaded file.
     *
     * @throws \RuntimeException When it has no version.
     *
     * @return string The build's version.
     */
    protected function prepare(string $file): string
    {
        $pdo = new PDO('sqlite:'.$file, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

        $statement = $pdo->query('SELECT "version" FROM "meta" LIMIT 1');
        $version = $statement->fetchColumn();
        $statement->closeCursor();

        if (! is_string($version) || $version === '') {
            throw new \RuntimeException('It has no meta version.');
        }

        foreach (static::INDEXES as $index) {
            $pdo->exec($index);
        }

        $pdo = null;

        return $version;
    }

    /**
     * Streams a gzipped file, inflating it to `$target` chunk by chunk so it
     * is never held in memory.
     *
     * @param string $url    The `.gz` URL.
     * @param string $target The file to write the inflated bytes to.
     *
     * @return PromiseInterface<void>
     */
    protected function download(string $url, string $target): PromiseInterface
    {
        $file = @fopen($target, 'wb');
        if ($file === false) {
            return reject(new \RuntimeException("Cannot write the {$this->label()} to {$target}."));
        }

        $cleanup = function () use (&$file, $target): void {
            if (is_resource($file)) {
                fclose($file);
            }
            @unlink($target);
        };

        return $this->browser
            ->requestStreaming('GET', $url, ['User-Agent' => $this->http->getUserAgent()])
            ->then(function (ResponseInterface $response) use (&$file, $url): PromiseInterface {
                if ($response->getStatusCode() !== 200) {
                    throw new \RuntimeException("GET {$url} answered HTTP {$response->getStatusCode()}.");
                }

                return $this->inflateTo($response->getBody(), $file, (int) $response->getHeaderLine('Content-Length'));
            })
            ->then(function () use (&$file): void {
                fclose($file);
            }, function (\Throwable $e) use ($cleanup): void {
                $cleanup();

                throw $e;
            });
    }

    /**
     * Inflates a gzip body stream into an open file.
     *
     * @param ReadableStreamInterface $body  The response body.
     * @param resource                $file  The file to write to.
     * @param int                     $total The compressed size, for progress logs (0 when unknown).
     *
     * @return PromiseInterface<void> Resolves once the gzip stream has ended cleanly.
     */
    protected function inflateTo(ReadableStreamInterface $body, $file, int $total): PromiseInterface
    {
        $deferred = new Deferred(fn () => $body->close());
        $inflate = inflate_init(ZLIB_ENCODING_GZIP);
        $received = 0;
        $logged = 0;

        $body->on('data', function (string $chunk) use ($body, $deferred, $file, $inflate, $total, &$received, &$logged): void {
            $received += strlen($chunk);
            $bytes = @inflate_add($inflate, $chunk, ZLIB_SYNC_FLUSH);

            if ($bytes === false) {
                $deferred->reject(new \RuntimeException("The {$this->label()} download is not valid gzip."));
                $body->close();

                return;
            }

            if ($bytes !== '' && fwrite($file, $bytes) !== strlen($bytes)) {
                $deferred->reject(new \RuntimeException("Writing the {$this->label()} failed (disk full?)."));
                $body->close();

                return;
            }

            if ($total > 0 && ($percent = intdiv($received * 100, $total)) >= $logged + 10) {
                $logged = $percent - $percent % 10;
                $this->logger->debug("{$this->label()} download {$logged}%");
            }
        });

        $body->on('end', function () use ($deferred, $inflate): void {
            inflate_get_status($inflate) === ZLIB_STREAM_END
                ? $deferred->resolve(null)
                : $deferred->reject(new \RuntimeException("The {$this->label()} download ended early."));
        });

        $body->on('error', fn (\Throwable $e) => $deferred->reject($e));
        $body->on('close', fn () => $deferred->reject(new \RuntimeException("The {$this->label()} download was cut off.")));

        return $deferred->promise();
    }

    /**
     * Starts the refresh timer, and checks right away if the build on disk
     * is already older than one interval.
     */
    protected function schedule(): void
    {
        if ($this->timer !== null || $this->refreshInterval <= 0) {
            return;
        }

        $this->timer = $this->loop->addPeriodicTimer($this->refreshInterval, fn () => $this->refreshQuietly());

        if (time() - (int) @filemtime($this->path) >= $this->refreshInterval) {
            $this->loop->futureTick(fn () => $this->refreshQuietly());
        }
    }

    /**
     * Refreshes, logging rather than throwing on failure; the open build
     * stays in use.
     */
    protected function refreshQuietly(): void
    {
        $this->refresh()->then(null, function (\Throwable $e): void {
            $this->logger->warning("{$this->label()} refresh failed, keeping version ".($this->getVersion() ?? 'none').': '.$e->getMessage());
        });
    }
}
