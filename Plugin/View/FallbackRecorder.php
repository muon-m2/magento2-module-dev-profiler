<?php
/**
 * Copyright © Muon. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Muon\DevProfiler\Plugin\View;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\View\Design\FileResolution\Fallback\ResolverInterface;
use Magento\Framework\View\Design\ThemeInterface;
use Muon\DevProfiler\Model\Run\Gate;
use Muon\DevProfiler\Model\Run\RunContext;

/**
 * Records which file won every fallback lookup, and the arguments that produced it.
 *
 * This is the highest-frequency hook in the module — several hundred calls on a developer-mode
 * page, where nothing is cached. So it does the least possible: one gate check and one array
 * append, of values the framework already handed it. No stat calls, no path building beyond
 * trimming the root, no enumeration of alternatives.
 *
 * Working out which *other* copies of the file existed is the interesting half, and it is
 * deliberately not done here. ShadowResolver replays the same lookup at read time, in the CLI,
 * where thousands of stat calls cost the profiled page nothing.
 */
class FallbackRecorder
{
    /**
     * @var string|null
     */
    private ?string $root = null;

    /**
     * @param \Muon\DevProfiler\Model\Run\Gate $gate
     * @param \Muon\DevProfiler\Model\Run\RunContext $context
     * @param \Magento\Framework\App\Filesystem\DirectoryList $directoryList
     */
    public function __construct(
        private readonly Gate $gate,
        private readonly RunContext $context,
        private readonly DirectoryList $directoryList
    ) {
    }

    /**
     * Record one resolution.
     *
     * @param \Magento\Framework\View\Design\FileResolution\Fallback\ResolverInterface $subject
     * @param string|false $result
     * @param string $type
     * @param string $file
     * @param string|null $area
     * @param \Magento\Framework\View\Design\ThemeInterface|null $theme
     * @param string|null $locale
     * @param string|null $module
     * @return string|false
     * @SuppressWarnings(PHPMD.UnusedFormalParameter) `$subject` is fixed by the plugin signature.
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) One branch per argument that may be absent. The
     * resolver is called with anything from two to seven arguments depending on the caller, and the
     * recorder has to describe the lookup that actually happened rather than the one it expected.
     */
    public function afterResolve(
        ResolverInterface $subject,
        $result,
        $type,
        $file,
        $area = null,
        ?ThemeInterface $theme = null,
        $locale = null,
        $module = null
    ) {
        if (!$this->gate->isProfiled()) {
            return $result;
        }

        try {
            $this->context->push('fallback', [
                'type' => (string)$type,
                'file' => (string)$file,
                'module' => $module !== null ? (string)$module : null,
                // Recorded verbatim because ShadowResolver needs it to pick the matching
                // fallback rule; guessing it at read time would silently mis-resolve.
                'area' => $area !== null ? (string)$area : null,
                'locale' => $locale !== null ? (string)$locale : null,
                'theme' => $theme?->getThemePath() ?: ($theme?->getCode() ?: null),
                'resolved' => is_string($result) && $result !== '' ? $this->relative($result) : null,
            ]);
        } catch (\Throwable) {
            // Bookkeeping must never stop a file from being served.
        }

        return $result;
    }

    /**
     * @param string $path
     * @return string
     */
    private function relative(string $path): string
    {
        try {
            $this->root ??= rtrim($this->directoryList->getRoot(), '/') . '/';
        } catch (\Throwable) {
            return $path;
        }

        return str_starts_with($path, $this->root) ? substr($path, strlen($this->root)) : $path;
    }
}
