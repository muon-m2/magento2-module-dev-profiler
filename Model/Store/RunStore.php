<?php
/**
 * Copyright © Muon. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Muon\DevProfiler\Model\Store;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\WriteInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Muon\DevProfiler\Api\RunReaderInterface;
use Psr\Log\LoggerInterface;

/**
 * Reads and writes profiler runs. The only class in the module that touches disk.
 *
 * One file per run, under var/, rather than a database table. Three reasons, in order of how much
 * they cost to get wrong:
 *
 *  1. A table write would happen inside the request being profiled. The v2 SQL collector would then
 *     report the profiler's own INSERT as part of the page — the tool contaminating its own
 *     measurement.
 *  2. `bin/magento setup:upgrade` flushes the cache. A cache-backed store would lose every run at exactly
 *     the moment somebody runs the refresh they were in the middle of debugging.
 *  3. The file is legible on its own. Reading a run does not require this module's CLI, or any
 *     Magento bootstrap at all.
 *
 * Retention is a ring, not a schedule: the oldest files are dropped as new ones arrive, so there is
 * no cron job to install and nothing to prune on a timer.
 */
class RunStore implements RunReaderInterface
{
    /**
     * Relative to var/. Kept shallow so the directory is obvious to anyone who finds it.
     */
    private const DIR = 'muon/profiler';

    /**
     * A run token is hex and nothing else. Enforced on the way in and on the way out, because this
     * value reaches a filesystem path and the CLI accepts it from an argument.
     */
    private const TOKEN_PATTERN = '/[^a-f0-9]/';

    /**
     * Suffix for the half-written file, so a partial run is never a candidate for reading.
     */
    private const TMP_SUFFIX = '.part';

    /**
     * @var \Magento\Framework\Filesystem\Directory\WriteInterface|null
     */
    private ?WriteInterface $varDirectory = null;

    /**
     * @param \Magento\Framework\Filesystem $filesystem
     * @param \Magento\Framework\Serialize\Serializer\Json $json
     * @param \Psr\Log\LoggerInterface $logger
     * @param int $ringSize How many runs to keep before the oldest are dropped.
     */
    public function __construct(
        private readonly Filesystem $filesystem,
        private readonly Json $json,
        private readonly LoggerInterface $logger,
        private readonly int $ringSize = 50,
        private readonly int $maxAgeHours = 72
    ) {
    }

    /**
     * Persist one run and drop whatever falls out of the ring.
     *
     * @param string $token
     * @param array<string, mixed> $run
     * @return void
     */
    public function write(string $token, array $run): void
    {
        $token = $this->sanitiseToken($token);

        if ($token === '') {
            return;
        }

        try {
            $directory = $this->directory();
            $directory->create(self::DIR);

            // Cast is not cosmetic: Magento types serialize() as bool|string, so writeFile()'s
            // string parameter is a real type error without it.
            $encoded = (string)$this->json->serialize($run);
            $target = self::DIR . '/' . $this->filename($token);

            // Written to a temporary name and renamed into place. writeFile() truncates and then
            // writes with no lock, so a reader listing the directory mid-write saw a partial file,
            // caught the decode failure and silently skipped the run — `muon:profile:list --limit=20`
            // quietly returning nineteen. rename() within one filesystem is atomic, so a run is
            // either absent or complete.
            $directory->writeFile($target . self::TMP_SUFFIX, $encoded);
            $directory->renameFile($target . self::TMP_SUFFIX, $target);
            $this->restrict($directory, $target);

            $this->prune();
        } catch (\Throwable $e) {
            // Losing a profile is an inconvenience. Losing the page it describes is not acceptable,
            // and this runs while the response is still in hand.
            $this->logger->debug('Muon_DevProfiler could not write a run: ' . $e->getMessage());
        }
    }

    /**
     * One run by token.
     *
     * @param string $token
     * @return array<string, mixed>|null
     */
    public function load(string $token): ?array
    {
        $token = $this->sanitiseToken($token);

        if ($token === '') {
            return null;
        }

        foreach ($this->files() as $path) {
            if (str_ends_with($path, '-' . $token . '.json')) {
                return $this->read($path);
            }
        }

        return null;
    }

    /**
     * The most recent run of any kind, including AJAX.
     *
     * @return array<string, mixed>|null
     */
    public function loadLast(): ?array
    {
        $files = $this->files();
        $path = end($files);

        return $path === false ? null : $this->read($path);
    }

    /**
     * The most recent run that was a full HTML document.
     *
     * A storefront page fires customer-section XHRs immediately behind it, so the newest run is
     * usually not the page you just loaded. This is what `bin/magento muon:profile:show` calls with no argument,
     * because it is what somebody means when they do not say.
     *
     * @return array<string, mixed>|null
     */
    public function loadLastDocument(): ?array
    {
        foreach (array_reverse($this->files()) as $path) {
            $run = $this->read($path);

            // A static-asset run has is_ajax => false, so it satisfied the old condition and could
            // be returned as "the last full document" — which it is not. Right after a static
            // rebuild that meant answering with a LESS file's run, for which the verdict is n/a.
            $kind = (string)($run['request']['kind'] ?? 'page');

            if ($run !== null && empty($run['request']['is_ajax']) && $kind !== 'static') {
                return $run;
            }
        }

        return null;
    }

    /**
     * Recent runs, newest first.
     *
     * @param int $limit
     * @return list<array<string, mixed>>
     */
    public function list(int $limit = 20): array
    {
        $runs = [];

        foreach (array_reverse($this->files()) as $path) {
            if (count($runs) >= max(1, $limit)) {
                break;
            }

            $run = $this->read($path);

            if ($run !== null) {
                $runs[] = $run;
            }
        }

        return $runs;
    }

    /**
     * How many runs the ring currently holds.
     *
     * Counts files without decoding any of them, so a caller that only needs the number — a status
     * line, a header — does not pay to unserialize fifty documents to print one integer.
     *
     * @return int
     */
    public function count(): int
    {
        return count($this->files());
    }

    /**
     * Drop every stored run.
     *
     * @return int Number of files removed.
     */
    public function clear(): int
    {
        $removed = 0;

        foreach ($this->files() as $path) {
            if ($this->remove($path)) {
                $removed++;
            }
        }

        return $removed;
    }

    /**
     * Stored run files, oldest first.
     *
     * The millisecond prefix makes lexical order chronological order, so no stat() call is needed
     * to sort them — which matters because this runs on every write.
     *
     * @return list<string>
     */
    private function files(): array
    {
        try {
            $found = $this->directory()->search(self::DIR . '/*.json');
        } catch (\Throwable $e) {
            $this->logger->debug('Muon_DevProfiler could not list runs: ' . $e->getMessage());

            return [];
        }

        $files = array_values(array_filter($found, 'is_string'));
        sort($files, SORT_STRING);

        // Age is enforced here rather than in prune(), because prune() runs only from write(). A
        // retention window that is only applied while profiling is active is not a retention
        // window: stop profiling and the last runs — full request URIs, resolved paths, statement
        // shapes — stay in var/ forever. Every path that can see a run goes through this method, so
        // enforcing it here means reading is enough to make the window real.
        foreach ($this->expired($files) as $path) {
            $this->remove($path);
            $files = array_values(array_diff($files, [$path]));
        }

        return $files;
    }

    /**
     * Drop whatever now falls outside the ring.
     *
     * @return void
     */
    private function prune(): void
    {
        // files() has already dropped anything outside the retention window.
        $files = $this->files();
        $excess = count($files) - max(1, $this->ringSize);

        if ($excess <= 0) {
            return;
        }

        foreach (array_slice($files, 0, $excess) as $path) {
            $this->remove($path);
        }
    }

    /**
     * Files older than the retention window, by the millisecond timestamp in their own name.
     *
     * Read from the filename rather than from the filesystem: it is the value the writer chose, it
     * needs no stat call per file, and it cannot drift when a directory is copied about.
     *
     * @param list<string> $files
     * @return list<string>
     */
    private function expired(array $files): array
    {
        if ($this->maxAgeHours <= 0) {
            return [];
        }

        $cutoff = (time() - ($this->maxAgeHours * 3600)) * 1000;
        $old = [];

        foreach ($files as $path) {
            if (preg_match('/(\d{10,})-[a-f0-9]+\.json$/', $path, $m) === 1 && (int)$m[1] < $cutoff) {
                $old[] = $path;
            }
        }

        return $old;
    }

    /**
     * Narrow the permissions Magento's Filesystem leaves behind.
     *
     * create() and writeFile() take the directory's configured mode, which is 0777 by default and
     * lands as 0755/0644 under the usual umask — world-readable. These files hold request URIs and
     * statement shapes, and anything that tars var/ for a backup or a support bundle carries them
     * off the box.
     *
     * Through the Filesystem API rather than chmod(), so failures arrive as exceptions this method
     * already catches instead of as suppressed warnings.
     *
     * @param \Magento\Framework\Filesystem\Directory\WriteInterface $directory
     * @param string $path
     * @return void
     */
    private function restrict(WriteInterface $directory, string $path): void
    {
        try {
            $directory->changePermissions($path, 0600);
            $directory->changePermissions(self::DIR, 0700);

            // Self-protecting even where the document root is misconfigured to serve var/.
            $guard = self::DIR . '/.htaccess';

            if (!$directory->isExist($guard)) {
                $directory->writeFile($guard, "Require all denied\n");
            }
        } catch (\Throwable) {
            // Tightening permissions is defence in depth. Failing to is not a reason to lose the
            // run, and on some filesystems (a bind mount from a host that does not support it) the
            // call cannot succeed at all.
        }
    }

    /**
     * @param string $path
     * @return bool
     */
    private function remove(string $path): bool
    {
        try {
            return (bool)$this->directory()->delete($path);
        } catch (\Throwable) {
            // A concurrent request may have pruned the same file between listing and deleting it.
            return false;
        }
    }

    /**
     * Decode one stored run.
     *
     * @param string $path
     * @return array<string, mixed>|null
     */
    private function read(string $path): ?array
    {
        try {
            $decoded = $this->json->unserialize($this->directory()->readFile($path));
        } catch (\Throwable) {
            // A half-written or hand-edited file must not break the command listing the others.
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param string $token
     * @return string
     */
    private function filename(string $token): string
    {
        return sprintf('%d-%s.json', (int)round(microtime(true) * 1000), $token);
    }

    /**
     * @param string $token
     * @return string
     */
    private function sanitiseToken(string $token): string
    {
        return (string)preg_replace(self::TOKEN_PATTERN, '', $token);
    }

    /**
     * @return \Magento\Framework\Filesystem\Directory\WriteInterface
     */
    private function directory(): WriteInterface
    {
        return $this->varDirectory ??= $this->filesystem->getDirectoryWrite(DirectoryList::VAR_DIR);
    }
}
