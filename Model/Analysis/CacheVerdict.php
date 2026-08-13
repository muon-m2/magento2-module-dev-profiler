<?php
/**
 * Copyright © Muon. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Muon\DevProfiler\Model\Analysis;

/**
 * Turns recorded layout facts into a cache verdict and, where the evidence supports one, a cause.
 *
 * Magento will tell you a page was not cached. It will not tell you what did it, and finding out
 * by hand means grepping every layout file that could have applied to the handles in play — then
 * discovering the culprit was not in layout XML at all, because a block constructed its own
 * non-cacheable layout in PHP.
 *
 * Both paths are recorded upstream, so both can be named here. When neither names anything, this
 * says so. An invented cause is worse than no cause: it sends somebody to edit the wrong file.
 */
class CacheVerdict
{
    public const HIT = 'hit';
    public const MISS = 'miss';
    public const UNCACHEABLE = 'uncacheable';
    public const UNKNOWN = 'unknown';
    public const NOT_APPLICABLE = 'n/a';

    /**
     * @param array<string, mixed> $layout Facts recorded by LayoutVerdict and LayoutOptOut.
     * @return array<string, mixed>
     */
    public function verdict(array $layout, string $requestKind = 'page'): array
    {
        // A static asset has no layout and no page cache verdict to give. Reporting "hit" because
        // layout never generated would be true of the mechanism and meaningless to the reader.
        if ($requestKind === 'static') {
            return $this->result(self::NOT_APPLICABLE, [], 'Static asset — no layout, no page cache.');
        }

        // Layout never ran, so nothing built the page: it came out of the full page cache. This is
        // why no X-Magento-Cache-Debug header is needed — that header only appears when Magento's
        // own cache debugging is switched on, and a tool that needs another setting enabled before
        // it can answer is not much of a tool.
        if (empty($layout['generated'])) {
            return $this->result(self::HIT, [], 'Layout never generated — served from full page cache.');
        }

        $cacheable = $layout['cacheable'] ?? null;

        if ($cacheable === true) {
            return $this->result(self::MISS, [], 'Page was built and is cacheable.');
        }

        if ($cacheable === null) {
            return $this->result(self::UNKNOWN, [], 'Layout could not report whether the page is cacheable.');
        }

        return $this->uncacheable($layout);
    }

    /**
     * @param array<string, mixed> $layout
     * @return array<string, mixed>
     */
    private function uncacheable(array $layout): array
    {
        $causes = [];

        foreach ($this->asList($layout['uncacheable_blocks'] ?? null) as $block) {
            // in_play is the whole distinction. The merged layout XML contains cacheable="false"
            // declarations inside handles and references that never produced a generated element;
            // listing those produces a panel that contradicts the verdict beside it.
            if (empty($block['in_play'])) {
                continue;
            }

            $causes[] = [
                'kind' => 'block',
                'detail' => sprintf('block "%s" declares cacheable="false"', (string)($block['name'] ?? '?')),
                'name' => $block['name'] ?? null,
                'class' => $block['class'] ?? null,
                'template' => $block['template'] ?? null,
            ];
        }

        foreach ($this->asList($layout['constructor_optouts'] ?? null) as $optOut) {
            $causes[] = [
                'kind' => 'constructor',
                'detail' => sprintf(
                    '%s constructed a non-cacheable layout',
                    (string)($optOut['origin'] ?? 'unknown origin')
                ),
                'origin' => $optOut['origin'] ?? null,
            ];
        }

        if ($causes === []) {
            return $this->result(
                self::UNCACHEABLE,
                [],
                'Layout reports the page is uncacheable, but no generated block and no layout '
                . 'construction accounts for it — cause unknown.'
            );
        }

        return $this->result(self::UNCACHEABLE, $causes, $causes[0]['detail']);
    }

    /**
     * @param mixed $value
     * @return list<array<string, mixed>>
     */
    private function asList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, 'is_array'));
    }

    /**
     * @param string $status
     * @param list<array<string, mixed>> $causes
     * @param string $summary
     * @return array<string, mixed>
     */
    private function result(string $status, array $causes, string $summary): array
    {
        return [
            'status' => $status,
            'summary' => $summary,
            'causes' => $causes,
            'cause_known' => $causes !== [],
        ];
    }
}
