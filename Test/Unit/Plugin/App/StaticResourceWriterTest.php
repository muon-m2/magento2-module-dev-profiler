<?php
/**
 * Copyright © Muon. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Muon\DevProfiler\Test\Unit\Plugin\App;

use Magento\Framework\App\ResponseInterface;
use Magento\Framework\App\StaticResource;
use Muon\DevProfiler\Model\Run\Gate;
use Muon\DevProfiler\Model\Run\RunContext;
use Muon\DevProfiler\Model\Run\RunFinalizer;
use Muon\DevProfiler\Plugin\App\StaticResourceWriter;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/**
 * @see StaticResourceWriter
 *
 * A cold page fires one static-resource request per unmaterialised asset — routinely 150 to 400.
 * Writing a run for each rotated the 50-entry ring several times over during a single page load,
 * evicting the page run the developer opened the profiler to read. Measured on a live install:
 * 22 of 50 stored runs were static, carrying no queries at all.
 */
#[AllowMockObjectsWithoutExpectations]
class StaticResourceWriterTest extends TestCase
{
    /**
     * @param bool $profiled
     * @param list<array<string, mixed>> $fallback
     * @param RunFinalizer&\PHPUnit\Framework\MockObject\MockObject $finalizer
     * @return StaticResourceWriter
     */
    private function writer(bool $profiled, array $fallback, RunFinalizer $finalizer): StaticResourceWriter
    {
        $gate = $this->createMock(Gate::class);
        $gate->method('isProfiled')->willReturn($profiled);

        $context = new RunContext();

        foreach ($fallback as $entry) {
            $context->push('fallback', $entry);
        }

        return new StaticResourceWriter($gate, $finalizer, $context);
    }

    /**
     * @return ResponseInterface
     */
    private function response(): ResponseInterface
    {
        return $this->createStub(ResponseInterface::class);
    }

    public function testAStylesheetResolutionIsWorthKeeping(): void
    {
        $finalizer = $this->createMock(RunFinalizer::class);
        $finalizer->expects(self::once())->method('finalize');

        $writer = $this->writer(true, [
            ['type' => 'file', 'file' => 'css/source/_extend.less', 'resolved' => 'app/design/x.less'],
        ], $finalizer);

        $writer->afterLaunch($this->createStub(StaticResource::class), $this->response());
    }

    /**
     * The case that was flooding the ring: an asset request that resolved no source stylesheet has
     * nothing App\Http could not already see, so there is nothing worth evicting a page run for.
     */
    public function testAnAssetRequestThatResolvedNoStylesheetIsNotStored(): void
    {
        $finalizer = $this->createMock(RunFinalizer::class);
        $finalizer->expects(self::never())->method('finalize');

        $writer = $this->writer(true, [
            ['type' => 'file', 'file' => 'Magento_Theme::js/theme.js', 'resolved' => 'pub/static/x.js'],
        ], $finalizer);

        $writer->afterLaunch($this->createStub(StaticResource::class), $this->response());
    }

    public function testNothingIsStoredWhenTheGateIsClosed(): void
    {
        $finalizer = $this->createMock(RunFinalizer::class);
        $finalizer->expects(self::never())->method('finalize');

        $writer = $this->writer(false, [
            ['type' => 'file', 'file' => 'css/source/_extend.less'],
        ], $finalizer);

        $writer->afterLaunch($this->createStub(StaticResource::class), $this->response());
    }

    /**
     * The escape hatch: an operator who wants the old behaviour back passes an empty list.
     */
    public function testAnEmptyExtensionListKeepsEveryStaticRun(): void
    {
        $finalizer = $this->createMock(RunFinalizer::class);
        $finalizer->expects(self::once())->method('finalize');

        $gate = $this->createMock(Gate::class);
        $gate->method('isProfiled')->willReturn(true);

        $writer = new StaticResourceWriter($gate, $finalizer, new RunContext(), []);

        $writer->afterLaunch($this->createStub(StaticResource::class), $this->response());
    }

    public function testTheResponseIsAlwaysReturnedUnchanged(): void
    {
        $finalizer = $this->createMock(RunFinalizer::class);
        $finalizer->method('finalize');

        $response = $this->response();
        $writer = $this->writer(true, [], $finalizer);

        self::assertSame($response, $writer->afterLaunch($this->createStub(StaticResource::class), $response));
    }
}
