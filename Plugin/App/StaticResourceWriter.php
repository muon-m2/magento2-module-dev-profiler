<?php
/**
 * Copyright © Muon. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Muon\DevProfiler\Plugin\App;

use Magento\Framework\App\ResponseInterface;
use Magento\Framework\App\StaticResource;
use Muon\DevProfiler\Model\Run\Gate;
use Muon\DevProfiler\Model\Run\RunContext;
use Muon\DevProfiler\Model\Run\RunFinalizer;

/**
 * Closes the run for a static asset that had to be built.
 *
 * Magento serves an unmaterialised static file through App\StaticResource, which bootstraps
 * independently and never reaches App\Http. That is where LESS files are resolved — so without
 * this, the profiler records every template a page used and none of its stylesheets, and cannot
 * answer the question it exists for: which copy of a theme's LESS is actually being compiled.
 *
 * Only requests that reach PHP are seen. Once a file is materialised, nginx serves it directly and
 * there is nothing to record — which is correct, because at that point no fallback resolution
 * happens either.
 */
class StaticResourceWriter
{
    /**
     * @param \Muon\DevProfiler\Model\Run\Gate $gate
     * @param \Muon\DevProfiler\Model\Run\RunFinalizer $finalizer
     * @param \Muon\DevProfiler\Model\Run\RunContext $context
     * @param list<string> $keepExtensions Source extensions worth a stored run; empty keeps all.
     */
    public function __construct(
        private readonly Gate $gate,
        private readonly RunFinalizer $finalizer,
        private readonly RunContext $context,
        private readonly array $keepExtensions = ['.less', '.css']
    ) {
    }

    /**
     * @param \Magento\Framework\App\StaticResource $subject
     * @param \Magento\Framework\App\ResponseInterface $result
     * @return \Magento\Framework\App\ResponseInterface
     * @SuppressWarnings(PHPMD.UnusedFormalParameter) `$subject` is fixed by the plugin signature.
     */
    public function afterLaunch(StaticResource $subject, ResponseInterface $result): ResponseInterface
    {
        if ($this->gate->isProfiled() && $this->worthKeeping()) {
            $this->finalizer->finalize($result, RunFinalizer::KIND_STATIC);
        }

        return $result;
    }

    /**
     * Whether this static run carries the evidence the hook exists to capture.
     *
     * A cold page fires one of these requests per unmaterialised asset — routinely 150 to 400 on a
     * storefront — and each one used to write a run and prune the ring. Against a ring of 50 that
     * rotates it several times over during a single page load, evicting the page run the developer
     * was trying to read before they can read it. Measured on a live install: 22 of 50 stored runs
     * were static, carrying no queries at all.
     *
     * The hook is here for LESS and CSS resolution, which is the one thing App\Http cannot see. A
     * static run that resolved neither has nothing the ledger needs, so it is not written. Pass an
     * empty $keepExtensions to keep every static run, as before.
     *
     * @return bool
     */
    private function worthKeeping(): bool
    {
        if ($this->keepExtensions === []) {
            return true;
        }

        foreach ($this->context->all('fallback') as $entry) {
            $file = is_array($entry) ? (string)($entry['file'] ?? '') : '';

            foreach ($this->keepExtensions as $extension) {
                if ($file !== '' && str_ends_with($file, $extension)) {
                    return true;
                }
            }
        }

        return false;
    }
}
