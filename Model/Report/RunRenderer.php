<?php
/**
 * Copyright © Muon. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Muon\DevProfiler\Model\Report;

use Muon\DevProfiler\Model\Analysis\CacheVerdict;

/**
 * Formats a stored run for a terminal.
 *
 * The rendering is deliberately plain text with no table borders: the primary readers of this
 * output are a developer scanning it and an agent grepping it, and both are better served by
 * stable, greppable lines than by box drawing.
 */
class RunRenderer
{
    /**
     * @param \Muon\DevProfiler\Model\Report\FallbackListRenderer $fallback
     * @param \Muon\DevProfiler\Model\Report\SqlListRenderer $sql
     * @param \Muon\DevProfiler\Model\Analysis\CacheVerdict $verdicts
     */
    public function __construct(
        private readonly FallbackListRenderer $fallback,
        private readonly SqlListRenderer $sql,
        private readonly CacheVerdict $verdicts
    ) {
    }

    /**
     * Render one run.
     *
     * @param array<string, mixed> $run
     * @param bool $shadowedOnly Suppress resolutions with nothing shadowed.
     * @param string|null $filter Substring match on the file name or any path it resolved to.
     * @param bool $showSql Include the SQL section.
     * @param array<string, mixed> $thresholds Read-time SQL thresholds.
     * @return list<string>
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag) These mirror `--shadowed-only` and `--sql` one
     * for one. They select which sections of one report are printed rather than switching the
     * method between behaviours, and keeping the shape of the console flags makes the command
     * classes read as a direct translation of what the operator typed.
     */
    public function render(
        array $run,
        bool $shadowedOnly = false,
        ?string $filter = null,
        bool $showSql = false,
        array $thresholds = []
    ): array {
        $request = is_array($run['request'] ?? null) ? $run['request'] : [];
        $context = is_array($run['context'] ?? null) ? $run['context'] : [];
        $layout = is_array($run['layout'] ?? null) ? $run['layout'] : [];

        $lines = array_merge(
            [$this->headline($run, $request), ''],
            $this->summaryLines($request, $context, $layout),
            ['']
        );

        $lines = array_merge($lines, $this->fallback->render($run, $context, $shadowedOnly, $filter));

        return $showSql
            ? array_merge($lines, $this->sql->render($run, $thresholds))
            : $lines;
    }

    /**
     * Store, theme, cache verdict and handles.
     *
     * @param array<string, mixed> $request
     * @param array<string, mixed> $context
     * @param array<string, mixed> $layout
     * @return list<string>
     */
    private function summaryLines(array $request, array $context, array $layout): array
    {
        $lines = [
            sprintf(
                'STORE     %s  (store %s, website %s)',
                (string)($context['store_code'] ?? '?'),
                (string)($context['store_id'] ?? '?'),
                (string)($context['website_id'] ?? '?')
            ),
            // A theme we had to look up afterwards is labelled, because "the store is set to this"
            // is a weaker claim than "this request used this".
            sprintf(
                'THEME     %s%s',
                (string)($context['theme_path'] ?? '?'),
                ($context['theme_source'] ?? null) === 'configured' ? '   (store default — not observed)' : ''
            ),
        ];

        $verdict = $this->verdicts->verdict($layout, (string)($request['kind'] ?? 'page'));
        $lines[] = sprintf('FPC       %s', (string)$verdict['status']);

        foreach ($verdict['causes'] as $cause) {
            $lines[] = sprintf('          └─ %s', (string)$cause['detail']);
        }

        if (!$verdict['cause_known'] && $verdict['status'] === CacheVerdict::UNCACHEABLE) {
            $lines[] = '          └─ cause unknown — no generated block and no layout construction accounts for it';
        }

        // Handles only mean something when layout actually ran. On a static asset it never does,
        // and on a cache hit it never does either — printing "HANDLES 0" in those cases states a
        // fact about nothing, the same way "FPC hit" used to for static requests.
        if (!empty($layout['generated'])) {
            $handles = is_array($layout['handles'] ?? null) ? $layout['handles'] : [];
            $lines[] = sprintf('HANDLES   %d', count($handles));
        }

        return $lines;
    }

    /**
     * @param array<string, mixed> $run
     * @param array<string, mixed> $request
     * @return string
     */
    private function headline(array $run, array $request): string
    {
        // A static run's duration is dominated by compiling the asset, not by serving it. Three
        // seconds next to a 50ms page reads like a problem unless it says why.
        $note = (string)($request['kind'] ?? 'page') === 'static'
            ? '   (includes asset build)'
            : '';

        return sprintf(
            'run %s   %s %s   %s   %sms%s%s',
            (string)($run['token'] ?? '?'),
            (string)($request['method'] ?? '?'),
            (string)($request['url'] ?? '?'),
            (string)($request['status'] ?? '?'),
            (string)($request['duration_ms'] ?? '?'),
            $note,
            !empty($request['is_ajax']) ? '   [ajax]' : ''
        );
    }

}
