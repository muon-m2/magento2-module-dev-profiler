<?php
/**
 * Copyright © Muon. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Muon\DevProfiler\Test\Unit\Model\Report;

use Muon\DevProfiler\Model\Analysis\QueryAnalyzer;
use Muon\DevProfiler\Model\Report\SqlListRenderer;
use PHPUnit\Framework\TestCase;

/**
 * @see SqlListRenderer
 *
 * `--sql` is one of the two report modes this module exists for, and until now nothing executed
 * this class: RunRendererTest constructs it, but every call leaves `showSql` at its default false,
 * so `render()` was never reached and the whole findings section was unverified.
 */
class SqlListRendererTest extends TestCase
{
    private SqlListRenderer $renderer;

    protected function setUp(): void
    {
        $this->renderer = new SqlListRenderer(new QueryAnalyzer());
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function group(array $overrides = []): array
    {
        return $overrides + [
            'fingerprint' => 'SELECT * FROM cms_block WHERE identifier = ?',
            'sample' => 'SELECT * FROM cms_block WHERE identifier = ?',
            'count' => 1,
            'total_ms' => 1.0,
            'max_ms' => 1.0,
            'binds' => [],
            'origin' => null,
            'is_userland' => false,
            'sql_varies' => false,
        ];
    }

    public function testAnEmptySqlSectionExplainsItselfRatherThanPrintingNothing(): void
    {
        $lines = $this->renderer->render(['queries' => []]);

        self::assertNotSame([], $lines);
        self::assertStringContainsString('nothing recorded', implode("\n", $lines));
    }

    /**
     * The N+1 is the headline finding: many executions of one shape. It must be named as such
     * rather than reported as a duplicate, which is the distinction the whole fingerprint/
     * sql_varies machinery exists to draw.
     */
    public function testAnNPlusOneIsReportedAsAnNPlusOne(): void
    {
        $out = implode("\n", $this->renderer->render(
            ['queries' => [$this->group(['count' => 47, 'total_ms' => 94.0, 'sql_varies' => true])]],
            ['nplus1' => 5]
        ));

        self::assertStringContainsString('N_PLUS_ONE', $out);
        self::assertStringContainsString('47 executions of one shape', $out);
        self::assertStringContainsString('variation observed', $out, 'sql_varies is the stated basis');
    }

    public function testASlowStatementIsReportedWithItsTiming(): void
    {
        $out = implode("\n", $this->renderer->render(
            ['queries' => [$this->group(['count' => 1, 'total_ms' => 512.5, 'max_ms' => 512.5])]],
            ['slow_ms' => 50]
        ));

        self::assertStringContainsString('512.5', $out);
    }

    /**
     * The renderer prints the fingerprint, never the raw statement — the collector stores only the
     * shape now, and a report that reintroduced literals would undo that.
     */
    public function testTheRenderedSectionCarriesNoInlinedLiterals(): void
    {
        $out = implode("\n", $this->renderer->render(
            ['queries' => [$this->group([
                'fingerprint' => 'SELECT * FROM customer_entity WHERE email = ?',
                'sample' => 'SELECT * FROM customer_entity WHERE email = ?',
                'count' => 3,
            ])]],
            ['duplicate' => 2]
        ));

        self::assertStringNotContainsString('@', $out, 'no address should be reachable from a shape');
        self::assertStringContainsString('customer_entity', $out, 'the table is what makes it useful');
    }

    public function testTheHeaderCountsStatementsAndShapes(): void
    {
        $out = implode("\n", $this->renderer->render(['queries' => [
            $this->group(['count' => 4]),
            $this->group(['fingerprint' => 'SELECT * FROM core_config_data WHERE path = ?', 'count' => 2]),
        ]]));

        self::assertStringContainsString('6', $out, '4 + 2 executions');
        self::assertStringContainsString('2', $out, 'across two shapes');
    }
}
