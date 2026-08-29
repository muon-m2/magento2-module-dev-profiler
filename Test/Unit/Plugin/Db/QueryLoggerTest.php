<?php
/**
 * Copyright © Muon. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Muon\DevProfiler\Test\Unit\Plugin\Db;

use Magento\Framework\DB\LoggerInterface;
use Muon\DevProfiler\Model\Run\Gate;
use Muon\DevProfiler\Model\Run\RunContext;
use Muon\DevProfiler\Model\Sql\QueryFingerprint;
use Muon\DevProfiler\Model\Sql\StatementOrigin;
use Muon\DevProfiler\Model\Sql\ValueMasker;
use Muon\DevProfiler\Plugin\Db\QueryLogger;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/**
 * @see QueryLogger
 *
 * The plugins in this module are normally left untested — they are gate-then-delegate adapters and
 * a test would exercise the mock. This one is the exception, because its failure mode is a hung
 * request rather than a failed one, and a hang is far harder to attribute than an exception.
 */
#[AllowMockObjectsWithoutExpectations]
class QueryLoggerTest extends TestCase
{
    private RunContext $context;

    /**
     * @param bool $profiled
     * @param callable|null $onGateCall Runs while the gate is being asked — used to re-enter.
     * @return QueryLogger
     */
    private function logger(bool $profiled = true, ?callable $onGateCall = null): QueryLogger
    {
        $this->context = new RunContext();

        /** @var Gate&\PHPUnit\Framework\MockObject\MockObject $gate */
        $gate = $this->createMock(Gate::class);
        $gate->method('isProfiled')->willReturnCallback(
            static function () use ($profiled, $onGateCall): bool {
                if ($onGateCall !== null) {
                    $onGateCall();
                }

                return $profiled;
            }
        );

        return new QueryLogger(
            $gate,
            $this->context,
            new QueryFingerprint(),
            new ValueMasker(),
            new StatementOrigin()
        );
    }

    /**
     * @return LoggerInterface&\PHPUnit\Framework\MockObject\MockObject
     */
    private function subject(): LoggerInterface
    {
        /** @var LoggerInterface&\PHPUnit\Framework\MockObject\MockObject $subject */
        $subject = $this->createMock(LoggerInterface::class);

        return $subject;
    }

    /**
     * @param QueryLogger $logger
     * @param string $sql
     * @param array<int|string, mixed> $bind
     * @return void
     */
    private function execute(QueryLogger $logger, string $sql, array $bind = []): void
    {
        $subject = $this->subject();
        $logger->beforeStartTimer($subject);
        $logger->beforeLogStats($subject, LoggerInterface::TYPE_QUERY, $sql, $bind);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function recorded(): array
    {
        $queries = $this->context->meta('queries', []);

        return is_array($queries) ? array_values($queries) : [];
    }

    /**
     * THE test this class exists for.
     *
     * Reading configuration can itself issue a query. If asking the gate re-enters the logger and
     * the logger asks the gate again, the request does not fail — it hangs. The `busy` flag must
     * make the nested call a no-op.
     */
    public function testReEntrantLoggingDoesNotRecurse(): void
    {
        $depth = 0;

        /** @var QueryLogger|null $logger */
        $logger = null;

        $reenter = function () use (&$logger, &$depth): void {
            $depth++;

            // Simulate a config read issuing its own statement while the gate is being resolved.
            if ($depth < 5 && $logger instanceof QueryLogger) {
                $this->execute($logger, 'SELECT value FROM core_config_data WHERE path = ?', ['path' => 'x']);
            }
        };

        $logger = $this->logger(true, $reenter);

        $this->execute($logger, 'SELECT * FROM catalog_product_entity WHERE entity_id = 1');

        self::assertLessThan(5, $depth, 'the guard must stop the nesting, not merely slow it');
        self::assertNotSame([], $this->recorded(), 'the outer statement is still recorded');
    }

    public function testStatementsAreGroupedByShape(): void
    {
        $logger = $this->logger();

        foreach ([1042, 2071, 3033] as $id) {
            $this->execute($logger, "SELECT * FROM catalog_product_entity WHERE entity_id = $id", ['id' => $id]);
        }

        $recorded = $this->recorded();

        self::assertCount(1, $recorded, 'three executions, one shape');
        self::assertSame(3, $recorded[0]['count']);
    }

    /**
     * The fingerprint deliberately normalises literals away, so the shape alone cannot tell seven
     * lookups of seven different rows from the same lookup seven times. The collector is the only
     * place that still sees the raw text, so it is the only place that can record the difference.
     */
    public function testVaryingInlinedLiteralsAreRecordedAsVariation(): void
    {
        $logger = $this->logger();

        foreach (['header_panel', 'muon_fm_help', 'footer_content'] as $identifier) {
            $this->execute($logger, "SELECT * FROM cms_block WHERE identifier = '$identifier'");
        }

        $recorded = $this->recorded();

        self::assertCount(1, $recorded, 'one shape, as the fingerprint intends');
        self::assertTrue($recorded[0]['sql_varies'], 'three different blocks, not one block three times');
    }

    /**
     * The leak this collector shipped for two releases.
     *
     * ValueMasker guards $bind, but Magento inlines values through quoteInto at least as often as
     * it binds them — every Model::load($value, $field) and every where('col = ?', $v) puts the
     * value in the statement TEXT. Storing that text wrote it to disk past the masker entirely.
     * Nothing recorded may contain a literal that was in the statement.
     */
    public function testInlinedLiteralsNeverReachTheRecordedGroup(): void
    {
        $logger = $this->logger();

        $secrets = ['shopper@example.com', 'a7f3c9d1e5b2', 'SUMMER-40-OFF'];

        $this->execute(
            $logger,
            "SELECT * FROM persistent_session WHERE `key` = 'a7f3c9d1e5b2' AND email = "
            . "'shopper@example.com' AND coupon = 'SUMMER-40-OFF'"
        );

        $encoded = json_encode($this->recorded());

        self::assertIsString($encoded);

        foreach ($secrets as $secret) {
            self::assertStringNotContainsString(
                $secret,
                $encoded,
                sprintf('%s was inlined in the statement and must not be stored', $secret)
            );
        }
    }

    /**
     * The stored sample is the shape, and the Board renders it — so it must stay readable SQL,
     * not be blanked. Dropping the key instead would silently empty the board's SQL column.
     */
    public function testTheStoredSampleIsTheNormalisedShape(): void
    {
        $logger = $this->logger();

        $this->execute($logger, "SELECT * FROM cms_block WHERE identifier = 'header_panel' AND store_id = 7");

        $recorded = $this->recorded();

        self::assertSame($recorded[0]['fingerprint'], $recorded[0]['sample']);
        self::assertSame('SELECT * FROM cms_block WHERE identifier = ? AND store_id = ?', $recorded[0]['sample']);
    }

    public function testRepeatingOneIdenticalStatementIsNotRecordedAsVariation(): void
    {
        $logger = $this->logger();

        for ($i = 0; $i < 3; $i++) {
            $this->execute($logger, "SELECT * FROM cms_block WHERE identifier = 'header_panel'");
        }

        self::assertFalse($this->recorded()[0]['sql_varies']);
    }

    public function testDifferentShapesAreKeptApart(): void
    {
        $logger = $this->logger();
        $this->execute($logger, 'SELECT * FROM a WHERE id = 1');
        $this->execute($logger, 'SELECT * FROM b WHERE id = 1');

        self::assertCount(2, $this->recorded());
    }

    public function testNothingIsRecordedWhenTheGateSaysNo(): void
    {
        $logger = $this->logger(false);
        $this->execute($logger, 'SELECT 1');

        self::assertSame([], $this->recorded());
    }

    public function testOnlyQueryTypeIsRecorded(): void
    {
        $logger = $this->logger();
        $subject = $this->subject();

        $logger->beforeStartTimer($subject);
        $logger->beforeLogStats($subject, LoggerInterface::TYPE_TRANSACTION, 'BEGIN');

        self::assertSame([], $this->recorded());
    }

    public function testBindValuesAreMaskedBeforeTheyAreStored(): void
    {
        $logger = $this->logger();
        $this->execute($logger, 'SELECT * FROM customer_entity WHERE email = ?', ['email' => 'a@b.com']);

        $recorded = $this->recorded();

        self::assertSame('••••••', $recorded[0]['binds']['email']);
    }

    /**
     * A stack walk is the most expensive thing here, so it is budgeted: not on the first execution
     * of a fast statement.
     */
    public function testNoBacktraceIsTakenForASingleFastStatement(): void
    {
        $logger = $this->logger();
        $this->execute($logger, 'SELECT 1');

        self::assertNull($this->recorded()[0]['origin']);
    }

    public function testAnOriginIsCapturedOnceAShapeRepeats(): void
    {
        $logger = $this->logger();

        for ($i = 0; $i < 3; $i++) {
            $this->execute($logger, 'SELECT * FROM t WHERE id = ' . $i);
        }

        self::assertNotNull($this->recorded()[0]['origin'], 'third execution earns the stack walk');
    }

    public function testAStatementWithNoStartTimerIsIgnored(): void
    {
        $logger = $this->logger();
        $logger->beforeLogStats($this->subject(), LoggerInterface::TYPE_QUERY, 'SELECT 1');

        self::assertSame([], $this->recorded());
    }
}
