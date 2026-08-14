<?php
/**
 * Copyright © Muon. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Muon\DevProfiler\Model\Report;

use Muon\DevProfiler\Model\Analysis\ResolutionSet;
use Muon\DevProfiler\Model\Analysis\ShadowResolver;

/**
 * Renders the fallback list: which files were looked up, which copy won, which were shadowed.
 *
 * Split out of RunRenderer, which had grown two jobs — the run summary at the top of the report,
 * and this. They change for different reasons: the summary follows what a request records, this
 * follows how findings are ranked, filtered and collapsed.
 */
class FallbackListRenderer
{
    /**
     * @param \Muon\DevProfiler\Model\Analysis\ShadowResolver $shadows
     * @param \Muon\DevProfiler\Model\Analysis\ResolutionSet $resolutions
     */
    public function __construct(
        private readonly ShadowResolver $shadows,
        private readonly ResolutionSet $resolutions
    ) {
    }

    /**
     * @param array<string, mixed> $run
     * @param array<string, mixed> $context
     * @param bool $shadowedOnly
     * @param string|null $filter
     * @return list<string>
     */
    public function render(array $run, array $context, bool $shadowedOnly, ?string $filter): array
    {
        return $this->fallbackSection($run, $context, $shadowedOnly, $filter);
    }

    /**
     * @param array<string, mixed> $run
     * @param array<string, mixed> $context
     * @param bool $shadowedOnly
     * @param string|null $filter
     * @return list<string>
     */
    private function fallbackSection(array $run, array $context, bool $shadowedOnly, ?string $filter): array
    {
        $recorded = is_array($run['fallback'] ?? null) ? $run['fallback'] : [];

        if ($recorded === []) {
            $lines = ['FALLBACK  nothing recorded'];

            // The single most confusing output this tool can produce. On a cache hit Magento loads
            // no design and resolves no files, so an empty profile is correct — but it reads
            // exactly like a broken tool unless it says so and says what to do instead.
            if ($this->isCacheHit($run)) {
                $lines[] = '';
                $lines[] = '  This request was served from the full page cache, so Magento resolved';
                $lines[] = '  no files and loaded no theme. That is why there is nothing here.';
                $lines[] = '  For fallback data, profile a cold request:';
                $lines[] = '      make profile-clear && bin/magento cache:flush   then reload the page';
            }

            return $lines;
        }

        $classified = $this->resolutions->present(
            $this->shadows->classify($recorded, (string)($context['theme_path'] ?? ''))
        );
        $shadowedCount = 0;
        $probeMisses = 0;
        $body = [];

        foreach ($classified as $entry) {
            if (($entry['shadowed'] ?? []) !== []) {
                $shadowedCount++;
            }

            if (($entry['anomaly'] ?? null) === 'probe-miss') {
                $probeMisses++;

                // Magento legitimately asks for files that may not exist. Counting them is honest;
                // listing them buries the signal.
                continue;
            }

            if ($this->skip($entry, $shadowedOnly, $filter)) {
                continue;
            }

            $body = array_merge($body, $this->entryLines($entry));
        }

        $header = $this->fallbackHeader(
            count($classified),
            count($recorded),
            $shadowedCount,
            $probeMisses,
            (int)($run['truncated']['fallback'] ?? 0)
        );

        if ($body === []) {
            $body[] = $this->emptyBodyReason($shadowedOnly, $filter, $probeMisses);
        }

        return array_merge([$header, ''], $body);
    }

    /**
     * Why the list came out empty.
     *
     * Each of these has a different remedy, so saying the wrong one sends the reader somewhere
     * useless — reporting "no resolution matched the filter" when no filter was given being the
     * clearest example.
     *
     * @param bool $shadowedOnly
     * @param string|null $filter
     * @param int $probeMisses
     * @return string
     */
    private function emptyBodyReason(bool $shadowedOnly, ?string $filter, int $probeMisses): string
    {
        if ($shadowedOnly) {
            return '  nothing is being shadowed on this request';
        }

        if ($filter !== null) {
            return '  no resolution matched the filter';
        }

        if ($probeMisses > 0) {
            return '  every lookup was a probe that found nothing — see the count above';
        }

        return '  nothing to show';
    }

    /**
     * Whether the filter matches this resolution's file name or any path it resolved to.
     *
     * Matching the requested file name alone made the most natural question unanswerable: "what is
     * this page pulling out of breeze-evolution?" is a search over resolved *paths*, and it
     * returned nothing at all even when the output plainly listed that theme.
     *
     * @param array<string, mixed> $entry
     * @param string $filter
     * @return bool
     */
    private function matches(array $entry, string $filter): bool
    {
        $haystacks = [(string)($entry['file'] ?? ''), (string)($entry['winner'] ?? '')];

        foreach ($entry['shadowed'] ?? [] as $shadow) {
            $haystacks[] = (string)$shadow;
        }

        foreach ($haystacks as $haystack) {
            if ($haystack !== '' && stripos($haystack, $filter) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether this resolution is filtered out of the report.
     *
     * @param array<string, mixed> $entry
     * @param bool $shadowedOnly
     * @param string|null $filter
     * @return bool
     */
    private function skip(array $entry, bool $shadowedOnly, ?string $filter): bool
    {
        if ($shadowedOnly && ($entry['shadowed'] ?? []) === []) {
            return true;
        }

        if ($filter === null) {
            return false;
        }

        return !$this->matches($entry, $filter);
    }

    /**
     * The lines describing one resolution.
     *
     * @param array<string, mixed> $entry
     * @return list<string>
     */
    private function entryLines(array $entry): array
    {
        $lookups = (int)($entry['lookups'] ?? 1);

        $lines = [
            sprintf('  %s%s', (string)$entry['file'], $lookups > 1 ? sprintf('   x%d', $lookups) : ''),
            sprintf('    won       %s', (string)($entry['winner'] ?? '(not found)')),
        ];

        foreach ($entry['shadowed'] as $shadow) {
            $lines[] = sprintf('    shadowed  %s   <-- ignored', (string)$shadow);
        }

        if (!empty($entry['anomaly'])) {
            $lines[] = sprintf('    anomaly   %s', (string)$entry['anomaly']);
        }

        return $lines;
    }

    /**
     * The one-line summary above the fallback list.
     *
     * @param int $distinct
     * @param int $lookups
     * @param int $shadowed
     * @param int $probeMisses
     * @param int $dropped
     * @return string
     */
    private function fallbackHeader(
        int $distinct,
        int $lookups,
        int $shadowed,
        int $probeMisses,
        int $dropped
    ): string {
        return sprintf(
            'FALLBACK  %d distinct files (%d lookups), %d shadowed%s%s',
            $distinct,
            $lookups,
            $shadowed,
            $probeMisses > 0 ? sprintf(', %d probe misses hidden', $probeMisses) : '',
            $dropped > 0 ? sprintf(' (%d more dropped at the cap)', $dropped) : ''
        );
    }

    /**
     * Whether this run was served from the full page cache.
     *
     * @param array<string, mixed> $run
     * @return bool
     */
    private function isCacheHit(array $run): bool
    {
        $layout = is_array($run['layout'] ?? null) ? $run['layout'] : [];
        $kind = (string)(($run['request'] ?? [])['kind'] ?? 'page');

        return $kind !== 'static' && empty($layout['generated']);
    }
}
