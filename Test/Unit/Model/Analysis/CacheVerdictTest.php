<?php
/**
 * Copyright © Muon. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Muon\DevProfiler\Test\Unit\Model\Analysis;

use Muon\DevProfiler\Model\Analysis\CacheVerdict;
use PHPUnit\Framework\TestCase;

/**
 * @see CacheVerdict
 */
class CacheVerdictTest extends TestCase
{
    private CacheVerdict $verdicts;

    protected function setUp(): void
    {
        $this->verdicts = new CacheVerdict();
    }

    public function testLayoutNeverGeneratedMeansCacheHit(): void
    {
        $result = $this->verdicts->verdict(['generated' => false]);

        self::assertSame(CacheVerdict::HIT, $result['status']);
    }

    public function testGeneratedAndCacheableIsAMiss(): void
    {
        $result = $this->verdicts->verdict(['generated' => true, 'cacheable' => true]);

        self::assertSame(CacheVerdict::MISS, $result['status']);
    }

    public function testNamesTheGeneratedBlockResponsible(): void
    {
        $result = $this->verdicts->verdict([
            'generated' => true,
            'cacheable' => false,
            'uncacheable_blocks' => [['name' => 'cart.sidebar', 'in_play' => true]],
        ]);

        self::assertSame(CacheVerdict::UNCACHEABLE, $result['status']);
        self::assertTrue($result['cause_known']);
        self::assertStringContainsString('cart.sidebar', $result['causes'][0]['detail']);
    }

    /**
     * Declarations that never generated are not why the page is uncacheable, and reporting them
     * sends somebody to edit a file that had no effect.
     */
    public function testIgnoresBlocksDeclaredButNeverGenerated(): void
    {
        $result = $this->verdicts->verdict([
            'generated' => true,
            'cacheable' => false,
            'uncacheable_blocks' => [['name' => 'never.rendered', 'in_play' => false]],
        ]);

        self::assertSame(CacheVerdict::UNCACHEABLE, $result['status']);
        self::assertFalse($result['cause_known']);
        self::assertSame([], $result['causes']);
    }

    public function testNamesTheConstructorOptOutOrigin(): void
    {
        $result = $this->verdicts->verdict([
            'generated' => true,
            'cacheable' => false,
            'constructor_optouts' => [['origin' => 'Coupon.php:44']],
        ]);

        self::assertTrue($result['cause_known']);
        self::assertStringContainsString('Coupon.php:44', $result['causes'][0]['detail']);
    }

    /**
     * An invented cause is worse than no cause.
     */
    public function testSaysCauseUnknownRatherThanGuessing(): void
    {
        $result = $this->verdicts->verdict(['generated' => true, 'cacheable' => false]);

        self::assertSame(CacheVerdict::UNCACHEABLE, $result['status']);
        self::assertFalse($result['cause_known']);
        self::assertStringContainsString('cause unknown', $result['summary']);
    }

    /**
     * A static asset has no layout, so "hit" — technically true of the mechanism — would be
     * meaningless to the reader.
     */
    public function testStaticAssetRequestsGetNoCacheVerdict(): void
    {
        $result = $this->verdicts->verdict(['generated' => false], 'static');

        self::assertSame(CacheVerdict::NOT_APPLICABLE, $result['status']);
        self::assertFalse($result['cause_known']);
    }

    public function testPageRequestsStillGetAVerdictWhenKindIsPassed(): void
    {
        $result = $this->verdicts->verdict(['generated' => true, 'cacheable' => true], 'page');

        self::assertSame(CacheVerdict::MISS, $result['status']);
    }

    public function testUnknownWhenLayoutCouldNotAnswer(): void
    {
        $result = $this->verdicts->verdict(['generated' => true, 'cacheable' => null]);

        self::assertSame(CacheVerdict::UNKNOWN, $result['status']);
    }
}
