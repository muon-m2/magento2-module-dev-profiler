<?php
/**
 * Copyright © Muon. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Muon\DevProfiler\Test\Unit\Plugin\View;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\View\Design\FileResolution\Fallback\ResolverInterface;
use Magento\Framework\View\Design\ThemeInterface;
use Muon\DevProfiler\Model\Run\Gate;
use Muon\DevProfiler\Model\Run\RunContext;
use Muon\DevProfiler\Plugin\View\FallbackRecorder;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/**
 * @see FallbackRecorder
 *
 * The module's own policy is that gate-then-delegate plugins are left untested, and that is right
 * for the one-liners. This one is not a one-liner: it takes seven arguments of which five are
 * optional, carries a CyclomaticComplexity suppression saying so, and is documented as the
 * highest-frequency hook in the module. Every optional argument is a branch that can record the
 * wrong thing, and a wrong fallback record is invisible — it just makes the report lie.
 */
#[AllowMockObjectsWithoutExpectations]
class FallbackRecorderTest extends TestCase
{
    private RunContext $context;

    /**
     * @param bool $profiled
     * @return FallbackRecorder
     */
    private function recorder(bool $profiled = true): FallbackRecorder
    {
        $this->context = new RunContext();

        $gate = $this->createMock(Gate::class);
        $gate->method('isProfiled')->willReturn($profiled);

        $directoryList = $this->createStub(DirectoryList::class);
        $directoryList->method('getRoot')->willReturn('/var/www/magento');

        return new FallbackRecorder($gate, $this->context, $directoryList);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function recorded(): array
    {
        return $this->context->all('fallback');
    }

    /**
     * @return ResolverInterface
     */
    private function subject(): ResolverInterface
    {
        return $this->createStub(ResolverInterface::class);
    }

    public function testTheMinimalCallStillRecordsTypeAndFile(): void
    {
        $recorder = $this->recorder();

        $recorder->afterResolve($this->subject(), '/var/www/magento/app/x.phtml', 'template', 'x.phtml');

        $entry = $this->recorded()[0];

        self::assertSame('template', $entry['type']);
        self::assertSame('x.phtml', $entry['file']);
        self::assertNull($entry['module'], 'an argument that was not passed must record as absent');
        self::assertNull($entry['area']);
        self::assertNull($entry['locale']);
        self::assertNull($entry['theme']);
    }

    public function testTheFullCallRecordsEveryArgument(): void
    {
        $theme = $this->createStub(ThemeInterface::class);
        $theme->method('getThemePath')->willReturn('Muon/cosmic');

        $recorder = $this->recorder();
        $recorder->afterResolve(
            $this->subject(),
            '/var/www/magento/app/design/frontend/Muon/cosmic/Magento_Theme/templates/html/header.phtml',
            'template',
            'html/header.phtml',
            'frontend',
            $theme,
            'en_US',
            'Magento_Theme'
        );

        $entry = $this->recorded()[0];

        self::assertSame('frontend', $entry['area']);
        self::assertSame('en_US', $entry['locale']);
        self::assertSame('Magento_Theme', $entry['module']);
        self::assertSame('Muon/cosmic', $entry['theme']);
        self::assertStringNotContainsString('/var/www/magento', (string)$entry['resolved'], 'paths are stored relative');
    }

    /**
     * A theme with no path still has a code, and losing it would leave the shadow analysis unable
     * to say which theme a lookup came from.
     */
    public function testAThemeWithoutAPathFallsBackToItsCode(): void
    {
        $theme = $this->createStub(ThemeInterface::class);
        $theme->method('getThemePath')->willReturn(null);
        $theme->method('getCode')->willReturn('Muon/cosmic-custom');

        $recorder = $this->recorder();
        $recorder->afterResolve($this->subject(), '/x', 'template', 'x.phtml', 'frontend', $theme);

        self::assertSame('Muon/cosmic-custom', $this->recorded()[0]['theme']);
    }

    /**
     * A miss is evidence too: it is how the report shows a file that was looked for and not found.
     */
    public function testAFailedResolutionIsRecordedWithNoResolvedPath(): void
    {
        $recorder = $this->recorder();
        $recorder->afterResolve($this->subject(), false, 'template', 'missing.phtml');

        self::assertNull($this->recorded()[0]['resolved']);
    }

    public function testTheResolvedPathIsAlwaysReturnedUnchanged(): void
    {
        $recorder = $this->recorder();
        $result = '/var/www/magento/app/x.phtml';

        self::assertSame($result, $recorder->afterResolve($this->subject(), $result, 'template', 'x.phtml'));
    }

    public function testNothingIsRecordedWhenTheGateIsClosed(): void
    {
        $recorder = $this->recorder(false);
        $recorder->afterResolve($this->subject(), '/x', 'template', 'x.phtml');

        self::assertSame([], $this->recorded());
    }
}
