<?php
/**
 * Copyright © Muon. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Muon\DevProfiler\Model\Sql;

/**
 * Turns a backtrace into the one line worth printing: where the statement actually came from.
 *
 * "This query ran 47 times" is a fact. "This query ran 47 times from Foo.php:112" is a work item.
 * Everything between the two is skipping frames that are true but useless — the logger itself, the
 * adapter, the interception plumbing, generated interceptor classes, and this module.
 *
 * The v1 layout collector gets this wrong and reports `Interceptor.php:146`, which is why the skip
 * list here includes the framework's interception directory explicitly rather than only generated
 * code.
 *
 * Frames are passed in rather than captured here, so the decision of *when* a stack is worth
 * walking stays with the caller — and so this class can be tested without a real stack.
 */
class StatementOrigin
{
    /**
     * Path fragments that are never the answer.
     */
    private const SKIP = [
        '/Muon/DevProfiler/',
        '/generated/code/',
        '/framework/DB/',
        // Magento's bundled Zend DB does not live under framework/DB, so a skip list that only
        // named that one reported every statement as coming from the adapter itself — true, and
        // useless. Found by running the collector against a real category page.
        '/zend-db/',
        '/Zend/Db/',
        '/framework/Interception/',
        '/framework/ObjectManager/',
        '/framework/App/ResourceConnection',
    ];

    /**
     * Path fragments that mean "code this project owns", as opposed to a vendor package.
     */
    private const USERLAND = ['/app/code/', '/app/design/'];

    /**
     * The first frame outside the plumbing.
     *
     * @param list<array<string, mixed>> $frames As returned by debug_backtrace().
     * @return array{origin: string|null, is_userland: bool}
     */
    public function resolve(array $frames): array
    {
        foreach ($frames as $frame) {
            $file = $frame['file'] ?? null;

            if (!is_string($file) || $file === '' || $this->isSkipped($file)) {
                continue;
            }

            return [
                'origin' => $this->relative($file) . ':' . (string)($frame['line'] ?? '?'),
                'is_userland' => $this->isUserland($file),
            ];
        }

        return ['origin' => null, 'is_userland' => false];
    }

    /**
     * @param string $file
     * @return bool
     */
    private function isSkipped(string $file): bool
    {
        foreach (self::SKIP as $fragment) {
            if (str_contains($file, $fragment)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param string $file
     * @return bool
     */
    private function isUserland(string $file): bool
    {
        foreach (self::USERLAND as $fragment) {
            if (str_contains($file, $fragment)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Trim the absolute path to something readable.
     *
     * Done by pattern rather than by asking DirectoryList for the root, deliberately: this class is
     * reached from a plugin that is instantiated during bootstrap, and every dependency added to
     * that graph is a risk of forcing code generation at the wrong moment.
     *
     * @param string $file
     * @return string
     */
    private function relative(string $file): string
    {
        foreach (['/app/code/', '/app/design/', '/vendor/', '/lib/internal/', '/pub/'] as $anchor) {
            $at = strpos($file, $anchor);

            if ($at !== false) {
                return ltrim(substr($file, $at + 1), '/');
            }
        }

        return basename($file);
    }
}
