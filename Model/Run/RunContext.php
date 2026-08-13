<?php
/**
 * Copyright © Muon. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Muon\DevProfiler\Model\Run;

/**
 * The per-request recorder every collector writes into.
 *
 * Deliberately dumb: no formatting, no analysis, no I/O. Collectors run inside the code they are
 * measuring, so the only thing they are permitted to do is append to an array. Everything that
 * costs real time — classifying shadowed files, deciding why a page was uncacheable — happens
 * later, in the CLI, against the written file.
 *
 * Every list is capped. Once full it stops growing and counts what it dropped, so a report can
 * say "312 of 2841" rather than quietly implying that was all of them.
 */
class RunContext
{
    /**
     * Lazily generated run identifier.
     *
     * @var string|null
     */
    private ?string $token = null;

    /**
     * Recorded facts, keyed by list name.
     *
     * @var array<string, list<array<string, mixed>>>
     */
    private array $lists = [];

    /**
     * Per-list count of entries refused after the cap was reached.
     *
     * @var array<string, int>
     */
    private array $dropped = [];

    /**
     * Single-valued facts about the run.
     *
     * @var array<string, mixed>
     */
    private array $meta = [];

    /**
     * Facts supplied on demand instead of eagerly.
     *
     * @var array<string, callable>
     */
    private array $metaProviders = [];

    /**
     * Whether the recorder has stopped accepting data.
     *
     * @var bool
     */
    private bool $frozen = false;

    /**
     * @param int $maxEntries Hard cap per list, so one pathological page cannot exhaust memory.
     */
    public function __construct(
        private readonly int $maxEntries = 2000
    ) {
    }

    /**
     * The run's public identifier, stable for the lifetime of the request.
     *
     * @return string
     */
    public function token(): string
    {
        return $this->token ??= bin2hex(random_bytes(6));
    }

    /**
     * Append one recorded fact to a named list.
     *
     * @param string $list
     * @param array<string, mixed> $entry Shape varies by list; see the JSON contract in the module README.
     * @return void
     */
    public function push(string $list, array $entry): void
    {
        if ($this->frozen) {
            return;
        }

        if (count($this->lists[$list] ?? []) >= $this->maxEntries) {
            $this->dropped[$list] = ($this->dropped[$list] ?? 0) + 1;

            return;
        }

        $this->lists[$list][] = $entry;
    }

    /**
     * Whether another distinct entry may be added to a list the caller maintains itself.
     *
     * The SQL collector folds statements into a map keyed by fingerprint rather than pushing one
     * entry per execution, so it never calls push() — but it must still respect the same cap and
     * report the same truncation, or a pathological page could grow that map without limit.
     *
     * @param string $list
     * @param int $currentCount
     * @return bool
     */
    public function canAccept(string $list, int $currentCount): bool
    {
        if ($this->frozen) {
            return false;
        }

        if ($currentCount >= $this->maxEntries) {
            $this->dropped[$list] = ($this->dropped[$list] ?? 0) + 1;

            return false;
        }

        return true;
    }

    /**
     * Supply a fact lazily, resolved when it is read rather than when it is registered.
     *
     * The SQL collector maintains a map that changes on every statement. Writing it into meta each
     * time would copy the whole map per statement — on a 213-statement page holding 58 groups that
     * is thousands of pointless element copies, paid by the very request being measured. A provider
     * is registered once and resolved when the run is assembled.
     *
     * @param string $key
     * @param callable $provider
     * @return void
     */
    public function setMetaProvider(string $key, callable $provider): void
    {
        if (!$this->frozen) {
            $this->metaProviders[$key] = $provider;
        }
    }

    /**
     * Every entry recorded into a list.
     *
     * @param string $list
     * @return list<array<string, mixed>>
     */
    public function all(string $list): array
    {
        return $this->lists[$list] ?? [];
    }

    /**
     * How many entries a list holds.
     *
     * @param string $list
     * @return int
     */
    public function count(string $list): int
    {
        return count($this->lists[$list] ?? []);
    }

    /**
     * How many entries were dropped from a list once it hit the cap.
     *
     * @param string $list
     * @return int
     */
    public function truncated(string $list): int
    {
        return $this->dropped[$list] ?? 0;
    }

    /**
     * Record a single-valued fact about the run.
     *
     * @param string $key
     * @param mixed $value
     * @return void
     */
    public function setMeta(string $key, mixed $value): void
    {
        if ($this->frozen) {
            return;
        }

        $this->meta[$key] = $value;
    }

    /**
     * Read one recorded fact, or the supplied default when it was never set.
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function meta(string $key, mixed $default = null): mixed
    {
        if (isset($this->metaProviders[$key])) {
            return ($this->metaProviders[$key])();
        }

        return $this->meta[$key] ?? $default;
    }

    /**
     * Every single-valued fact recorded for this run.
     *
     * @return array<string, mixed>
     */
    public function allMeta(): array
    {
        return $this->meta;
    }

    /**
     * Stop accepting data.
     *
     * Called once the response is assembled. Anything running after that point — writing the
     * profile itself — would otherwise appear in its own numbers.
     *
     * @return void
     */
    public function freeze(): void
    {
        $this->frozen = true;
    }

    /**
     * Whether the recorder has been frozen.
     *
     * @return bool
     */
    public function isFrozen(): bool
    {
        return $this->frozen;
    }
}
