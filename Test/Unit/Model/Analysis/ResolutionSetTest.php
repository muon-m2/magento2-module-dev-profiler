<?php
/**
 * Copyright © Muon. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Muon\DevProfiler\Test\Unit\Model\Analysis;

use Muon\DevProfiler\Model\Analysis\ResolutionSet;
use PHPUnit\Framework\TestCase;

/**
 * @see ResolutionSet
 */
class ResolutionSetTest extends TestCase
{
    private ResolutionSet $set;

    protected function setUp(): void
    {
        $this->set = new ResolutionSet();
    }

    public function testCollapsesRepeatLookupsOfTheSameFileAndCountsThem(): void
    {
        $collapsed = $this->set->collapse([
            $this->entry('etc/view.xml', 'file', 'a/view.xml'),
            $this->entry('etc/view.xml', 'file', 'a/view.xml'),
            $this->entry('etc/view.xml', 'file', 'a/view.xml'),
        ]);

        self::assertCount(1, $collapsed);
        self::assertSame(3, $collapsed[0]['lookups']);
    }

    public function testDoesNotCollapseTheSameFileResolvedToDifferentWinners(): void
    {
        $collapsed = $this->set->collapse([
            $this->entry('etc/view.xml', 'file', 'a/view.xml'),
            $this->entry('etc/view.xml', 'file', 'b/view.xml'),
        ]);

        self::assertCount(2, $collapsed);
    }

    public function testEveryEntryCarriesALookupCountEvenWhenItIsOne(): void
    {
        $collapsed = $this->set->collapse([$this->entry('a.phtml', 'template', 'x/a.phtml')]);

        self::assertSame(1, $collapsed[0]['lookups']);
    }

    public function testRanksShadowedFirstThenAnomaliesThenCleanResolutions(): void
    {
        $clean = $this->entry('clean.xml', 'file', 'x/clean.xml');
        $anomalous = $this->entry('odd.xml', 'file', 'x/odd.xml') + [];
        $anomalous['anomaly'] = 'replay-diverged';
        $shadowed = $this->entry('shadow.xml', 'file', 'x/shadow.xml');
        $shadowed['shadowed'] = ['y/shadow.xml'];

        $ranked = $this->set->rank([$clean, $anomalous, $shadowed]);

        self::assertSame('shadow.xml', $ranked[0]['file']);
        self::assertSame('odd.xml', $ranked[1]['file']);
        self::assertSame('clean.xml', $ranked[2]['file']);
    }

    public function testRankingPreservesOriginalOrderWithinAGroup(): void
    {
        $first = $this->entry('a.xml', 'file', 'x/a.xml');
        $second = $this->entry('b.xml', 'file', 'x/b.xml');
        $third = $this->entry('c.xml', 'file', 'x/c.xml');

        $ranked = $this->set->rank([$first, $second, $third]);

        self::assertSame(['a.xml', 'b.xml', 'c.xml'], array_column($ranked, 'file'));
    }

    public function testPresentCollapsesThenRanks(): void
    {
        $shadowed = $this->entry('shadow.xml', 'file', 'x/shadow.xml');
        $shadowed['shadowed'] = ['y/shadow.xml'];

        $presented = $this->set->present([
            $this->entry('clean.xml', 'file', 'x/clean.xml'),
            $this->entry('clean.xml', 'file', 'x/clean.xml'),
            $shadowed,
        ]);

        self::assertCount(2, $presented);
        self::assertSame('shadow.xml', $presented[0]['file']);
        self::assertSame(2, $presented[1]['lookups']);
    }

    public function testAnEmptyListStaysEmpty(): void
    {
        self::assertSame([], $this->set->present([]));
    }

    /**
     * @param string $file
     * @param string $type
     * @param string $winner
     * @return array<string,mixed>
     */
    private function entry(string $file, string $type, string $winner): array
    {
        return [
            'type' => $type,
            'file' => $file,
            'module' => null,
            'winner' => $winner,
            'shadowed' => [],
            'candidates' => 2,
            'anomaly' => null,
        ];
    }
}
