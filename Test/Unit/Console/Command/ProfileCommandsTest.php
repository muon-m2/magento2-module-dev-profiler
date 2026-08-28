<?php
/**
 * Copyright © Muon. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Muon\DevProfiler\Test\Unit\Console\Command;

use Muon\DevProfiler\Console\Command\ProfileClearCommand;
use Muon\DevProfiler\Console\Command\ProfileListCommand;
use Muon\DevProfiler\Console\Command\ProfileShowCommand;
use Muon\DevProfiler\Model\Analysis\CacheVerdict;
use Magento\Framework\Serialize\Serializer\Json;
use Muon\DevProfiler\Model\Report\RunRenderer;
use Muon\DevProfiler\Model\Store\RunStore;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The three console commands are how a human actually reaches this module, and none of them had a
 * test. They are thin over well-tested collaborators, but the option parsing and the
 * which-run-do-you-mean branching are original code, and a wrong answer there is silent.
 *
 * @see ProfileShowCommand
 * @see ProfileListCommand
 * @see ProfileClearCommand
 */
#[AllowMockObjectsWithoutExpectations]
class ProfileCommandsTest extends TestCase
{
    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function storedRun(array $overrides = []): array
    {
        return $overrides + [
            'schema' => 1,
            'token' => 'ce0532817023',
            'captured_at' => '2026-08-28T12:00:00+00:00',
            'request' => [
                'method' => 'GET',
                'url' => '/en-us/nb-home',
                'full_action' => 'cms_index_index',
                'status' => 200,
                'is_ajax' => false,
                'kind' => 'page',
                'duration_ms' => 128.4,
                'memory_peak_kb' => 20480,
            ],
            'context' => ['store_code' => 'en_us', 'theme_path' => 'Muon/cosmic'],
            'layout' => [
                'generated' => true,
                'cacheable' => true,
                'handles' => ['default', 'cms_index_index'],
                'uncacheable_blocks' => [],
                'constructor_optouts' => [],
            ],
            'fallback' => [],
            'queries' => [],
            'truncated' => ['fallback' => 0, 'queries' => 0],
        ];
    }

    /**
     * The renderer is stubbed: these tests are about which run the command asks for and what it
     * does with the answer, not about formatting, which RunRendererTest and SqlListRendererTest
     * already cover against the real classes.
     *
     * @param RunStore $store
     * @return ProfileShowCommand
     */
    private function showCommand(RunStore $store): ProfileShowCommand
    {
        $renderer = $this->createStub(RunRenderer::class);
        $renderer->method('render')->willReturnCallback(
            static fn (array $run): array => ['run ' . (string)($run['token'] ?? '?')]
        );

        return new ProfileShowCommand($store, $renderer, new Json());
    }

    public function testShowWithNoTokenAsksForTheLastFullDocument(): void
    {
        $store = $this->createMock(RunStore::class);
        $store->expects(self::once())->method('loadLastDocument')->willReturn($this->storedRun());
        $store->expects(self::never())->method('loadLast');

        $tester = new CommandTester($this->showCommand($store));
        $tester->execute([]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('ce0532817023', $tester->getDisplay());
    }

    /**
     * `--any` is the escape hatch documented for AJAX and static-asset runs; it must change which
     * store method is asked, not merely filter afterwards.
     */
    public function testShowWithAnyAsksForTheLastRunOfAnyKind(): void
    {
        $store = $this->createMock(RunStore::class);
        $store->expects(self::once())->method('loadLast')->willReturn($this->storedRun());
        $store->expects(self::never())->method('loadLastDocument');

        $tester = new CommandTester($this->showCommand($store));
        $tester->execute(['--any' => true]);

        self::assertSame(0, $tester->getStatusCode());
    }

    public function testShowWithATokenLoadsThatRun(): void
    {
        $store = $this->createMock(RunStore::class);
        $store->expects(self::once())->method('load')->with('ce0532817023')->willReturn($this->storedRun());

        $tester = new CommandTester($this->showCommand($store));
        $tester->execute(['token' => 'ce0532817023']);

        self::assertSame(0, $tester->getStatusCode());
    }

    public function testShowSaysSoWhenThereIsNothingToShow(): void
    {
        $store = $this->createMock(RunStore::class);
        $store->expects(self::once())->method('loadLastDocument')->willReturn(null);

        $tester = new CommandTester($this->showCommand($store));
        $tester->execute([]);

        self::assertNotSame('', trim($tester->getDisplay()), 'silence is not an answer');
        self::assertMatchesRegularExpression('/no runs|not found|nothing/i', $tester->getDisplay());
    }

    /**
     * A threshold typed as nonsense must fall back to the documented default rather than becoming
     * 0 and reporting every statement as slow.
     */
    public function testShowFallsBackToDefaultsForNonNumericThresholds(): void
    {
        $store = $this->createMock(RunStore::class);
        $store->expects(self::once())->method('loadLastDocument')->willReturn($this->storedRun());

        $tester = new CommandTester($this->showCommand($store));
        $tester->execute(['--sql' => true, '--slow-query' => 'abc', '--nplus1' => 'xyz']);

        self::assertSame(0, $tester->getStatusCode(), 'garbage in an option must not fail the command');
    }

    public function testListPrintsRecentRunsNewestFirst(): void
    {
        $store = $this->createMock(RunStore::class);
        $store->expects(self::once())->method('list')->with(20)->willReturn([
            $this->storedRun(['token' => 'aaaaaaaaaaaa']),
            $this->storedRun(['token' => 'bbbbbbbbbbbb']),
        ]);

        $tester = new CommandTester(new ProfileListCommand($store, new CacheVerdict()));
        $tester->execute([]);

        $out = $tester->getDisplay();

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('aaaaaaaaaaaa', $out);
        self::assertStringContainsString('bbbbbbbbbbbb', $out);
        self::assertLessThan(
            strpos($out, 'bbbbbbbbbbbb'),
            strpos($out, 'aaaaaaaaaaaa'),
            'the store returns newest first and the command must not reorder'
        );
    }

    public function testListHonoursTheLimitOption(): void
    {
        $store = $this->createMock(RunStore::class);
        $store->expects(self::once())->method('list')->with(3)->willReturn([]);

        $tester = new CommandTester(new ProfileListCommand($store, new CacheVerdict()));
        $tester->execute(['--limit' => '3']);

        self::assertSame(0, $tester->getStatusCode());
    }

    public function testClearReportsHowManyRunsItRemoved(): void
    {
        $store = $this->createMock(RunStore::class);
        $store->expects(self::once())->method('clear')->willReturn(37);

        $tester = new CommandTester(new ProfileClearCommand($store));
        $tester->execute([]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('37', $tester->getDisplay());
    }
}
