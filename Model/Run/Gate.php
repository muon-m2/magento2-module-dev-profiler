<?php
/**
 * Copyright © Muon. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Muon\DevProfiler\Model\Run;

use Magento\Framework\App\Area;
use Magento\Framework\App\State;

/**
 * Decides whether this request is profiled.
 *
 * One question, asked by every collector as its first line, and answered once per request.
 *
 * There is deliberately no configuration field behind this. A profiler that can be switched on
 * in production is a profiler that eventually is, and nothing this module records is worth that
 * risk — so the answer is compiled into the code path rather than read from the database.
 *
 * Both checks fail closed. An installation that cannot report its own mode or has not yet
 * resolved an area is treated as production, because the alternative — assuming developer mode
 * when we cannot tell — is the one mistake with a real consequence.
 */
class Gate
{
    /**
     * Memoized answer; null until the first call resolves it.
     *
     * @var bool|null
     */
    private ?bool $profiled = null;

    /**
     * @param \Magento\Framework\App\State $appState
     */
    public function __construct(
        private readonly State $appState
    ) {
    }

    /**
     * Whether collectors may record anything for this request.
     *
     * @return bool
     */
    public function isProfiled(): bool
    {
        if ($this->profiled !== null) {
            return $this->profiled;
        }

        // Mode is asked first, and its "no" is remembered forever. State::getMode() reads a value
        // fixed at bootstrap from MAGE_MODE, so it cannot change within a process and a production
        // install can answer once. Asking the area first instead costs a thrown LocalizedException
        // on every call in any process that never sets one — which is every bin/magento command,
        // since Console\Cli does not set an area — and that exception captures a backtrace over the
        // whole stack, 40-80 frames deep when the caller is the DB layer.
        if (!$this->isDeveloperMode()) {
            return $this->profiled = false;
        }

        // The answer is not knowable until the request has an area, and Http::launch() sets it
        // partway through its own execution. A caller that asks before then — a globally scoped
        // plugin, anything running during bootstrap — must get "no" *without that no being
        // remembered*, or the first such question silences every collector for the whole request.
        // The failure would be silent: no error, just an empty profile.
        if (!$this->areaResolved()) {
            return false;
        }

        return $this->profiled = $this->isFrontendArea();
    }

    /**
     * Whether the answer above is final, i.e. safe for a caller to cache alongside its own state.
     *
     * A collector that asks per statement needs to know the difference between "no, not yet" and
     * "no, and never on this process". Only the second may be remembered.
     *
     * @return bool
     */
    public function isDecided(): bool
    {
        return $this->profiled !== null;
    }

    /**
     * Whether the request has decided what area it is yet.
     *
     * @return bool
     */
    private function areaResolved(): bool
    {
        try {
            $this->appState->getAreaCode();

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Whether the installation is in developer mode.
     *
     * @return bool
     */
    private function isDeveloperMode(): bool
    {
        try {
            return $this->appState->getMode() === State::MODE_DEVELOPER;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Whether the request has resolved to the storefront.
     *
     * @return bool
     */
    private function isFrontendArea(): bool
    {
        try {
            return $this->appState->getAreaCode() === Area::AREA_FRONTEND;
        } catch (\Throwable) {
            // Thrown when the area is not set yet, which is itself the answer: not yet.
            return false;
        }
    }
}
