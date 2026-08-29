<?php
/**
 * Copyright © Muon. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Muon\DevProfiler\Plugin\View;

use Magento\Framework\View\Layout;
use Muon\DevProfiler\Model\Run\Gate;
use Muon\DevProfiler\Model\Run\RunContext;

/**
 * Records the handles, the authoritative cacheable flag, and the blocks that opted out.
 *
 * Two details make the difference between a useful answer and a misleading one.
 *
 * The flag is read in generateElements() rather than after generateXml(), because that is the same
 * point PageCache's own layout plugin reads it to decide whether to send public headers. Asking
 * earlier gives an answer that has not been decided yet.
 *
 * The opted-out blocks are cross-checked with hasElement(). Merged layout XML contains
 * cacheable="false" declarations inside handles and references that never produced a generated
 * element; reporting those makes the panel contradict the verdict printed beside it.
 */
class LayoutVerdict
{
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
     * Note that layout actually ran, and record the handles in play.
     *
     * @param \Magento\Framework\View\Layout $subject
     * @param mixed $result
     * @return mixed
     */
    public function afterGenerateXml(Layout $subject, mixed $result): mixed
    {
        if (!$this->gate->isProfiled()) {
            return $result;
        }

        // The single fact that distinguishes a full-page-cache hit from a miss: on a hit, layout
        // never runs at all, so this line is never reached.
        $this->context->setMeta('layout_generated', true);

        try {
            $this->context->setMeta('layout_handles', array_values($subject->getUpdate()->getHandles()));
        } catch (\Throwable) {
            $this->context->setMeta('layout_handles', []);
        }

        return $result;
    }

    /**
     * Record the cacheable verdict and everything that could account for it.
     *
     * @param \Magento\Framework\View\Layout $subject
     * @param mixed $result
     * @return mixed
     */
    public function afterGenerateElements(Layout $subject, mixed $result): mixed
    {
        if (!$this->gate->isProfiled()) {
            return $result;
        }

        try {
            $this->context->setMeta('layout_cacheable', $subject->isCacheable());
        } catch (\Throwable) {
            $this->context->setMeta('layout_cacheable', null);
        }

        $this->recordOptOuts($subject);

        return $result;
    }

    /**
     * Find the declarations that opt this page out of full page caching.
     *
     * The xpath deliberately matches Magento's own in Layout::isCacheable() — `//block` carrying
     * cacheable="false" — so the list and the verdict cannot disagree, and it reads the same tree
     * through the same accessor.
     *
     * @param \Magento\Framework\View\Layout $subject
     * @return void
     */
    private function recordOptOuts(Layout $subject): void
    {
        try {
            // getNode(), not getUpdate()->asSimplexml(). Merge::asSimplexml() caches nothing: each
            // call re-implodes every merged layout fragment and re-parses the result, measured at
            // 0.8ms for a 100KB merged layout and 8.7ms at 800KB. generateXml() has already built
            // that tree and handed it to setXml(), so this is the same document for the cost of a
            // property read — and it is the tree Layout::isCacheable() itself consults, which is the
            // one this list has to agree with. It also runs inside the request whose duration_ms
            // the profiler reports, so paying for it twice inflated the number being measured.
            //
            // getNode() rather than getXml(): Simplexml\Config::getXml() is protected, getNode()
            // is its public accessor and returns false when no tree has been set.
            $xml = $subject->getNode();

            if (!$xml instanceof \Magento\Framework\Simplexml\Element) {
                return;
            }

            $nodes = $xml->xpath('//block[@cacheable="false"]') ?: [];
        } catch (\Throwable) {
            // Layout XML that cannot be re-read is not worth failing a page render over.
            return;
        }

        foreach ($nodes as $node) {
            $attributes = $node->attributes();
            $name = (string)($attributes['name'] ?? '');

            $this->context->push('uncacheable_blocks', [
                'name' => $name,
                'class' => (string)($attributes['class'] ?? ''),
                'template' => (string)($attributes['template'] ?? ''),
                // False means declared somewhere in the merged layout but never generated — so it
                // is not why this page is uncacheable, and must not be reported as the cause.
                'in_play' => $name !== '' && $subject->hasElement($name),
            ]);
        }
    }
}
