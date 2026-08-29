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
        /** @var WriteInterface&MockObject $directory */
        $directory = $this->createMock(WriteInterface::class);
        $directory->method('search')->willReturnCallback(fn (): array => array_keys($this->disk) ?: $files);
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

        foreach ($files as $path) {
            $this->disk[$path] = '{"token":"' . $this->tokenOf($path) . '","request":{"is_ajax":false}}';
        }

        /** @var Filesystem&MockObject $filesystem */
        $filesystem = $this->createMock(Filesystem::class);
        $filesystem->method('getDirectoryWrite')->willReturn($directory);

        return new RunStore($filesystem, new Json(), $this->createMock(LoggerInterface::class), $ringSize);
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

        for ($i = 1; $i <= $count; $i++) {
            $paths[] = sprintf('muon/profiler/%d-%s.json', 1700000000000 + $i, str_pad((string)$i, 12, 'a', STR_PAD_LEFT));
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
        $store = $this->store($this->paths(5), 5);
        $store->write('deadbeefcafe', ['token' => 'deadbeefcafe']);

        self::assertCount(1, $this->deleted);
        self::assertStringContainsString('1700000000001-', $this->deleted[0]);
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
}
