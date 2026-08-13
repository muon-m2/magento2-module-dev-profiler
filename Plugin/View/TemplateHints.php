<?php
/**
 * Copyright © Muon. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Muon\DevProfiler\Plugin\View;

use Magento\Developer\Model\TemplateEngine\Decorator\DebugHintsFactory;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Stdlib\Cookie\CookieMetadataFactory;
use Magento\Framework\Stdlib\CookieManagerInterface;
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
class TemplateHints
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
     */
    public function __construct(
        private readonly Gate $gate,
        private readonly DebugHintsFactory $debugHintsFactory,
        private readonly RequestInterface $request,
        private readonly CookieManagerInterface $cookieManager,
        private readonly CookieMetadataFactory $cookieMetadataFactory
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

        try {
            $mode = $this->resolveMode();
        } catch (\Throwable) {
            return $result;
        }

        if ($mode === null || $mode === '0') {
            return $result;
        }

        return $this->debugHintsFactory->create([
            'subject' => $result,
            'showBlockHints' => $mode === 'blocks',
        ]);
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
}
