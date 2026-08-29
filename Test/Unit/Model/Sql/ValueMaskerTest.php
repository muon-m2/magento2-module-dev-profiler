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

    /**
     * Ordinary PII no shape rule can recognise: "Alice" is five letters like any other five.
     * The key has to carry the decision, so the key list has to be complete.
     */
    public function testOrdinaryPersonalDataIsMaskedByKeyName(): void
    {
        $masked = (new ValueMasker())->maskBinds([
            'firstname' => 'Alice',
            'lastname' => 'Bergstrom',
            'company' => 'Northwind Trading',
            'city' => 'Rotterdam',
            'country_id' => 'NL',
            'coupon_code' => 'SUMMER-40',
        ]);

        foreach ($masked as $key => $value) {
            self::assertSame('••••••', $value, $key . ' was stored in the clear');
        }
    }

    public function testAPasswordHashAndAJwtAreMaskedByShape(): void
    {
        $masker = new ValueMasker();

        // Magento's password hash is hash:salt:version — the colons used to defeat the token rule.
        $hash = str_repeat('a', 64) . ':' . str_repeat('b', 32) . ':1';
        $jwt = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.dBjftJeZ4CVPmB92K27u';

        self::assertStringContainsString('••••••', (string)$masker->maskBinds([0 => $hash])[0]);
        self::assertStringContainsString('••••••', (string)$masker->maskBinds([0 => $jwt])[0]);
    }

    /**
     * The counterweight: over-masking destroys the evidence that separates an N+1 from a duplicate,
     * so the widened rules must not start swallowing ordinary identifiers.
     */
    public function testIdentifiersAndShortValuesStillPassThrough(): void
    {
        $masked = (new ValueMasker())->maskBinds([
            'entity_id' => 14092,
            'sku' => 'MUON-20-00264',
            'identifier' => 'header_panel',
            'store_id' => 7,
        ]);

        self::assertSame(14092, $masked['entity_id']);
        self::assertSame('MUON-20-00264', $masked['sku']);
        self::assertSame('header_panel', $masked['identifier']);
        self::assertSame(7, $masked['store_id']);
    }
}
