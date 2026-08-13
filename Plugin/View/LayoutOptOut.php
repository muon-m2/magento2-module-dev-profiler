<?php
/**
 * Copyright © Muon. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Muon\DevProfiler\Plugin\View;

use Magento\Framework\View\LayoutFactory;
use Magento\Framework\View\LayoutInterface;
use Muon\DevProfiler\Model\Run\Gate;
use Muon\DevProfiler\Model\Run\RunContext;

/**
 * Catches the other way a page becomes uncacheable.
 *
 * `Layout::isCacheable()` returns false for two quite different reasons: a generated block declared
 * cacheable="false", or the layout object was simply *constructed* non-cacheable before any block
 * was considered. Only the first leaves a trace in layout XML — which is why a page can report
 * itself uncacheable while the block list truthfully says nothing is responsible. An answer that is
 * correct and completely useless.
 *
 * `cacheable` is a constructor argument with no setter, so the only place to catch the second case
 * is where layouts are built. Core does it in several places, and so do third-party modules;
 * recording the call site turns "something did this" into a file and a line.
 *
 * The backtrace here is affordable precisely because this fires a handful of times per request, not
 * once per statement.
 */
class LayoutOptOut
{
    /**
     * How far up to walk. Deep enough to pass the factory and interceptor frames and reach the
     * caller that actually asked for a non-cacheable layout.
     */
    private const TRACE_DEPTH = 20;

    /**
     * @param \Muon\DevProfiler\Model\Run\Gate $gate
     * @param \Muon\DevProfiler\Model\Run\RunContext $context
     */
    public function __construct(
        private readonly Gate $gate,
        private readonly RunContext $context
    ) {
    }

    /**
     * Record layouts constructed with cacheable disabled.
     *
     * @param \Magento\Framework\View\LayoutFactory $subject
     * @param \Magento\Framework\View\LayoutInterface $result
     * @param array<string, mixed> $data
     * @return \Magento\Framework\View\LayoutInterface
     * @SuppressWarnings(PHPMD.UnusedFormalParameter) `$subject` is fixed by the plugin signature.
     */
    public function afterCreate(
        LayoutFactory $subject,
        LayoutInterface $result,
        array $data = []
    ): LayoutInterface {
        // Cheapest checks first: the overwhelming majority of layouts are built cacheable, and
        // those must not pay for a gate lookup at all.
        if (!array_key_exists('cacheable', $data) || $data['cacheable'] || !$this->gate->isProfiled()) {
            return $result;
        }

        try {
            $this->context->push('constructor_optouts', [
                'origin' => $this->origin(),
            ]);
        } catch (\Throwable) {
            // Diagnostics are never worth a failed page.
        }

        return $result;
    }

    /**
     * The first frame outside this module and the framework's own factory plumbing.
     *
     * @return string
     */
    private function origin(): string
    {
        $frames = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, self::TRACE_DEPTH);

        foreach ($frames as $frame) {
            $file = $frame['file'] ?? null;

            if (!is_string($file)) {
                continue;
            }

            if (str_contains($file, '/Muon/DevProfiler/')
                || str_contains($file, '/generated/code/')
                || str_contains($file, '/framework/View/LayoutFactory')
                || str_contains($file, '/framework/ObjectManager/')
            ) {
                continue;
            }

            return basename($file) . ':' . (string)($frame['line'] ?? '?');
        }

        return 'unknown';
    }
}
