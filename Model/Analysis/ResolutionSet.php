<?php
/**
 * Copyright © Muon. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Muon\DevProfiler\Model\Analysis;

/**
 * Turns classified resolutions into the list a report shows.
 *
 * Two presentation rules, extracted here so every read surface applies the same ones. They were
 * private to the console renderer until a second surface needed them, and a second copy would have
 * been a second answer: the board would have shown four identical `etc/view.xml` rows where
 * `bin/magento muon:profile:show` shows one, and a reader comparing the two would have had no way to tell which was
 * lying.
 *
 * Nothing here discards evidence. The stored run keeps every lookup; only the presentation is
 * collapsed, and the count comes with it.
 *
 * @api
 */
class ResolutionSet
{
    /**
     * Collapsed, then ranked — the order a report renders.
     *
     * @param list<array<string, mixed>> $classified From ShadowResolver::classify().
     * @return list<array<string, mixed>>
     */
    public function present(array $classified): array
    {
        return $this->rank($this->collapse($classified));
    }

    /**
     * Collapse repeat lookups *before* classification, on the recorded entry's own identity.
     *
     * ShadowResolver::classify() stats every candidate directory for every entry it is handed, and
     * Magento resolves the same file more than once per request — reliably twice on a static run.
     * Collapsing afterwards, as present() does, means those duplicate stat calls have already been
     * paid: roughly 1,200-5,000 is_file() calls on a real run, about half of them repeats of a path
     * already probed.
     *
     * Safe because classification is a pure function of exactly these keys, so two entries that
     * collapse here would have classified identically. The count is returned alongside rather than
     * inside the entry, because classifyOne() rebuilds its result array and would drop it.
     *
     * @param list<array<string, mixed>> $recorded Raw fallback entries, as captured.
     * @return list<array{entry: array<string, mixed>, lookups: int}>
     */
    public function collapseRecorded(array $recorded): array
    {
        $collapsed = [];

        foreach ($recorded as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $key = implode('|', [
                (string)($entry['type'] ?? ''),
                (string)($entry['module'] ?? ''),
                (string)($entry['file'] ?? ''),
                (string)($entry['area'] ?? ''),
                (string)($entry['locale'] ?? ''),
                (string)($entry['theme'] ?? ''),
                (string)($entry['resolved'] ?? ''),
            ]);

            if (isset($collapsed[$key])) {
                $collapsed[$key]['lookups']++;

                continue;
            }

            $collapsed[$key] = ['entry' => $entry, 'lookups' => 1];
        }

        return array_values($collapsed);
    }

    /**
     * Collapse repeat lookups of the same file into one row carrying a count.
     *
     * Magento resolves the same file more than once per request — on a static run it is reliably
     * twice, so half of every report was a verbatim repeat of the line above it, and the headline
     * count was double the number of files actually involved.
     *
     * @param list<array<string, mixed>> $classified
     * @return list<array<string, mixed>>
     */
    public function collapse(array $classified): array
    {
        $collapsed = [];

        foreach ($classified as $entry) {
            $key = ($entry['type'] ?? '') . '|' . ($entry['module'] ?? '') . '|' . ($entry['file'] ?? '')
                . '|' . ($entry['winner'] ?? '');

            if (isset($collapsed[$key])) {
                $collapsed[$key]['lookups']++;

                continue;
            }

            $entry['lookups'] = 1;
            $collapsed[$key] = $entry;
        }

        return array_values($collapsed);
    }

    /**
     * Shadowed files first, then anomalies, then everything that resolved cleanly.
     *
     * @param list<array<string, mixed>> $entries
     * @return list<array<string, mixed>>
     */
    public function rank(array $entries): array
    {
        $weight = static function (array $entry): int {
            if (($entry['shadowed'] ?? []) !== []) {
                return 0;
            }

            return ($entry['anomaly'] ?? null) !== null ? 1 : 2;
        };

        // Carry the index and use it as the tie-breaker rather than trusting sort stability, so
        // original order is preserved within a weight group.
        $indexed = array_values($entries);
        $order = array_keys($indexed);

        usort($order, static function (int $a, int $b) use ($indexed, $weight): int {
            return [$weight($indexed[$a]), $a] <=> [$weight($indexed[$b]), $b];
        });

        return array_map(static fn (int $i): array => $indexed[$i], $order);
    }
}
