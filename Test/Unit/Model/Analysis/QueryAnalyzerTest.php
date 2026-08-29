<?php
/**
 * Copyright © Muon. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Muon\DevProfiler\Test\Unit\Model\Analysis;

use Muon\DevProfiler\Model\Analysis\QueryAnalyzer;
use PHPUnit\Framework\TestCase;

/**
 * @see QueryAnalyzer
 */
class QueryAnalyzerTest extends TestCase
{
    private QueryAnalyzer $analyzer;

    protected function setUp(): void
    {
        $this->analyzer = new QueryAnalyzer();
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function group(array $overrides = []): array
    {
        return array_replace([
            'fingerprint' => 'SELECT * FROM t WHERE id = ?',
            'sample' => 'SELECT * FROM t WHERE id = 1',
            'count' => 1,
            'total_ms' => 1.0,
            'max_ms' => 1.0,
            'binds' => ['id' => 1],
            'origin' => 'Foo.php:1',
            'is_userland' => true,
        ], $overrides);
    }

    public function testAParameterisedShapeRepeatedPastTheThresholdIsAnNPlusOne(): void
    {
        $out = $this->analyzer->classify([$this->group(['count' => 47, 'total_ms' => 56.0])]);

        self::assertSame(QueryAnalyzer::N_PLUS_ONE, $out['groups'][0]['finding']['kind']);
        self::assertGreaterThan(0, $out['groups'][0]['finding']['saving_ms']);
    }

    /**
     * The distinction the class is careful about: the same statement text repeated with nothing
     * bound is a caching problem, not a loop.
     */
    public function testARepeatedShapeWithNoBoundArgumentsIsADuplicateNotAnNPlusOne(): void
    {
        $out = $this->analyzer->classify([$this->group(['count' => 47, 'binds' => []])]);

        self::assertSame(QueryAnalyzer::DUPLICATE, $out['groups'][0]['finding']['kind']);
    }

    /**
     * The false positive this guard exists for.
     *
     * Magento inlines literals as often as it binds them, and the fingerprint normalises those away.
     * Seven lookups of seven DIFFERENT CMS blocks arrive as one shape with seven executions and no
     * binds — which read as "the same query repeated seven times". It cost a real debugging session
     * chasing a duplicate that did not exist, so the collector now records whether the text changed
     * and that observation outranks the bind-based guess.
     */
    public function testAShapeWhoseTextVariedIsNotReportedAsAnIdenticalStatement(): void
    {
        $out = $this->analyzer->classify(
            [$this->group(['count' => 7, 'binds' => [], 'sql_varies' => true])]
        );

        $finding = $out['groups'][0]['finding'];

        self::assertSame(QueryAnalyzer::N_PLUS_ONE, $finding['kind']);
        self::assertStringContainsString('observed', $finding['basis']);
    }

    /**
     * Varying text below the N+1 threshold is still N distinct reads. Calling it a duplicate would
     * point the reader at a cache that would return the wrong row.
     */
    public function testVariedTextBelowTheNPlusOneThresholdIsStillNotADuplicate(): void
    {
        $out = $this->analyzer->classify(
            [$this->group(['count' => 4, 'binds' => [], 'sql_varies' => true])],
            ['nplus1' => 50, 'duplicate' => 3]
        );

        self::assertSame(QueryAnalyzer::N_PLUS_ONE, $out['groups'][0]['finding']['kind']);
    }

    /**
     * Runs captured before the collector recorded the flag must keep their old reading rather than
     * silently becoming "text did not vary" — the key is absent, not false.
     */
    public function testARunCapturedWithoutTheFlagFallsBackToBindInference(): void
    {
        $group = $this->group(['count' => 47]);
        unset($group['sql_varies']);

        $out = $this->analyzer->classify([$group]);

        self::assertSame(QueryAnalyzer::N_PLUS_ONE, $out['groups'][0]['finding']['kind']);
        self::assertStringContainsString('not proven', $out['groups'][0]['finding']['basis']);
    }

    public function testTheFindingStatesWhatItsClassificationRestsOn(): void
    {
        $out = $this->analyzer->classify([$this->group(['count' => 47])]);

        self::assertStringContainsString('not proven', $out['groups'][0]['finding']['basis']);
    }

    public function testASingleSlowStatementIsReportedAsSlow(): void
    {
        $out = $this->analyzer->classify([$this->group(['count' => 1, 'max_ms' => 120.0])]);

        self::assertSame(QueryAnalyzer::SLOW, $out['groups'][0]['finding']['kind']);
    }

    public function testAFastSingleStatementIsNotAFinding(): void
    {
        $out = $this->analyzer->classify([$this->group()]);

        self::assertNull($out['groups'][0]['finding']);
        self::assertSame(0, $out['findings']);
    }

    // ---- threshold boundaries ----

    public function testTheNPlusOneThresholdIsInclusive(): void
    {
        $at = $this->analyzer->classify([$this->group(['count' => 5])], ['nplus1' => 5]);
        $below = $this->analyzer->classify([$this->group(['count' => 4])], ['nplus1' => 5, 'duplicate' => 99]);

        self::assertSame(QueryAnalyzer::N_PLUS_ONE, $at['groups'][0]['finding']['kind']);
        self::assertNull($below['groups'][0]['finding']);
    }

    public function testTheSlowThresholdIsInclusive(): void
    {
        $at = $this->analyzer->classify([$this->group(['max_ms' => 50.0])], ['slow_ms' => 50]);
        $below = $this->analyzer->classify([$this->group(['max_ms' => 49.9])], ['slow_ms' => 50]);

        self::assertSame(QueryAnalyzer::SLOW, $at['groups'][0]['finding']['kind']);
        self::assertNull($below['groups'][0]['finding']);
    }

    /**
     * The point of read-time thresholds: the same capture re-examined at a different sensitivity.
     */
    public function testTheSameCaptureReclassifiesAtADifferentThreshold(): void
    {
        $capture = [$this->group(['count' => 6])];

        $strict = $this->analyzer->classify($capture, ['nplus1' => 5]);
        $loose = $this->analyzer->classify($capture, ['nplus1' => 50, 'duplicate' => 50]);

        self::assertSame(QueryAnalyzer::N_PLUS_ONE, $strict['groups'][0]['finding']['kind']);
        self::assertNull($loose['groups'][0]['finding']);
    }

    public function testFindingsSortAheadOfEverythingElse(): void
    {
        $out = $this->analyzer->classify([
            $this->group(['fingerprint' => 'quiet', 'count' => 1]),
            $this->group(['fingerprint' => 'loop', 'count' => 40]),
        ]);

        self::assertSame('loop', $out['groups'][0]['fingerprint']);
    }

    public function testTotalsCountStatementsNotShapes(): void
    {
        $out = $this->analyzer->classify([
            $this->group(['count' => 40, 'total_ms' => 40.0]),
            $this->group(['fingerprint' => 'b', 'count' => 2, 'total_ms' => 2.0]),
        ]);

        self::assertSame(2, $out['shapes']);
        self::assertSame(42, $out['statements']);
        self::assertSame(42.0, $out['total_ms']);
    }

    public function testMalformedGroupsAreSkippedRatherThanFatal(): void
    {
        // Deliberately the wrong shape: a stored run is JSON decoded from disk and may have been
        // written by an older collector, so classify() must survive junk rather than fatal. The
        // type violation is the assertion.
        // @phpstan-ignore-next-line argument.type
        $out = $this->analyzer->classify([$this->group(), 'nonsense', 42]);

        self::assertSame(1, $out['shapes']);
    }
}
