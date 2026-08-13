<?php
/**
 * Copyright © Muon. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Muon\DevProfiler\Test\Unit\Model\Analysis;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\View\Design\Fallback\Rule\RuleInterface;
use Magento\Framework\View\Design\Fallback\RulePool;
use Magento\Framework\View\Design\Theme\FlyweightFactory;
use Magento\Framework\View\Design\ThemeInterface;
use Muon\DevProfiler\Model\Analysis\FileExistenceChecker;
use Muon\DevProfiler\Model\Analysis\ShadowResolver;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @see ShadowResolver
 */
#[AllowMockObjectsWithoutExpectations]
class ShadowResolverTest extends TestCase
{
    private const ROOT = '/var/www/magento';

    /**
     * @param list<string> $dirs Ordered candidate directories the fallback rule would search.
     * @param list<string> $existing Absolute paths that exist.
     * @return ShadowResolver
     */
    private function resolver(array $dirs, array $existing): ShadowResolver
    {
        /** @var RuleInterface&\PHPUnit\Framework\MockObject\MockObject $rule */
        $rule = $this->createMock(RuleInterface::class);
        $rule->method('getPatternDirs')->willReturn($dirs);

        /** @var RulePool&\PHPUnit\Framework\MockObject\MockObject $pool */
        $pool = $this->createMock(RulePool::class);
        $pool->method('getRule')->willReturn($rule);

        /** @var ThemeInterface&\PHPUnit\Framework\MockObject\MockObject $theme */
        $theme = $this->createMock(ThemeInterface::class);

        /** @var FlyweightFactory&\PHPUnit\Framework\MockObject\MockObject $factory */
        $factory = $this->createMock(FlyweightFactory::class);
        $factory->method('create')->willReturn($theme);

        /** @var FileExistenceChecker&\PHPUnit\Framework\MockObject\MockObject $files */
        $files = $this->createMock(FileExistenceChecker::class);
        $files->method('exists')->willReturnCallback(
            static fn (string $path): bool => in_array($path, $existing, true)
        );

        /** @var DirectoryList&\PHPUnit\Framework\MockObject\MockObject $directoryList */
        $directoryList = $this->createMock(DirectoryList::class);
        $directoryList->method('getRoot')->willReturn(self::ROOT);

        return new ShadowResolver($pool, $factory, $files, $directoryList, $this->createMock(LoggerInterface::class));
    }

    /**
     * @param string|null $resolved
     * @return array<string, mixed>
     */
    private function resolution(?string $resolved): array
    {
        return [
            'type' => 'static',
            'file' => 'css/tokens.less',
            'module' => null,
            'area' => 'frontend',
            'locale' => 'en_US',
            'theme' => 'Muon/cosmic-custom',
            'resolved' => $resolved,
        ];
    }

    public function testReportsNoShadowsWhenOnlyOneCopyExists(): void
    {
        $resolver = $this->resolver(
            [self::ROOT . '/app/design/a', self::ROOT . '/vendor/b'],
            [self::ROOT . '/app/design/a/css/tokens.less']
        );

        $result = $resolver->classify([$this->resolution('app/design/a/css/tokens.less')], 'Muon/cosmic-custom');

        self::assertSame('app/design/a/css/tokens.less', $result[0]['winner']);
        self::assertSame([], $result[0]['shadowed']);
        self::assertNull($result[0]['anomaly']);
    }

    /**
     * The case the module exists for: an override in a child theme hiding the parent's copy.
     */
    public function testReportsTheShadowedCopy(): void
    {
        $resolver = $this->resolver(
            [self::ROOT . '/app/design/a', self::ROOT . '/vendor/b'],
            [self::ROOT . '/app/design/a/css/tokens.less', self::ROOT . '/vendor/b/css/tokens.less']
        );

        $result = $resolver->classify([$this->resolution('app/design/a/css/tokens.less')], 'Muon/cosmic-custom');

        self::assertSame('app/design/a/css/tokens.less', $result[0]['winner']);
        self::assertSame(['vendor/b/css/tokens.less'], $result[0]['shadowed']);
    }

    public function testReportsEveryShadowedCopyInSearchOrder(): void
    {
        $resolver = $this->resolver(
            [self::ROOT . '/a', self::ROOT . '/b', self::ROOT . '/c'],
            [self::ROOT . '/a/css/tokens.less', self::ROOT . '/b/css/tokens.less', self::ROOT . '/c/css/tokens.less']
        );

        $result = $resolver->classify([$this->resolution('a/css/tokens.less')], 'Muon/cosmic-custom');

        self::assertSame(['b/css/tokens.less', 'c/css/tokens.less'], $result[0]['shadowed']);
    }

    public function testFlagsAReplayThatDivergedFromTheLiveLookup(): void
    {
        $resolver = $this->resolver([self::ROOT . '/a'], []);

        $result = $resolver->classify([$this->resolution('a/css/tokens.less')], 'Muon/cosmic-custom');

        self::assertSame('replay-diverged', $result[0]['anomaly']);
    }

    /**
     * Magento asks for files that are allowed not to exist — theme i18n CSVs being the common
     * case. That is an ordinary probe, not an anomaly, and reporting it as one buried the real
     * signal: it was the first four lines of a storefront page's output.
     */
    public function testAProbeThatFindsNothingIsNotAnAnomaly(): void
    {
        $resolver = $this->resolver([self::ROOT . '/a'], []);

        $result = $resolver->classify([$this->resolution(null)], 'Muon/cosmic-custom');

        self::assertSame('probe-miss', $result[0]['anomaly']);
    }

    public function testFlagsAWinnerThatDisagreesWithWhatWasRecorded(): void
    {
        $resolver = $this->resolver(
            [self::ROOT . '/a', self::ROOT . '/b'],
            [self::ROOT . '/a/css/tokens.less']
        );

        $result = $resolver->classify([$this->resolution('b/css/tokens.less')], 'Muon/cosmic-custom');

        self::assertSame('winner-mismatch', $result[0]['anomaly']);
        self::assertSame('b/css/tokens.less', $result[0]['recorded_winner']);
    }

    public function testUnknownResolutionTypeIsReportedNotSwallowed(): void
    {
        $resolver = $this->resolver([self::ROOT . '/a'], [self::ROOT . '/a/css/tokens.less']);

        $result = $resolver->classify(
            [['type' => 'nonsense', 'file' => 'css/tokens.less', 'theme' => 'Muon/cosmic-custom']],
            'Muon/cosmic-custom'
        );

        self::assertSame('unsupported-type', $result[0]['anomaly']);
    }

    /**
     * The regression this prefix exists to prevent.
     *
     * `html_template` files are recorded as `modal/modal-popup.html` but live at
     * `<module>/view/base/web/templates/modal/modal-popup.html`. Probing without the `templates/`
     * segment finds nothing, and because the framework did resolve the file the miss is reported
     * as `replay-diverged` — a false alarm claiming the analysis cannot be trusted. That was 22 of
     * 93 files on one storefront page.
     */
    public function testHtmlTemplatesAreProbedUnderTheTemplatesSegment(): void
    {
        $resolver = $this->resolver(
            [self::ROOT . '/theme/Magento_Ui/web', self::ROOT . '/module/view/base/web'],
            [
                self::ROOT . '/theme/Magento_Ui/web/templates/modal/popup.html',
                self::ROOT . '/module/view/base/web/templates/modal/popup.html',
            ]
        );

        $result = $resolver->classify([[
            'type' => 'html_template', 'file' => 'modal/popup.html', 'module' => 'Magento_Ui',
            'area' => 'frontend', 'locale' => 'en_US', 'theme' => 'Muon/cosmic-custom',
            'resolved' => 'theme/Magento_Ui/web/templates/modal/popup.html',
        ]], 'Muon/cosmic-custom');

        self::assertNull($result[0]['anomaly'], 'must not be reported as a diverged replay');
        self::assertSame('theme/Magento_Ui/web/templates/modal/popup.html', $result[0]['winner']);
        self::assertSame(['module/view/base/web/templates/modal/popup.html'], $result[0]['shadowed']);
    }

    /**
     * Magento uses both spellings — 62 modules ship `web/template/` on this installation and 5
     * ship `web/templates/`. Probing only the plural left 17 files still falsely diverged.
     */
    public function testHtmlTemplatesAreAlsoFoundUnderTheSingularSegment(): void
    {
        $resolver = $this->resolver(
            [self::ROOT . '/theme/web', self::ROOT . '/module/view/frontend/web'],
            [
                self::ROOT . '/theme/web/template/messages.html',
                self::ROOT . '/module/view/frontend/web/template/messages.html',
            ]
        );

        $result = $resolver->classify([[
            'type' => 'html_template', 'file' => 'messages.html', 'module' => 'Magento_Ui',
            'area' => 'frontend', 'locale' => 'en_US', 'theme' => 'Muon/cosmic-custom',
            'resolved' => 'theme/web/template/messages.html',
        ]], 'Muon/cosmic-custom');

        self::assertNull($result[0]['anomaly']);
        self::assertSame('theme/web/template/messages.html', $result[0]['winner']);
        self::assertSame(['module/view/frontend/web/template/messages.html'], $result[0]['shadowed']);
    }

    /**
     * A module shipping the file under both spellings must not be reported as its own shadow.
     */
    public function testADirectoryContributesAtMostOneCopy(): void
    {
        $resolver = $this->resolver(
            [self::ROOT . '/only'],
            [self::ROOT . '/only/template/x.html', self::ROOT . '/only/templates/x.html']
        );

        $result = $resolver->classify([[
            'type' => 'html_template', 'file' => 'x.html', 'area' => 'frontend',
            'locale' => 'en_US', 'theme' => 'Muon/cosmic-custom',
            'resolved' => 'only/template/x.html',
        ]], 'Muon/cosmic-custom');

        self::assertSame([], $result[0]['shadowed']);
    }

    /**
     * Distinct search directories can resolve to the same physical file — the static rule yields
     * both a locale-specific and a plain web/ directory. Counting it twice made a file appear to
     * shadow itself.
     */
    public function testTheSameFileReachedTwiceDoesNotShadowItself(): void
    {
        $resolver = $this->resolver(
            [self::ROOT . '/a', self::ROOT . '/a'],
            [self::ROOT . '/a/css/tokens.less']
        );

        $result = $resolver->classify([$this->resolution('a/css/tokens.less')], 'Muon/cosmic-custom');

        self::assertSame('a/css/tokens.less', $result[0]['winner']);
        self::assertSame([], $result[0]['shadowed'], 'a file cannot shadow itself');
    }
}
