<?php
/**
 * Copyright © Muon. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Muon\DevProfiler\Model\Report;

use Muon\DevProfiler\Model\Analysis\QueryAnalyzer;

/**
 * Renders the SQL section: statement shapes, grouped, findings first.
 *
 * A third renderer beside RunRenderer and FallbackListRenderer rather than a fourth concern inside
 * either — they change for different reasons, and the two existing ones were split apart for
 * exactly this.
 */
class SqlListRenderer
{
    /**
     * Longest statement printed. The shape is the useful part; the tail rarely is.
     */
    private const SAMPLE_WIDTH = 150;

    /**
     * @param \Muon\DevProfiler\Model\Analysis\QueryAnalyzer $analyzer
     */
    public function __construct(
        private readonly QueryAnalyzer $analyzer
    ) {
    }

    /**
     * @param array<string, mixed> $run
     * @param array<string, mixed> $thresholds
     * @return list<string>
     */
    public function render(array $run, array $thresholds = []): array
    {
        $queries = is_array($run['queries'] ?? null) ? $run['queries'] : [];

        if ($queries === []) {
            return ['', 'SQL       nothing recorded', ...$this->whyEmpty($run)];
        }

        $result = $this->analyzer->classify($queries, $thresholds);
        $dropped = (int)($run['truncated']['queries'] ?? 0);

        $lines = [
            '',
            sprintf(
                'SQL       %d shapes (%d statements), %sms, %d findings%s',
                $result['shapes'],
                $result['statements'],
                $result['total_ms'],
                $result['findings'],
                $dropped > 0 ? sprintf(' (%d shapes dropped at the cap)', $dropped) : ''
            ),
            '',
        ];

        foreach ($result['groups'] as $group) {
            $lines = array_merge($lines, $this->groupLines($group));
        }

        return $lines;
    }

    /**
     * @param array<string, mixed> $group
     * @return list<string>
     */
    private function groupLines(array $group): array
    {
        $finding = $group['finding'] ?? null;
        $count = (int)($group['count'] ?? 0);

        $lines = [sprintf(
            '  x%-4d %s',
            $count,
            $this->clip((string)($group['fingerprint'] ?? ''))
        )];

        if (is_array($finding)) {
            $lines[] = sprintf('        %s — %s', strtoupper((string)$finding['kind']), (string)$finding['detail']);
            $lines[] = sprintf('        basis: %s', (string)$finding['basis']);

            if ((float)$finding['saving_ms'] > 0) {
                $lines[] = sprintf('        up to %sms recoverable', $finding['saving_ms']);
            }
        }

        $lines[] = sprintf(
            '        %sms total, %sms slowest',
            $group['total_ms'] ?? '?',
            $group['max_ms'] ?? '?'
        );

        if (!empty($group['origin'])) {
            $lines[] = sprintf(
                '        from %s%s',
                (string)$group['origin'],
                !empty($group['is_userland']) ? '   <-- your code' : ''
            );
        }

        return $lines;
    }

    /**
     * An empty SQL section is normal on a cache hit and suspicious otherwise; say which.
     *
     * @param array<string, mixed> $run
     * @return list<string>
     */
    private function whyEmpty(array $run): array
    {
        $layout = is_array($run['layout'] ?? null) ? $run['layout'] : [];

        if (empty($layout['generated'])) {
            return ['', '  Served from the full page cache — the page ran no queries.'];
        }

        return ['', '  This run predates the SQL collector, or every statement was filtered out.'];
    }

    /**
     * @param string $sql
     * @return string
     */
    private function clip(string $sql): string
    {
        return strlen($sql) > self::SAMPLE_WIDTH
            ? substr($sql, 0, self::SAMPLE_WIDTH) . '…'
            : $sql;
    }
}
