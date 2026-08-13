<?php
/**
 * Copyright © Muon. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Muon\DevProfiler\Model\Sql;

/**
 * Reduces a statement to its shape, so repeated executions can be recognised as one thing.
 *
 * This is the whole basis of N+1 detection: a loop issues the same statement 47 times with a
 * different id each time, and the only way to see that as one finding rather than 47 unrelated
 * queries is to normalise the parts that vary.
 *
 * The balance matters in both directions. Normalise too aggressively and genuinely different
 * statements merge, so a finding points at a shape that no single piece of code produces.
 * Normalise too little and one N+1 splits into 47 groups of one, which is exactly the state that
 * hides it. The rules below are deliberately narrow: literals, IN-lists, and whitespace. Nothing
 * else is touched — no table aliasing, no column reordering, no clause rewriting.
 */
class QueryFingerprint
{
    /**
     * Longest fingerprint kept. A statement long enough to exceed this is already unreadable in a
     * report; the tail is dropped rather than storing kilobytes per group.
     */
    private const MAX_LENGTH = 2000;

    /**
     * Reduce a statement to its shape.
     *
     * @param string $sql
     * @return string
     * @SuppressWarnings(PHPMD.ShortMethodName) `$fingerprint->of($sql)` reads as the sentence it is.
     */
    public function of(string $sql): string
    {
        $shape = $this->collapseInLists(
            $this->normaliseLiterals($sql)
        );

        $shape = trim((string)preg_replace('/\s+/', ' ', $shape));

        return strlen($shape) > self::MAX_LENGTH
            ? substr($shape, 0, self::MAX_LENGTH) . '…'
            : $shape;
    }

    /**
     * Replace quoted strings and bare numbers with a placeholder.
     *
     * Strings are handled before numbers so a numeral inside a quoted literal is not rewritten
     * twice. Numbers are matched only when bounded by non-word characters, so identifiers like
     * `customer_entity_int` and `sales_order_grid` keep their digits and do not collapse into each
     * other.
     *
     * @param string $sql
     * @return string
     */
    private function normaliseLiterals(string $sql): string
    {
        // Single- and double-quoted literals, honouring backslash escapes and doubled quotes.
        $sql = (string)preg_replace("/'(?:[^'\\\\]|\\\\.|'')*'/", '?', $sql);
        $sql = (string)preg_replace('/"(?:[^"\\\\]|\\\\.|"")*"/', '?', $sql);

        // Numeric literals, including decimals and negatives, but never digits inside an
        // identifier. `= 42`, `(-1.5)` and `, 0` normalise; `catalog_product_entity_int` does not.
        return (string)preg_replace('/(?<![\w.])-?\d+(?:\.\d+)?(?![\w.])/', '?', $sql);
    }

    /**
     * Collapse `IN (?, ?, ?)` to `IN (?)`.
     *
     * Without this, the same lookup against 3 ids and against 4 ids are different shapes, and a
     * page that varies its batch size produces a scatter of one-off groups instead of one entry.
     *
     * @param string $sql
     * @return string
     */
    private function collapseInLists(string $sql): string
    {
        return (string)preg_replace('/\bIN\s*\(\s*\?(?:\s*,\s*\?)*\s*\)/i', 'IN (?)', $sql);
    }
}
