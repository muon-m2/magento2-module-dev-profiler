<?php
/**
 * Copyright © Muon. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Muon\DevProfiler\Model\Analysis;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\View\Design\Fallback\RulePool;
use Magento\Framework\View\Design\Theme\FlyweightFactory;
use Magento\Framework\View\Design\ThemeInterface;
use Psr\Log\LoggerInterface;

/**
 * Works out which copies of a file were passed over.
 *
 * Knowing which file won a fallback lookup is easy — the framework hands it back. The question
 * that actually costs an afternoon is which *other* copies existed and were skipped: an override
 * written in the wrong theme is invisible, because the site behaves exactly as if it were never
 * written at all.
 *
 * The answer is obtained by replaying Magento's own rule rather than reimplementing it.
 * `RulePool::getRule($type)->getPatternDirs($params)` returns the ordered directory list the
 * resolver searched; stat each in order and the first hit is the winner, while every later hit is
 * a copy that exists and is being ignored.
 *
 * This runs at read time, in the CLI, never during a profiled request. A developer-mode page
 * performs several hundred resolutions and enumerating candidates inline would mean thousands of
 * stat calls on the page being measured. Deferring costs nothing, because the recorded arguments
 * are sufficient to reproduce the lookup exactly — including the theme, which is rebuilt by path
 * with the area passed explicitly, so no area emulation is required.
 */
class ShadowResolver
{
    /**
     * Recorded resolution types mapped to the fallback rule that produced them.
     */
    private const RULE_BY_TYPE = [
        'file' => RulePool::TYPE_FILE,
        'locale' => RulePool::TYPE_LOCALE_FILE,
        'template' => RulePool::TYPE_TEMPLATE_FILE,
        'static' => RulePool::TYPE_STATIC_FILE,
        'email' => RulePool::TYPE_EMAIL_TEMPLATE,
        // Knockout / UI-component .html templates arrive with their own type, which RulePool has
        // no constant for. Their search order is the static rule's — but see FILE_PREFIX_BY_TYPE:
        // the directories alone are not enough.
        'html_template' => RulePool::TYPE_STATIC_FILE,
    ];

    /**
     * Path segments sitting between a rule's directories and the recorded file key, in probe order.
     *
     * `html_template` is recorded as e.g. `modal/popup.html`, but the file lives under a segment
     * the resolver applies on top of the rule's `web/` directories and which is not part of the
     * recorded key. Magento uses **both spellings**: on this installation 62 modules ship
     * `web/template/` and 5 ship `web/templates/`, the plural being almost exclusively
     * `Magento_Ui`. Singular is probed first because it is by far the common case.
     *
     * Getting this wrong is not harmless. Probing the wrong segment finds nothing, and because the
     * framework *did* resolve the file, the miss is reported as `replay-diverged` — an alarm
     * saying the analysis cannot be trusted. Probing no segment at all put 22 of 93 files on one
     * storefront page into that state; probing only the plural still left 17.
     */
    private const FILE_PREFIXES_BY_TYPE = [
        'html_template' => ['template/', 'templates/'],
    ];

    /**
     * Themes already rebuilt, keyed by theme path.
     *
     * @var array<string, \Magento\Framework\View\Design\ThemeInterface|null>
     */
    private array $themes = [];

    /**
     * @param \Magento\Framework\View\Design\Fallback\RulePool $rulePool
     * @param \Magento\Framework\View\Design\Theme\FlyweightFactory $themeFactory
     * @param \Muon\DevProfiler\Model\Analysis\FileExistenceChecker $files
     * @param \Magento\Framework\App\Filesystem\DirectoryList $directoryList
     * @param \Psr\Log\LoggerInterface $logger
     */
    public function __construct(
        private readonly RulePool $rulePool,
        private readonly FlyweightFactory $themeFactory,
        private readonly FileExistenceChecker $files,
        private readonly DirectoryList $directoryList,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Classify every recorded resolution into winner and shadowed candidates.
     *
     * @param array<int, array<string, mixed>> $resolutions Entries recorded by FallbackRecorder.
     * @param string $themePath Theme the request resolved to, e.g. "Muon/cosmic-custom".
     * @return list<array<string, mixed>>
     */
    public function classify(array $resolutions, string $themePath): array
    {
        $classified = [];

        foreach ($resolutions as $resolution) {
            if (!is_array($resolution)) {
                continue;
            }

            $classified[] = $this->classifyOne($resolution, $themePath);
        }

        return $classified;
    }

    /**
     * @param array<string, mixed> $resolution
     * @param string $themePath
     * @return array<string, mixed>
     */
    private function classifyOne(array $resolution, string $themePath): array
    {
        $file = (string)($resolution['file'] ?? '');
        $type = (string)($resolution['type'] ?? '');
        $recorded = $resolution['resolved'] ?? null;

        $result = [
            'type' => $type,
            'file' => $file,
            'module' => $resolution['module'] ?? null,
            'winner' => is_string($recorded) ? $recorded : null,
            'shadowed' => [],
            'candidates' => 0,
            'anomaly' => null,
        ];

        if (!isset(self::RULE_BY_TYPE[$type])) {
            // Worth distinguishing from a failed rebuild: this one is a gap in our mapping, and
            // reporting it as a generic failure would hide the fact that a whole class of file is
            // going unanalysed.
            $result['anomaly'] = 'unsupported-type';

            return $result;
        }

        $candidates = $this->candidateDirs($resolution, $themePath);

        if ($candidates === null) {
            $result['anomaly'] = 'candidates-unavailable';

            return $result;
        }

        $result['candidates'] = count($candidates);
        $hits = $this->existingCopies($candidates, $file, self::FILE_PREFIXES_BY_TYPE[$type] ?? ['']);

        if ($hits === []) {
            // Two very different situations. If the framework itself resolved nothing, this is an
            // ordinary probe: Magento asks for files that are allowed not to exist (theme i18n
            // CSVs being the common case) and carries on. Calling that an anomaly buries the real
            // signal under noise — on a storefront page it was the first four lines of output.
            //
            // If the framework *did* resolve a path and we cannot find it, that is a genuine
            // problem: our replay diverged from the live lookup and nothing below can be trusted.
            $result['anomaly'] = $recorded === null ? 'probe-miss' : 'replay-diverged';

            return $result;
        }

        $result['winner'] = $hits[0];
        $result['shadowed'] = array_slice($hits, 1);

        if (is_string($recorded) && $recorded !== '' && $hits[0] !== $recorded) {
            $result['anomaly'] = 'winner-mismatch';
            $result['recorded_winner'] = $recorded;
        }

        return $result;
    }

    /**
     * Every distinct copy of the file that exists, in search order.
     *
     * Distinct search directories can resolve to the same physical file — the static rule yields
     * both a locale-specific and a plain web/ directory, and a deployed asset is reachable through
     * more than one of them. Counting the same path twice made a file look as though it were
     * shadowing itself.
     *
     * @param list<string> $candidates
     * @param string $file
     * @param list<string> $prefixes Segments to try inside each directory, in order.
     * @return list<string>
     */
    private function existingCopies(array $candidates, string $file, array $prefixes = ['']): array
    {
        $hits = [];

        foreach ($candidates as $dir) {
            foreach ($prefixes as $prefix) {
                $path = $dir . '/' . $prefix . $file;

                if (!$this->files->exists($path)) {
                    continue;
                }

                $hit = $this->relative($path);

                if (!in_array($hit, $hits, true)) {
                    $hits[] = $hit;
                }

                // One directory holds the file under one spelling; stop at the first that exists
                // so a module shipping both does not report itself as its own shadow.
                break;
            }
        }

        return $hits;
    }

    /**
     * The ordered directories the framework searched for this file.
     *
     * @param array<string, mixed> $resolution
     * @param string $themePath
     * @return list<string>|null Null when the rule or theme could not be rebuilt.
     */
    private function candidateDirs(array $resolution, string $themePath): ?array
    {
        $ruleType = self::RULE_BY_TYPE[(string)($resolution['type'] ?? '')] ?? null;
        $theme = $this->theme((string)($resolution['theme'] ?? '') ?: $themePath);

        if ($ruleType === null || $theme === null) {
            return null;
        }

        // Rebuilt exactly as Magento\Framework\View\Design\FileResolution\Fallback\Resolver\Simple
        // builds them: null-valued keys omitted, module under 'module_name', file included.
        $params = [
            'area' => (string)($resolution['area'] ?? '') ?: 'frontend',
            'theme' => $theme,
            'locale' => $resolution['locale'] ?? null,
            'file' => (string)($resolution['file'] ?? ''),
        ];

        if (!empty($resolution['module'])) {
            $params['module_name'] = (string)$resolution['module'];
        }

        $params = array_filter($params, static fn ($value): bool => $value !== null);

        try {
            $dirs = $this->rulePool->getRule($ruleType)->getPatternDirs($params);
        } catch (\Throwable $e) {
            $this->logger->debug('Muon_DevProfiler could not rebuild fallback dirs: ' . $e->getMessage());

            return null;
        }

        return array_values(array_filter($dirs, 'is_string'));
    }

    /**
     * Rebuild a theme by path, in the frontend area.
     *
     * The area is passed explicitly rather than emulated, which is why this works from a console
     * command where no area code is set at all.
     *
     * @param string $themePath
     * @return \Magento\Framework\View\Design\ThemeInterface|null
     */
    private function theme(string $themePath): ?ThemeInterface
    {
        if ($themePath === '') {
            return null;
        }

        if (array_key_exists($themePath, $this->themes)) {
            return $this->themes[$themePath];
        }

        try {
            $theme = $this->themeFactory->create($themePath, 'frontend');
        } catch (\Throwable $e) {
            $this->logger->debug('Muon_DevProfiler could not load theme ' . $themePath . ': ' . $e->getMessage());
            $theme = null;
        }

        return $this->themes[$themePath] = $theme instanceof ThemeInterface ? $theme : null;
    }

    /**
     * Strip the Magento root so output is readable and portable.
     *
     * @param string $path
     * @return string
     */
    private function relative(string $path): string
    {
        try {
            $root = rtrim($this->directoryList->getRoot(), '/') . '/';
        } catch (\Throwable) {
            return $path;
        }

        return str_starts_with($path, $root) ? substr($path, strlen($root)) : $path;
    }
}
