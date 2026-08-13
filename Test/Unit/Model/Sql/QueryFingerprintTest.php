<?php
/**
 * Copyright © Muon. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Muon\DevProfiler\Test\Unit\Model\Sql;

use Muon\DevProfiler\Model\Sql\QueryFingerprint;
use PHPUnit\Framework\TestCase;

/**
 * @see QueryFingerprint
 */
class QueryFingerprintTest extends TestCase
{
    private QueryFingerprint $fingerprint;

    protected function setUp(): void
    {
        $this->fingerprint = new QueryFingerprint();
    }

    /**
     * The case the whole collector exists for: a loop issuing one statement per id must collapse
     * to a single shape, or the N+1 is invisible.
     */
    public function testTheSameLookupWithDifferentIdsIsOneShape(): void
    {
        $a = $this->fingerprint->of('SELECT * FROM catalog_product_entity WHERE entity_id = 1042');
        $b = $this->fingerprint->of('SELECT * FROM catalog_product_entity WHERE entity_id = 2071');

        self::assertSame($a, $b);
    }

    public function testQuotedStringsAreNormalised(): void
    {
        $a = $this->fingerprint->of("SELECT * FROM store WHERE code = 'en_us'");
        $b = $this->fingerprint->of("SELECT * FROM store WHERE code = 'de_de'");

        self::assertSame($a, $b);
        self::assertStringNotContainsString('en_us', $a);
    }

    public function testQuotesContainingEscapesDoNotBreakNormalisation(): void
    {
        $a = $this->fingerprint->of("SELECT * FROM t WHERE name = 'O\\'Brien'");
        $b = $this->fingerprint->of("SELECT * FROM t WHERE name = 'Smith'");

        self::assertSame($a, $b);
    }

    public function testInListsOfAnyLengthCollapseToTheSameShape(): void
    {
        $three = $this->fingerprint->of('SELECT * FROM t WHERE id IN (1, 2, 3)');
        $seven = $this->fingerprint->of('SELECT * FROM t WHERE id IN (1, 2, 3, 4, 5, 6, 7)');

        self::assertSame($three, $seven);
        self::assertStringContainsString('IN (?)', $three);
    }

    public function testWhitespaceAndNewlinesDoNotCreateDistinctShapes(): void
    {
        $flat = $this->fingerprint->of('SELECT a FROM t WHERE id = 1');
        $wrapped = $this->fingerprint->of("SELECT a\n  FROM t\n  WHERE id = 2");

        self::assertSame($flat, $wrapped);
    }

    public function testAlreadyParameterisedStatementsAreLeftAlone(): void
    {
        $sql = 'SELECT * FROM t WHERE id = ? AND code = ?';

        self::assertSame($sql, $this->fingerprint->of($sql));
    }

    /**
     * The under-normalisation guard. Digits inside identifiers must survive, or distinct tables
     * merge into one shape and a finding points at something that does not exist.
     */
    public function testDigitsInsideIdentifiersAreNotNormalised(): void
    {
        $shape = $this->fingerprint->of('SELECT * FROM catalog_product_entity_int WHERE store_id = 1');

        self::assertStringContainsString('catalog_product_entity_int', $shape);
        self::assertStringContainsString('store_id = ?', $shape);
    }

    public function testDifferentTablesRemainDifferentShapes(): void
    {
        $a = $this->fingerprint->of('SELECT * FROM catalog_product_entity WHERE id = 1');
        $b = $this->fingerprint->of('SELECT * FROM customer_entity WHERE id = 1');

        self::assertNotSame($a, $b);
    }

    public function testDecimalAndNegativeLiteralsNormalise(): void
    {
        $a = $this->fingerprint->of('SELECT * FROM t WHERE price > 10.50 AND qty > -1');
        $b = $this->fingerprint->of('SELECT * FROM t WHERE price > 99.99 AND qty > -7');

        self::assertSame($a, $b);
    }

    public function testAVeryLongStatementIsTruncatedRatherThanStoredWhole(): void
    {
        $shape = $this->fingerprint->of('SELECT ' . str_repeat('col_name, ', 500) . 'x FROM t');

        // The marker is multi-byte, so the byte length is the cap plus its width — not the cap
        // plus one. Asserting 2001 here failed for a reason that had nothing to do with the code.
        self::assertLessThanOrEqual(2000 + strlen('…'), strlen($shape));
        self::assertStringEndsWith('…', $shape);
    }
}
