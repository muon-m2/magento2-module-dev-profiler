<?php
/**
 * Copyright © Muon. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Muon\DevProfiler\Model\Analysis;

/**
 * Turns recorded statement groups into findings, at read time.
 *
 * Thresholds arrive as arguments rather than configuration, so an existing capture can be
 * re-examined at a different sensitivity without re-running the page — the same reason the theme
 * and cache analysis live here rather than in the collectors.
 *
 * ## The distinction this class is careful about
 *
 * An N+1 and a plain duplicate look identical from a distance: one statement shape, executed many
 * times. The difference is whether the *bound values* varied. A query repeated with the same
 * arguments is wasted work that a cache would remove; a query repeated with a different id each
 * time is a loop that should have been one query.
 *
 * The collector keeps binds from a single sample execution, so it cannot prove variation. Rather
 * than guess, the distinction is drawn from the strongest available evidence — a parameterised
 * statement carrying binds is treated as varying, and one carrying none is not — and the result
 * records which basis was used, so a reader knows how much to trust it.
 *
 * @api
 */
class QueryAnalyzer
{
    public const N_PLUS_ONE = 'n_plus_one';
    public const DUPLICATE = 'duplicate';
    public const SLOW = 'slow';

    public const DEFAULT_SLOW_MS = 50.0;
    public const DEFAULT_NPLUS1 = 5;
    public const DEFAULT_DUPLICATE = 3;

    /**
     * Classify every group, worst first.
     *
     * @param array<int, array<string, mixed>> $queries Groups as stored.
     * @param array<string, mixed> $thresholds slow_ms, nplus1, duplicate — each optional.
     * @return array<string, mixed>
     */
    public function classify(array $queries, array $thresholds = []): array
    {
        $slowMs = (float)($thresholds['slow_ms'] ?? self::DEFAULT_SLOW_MS);
        $nPlusOne = max(2, (int)($thresholds['nplus1'] ?? self::DEFAULT_NPLUS1));
        $duplicate = max(2, (int)($thresholds['duplicate'] ?? self::DEFAULT_DUPLICATE));

        $classified = [];
        $statements = 0;
        $totalMs = 0.0;

        foreach ($queries as $group) {
            if (!is_array($group)) {
                continue;
            }

            $count = (int)($group['count'] ?? 0);
            $statements += $count;
            $totalMs += (float)($group['total_ms'] ?? 0);

            $group['finding'] = $this->findingFor($group, $slowMs, $nPlusOne, $duplicate);
            $classified[] = $group;
        }

        usort($classified, fn (array $a, array $b): int => $this->rank($a) <=> $this->rank($b));

        return [
            'groups' => $classified,
            'shapes' => count($classified),
            'statements' => $statements,
            'total_ms' => round($totalMs, 1),
            'findings' => count(array_filter($classified, static fn (array $g): bool => $g['finding'] !== null)),
        ];
    }

    /**
     * @param array<string, mixed> $group
     * @param float $slowMs
     * @param int $nPlusOne
     * @param int $duplicate
     * @return array<string, mixed>|null
     */
    private function findingFor(array $group, float $slowMs, int $nPlusOne, int $duplicate): ?array
    {
        $count = (int)($group['count'] ?? 0);
        $maxMs = (float)($group['max_ms'] ?? 0);
        $totalMs = (float)($group['total_ms'] ?? 0);

        $varied = $this->sqlVaried($group);

        if ($count >= $nPlusOne && ($varied || $this->looksParameterised($group))) {
            return [
                'kind' => self::N_PLUS_ONE,
                'detail' => sprintf('%d executions of one shape with varying arguments', $count),
                'basis' => $varied
                    ? 'statement text differed between executions — variation observed'
                    : 'bound arguments present — variation inferred, not proven',
                'saving_ms' => round($totalMs - ($totalMs / max(1, $count)), 1),
            ];
        }

        if ($count >= $duplicate) {
            // Only claim "identical" when the statement text was observed not to change. A shape
            // whose literals varied is N distinct reads that fell under the N+1 threshold — saying
            // it repeats sends a reader looking for a cache that would be wrong to add.
            if ($varied) {
                return [
                    'kind' => self::N_PLUS_ONE,
                    'detail' => sprintf('%d executions of one shape with varying arguments', $count),
                    'basis' => 'statement text differed between executions — variation observed',
                    'saving_ms' => round($totalMs - ($totalMs / max(1, $count)), 1),
                ];
            }

            return [
                'kind' => self::DUPLICATE,
                'detail' => sprintf('%d executions of an identical statement', $count),
                'basis' => $this->looksParameterised($group)
                    ? 'below the N+1 threshold'
                    : 'identical statement text each time — observed, not inferred',
                'saving_ms' => round($totalMs - ($totalMs / max(1, $count)), 1),
            ];
        }

        if ($maxMs >= $slowMs) {
            return [
                'kind' => self::SLOW,
                'detail' => sprintf('single execution took %.1fms', $maxMs),
                'basis' => sprintf('threshold %.0fms', $slowMs),
                'saving_ms' => 0.0,
            ];
        }

        return null;
    }

    /**
     * Whether the statement text was observed to change between executions of this shape.
     *
     * Absent on runs captured before the collector recorded it; those fall back to the bind-based
     * inference rather than silently reading as "did not vary".
     *
     * @param array<string, mixed> $group
     * @return bool
     */
    private function sqlVaried(array $group): bool
    {
        return ($group['sql_varies'] ?? false) === true;
    }

    /**
     * Whether this shape was executed with bound arguments.
     *
     * The best available proxy for "the values varied". Recorded binds come from one sample
     * execution, so this cannot be certain — which is why the finding says so rather than
     * asserting it.
     *
     * @param array<string, mixed> $group
     * @return bool
     */
    private function looksParameterised(array $group): bool
    {
        $binds = $group['binds'] ?? [];

        return is_array($binds) && $binds !== [];
    }

    /**
     * Sort order: findings first, worst kind first, then by time spent.
     *
     * @param array<string, mixed> $group
     * @return array{int, float}
     */
    private function rank(array $group): array
    {
        $kind = $group['finding']['kind'] ?? null;

        $weight = match ($kind) {
            self::N_PLUS_ONE => 0,
            self::DUPLICATE => 1,
            self::SLOW => 2,
            default => 3,
        };

        return [$weight, -1 * (float)($group['total_ms'] ?? 0)];
    }
}
