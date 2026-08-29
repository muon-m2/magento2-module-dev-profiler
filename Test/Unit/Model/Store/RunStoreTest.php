<?php
/**
 * Copyright © Muon. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Muon\DevProfiler\Test\Unit\Model\Store;

use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\WriteInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Muon\DevProfiler\Model\Store\RunStore;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @see RunStore
 */
#[AllowMockObjectsWithoutExpectations]
class RunStoreTest extends TestCase
{

    /** @var array<string, string> */
    private array $disk = [];

    /** @var list<string> */
    private array $deleted = [];

    /**
     * @param list<string> $files Paths the directory reports, in arbitrary order.
     * @param int $ringSize
     * @return RunStore
     */
    private function store(array $files = [], int $ringSize = 50): RunStore
    {
        return new RunStore(
            $this->filesystemFor($files),
            new Json(),
            $this->createMock(LoggerInterface::class),
            $ringSize
        );
    }

    /**
     * A fake filesystem: an in-memory disk that models truncating writes, atomic renames and
     * deletes, so the store's own ordering can be asserted without touching a real directory.
     *
     * @param list<string> $files
     * @return Filesystem
     */
    private function filesystemFor(array $files = []): Filesystem
    {
        /** @var WriteInterface&MockObject $directory */
        $directory = $this->createMock(WriteInterface::class);
        // The real call is search('muon/profiler/*.json'), so the fake honours the same shape: the
        // transient .part file and the .htaccess guard the store writes are not runs.
        $directory->method('search')->willReturnCallback(
            fn (): array => array_values(array_filter(
                array_keys($this->disk) ?: $files,
                static fn (string $p): bool => str_ends_with($p, '.json')
            ))
        );
        $directory->method('readFile')->willReturnCallback(fn (string $p): string => $this->disk[$p] ?? '');
        $directory->method('writeFile')->willReturnCallback(function (string $p, string $c): int {
            $this->disk[$p] = $c;

            return strlen($c);
        });
        $directory->method('delete')->willReturnCallback(function (string $p): bool {
            $this->deleted[] = $p;
            unset($this->disk[$p]);

            return true;
        });
        // Runs are written to a .part name and renamed into place, so a reader never sees a
        // half-written file. The fake filesystem has to model that or every write stays temporary.
        $directory->method('isExist')->willReturnCallback(fn (string $p): bool => isset($this->disk[$p]));
        $directory->method('changePermissions')->willReturn(true);
        $directory->method('renameFile')->willReturnCallback(function (string $from, string $to): bool {
            $this->disk[$to] = $this->disk[$from] ?? '';
            unset($this->disk[$from]);

            return true;
        });
        $directory->method('getAbsolutePath')->willReturnCallback(
            static fn (?string $p = null): string => '/var/www/magento/var/' . ($p ?? '')
        );

        foreach ($files as $path) {
            $this->disk[$path] = '{"token":"' . $this->tokenOf($path) . '","request":{"is_ajax":false}}';
        }

        /** @var Filesystem&MockObject $filesystem */
        $filesystem = $this->createMock(Filesystem::class);
        $filesystem->method('getDirectoryWrite')->willReturn($directory);

        return $filesystem;
    }

    /**
     * @param string $path
     * @return string
     */
    private function tokenOf(string $path): string
    {
        return (string)preg_replace('/^.*-([a-f0-9]+)\.json$/', '$1', $path);
    }

    /**
     * @param int $count
     * @return list<string>
     */
    private function paths(int $count): array
    {
        $paths = [];

        // Anchored to now, not to a fixed 2023 epoch. The store prunes by age as well as by count,
        // and a ring of runs from two years ago is genuinely expired — a fixture that pretends
        // otherwise would test the count path against files the age path should already have taken.
        // In the recent past, oldest first, so a run written during the test is genuinely the
        // newest — and inside the retention window, so the age sweep leaves them alone and the
        // count path is what the assertion is actually testing.
        $base = (int)(microtime(true) * 1000);

        for ($i = 1; $i <= $count; $i++) {
            $paths[] = sprintf(
                'muon/profiler/%d-%s.json',
                $base - (($count - $i + 1) * 1000),
                str_pad((string)$i, 12, 'a', STR_PAD_LEFT)
            );
        }

        return $paths;
    }

    public function testDoesNotPruneBelowTheCap(): void
    {
        $store = $this->store($this->paths(4), 5);
        $store->write('deadbeefcafe', ['token' => 'deadbeefcafe']);

        self::assertSame([], $this->deleted);
    }

    public function testPrunesTheOldestOnceOverTheCap(): void
    {
        $paths = $this->paths(5);

        $store = $this->store($paths, 5);
        $store->write('deadbeefcafe', ['token' => 'deadbeefcafe']);

        self::assertCount(1, $this->deleted);
        self::assertSame($paths[0], $this->deleted[0], 'the oldest goes, and only the oldest');
    }

    public function testPrunesEveryExcessFileNotJustOne(): void
    {
        $store = $this->store($this->paths(15), 5);
        $store->write('deadbeefcafe', ['token' => 'deadbeefcafe']);

        self::assertCount(11, $this->deleted);
    }

    public function testLoadsByToken(): void
    {
        $store = $this->store($this->paths(3));

        $run = $store->load('aaaaaaaaaaa2');

        self::assertNotNull($run);
        self::assertSame('aaaaaaaaaaa2', $run['token']);
    }

    public function testRejectsANonHexTokenRatherThanTouchingThePath(): void
    {
        $store = $this->store($this->paths(3));

        self::assertNull($store->load('../../../etc/passwd'));
    }

    public function testLastDocumentSkipsAjaxRuns(): void
    {
        $paths = $this->paths(2);
        $store = $this->store($paths);
        $this->disk[$paths[1]] = '{"token":"newest","request":{"is_ajax":true}}';

        $run = $store->loadLastDocument();

        self::assertNotNull($run);
        self::assertSame('aaaaaaaaaaa1', $run['token'], 'the newest run was AJAX and must be skipped');
    }

    public function testCorruptJsonReturnsNullInsteadOfThrowing(): void
    {
        $paths = $this->paths(1);
        $store = $this->store($paths);
        $this->disk[$paths[0]] = '{ this is not json';

        self::assertNull($store->loadLast());
    }

    public function testOneCorruptFileDoesNotBreakListingTheOthers(): void
    {
        $paths = $this->paths(3);
        $store = $this->store($paths);
        $this->disk[$paths[1]] = 'truncated…';

        self::assertCount(2, $store->list(10));
    }

    public function testClearReportsHowManyItRemoved(): void
    {
        $store = $this->store($this->paths(4));

        self::assertSame(4, $store->clear());
    }

    public function testCountReportsHowManyRunsTheRingHolds(): void
    {
        self::assertSame(7, $this->store($this->paths(7))->count());
    }

    public function testCountIsZeroBeforeAnythingIsRecorded(): void
    {
        self::assertSame(0, $this->store([])->count());
    }

    /**
     * The point of having count() at all: a caller that needs the number must not pay to decode
     * every document to get it.
     */
    public function testCountDecodesNothing(): void
    {
        $paths = $this->paths(3);
        $store = $this->store($paths);
        $this->disk[$paths[1]] = 'not json at all';

        self::assertSame(3, $store->count(), 'an undecodable file still occupies a slot in the ring');
    }

    /**
     * Retention should not depend on someone remembering to run muon:profile:clear. The ring only
     * ever shrank on write, so once profiling stopped, up to ringSize documents — request URIs,
     * resolved paths, statement shapes — sat in var/ indefinitely.
     */
    public function testDropsRunsOlderThanTheRetentionWindow(): void
    {
        $now = (int)(microtime(true) * 1000);
        $old = sprintf('muon/profiler/%d-%s.json', $now - (96 * 3600 * 1000), 'aaaaaaaaaaaa');
        $fresh = sprintf('muon/profiler/%d-%s.json', $now - (1 * 3600 * 1000), 'bbbbbbbbbbbb');

        $store = $this->store([$old, $fresh]);
        $store->write('cccccccccccc', ['token' => 'cccccccccccc']);

        self::assertContains($old, $this->deleted, 'a run from four days ago is past the 72h window');
        self::assertNotContains($fresh, $this->deleted, 'an hour-old run is not');
    }

    /**
     * The window has to hold on read as well as on write.
     *
     * Age was previously enforced only from prune(), which runs from write(). That makes the
     * guarantee conditional on profiling still being active — exactly the case where it matters
     * least. A developer who profiles once and stops is the one left with captured request data on
     * disk, and nothing they do short of muon:profile:clear removes it.
     */
    public function testTheRetentionWindowIsEnforcedWithoutAnyWrite(): void
    {
        $now = (int)(microtime(true) * 1000);
        $old = sprintf('muon/profiler/%d-%s.json', $now - (96 * 3600 * 1000), 'aaaaaaaaaaaa');
        $fresh = sprintf('muon/profiler/%d-%s.json', $now - (1 * 3600 * 1000), 'bbbbbbbbbbbb');

        $store = $this->store([$old, $fresh]);

        // A pure read. Nothing here writes a run.
        self::assertSame(1, $store->count(), 'the expired run must not be counted');
        self::assertContains($old, $this->deleted, 'reading is enough to enforce the window');
        self::assertNotContains($fresh, $this->deleted);
    }

    public function testAZeroRetentionWindowKeepsEverythingTheRingAllows(): void
    {
        $now = (int)(microtime(true) * 1000);
        $ancient = sprintf('muon/profiler/%d-%s.json', $now - (365 * 24 * 3600 * 1000), 'aaaaaaaaaaaa');

        $store = new RunStore(
            $this->filesystemFor([$ancient]),
            new Json(),
            $this->createMock(LoggerInterface::class),
            50,
            0
        );
        $store->write('bbbbbbbbbbbb', ['token' => 'bbbbbbbbbbbb']);

        self::assertNotContains($ancient, $this->deleted, 'age pruning is opt-out via maxAgeHours=0');
    }

    /**
     * A half-written run must never be readable. writeFile() truncates then writes with no lock, so
     * the store writes to a .part name and renames into place.
     */
    public function testARunIsWrittenToATemporaryNameAndRenamedIntoPlace(): void
    {
        $store = $this->store();
        $store->write('abcdef123456', ['token' => 'abcdef123456']);

        foreach (array_keys($this->disk) as $path) {
            self::assertStringNotContainsString('.part', $path, 'the temporary file must not survive');
        }

        self::assertNotSame([], $this->disk, 'and the run itself must be there');
    }
}
