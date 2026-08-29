<?php
/**
 * Copyright © Muon. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Muon\DevProfiler\Plugin\Db;

use Magento\Framework\DB\LoggerInterface;
use Magento\Framework\ObjectManager\ResetAfterRequestInterface;
use Muon\DevProfiler\Model\Run\Gate;
use Muon\DevProfiler\Model\Run\RunContext;
use Muon\DevProfiler\Model\Sql\QueryFingerprint;
use Muon\DevProfiler\Model\Sql\StatementOrigin;
use Muon\DevProfiler\Model\Sql\ValueMasker;

/**
 * Records every statement the storefront runs, grouped by shape, and where each group came from.
 *
 * Magento already calls startTimer() before a statement and logStats() after it, on whichever
 * LoggerInterface the installation is configured with — normally one that does nothing. Hooking
 * those two points yields the statement, its bound values and its duration without switching on
 * Magento's own profiler and without replacing the configured logger.
 *
 * ## Two constraints shape this class, and both have already broken this module once
 *
 * **Re-entrancy.** Reading configuration can itself hit the database. Without a guard, the first
 * statement of the request asks the gate whether it is allowed, the gate reads config, that read
 * issues a statement, and the request never finishes. This does not fail — it *hangs*, which is
 * far worse to diagnose than an exception.
 *
 * **Bootstrap-safe dependencies.** This plugin is registered globally, because LoggerInterface is
 * resolved during the first configuration read, long before area configuration loads. A globally
 * registered plugin is instantiated inside ___callPlugins(); if its constructor graph reaches a
 * plugged class or any generated \Proxy, the object manager generates code at that moment,
 * re-enters itself, and resets PluginList::$_data — returning 500 on every page. Every dependency
 * below is a plain, unplugged, hand-written class. Nothing here may be proxied. This is invisible
 * with DI compiled, so it must be verified with generated/ empty.
 *
 * ## Aggregation
 *
 * Statements are folded into a map keyed by fingerprint as they happen, rather than appended one
 * per execution. A page issuing 213 statements holds ~58 entries, and an N+1 loop of 400 costs one
 * map update each instead of 400 array pushes.
 */
class QueryLogger implements ResetAfterRequestInterface
{
    /**
     * A statement slower than this is worth a stack walk on its own.
     */
    private const SLOW_MS = 50.0;

    /**
     * The execution on which a repeated shape earns a stack walk.
     *
     * The cheapest possible moment to notice an N+1: late enough that one-off statements never pay
     * for a backtrace, early enough that the origin is captured while the loop is still running.
     * One origin describes the whole group, because every member came from the same place.
     */
    private const TRACE_ON_REPEAT = 3;

    private const TRACE_DEPTH = 30;

    /**
     * Guards against the profiler profiling itself. See the class docblock — without this the
     * request hangs rather than fails.
     *
     * @var bool
     */
    private bool $busy = false;

    /**
     * @var float|null
     */
    private ?float $startedAt = null;

    /**
     * @var bool|null
     */
    private ?bool $active = null;

    /**
     * Groups keyed by fingerprint, folded in place.
     *
     * @var array<string, array<string, mixed>>
     */
    private array $groups = [];

    /**
     * CRC32 of the first raw statement seen for each fingerprint, kept only to answer `sql_varies`.
     *
     * Deliberately not the statement itself, and deliberately not part of $groups: raw statement
     * text is what must never reach disk (see record()), and a checksum keeps this bounded at four
     * bytes per shape instead of holding up to 2000 full statements in request memory.
     *
     * @var array<string, int>
     */
    private array $shapeChecksums = [];

    /**
     * @var bool
     */
    private bool $registered = false;

    /**
     * @param \Muon\DevProfiler\Model\Run\Gate $gate
     * @param \Muon\DevProfiler\Model\Run\RunContext $context
     * @param \Muon\DevProfiler\Model\Sql\QueryFingerprint $fingerprint
     * @param \Muon\DevProfiler\Model\Sql\ValueMasker $masker
     * @param \Muon\DevProfiler\Model\Sql\StatementOrigin $origin
     */
    public function __construct(
        private readonly Gate $gate,
        private readonly RunContext $context,
        private readonly QueryFingerprint $fingerprint,
        private readonly ValueMasker $masker,
        private readonly StatementOrigin $origin
    ) {
    }

    /**
     * Start the clock for the statement about to run.
     *
     * @param \Magento\Framework\DB\LoggerInterface $subject
     * @return void
     * @SuppressWarnings(PHPMD.UnusedFormalParameter) `$subject` is fixed by the plugin signature.
     */
    public function beforeStartTimer(LoggerInterface $subject): void
    {
        if ($this->isActive()) {
            $this->startedAt = microtime(true);
        }
    }

    /**
     * Fold the finished statement into its group.
     *
     * @param \Magento\Framework\DB\LoggerInterface $subject
     * @param string $type
     * @param string $sql
     * @param array<int|string, mixed> $bind
     * @param mixed $result
     * @return void
     * @SuppressWarnings(PHPMD.UnusedFormalParameter) `$subject` and `$result` are fixed by the plugin
     * signature; only the statement and its bindings are recorded.
     */
    public function beforeLogStats(
        LoggerInterface $subject,
        $type,
        $sql,
        $bind = [],
        $result = null
    ): void {
        if ($type !== LoggerInterface::TYPE_QUERY || $this->startedAt === null || !$this->isActive()) {
            return;
        }

        $ms = (microtime(true) - $this->startedAt) * 1000;
        $this->startedAt = null;

        $this->busy = true;

        try {
            $this->record((string)$sql, is_array($bind) ? $bind : [], $ms);
        } catch (\Throwable) {
            // A profiler must never be the reason a page fails to render.
        } finally {
            $this->busy = false;
        }
    }

    /**
     * @param string $sql
     * @param array<int|string, mixed> $bind
     * @param float $ms
     * @return void
     */
    private function record(string $sql, array $bind, float $ms): void
    {
        $this->registerProvider();

        $shape = $this->fingerprint->of($sql);

        if (!isset($this->groups[$shape])) {
            if (!$this->context->canAccept('queries', count($this->groups))) {
                return;
            }

            $this->groups[$shape] = [
                'fingerprint' => $shape,
                // The SHAPE, never the raw statement. Magento inlines values through quoteInto at
                // least as often as it binds them — Model::load($value, $field) and every
                // where('col = ?', $v) put the value in the statement text — so storing $sql here
                // wrote customer emails, coupon codes and persistent_session keys to disk in
                // cleartext, past the masker that guards $bind. The fingerprint has already
                // replaced every quoted and numeric literal with `?`, so it is the same text
                // without the values, and it is capped where $sql was not.
                'sample' => $shape,
                'count' => 0,
                'total_ms' => 0.0,
                'max_ms' => 0.0,
                'binds' => $this->masker->maskBinds($bind),
                'origin' => null,
                'is_userland' => false,
                // Whether the statement TEXT differed between executions of this shape. Magento
                // inlines literals as often as it binds them, and the fingerprint normalises those
                // away — so without this, seven lookups of seven different CMS blocks are
                // indistinguishable from the same lookup run seven times. Read-time analysis was
                // calling that second case, and reporting distinct work as a duplicate.
                'sql_varies' => false,
            ];
        }

        $checksum = crc32($sql);
        $this->shapeChecksums[$shape] ??= $checksum;

        $group = &$this->groups[$shape];

        // One integer comparison per statement, against the first raw statement seen for this
        // shape. It turns "probably varied" into something observed rather than inferred from the
        // presence of binds — and it is the only reason the raw text is looked at at all, which is
        // why a checksum is enough and the text itself is never kept.
        if (!$group['sql_varies'] && $checksum !== $this->shapeChecksums[$shape]) {
            $group['sql_varies'] = true;
        }

        $group['count']++;
        $group['total_ms'] = round($group['total_ms'] + $ms, 3);
        $group['max_ms'] = round(max($group['max_ms'], $ms), 3);

        if ($group['origin'] === null && $this->worthTracing($ms, $group['count'])) {
            $resolved = $this->origin->resolve(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, self::TRACE_DEPTH));
            $group['origin'] = $resolved['origin'];
            $group['is_userland'] = $resolved['is_userland'];
        }

        unset($group);
    }

    /**
     * Register the provider once, on the first recorded statement.
     *
     * Writing the map into the context on every statement would copy it every time — thousands of
     * element copies on a busy page, paid by the request being measured. The context resolves this
     * when the run is assembled instead.
     *
     * @return void
     */
    private function registerProvider(): void
    {
        if ($this->registered) {
            return;
        }

        $this->registered = true;
        $this->context->setMetaProvider('queries', fn (): array => array_values($this->groups));
    }

    /**
     * Whether this execution earns a stack walk.
     *
     * Fixed thresholds, deliberately not configurable: a stack walk is by far the most expensive
     * thing here, and making the budget tunable invites setting it somewhere that makes the
     * profiler dominate its own numbers.
     *
     * @param float $ms
     * @param int $count
     * @return bool
     */
    private function worthTracing(float $ms, int $count): bool
    {
        return $ms > self::SLOW_MS || $count === self::TRACE_ON_REPEAT;
    }

    /**
     * Whether this request is being recorded.
     *
     * @return bool
     */
    private function isActive(): bool
    {
        if ($this->busy || $this->context->isFrozen()) {
            return false;
        }

        if ($this->active !== null) {
            return $this->active;
        }

        $this->busy = true;

        try {
            $answer = $this->gate->isProfiled();

            // Only remember the answer once it is real. Before the area resolves the gate says no,
            // and caching that would silence the collector for the rest of the request — which is
            // exactly the bug fixed in Gate itself and would be reintroduced here by a `??=`.
            // isDecided() draws that line: it is true for a settled no (production mode, which
            // cannot change within the process) and false for "not yet". Without it this plugin
            // re-enters the gate for every statement of every request on every install.
            if ($answer || $this->gate->isDecided()) {
                $this->active = $answer;
            }

            return $answer;
        } catch (\Throwable) {
            return false;
        } finally {
            $this->busy = false;
        }
    }

    /**
     * Clear per-request state so a long-running process does not carry it into the next request.
     *
     * $groups is the whole SQL capture. Leaving it would fold the next request's statements into this
     * one's and grow without bound.
     *
     * @return void
     * @SuppressWarnings(PHPMD.CamelCaseMethodName) The name is fixed by ResetAfterRequestInterface.
     */
    public function _resetState(): void
    {
        $this->busy = false;
        $this->startedAt = null;
        $this->active = null;
        $this->groups = [];
        $this->shapeChecksums = [];
        $this->registered = false;
    }
}
