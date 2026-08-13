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
}
