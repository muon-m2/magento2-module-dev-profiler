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
     */
    public function __construct(
        private readonly Gate $gate,
        private readonly RunFinalizer $finalizer
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
        if ($this->gate->isProfiled()) {
            $this->finalizer->finalize($result, RunFinalizer::KIND_STATIC);
        }

        return $result;
    }
}
