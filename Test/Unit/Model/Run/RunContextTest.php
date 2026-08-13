<?php
/**
 * Copyright © Muon. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Muon\DevProfiler\Test\Unit\Model\Run;

use Muon\DevProfiler\Model\Run\RunContext;
use PHPUnit\Framework\TestCase;

/**
 * @see RunContext
 */
class RunContextTest extends TestCase
{
    public function testTokenIsStableWithinARun(): void
    {
        $context = new RunContext();

        self::assertSame($context->token(), $context->token());
        self::assertMatchesRegularExpression('/^[a-f0-9]{12}$/', $context->token());
    }

    public function testStopsAtTheCapAndCountsWhatItDropped(): void
    {
        $context = new RunContext(3);

        foreach (range(1, 10) as $i) {
            $context->push('fallback', ['n' => $i]);
        }

        self::assertSame(3, $context->count('fallback'));
        self::assertSame(7, $context->truncated('fallback'));
    }

    public function testCapsArePerList(): void
    {
        $context = new RunContext(1);
        $context->push('a', ['x' => 1]);
        $context->push('a', ['x' => 2]);
        $context->push('b', ['x' => 1]);

        self::assertSame(1, $context->count('a'));
        self::assertSame(1, $context->count('b'));
        self::assertSame(0, $context->truncated('b'));
    }

    public function testFreezeStopsAcceptingData(): void
    {
        $context = new RunContext();
        $context->push('fallback', ['before' => true]);
        $context->setMeta('k', 'before');

        $context->freeze();
        $context->push('fallback', ['after' => true]);
        $context->setMeta('k', 'after');

        self::assertTrue($context->isFrozen());
        self::assertSame(1, $context->count('fallback'));
        self::assertSame('before', $context->meta('k'));
    }

    public function testUnknownListsAndKeysAreEmptyRatherThanFatal(): void
    {
        $context = new RunContext();

        self::assertSame([], $context->all('nope'));
        self::assertSame(0, $context->count('nope'));
        self::assertSame(0, $context->truncated('nope'));
        self::assertSame('fallback', $context->meta('nope', 'fallback'));
    }
}
