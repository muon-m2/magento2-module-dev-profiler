<?php
/**
 * Copyright © Muon. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Muon\DevProfiler\Plugin\View;

use Magento\Developer\Helper\Data as DeveloperHelper;
use Magento\Developer\Model\TemplateEngine\Decorator\DebugHints;
use Magento\Developer\Model\TemplateEngine\Decorator\DebugHintsFactory;
use Magento\Framework\App\Http\Context as HttpContext;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\ObjectManager\ResetAfterRequestInterface;
use Magento\Framework\Stdlib\CookieManagerInterface;
use Magento\Framework\Stdlib\Cookie\CookieMetadataFactory;
use Magento\Framework\View\TemplateEngineFactory;
use Magento\Framework\View\TemplateEngineInterface;
use Muon\DevProfiler\Model\Run\Gate;

/**
 * Template hints for one browser instead of one store.
 *
 * Magento's own switch lives at dev/debug/template_hints_storefront, which is store scoped. On this
 * project that is unusable: turning it on to look at one block turns it on for every visitor of
 * that store view, across sixteen of them, and it survives being forgotten about.
 *
 * This rides on a query parameter, with a cookie so it sticks while clicking around — which means
 * no route, no controller and no CSRF surface, and a parameter that curl and headless Playwright
 * can set trivially. It is still behind the gate, so it does not exist outside developer mode.
 *
 * The decorator itself is Magento's, so the hints look exactly like the familiar ones.
 */
class TemplateHints implements ResetAfterRequestInterface
{
    public const PARAM = '__muon_hints';
    public const COOKIE = 'muon_hints';

    /**
     * Anything not in this list is ignored rather than passed through to the decorator.
     */
    private const MODES = ['0', '1', 'blocks'];

    /**
     * @var string|null
     */
    private ?string $mode = null;

    /**
     * @param \Muon\DevProfiler\Model\Run\Gate $gate
     * @param \Magento\Developer\Model\TemplateEngine\Decorator\DebugHintsFactory $debugHintsFactory
     * @param \Magento\Framework\App\RequestInterface $request
     * @param \Magento\Framework\Stdlib\CookieManagerInterface $cookieManager
     * @param \Magento\Framework\Stdlib\Cookie\CookieMetadataFactory $cookieMetadataFactory
     * @param \Magento\Framework\App\Http\Context $httpContext
     * @param \Magento\Developer\Helper\Data $developerHelper
     */
    public function __construct(
        private readonly Gate $gate,
        private readonly DebugHintsFactory $debugHintsFactory,
        private readonly RequestInterface $request,
        private readonly CookieManagerInterface $cookieManager,
        private readonly CookieMetadataFactory $cookieMetadataFactory,
        private readonly HttpContext $httpContext,
        private readonly DeveloperHelper $developerHelper
    ) {
    }

    /**
     * Decorate the engine with Magento's own debug hints when this browser asked for them.
     *
     * @param \Magento\Framework\View\TemplateEngineFactory $subject
     * @param \Magento\Framework\View\TemplateEngineInterface $result
     * @return \Magento\Framework\View\TemplateEngineInterface
     * @SuppressWarnings(PHPMD.UnusedFormalParameter) `$subject` is fixed by the plugin signature.
     */
    public function afterCreate(
        TemplateEngineFactory $subject,
        TemplateEngineInterface $result
    ): TemplateEngineInterface {
        if (!$this->gate->isProfiled()) {
            return $result;
        }

        // Core's own hints honour dev/restrict/allow_ips (Developer\Model\TemplateEngine\Plugin\
        // DebugHints::afterCreate does the same check). An operator who has restricted debugging to
        // their own address has said something about who may see template paths, and a query
        // parameter must not be a way around it.
        if (!$this->isDevAllowed()) {
            return $result;
        }

        try {
            $mode = $this->resolveMode();
        } catch (\Throwable) {
            return $result;
        }

        if ($mode === null || $mode === '0') {
            return $result;
        }

        // Hints change the response body, so the page that carries them must not be served from
        // cache to anyone else. Http\Context feeds getVaryString(), which becomes the X-Magento-Vary
        // cookie, which the full-page cache hashes into its key — so a hinted page is stored under
        // a key no ordinary visitor computes. Without this the first hinted response is cached
        // under the clean URL and every subsequent visitor is served red dotted borders and this
        // installation's server-side template paths.
        // Core's own plugin (Magento_Developer, sortOrder 10) has already wrapped the engine when
        // dev/debug/template_hints_storefront is on, and this one runs at 100. Wrapping the wrapper
        // renders two nested hint frames around every block.
        if ($result instanceof DebugHints) {
            return $result;
        }

        $this->vary($mode);

        return $this->debugHintsFactory->create([
            'subject' => $result,
            'showBlockHints' => $mode === 'blocks',
        ]);
    }

    /**
     * Put the active mode into the page-cache vary, so a hinted page cannot be served to anyone else.
     *
     * @param string $mode
     * @return void
     */
    private function vary(string $mode): void
    {
        try {
            $this->httpContext->setValue(self::COOKIE, $mode, '0');
        } catch (\Throwable) {
            // A vary that cannot be set is a reason not to decorate, but the caller has already
            // decided; failing the page render over it would be worse than the stale-cache risk,
            // which is bounded to developer mode.
        }
    }

    /**
     * Whether debugging is permitted for this client, per dev/restrict/allow_ips.
     *
     * @return bool
     */
    private function isDevAllowed(): bool
    {
        try {
            return (bool)$this->developerHelper->isDevAllowed();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * The requested hint mode: an explicit parameter this request, else the sticky cookie.
     *
     * @return string|null
     */
    private function resolveMode(): ?string
    {
        if ($this->mode !== null) {
            return $this->mode;
        }

        $requested = $this->request->getParam(self::PARAM);

        if ($requested !== null && in_array((string)$requested, self::MODES, true)) {
            $this->persist((string)$requested);

            return $this->mode = (string)$requested;
        }

        $cookie = $this->cookieManager->getCookie(self::COOKIE);

        return $this->mode = is_string($cookie) && in_array($cookie, self::MODES, true) ? $cookie : null;
    }

    /**
     * Remember the choice for this browser so it survives the next click.
     *
     * @param string $mode
     * @return void
     */
    private function persist(string $mode): void
    {
        try {
            if ($mode === '0') {
                $this->cookieManager->deleteCookie(
                    self::COOKIE,
                    $this->cookieMetadataFactory->createCookieMetadata()->setPath('/')
                );

                return;
            }

            $metadata = $this->cookieMetadataFactory->createPublicCookieMetadata()
                ->setPath('/')
                ->setDuration(86400)
                ->setHttpOnly(true)
                ->setSameSite('Lax');

            $this->cookieManager->setPublicCookie(self::COOKIE, $mode, $metadata);
        } catch (\Throwable) {
            // Headers may already be sent on some paths; the parameter still works for this
            // request, it just will not stick.
        }
    }

    /**
     * Clear per-request state so a long-running process does not carry it into the next request.
     *
     * The hints mode is read from this request's parameter or cookie and must not leak into the next
     * visitor's response.
     *
     * @return void
     * @SuppressWarnings(PHPMD.CamelCaseMethodName) The name is fixed by ResetAfterRequestInterface.
     */
    public function _resetState(): void
    {
        $this->mode = null;
    }
}
