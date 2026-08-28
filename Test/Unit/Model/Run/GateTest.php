<?php
/**
 * Copyright © Muon. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Muon\DevProfiler\Test\Unit\Model\Run;

use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;
use Muon\DevProfiler\Model\Run\Gate;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/**
 * @see Gate
 */
#[AllowMockObjectsWithoutExpectations]
class GateTest extends TestCase
{
    /**
     * @param string $mode
     * @param string $area
     * @return Gate
     */
    private function gate(string $mode, string $area): Gate
    {
        /** @var State&\PHPUnit\Framework\MockObject\MockObject $state */
        $state = $this->createMock(State::class);
        $state->method('getMode')->willReturn($mode);
        $state->method('getAreaCode')->willReturn($area);

        return new Gate($state);
    }

    public function testProfilesDeveloperModeOnTheStorefront(): void
    {
        self::assertTrue($this->gate(State::MODE_DEVELOPER, Area::AREA_FRONTEND)->isProfiled());
    }

    public function testRefusesProductionMode(): void
    {
        self::assertFalse($this->gate(State::MODE_PRODUCTION, Area::AREA_FRONTEND)->isProfiled());
    }

    public function testRefusesDefaultMode(): void
    {
        self::assertFalse($this->gate(State::MODE_DEFAULT, Area::AREA_FRONTEND)->isProfiled());
    }

    public function testRefusesNonFrontendAreas(): void
    {
        self::assertFalse($this->gate(State::MODE_DEVELOPER, Area::AREA_ADMINHTML)->isProfiled());
    }

    public function testFailsClosedWhenModeCannotBeRead(): void
    {
        /** @var State&\PHPUnit\Framework\MockObject\MockObject $state */
        $state = $this->createMock(State::class);
        $state->method('getAreaCode')->willReturn(Area::AREA_FRONTEND);
        $state->method('getMode')->willThrowException(new \RuntimeException('broken'));

        self::assertFalse((new Gate($state))->isProfiled());
    }

    /**
     * The regression this class exists to prevent.
     *
     * Http::launch() sets the area code partway through its own execution. A caller asking before
     * that point must get "no" WITHOUT that answer being memoized — otherwise the first such
     * question silences every collector for the rest of the request, silently.
     */
    public function testDoesNotMemoizeAnAnswerGivenBeforeTheAreaResolved(): void
    {
        /** @var State&\PHPUnit\Framework\MockObject\MockObject $state */
        $state = $this->createMock(State::class);
        $state->method('getMode')->willReturn(State::MODE_DEVELOPER);
        $state->method('getAreaCode')->willReturnOnConsecutiveCalls(
            self::throwException(new LocalizedException(new Phrase('Area code is not set'))),
            Area::AREA_FRONTEND,
            Area::AREA_FRONTEND
        );

        $gate = new Gate($state);

        self::assertFalse($gate->isProfiled(), 'asked too early — must answer no');
        self::assertTrue($gate->isProfiled(), 'once the area resolves the early no must not persist');
    }

    /**
     * The other regression, and the more expensive one.
     *
     * getAreaCode() throws when no area is set, and bin/magento never sets one — so asking it
     * before the mode cost a constructed-and-thrown LocalizedException per call, with a backtrace
     * captured over the whole stack. Every production install paid it on every statement.
     */
    public function testProductionModeIsAnsweredWithoutEverAskingForTheArea(): void
    {
        /** @var State&\PHPUnit\Framework\MockObject\MockObject $state */
        $state = $this->createMock(State::class);
        $state->method('getMode')->willReturn(State::MODE_PRODUCTION);
        $state->expects(self::never())->method('getAreaCode');

        self::assertFalse((new Gate($state))->isProfiled());
    }

    /**
     * A mode that cannot change within the process may be answered once and remembered, so a
     * caller asking per statement is not re-entered thousands of times.
     */
    public function testASettledNoIsAskedOnlyOnce(): void
    {
        /** @var State&\PHPUnit\Framework\MockObject\MockObject $state */
        $state = $this->createMock(State::class);
        $state->expects(self::once())->method('getMode')->willReturn(State::MODE_PRODUCTION);

        $gate = new Gate($state);

        self::assertFalse($gate->isProfiled());
        self::assertFalse($gate->isProfiled());
        self::assertFalse($gate->isProfiled());
    }

    public function testReportsWhetherItsAnswerIsSettled(): void
    {
        $decided = $this->gate(State::MODE_PRODUCTION, Area::AREA_FRONTEND);
        $decided->isProfiled();

        self::assertTrue($decided->isDecided(), 'production cannot change — the no is final');

        /** @var State&\PHPUnit\Framework\MockObject\MockObject $state */
        $state = $this->createMock(State::class);
        $state->method('getMode')->willReturn(State::MODE_DEVELOPER);
        $state->method('getAreaCode')
            ->willThrowException(new LocalizedException(new Phrase('Area code is not set')));

        $premature = new Gate($state);

        self::assertFalse($premature->isProfiled());
        self::assertFalse($premature->isDecided(), 'a not-yet must never be cached by a caller');
    }
}
