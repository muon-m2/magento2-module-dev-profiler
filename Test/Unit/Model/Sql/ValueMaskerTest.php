<?php
/**
 * Copyright © Muon. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Muon\DevProfiler\Test\Unit\Model\Sql;

use Muon\DevProfiler\Model\Sql\ValueMasker;
use PHPUnit\Framework\TestCase;

/**
 * @see ValueMasker
 */
class ValueMaskerTest extends TestCase
{
    private ValueMasker $masker;

    protected function setUp(): void
    {
        $this->masker = new ValueMasker();
    }

    // ---- the key rule ----

    public function testASensitiveKeyMasksRegardlessOfValue(): void
    {
        self::assertSame('••••••', $this->masker->maskValue('password_hash', 'anything at all'));
        self::assertSame('••••••', $this->masker->maskValue(':customer_email', 'x'));
        self::assertSame('••••••', $this->masker->maskValue('api_token', 'short'));
        self::assertSame('••••••', $this->masker->maskValue('street', '1 High Street'));
    }

    // ---- the shape rule, for binds whose key says nothing ----

    public function testAnEmailIsMaskedEvenUnderAMeaninglessKey(): void
    {
        $masked = $this->masker->maskValue(0, 'shopper@example.com');

        self::assertSame('s••••••@example.com', $masked);
        self::assertStringNotContainsString('shopper', $masked);
    }

    public function testMagentoCiphertextIsMasked(): void
    {
        self::assertSame(
            '••••••',
            $this->masker->maskValue(':p0', '0:3:VGhpcyBpcyBjaXBoZXJ0ZXh0IGRhdGE=')
        );
    }

    public function testALongRandomLookingStringIsTruncatedToAStub(): void
    {
        $masked = $this->masker->maskValue(':p1', str_repeat('a1B2c3D4', 6));

        self::assertSame('a1B2••••••', $masked);
    }

    // ---- the opposite failure: masking too much makes an N+1 undiagnosable ----

    /**
     * The bound id is the evidence that distinguishes an N+1 from a duplicate. Masking it would
     * leave "the same statement 47 times" with no way to tell which.
     */
    public function testNumericIdsAreNeverMasked(): void
    {
        self::assertSame(1042, $this->masker->maskValue(0, 1042));
        self::assertSame('1042', $this->masker->maskValue(':entity_id', '1042'));
        self::assertSame(10.5, $this->masker->maskValue('price', 10.5));
    }

    public function testOrdinaryShortValuesArePreserved(): void
    {
        self::assertSame('en_us', $this->masker->maskValue(0, 'en_us'));
        self::assertSame('simple', $this->masker->maskValue(':type', 'simple'));
        self::assertSame('catalog_product_entity', $this->masker->maskValue(1, 'catalog_product_entity'));
    }

    public function testNullAndBooleanPassThrough(): void
    {
        self::assertNull($this->masker->maskValue(0, null));
        self::assertTrue($this->masker->maskValue('flag', true));
    }

    /**
     * A long *sentence* is not a token — it has spaces, so the character-class guard rejects it.
     */
    public function testALongOrdinarySentenceIsNotTreatedAsAToken(): void
    {
        $text = 'this is a reasonably long product description with many words in it';

        self::assertSame($text, $this->masker->maskValue(0, $text));
    }

    // ---- containers and bulk behaviour ----

    public function testArraysAndObjectsAreSummarisedNotDumped(): void
    {
        self::assertSame('array(3)', $this->masker->maskValue(0, [1, 2, 3]));
        self::assertSame(\stdClass::class, $this->masker->maskValue(0, new \stdClass()));
    }

    public function testMaskBindsAppliesPerKeyAndCapsTheSet(): void
    {
        $binds = ['email' => 'a@b.com', ':p0' => 'shopper@example.com', 'entity_id' => 7];

        $masked = $this->masker->maskBinds($binds);

        self::assertSame('••••••', $masked['email'], 'key rule');
        self::assertSame('s••••••@example.com', $masked[':p0'], 'shape rule');
        self::assertSame(7, $masked['entity_id'], 'id survives');
    }

    public function testMaskBindsStopsAtTheCapAndSaysHowManyItDropped(): void
    {
        $binds = array_fill_keys(array_map(static fn (int $i): string => 'k' . $i, range(1, 60)), 'v');

        $masked = $this->masker->maskBinds($binds, 50);

        self::assertCount(51, $masked, '50 kept plus one summary row');
        self::assertSame('10 more', $masked['…']);
    }

    public function testAVeryLongValueIsClippedAfterMaskingNotBefore(): void
    {
        $masked = $this->masker->maskValue(0, str_repeat('word ', 100));

        self::assertLessThanOrEqual(200 + strlen('…'), strlen((string)$masked));
        self::assertStringEndsWith('…', (string)$masked);
    }
}
