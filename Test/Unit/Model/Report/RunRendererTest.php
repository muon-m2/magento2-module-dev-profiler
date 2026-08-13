<?php
/**
 * Copyright © Muon. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Muon\DevProfiler\Test\Unit\Model\Report;

use Muon\DevProfiler\Model\Analysis\CacheVerdict;
use Muon\DevProfiler\Model\Analysis\ShadowResolver;
use Muon\DevProfiler\Model\Analysis\QueryAnalyzer;
use Muon\DevProfiler\Model\Report\FallbackListRenderer;
use Muon\DevProfiler\Model\Report\SqlListRenderer;
use Muon\DevProfiler\Model\Report\RunRenderer;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/**
 * @see RunRenderer
 */
#[AllowMockObjectsWithoutExpectations]
class RunRendererTest extends TestCase
{
    /**
     * @param list<array<string, mixed>> $classified What ShadowResolver would return.
     * @return RunRenderer
     */
    private function renderer(array $classified): RunRenderer
    {
        /** @var ShadowResolver&\PHPUnit\Framework\MockObject\MockObject $shadows */
        $shadows = $this->createMock(ShadowResolver::class);
        $shadows->method('classify')->willReturn($classified);

        return new RunRenderer(
            new FallbackListRenderer($shadows),
            new SqlListRenderer(new QueryAnalyzer()),
            new CacheVerdict()
        );
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function profileRun(array $overrides = []): array
    {
        $run = array_replace_recursive([
            'token' => 'abc123',
            'request' => [
                'method' => 'GET', 'url' => '/en-us/', 'status' => 200,
                'kind' => 'page', 'duration_ms' => 100.0, 'is_ajax' => false,
            ],
            'context' => ['store_code' => 'en_us', 'store_id' => 2, 'website_id' => 2, 'theme_path' => 'Muon/cosmic-custom'],
            'layout' => ['generated' => true, 'cacheable' => true],
            'fallback' => [['type' => 'static', 'file' => 'x']],
        ], $overrides);

        // array_replace_recursive() cannot replace a populated array with an empty one — it has
        // nothing to recurse into — so an explicit empty list has to be reapplied by hand.
        foreach (['fallback', 'layout', 'context'] as $key) {
            if (array_key_exists($key, $overrides)) {
                $run[$key] = $overrides[$key];
            }
        }

        return $run;
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function entry(string $file, array $extra = []): array
    {
        return array_replace([
            'type' => 'static', 'file' => $file, 'module' => null,
            'winner' => 'a/' . $file, 'shadowed' => [], 'candidates' => 1, 'anomaly' => null,
        ], $extra);
    }

    public function testRepeatLookupsCollapseIntoOneRowWithACount(): void
    {
        $renderer = $this->renderer([$this->entry('tokens.less'), $this->entry('tokens.less')]);

        // Both numbers in the header have to come from a run that could actually have happened:
        // `distinct` is counted from the resolver's output and `lookups` from what was recorded,
        // so a fixture with two classified entries and one recorded lookup tests nothing — the two
        // halves never meet. Two recorded lookups of one file is the case being described.
        $out = implode("\n", $renderer->render($this->profileRun([
            'fallback' => [
                ['type' => 'static', 'file' => 'tokens.less'],
                ['type' => 'static', 'file' => 'tokens.less'],
            ],
        ])));

        self::assertSame(1, substr_count($out, 'tokens.less   x2'), 'one row carrying x2');
        self::assertStringContainsString('1 distinct files (2 lookups)', $out);
    }

    /**
     * The list is otherwise in the order Magento happened to ask for files, which is meaningful to
     * nobody. Shadowed files are the reason the tool exists, so they must lead.
     */
    public function testShadowedFilesAreReportedFirst(): void
    {
        $renderer = $this->renderer([
            $this->entry('boring-a.less'),
            $this->entry('odd.less', ['anomaly' => 'winner-mismatch']),
            $this->entry('important.less', ['shadowed' => ['b/important.less']]),
            $this->entry('boring-b.less'),
        ]);

        $out = implode("\n", $renderer->render($this->profileRun()));

        self::assertLessThan(
            strpos($out, 'odd.less'),
            strpos($out, 'important.less'),
            'shadowed leads'
        );
        self::assertLessThan(
            strpos($out, 'boring-a.less'),
            strpos($out, 'odd.less'),
            'anomalies come before ordinary rows'
        );
        self::assertLessThan(
            strpos($out, 'boring-b.less'),
            strpos($out, 'boring-a.less'),
            'ordinary rows keep their original order'
        );
    }

    public function testProbeMissesAreCountedRatherThanListed(): void
    {
        $renderer = $this->renderer([
            $this->entry('real.less'),
            $this->entry('i18n/en_US.csv', ['anomaly' => 'probe-miss', 'winner' => null]),
        ]);

        $out = implode("\n", $renderer->render($this->profileRun()));

        self::assertStringContainsString('1 probe misses hidden', $out);
        self::assertStringNotContainsString('i18n/en_US.csv', $out);
    }

    /**
     * "What is this page pulling out of breeze-evolution?" is a search over resolved paths, and it
     * returned nothing at all while the output plainly listed that theme.
     */
    public function testTheFilterMatchesResolvedPathsNotJustTheFileName(): void
    {
        $renderer = $this->renderer([
            $this->entry('a.less', ['winner' => 'vendor/swissup/theme-frontend-breeze-evolution/web/a.less']),
            $this->entry('b.less', ['winner' => 'vendor/magento/module-theme/web/b.less']),
        ]);

        $out = implode("\n", $renderer->render($this->profileRun(), false, 'breeze-evolution'));

        self::assertStringContainsString('a.less', $out);
        self::assertStringNotContainsString('b.less', $out);
    }

    public function testTheFilterAlsoMatchesShadowedPaths(): void
    {
        $renderer = $this->renderer([
            $this->entry('c.less', [
                'winner' => 'app/design/x/c.less',
                'shadowed' => ['vendor/muon/theme-frontend-cosmic/web/c.less'],
            ]),
        ]);

        $out = implode("\n", $renderer->render($this->profileRun(), false, 'theme-frontend-cosmic'));

        self::assertStringContainsString('c.less', $out);
    }

    /**
     * An empty profile on a cache hit is correct, and reads exactly like a broken tool.
     */
    public function testACacheHitExplainsWhyItRecordedNothing(): void
    {
        $renderer = $this->renderer([]);

        $out = implode("\n", $renderer->render($this->profileRun([
            'layout' => ['generated' => false],
            'fallback' => [],
        ])));

        self::assertStringContainsString('served from the full page cache', $out);
        self::assertStringContainsString('make profile-clear', $out);
    }

    public function testAnEmptyPageRunThatWasNotAHitGetsNoMisleadingHint(): void
    {
        $renderer = $this->renderer([]);

        $out = implode("\n", $renderer->render($this->profileRun(['fallback' => []])));

        self::assertStringContainsString('nothing recorded', $out);
        self::assertStringNotContainsString('full page cache', $out);
    }

    /**
     * Saying "no resolution matched the filter" when no filter was given sends the reader to
     * change a filter that does not exist.
     */
    public function testAnEmptyListExplainsTheRightReason(): void
    {
        $renderer = $this->renderer([
            $this->entry('i18n/en_US.csv', ['anomaly' => 'probe-miss', 'winner' => null]),
        ]);

        $out = implode("\n", $renderer->render($this->profileRun()));

        self::assertStringContainsString('every lookup was a probe that found nothing', $out);
        self::assertStringNotContainsString('matched the filter', $out);
    }

    /**
     * "HANDLES 0" on a cache hit states a fact about nothing — layout never ran.
     */
    public function testHandlesAreOmittedWhenLayoutNeverRan(): void
    {
        $renderer = $this->renderer([]);

        $out = implode("\n", $renderer->render($this->profileRun([
            'layout' => ['generated' => false],
            'fallback' => [],
        ])));

        self::assertStringNotContainsString('HANDLES', $out);
    }

    public function testHandlesAreShownWhenLayoutDidRun(): void
    {
        $renderer = $this->renderer([$this->entry('a.less')]);

        $out = implode("\n", $renderer->render($this->profileRun([
            'layout' => ['generated' => true, 'cacheable' => true, 'handles' => ['default', 'cms_index_index']],
        ])));

        self::assertStringContainsString('HANDLES   2', $out);
    }

    /**
     * A theme recovered from configuration rather than observed must say so — it is a weaker
     * claim, and silently presenting it as observed would be the kind of invented answer this
     * module refuses to make elsewhere.
     */
    public function testAThemeRecoveredFromConfigurationIsLabelled(): void
    {
        $renderer = $this->renderer([]);

        $out = implode("\n", $renderer->render($this->profileRun([
            'context' => ['theme_path' => 'Muon/cosmic-custom', 'theme_source' => 'configured'],
            'layout' => ['generated' => false],
            'fallback' => [],
        ])));

        self::assertStringContainsString('store default — not observed', $out);
    }

    public function testAnObservedThemeIsNotLabelled(): void
    {
        $renderer = $this->renderer([$this->entry('a.less')]);

        $out = implode("\n", $renderer->render($this->profileRun([
            'context' => ['theme_source' => 'observed'],
        ])));

        self::assertStringNotContainsString('not observed', $out);
    }

    /**
     * Three seconds next to a 50ms page reads like a problem unless it says why.
     */
    public function testStaticRunsLabelTheAssetBuildInTheDuration(): void
    {
        $renderer = $this->renderer([$this->entry('a.less')]);

        $out = implode("\n", $renderer->render($this->profileRun([
            'request' => ['kind' => 'static', 'duration_ms' => 3200.0],
            'layout' => ['generated' => false],
        ])));

        self::assertStringContainsString('(includes asset build)', $out);
        self::assertStringContainsString('FPC       n/a', $out);
        self::assertStringNotContainsString('HANDLES', $out, 'a static request has no layout handles');
    }
}
