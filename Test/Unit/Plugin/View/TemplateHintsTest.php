<?php
/**
 * Copyright © Muon. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Muon\DevProfiler\Test\Unit\Plugin\View;

require_once __DIR__ . '/../../Stub/generated.php';

use Magento\Developer\Helper\Data as DeveloperHelper;
use Magento\Developer\Model\TemplateEngine\Decorator\DebugHints;
use Magento\Developer\Model\TemplateEngine\Decorator\DebugHintsFactory;
use Magento\Framework\App\Http\Context as HttpContext;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Stdlib\Cookie\CookieMetadataFactory;
use Magento\Framework\Stdlib\CookieManagerInterface;
use Magento\Framework\View\TemplateEngineFactory;
use Magento\Framework\View\TemplateEngineInterface;
use Muon\DevProfiler\Model\Run\Gate;
use Muon\DevProfiler\Plugin\View\TemplateHints;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @see TemplateHints
 *
 * This plugin is the one place the module changes the response body, which makes it the one place
 * it can poison a shared cache. It also reads a query parameter, so it is reachable by anyone who
 * can make a request to a developer-mode instance.
 */
#[AllowMockObjectsWithoutExpectations]
class TemplateHintsTest extends TestCase
{
    /** @var HttpContext&MockObject */
    private HttpContext $httpContext;

    /** @var DebugHintsFactory&MockObject */
    private DebugHintsFactory $factory;

    /**
     * @param string|null $param
     * @param string|null $cookie
     * @param bool $profiled
     * @param bool $devAllowed
     * @return TemplateHints
     */
    private function plugin(
        ?string $param = null,
        ?string $cookie = null,
        bool $profiled = true,
        bool $devAllowed = true
    ): TemplateHints {
        $gate = $this->createMock(Gate::class);
        $gate->method('isProfiled')->willReturn($profiled);

        $request = $this->createStub(RequestInterface::class);
        $request->method('getParam')->willReturn($param);

        $cookies = $this->createStub(CookieManagerInterface::class);
        $cookies->method('getCookie')->willReturn($cookie);

        $developer = $this->createStub(DeveloperHelper::class);
        $developer->method('isDevAllowed')->willReturn($devAllowed);

        $this->httpContext = $this->createMock(HttpContext::class);
        $this->factory = $this->createMock(DebugHintsFactory::class);

        return new TemplateHints(
            $gate,
            $this->factory,
            $request,
            $cookies,
            $this->createStub(CookieMetadataFactory::class),
            $this->httpContext,
            $developer
        );
    }

    /**
     * @return TemplateEngineInterface
     */
    private function engine(): TemplateEngineInterface
    {
        return $this->createStub(TemplateEngineInterface::class);
    }

    /**
     * The regression this class exists to prevent.
     *
     * Hints rewrite every block's HTML. Http\Context feeds getVaryString(), which becomes the
     * X-Magento-Vary cookie, which the full-page cache hashes into its key — so without this the
     * first hinted response is stored under the clean URL and served to every later visitor, red
     * dotted borders and server-side template paths included.
     */
    public function testAHintedPageIsPutIntoTheCacheVary(): void
    {
        $plugin = $this->plugin(param: '1');

        $this->httpContext->expects(self::once())
            ->method('setValue')
            ->with(TemplateHints::COOKIE, '1', '0');

        $this->factory->method('create')->willReturn($this->createStub(DebugHints::class));

        $plugin->afterCreate($this->createStub(TemplateEngineFactory::class), $this->engine());
    }

    public function testAnUnhintedPageTouchesNeitherTheVaryNorTheDecorator(): void
    {
        $plugin = $this->plugin();

        $this->httpContext->expects(self::never())->method('setValue');
        $this->factory->expects(self::never())->method('create');

        $engine = $this->engine();

        self::assertSame(
            $engine,
            $plugin->afterCreate($this->createStub(TemplateEngineFactory::class), $engine)
        );
    }

    /**
     * Core's own hints honour dev/restrict/allow_ips. An operator who restricted debugging to their
     * own address must not be worked around by a query parameter.
     */
    public function testAnIpRestrictionIsHonoured(): void
    {
        $plugin = $this->plugin(param: '1', devAllowed: false);

        $this->factory->expects(self::never())->method('create');
        $this->httpContext->expects(self::never())->method('setValue');

        $engine = $this->engine();

        self::assertSame(
            $engine,
            $plugin->afterCreate($this->createStub(TemplateEngineFactory::class), $engine)
        );
    }

    public function testTheGateStillComesFirst(): void
    {
        $plugin = $this->plugin(param: '1', profiled: false);

        $this->factory->expects(self::never())->method('create');

        $engine = $this->engine();

        self::assertSame(
            $engine,
            $plugin->afterCreate($this->createStub(TemplateEngineFactory::class), $engine)
        );
    }

    /**
     * A mode that is not one of the three is ignored rather than passed to the decorator.
     */
    public function testAnUnknownModeIsRefused(): void
    {
        $plugin = $this->plugin(param: 'malicious');

        $this->factory->expects(self::never())->method('create');

        $engine = $this->engine();

        self::assertSame(
            $engine,
            $plugin->afterCreate($this->createStub(TemplateEngineFactory::class), $engine)
        );
    }

    public function testTheCookieKeepsHintsOnWhenNoParameterIsPresent(): void
    {
        $plugin = $this->plugin(cookie: 'blocks');

        $this->factory->expects(self::once())
            ->method('create')
            ->with(self::callback(static fn (array $args): bool => $args['showBlockHints'] === true))
            ->willReturn($this->createStub(DebugHints::class));

        $plugin->afterCreate($this->createStub(TemplateEngineFactory::class), $this->engine());
    }
}
