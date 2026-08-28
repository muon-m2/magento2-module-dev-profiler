<?php
/**
 * Copyright © Muon. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Muon\DevProfiler\Api;

/**
 * Read access to captured profiler runs.
 *
 * This is the module's supported surface for anything that displays runs it did not record — the
 * console commands here, and the web board in Muon_DevProfilerBoard. It is deliberately read-only:
 * writing is the collectors' business, and a consumer that could write could also corrupt the very
 * evidence it exists to show.
 *
 * The stored document's shape is versioned separately by its own `schema` field. This interface
 * promises the methods, not the contents of the arrays they return; a consumer that cares should
 * check `schema` and say so when it does not recognise it, rather than rendering a newer capture as
 * empty panels.
 *
 * @api
 */
interface RunReaderInterface
{
    /**
     * One run by its token, or null when no such run is stored.
     *
     * The token is sanitised by the implementation, so a caller may pass request input directly.
     *
     * @param string $token
     * @return array<string, mixed>|null
     */
    public function load(string $token): ?array;

    /**
     * The most recent run of any kind, or null when the ring is empty.
     *
     * @return array<string, mixed>|null
     */
    public function loadLast(): ?array;

    /**
     * The most recent run that was a full HTML document, skipping AJAX and static-asset runs.
     *
     * A page load fires many requests; this is the one the reader almost always means.
     *
     * @return array<string, mixed>|null
     */
    public function loadLastDocument(): ?array;

    /**
     * The most recent runs, newest first.
     *
     * @param int $limit
     * @return list<array<string, mixed>>
     */
    public function list(int $limit = 20): array;

    /**
     * How many runs are stored, without decoding any of them.
     *
     * @return int
     */
    public function count(): int;
}
