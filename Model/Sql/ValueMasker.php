<?php
/**
 * Copyright © Muon. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Muon\DevProfiler\Model\Sql;

/**
 * Keeps personal data out of the query log.
 *
 * Until this collector existed the module recorded file paths and layout names — nothing anyone
 * could be harmed by. Bind values are different: they are whatever the page happened to be looking
 * up, which on a storefront means email addresses, names, addresses, session ids, password hashes
 * and payment tokens. Those would be written to a file on disk and rendered into a report.
 *
 * Two rules, because either alone leaks:
 *
 *  - The **key** catches the obvious cases (`:email`, `password`).
 *  - The **value shape** catches the ones with meaningless keys — bound parameters are frequently
 *    just `?` or `:p0`, so judging on the name alone would mask almost nothing.
 *
 * The opposite failure matters too. Masking the bound id turns a diagnosable N+1 into an
 * undiagnosable one: "the same statement 47 times" is only actionable when you can see it was
 * looking up 47 *different* ids. So numerics and short values are deliberately left intact.
 */
class ValueMasker
{
    private const SENSITIVE_KEY = '/pass|secret|key|token|salt|private|credential|licen[cs]e'
        . '|signature|cipher|hash|auth|session|cookie|email|mail|phone|telephone|postcode|zip'
        . '|street|dob|tax|vat|iban|card'
        // Names, addresses and the rest of the ordinary PII a storefront binds constantly. These
        // are not secrets and no shape rule can recognise them: "Alice" is five letters that look
        // like any other five letters, which is exactly why the key has to carry the decision.
        . '|firstname|lastname|middlename|fullname|surname|company|city|region|country|province'
        . '|county|address|birth|gender|prefix|suffix|coupon|discount_code|vat_id|fax/i';

    private const EMAIL = '/^[^@\s]+@[^@\s]+\.[^@\s]{2,}$/';

    /** Magento ciphertext, as written by the framework's encryptor. */
    private const CIPHERTEXT = '/^\d+:\d+:[A-Za-z0-9+\/=]{16,}$/';

    /** Long, high-entropy-looking strings are tokens far more often than they are content. */
    private const TOKENISH_MIN_LENGTH = 32;

    private const MASK = '••••••';

    /**
     * Longest bind value kept. Masked first, then clipped — a half-shown email is still an email.
     */
    private const MAX_LENGTH = 200;

    /**
     * Mask a whole set of bound parameters.
     *
     * @param array<int|string, mixed> $binds
     * @param int $maxKeys Hard cap, so one pathological statement cannot dominate a run.
     * @return array<int|string, mixed>
     */
    public function maskBinds(array $binds, int $maxKeys = 50): array
    {
        $masked = [];

        foreach ($binds as $key => $value) {
            if (count($masked) >= $maxKeys) {
                $masked['…'] = sprintf('%d more', count($binds) - $maxKeys);

                break;
            }

            $masked[$key] = $this->maskValue($key, $value);
        }

        return $masked;
    }

    /**
     * Mask one bound value.
     *
     * @param int|string $key
     * @param mixed $value
     * @return mixed
     */
    public function maskValue(int|string $key, mixed $value): mixed
    {
        if (is_array($value)) {
            return sprintf('array(%d)', count($value));
        }

        if (is_object($value)) {
            return get_class($value);
        }

        if (!is_string($value) || $value === '') {
            // Numerics, booleans and null pass through untouched. This is deliberate: the bound id
            // is the evidence that distinguishes an N+1 from a duplicate.
            return $value;
        }

        if (is_string($key) && preg_match(self::SENSITIVE_KEY, $key)) {
            return self::MASK;
        }

        return $this->clip($this->maskByShape($value));
    }

    /**
     * Mask on the shape of the value alone, for the many binds whose key says nothing.
     *
     * @param string $value
     * @return string
     */
    private function maskByShape(string $value): string
    {
        if (preg_match(self::EMAIL, $value)) {
            // Keep enough to recognise which account it was without disclosing it.
            [$local, $domain] = explode('@', $value, 2);

            return substr($local, 0, 1) . self::MASK . '@' . $domain;
        }

        if (preg_match(self::CIPHERTEXT, $value)) {
            return self::MASK;
        }

        // The character class admits ':' and '.' as well as base64's alphabet, so two shapes that
        // used to slip through are caught: a Magento password hash is `hash:salt:version`, and a
        // JWT is three dot-separated segments. Both are long, both are credentials, and both failed
        // the old class on one punctuation character.
        if (strlen($value) >= self::TOKENISH_MIN_LENGTH && preg_match('/^[A-Za-z0-9+\/=_.:-]+$/', $value)) {
            return substr($value, 0, 4) . self::MASK;
        }

        return $value;
    }

    /**
     * @param string $value
     * @return string
     */
    private function clip(string $value): string
    {
        return strlen($value) > self::MAX_LENGTH
            ? substr($value, 0, self::MAX_LENGTH) . '…'
            : $value;
    }
}
