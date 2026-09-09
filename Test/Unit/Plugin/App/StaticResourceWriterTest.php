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
use ReflectionClass;

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

        $writer->aroundLaunch($this->createStub(StaticResource::class), fn (): ResponseInterface => $this->response());
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

        $writer->aroundLaunch($this->createStub(StaticResource::class), fn (): ResponseInterface => $this->response());
    }

    public function testNothingIsStoredWhenTheGateIsClosed(): void
    {
        $finalizer = $this->createMock(RunFinalizer::class);
        $finalizer->expects(self::never())->method('finalize');

        $writer = $this->writer(false, [
            ['type' => 'file', 'file' => 'css/source/_extend.less'],
        ], $finalizer);

        $writer->aroundLaunch($this->createStub(StaticResource::class), fn (): ResponseInterface => $this->response());
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

        $writer->aroundLaunch($this->createStub(StaticResource::class), fn (): ResponseInterface => $this->response());
    }

    public function testTheResponseIsAlwaysReturnedUnchanged(): void
    {
        $finalizer = $this->createMock(RunFinalizer::class);
        $finalizer->method('finalize');

        $response = $this->response();
        $writer = $this->writer(true, [], $finalizer);

        self::assertSame(
            $response,
            $writer->aroundLaunch($this->createStub(StaticResource::class), fn (): ResponseInterface => $response)
        );
    }

    /**
     * App\StaticResource::launch() calls State::setAreaCode(), which changes the DI config scope
     * mid-chain; PluginList::_loadScopedData() then replaces $_inherited wholesale, and this
     * primary-scope entry point has no row in the area-scope table. The after-listener lookup at
     * Interceptor.php:144 happens on the far side of that switch, so PluginList::getPlugin() warns
     * on a missing key, the warning is promoted to an exception, and
     * StaticResource::catchException() turns it into a 404 text/plain — for the asset it had
     * already published. Every cold static request 404s on first fetch, which strips the admin of
     * its RequireJS stack and leaves the menus unbound.
     *
     * `around` (Interceptor.php:133) is resolved before ___callParent() runs and never sees the
     * swapped table. This hook must therefore stay `around`.
     */
    public function testRegressionTheLaunchHookIsAroundNotAfter(): void
    {
        $plugin = new ReflectionClass(StaticResourceWriter::class);

        self::assertTrue(
            $plugin->hasMethod('aroundLaunch'),
            'launch() must be intercepted with an around listener, resolved before the scope switch.'
        );

        self::assertFalse(
            $plugin->hasMethod('afterLaunch'),
            'afterLaunch() is resolved after StaticResource::launch() swaps the DI scope, which 404s the asset.'
        );
    }

    /**
     * The sibling guard. The test above pins this class; this one pins the declaration, because
     * di.xml is where the choice is actually made and where the wrong rationale lived. Any plugin
     * registered on App\StaticResource — this one or a later addition — must intercept launch()
     * with `around` or `before`, never `after`.
     */
    public function testRegressionNoPluginOnStaticResourceUsesAnAfterListener(): void
    {
        $di = simplexml_load_file(__DIR__ . '/../../../../etc/di.xml');
        self::assertNotFalse($di, 'etc/di.xml must be readable and well-formed.');

        $plugins = $di->xpath('//type[@name="Magento\\Framework\\App\\StaticResource"]/plugin') ?: [];
        self::assertNotEmpty($plugins, 'The StaticResource plugin declaration has moved or been removed.');

        foreach ($plugins as $plugin) {
            $class = (string)$plugin['type'];
            self::assertTrue(class_exists($class), sprintf('Plugin class %s does not exist.', $class));

            self::assertFalse(
                (new ReflectionClass($class))->hasMethod('afterLaunch'),
                sprintf(
                    '%s declares afterLaunch(); launch() swaps the DI scope before the after-listener '
                    . 'lookup, so the asset is served as a 404. Use aroundLaunch().',
                    $class
                )
            );
        }
    }
}
