<?php
/**
 * Copyright © Muon. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Muon\DevProfiler\Model\Run;

use Magento\Framework\App\Area;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\View\Design\Theme\FlyweightFactory;
use Magento\Framework\View\DesignInterface;
use Magento\Store\Model\StoreManagerInterface;
use Muon\DevProfiler\Model\Store\RunStore;
use Psr\Log\LoggerInterface;

/**
 * Closes a run: freezes the recorder, assembles the document, persists it, tags the response.
 *
 * Shared by both entry points, because Magento has two. A page is served by App\Http; a static
 * asset that has not been materialised yet is served by App\StaticResource, which bootstraps
 * separately and never reaches App\Http. LESS files are resolved almost exclusively in the second
 * kind of request, so a profiler that only hooks the first cannot answer the question this module
 * exists for — which copy of a theme's stylesheet is live.
 *
 * **This class never touches the response body.** Only a header is added. That is the module's
 * central safety property: appending markup here would be cached by Varnish or any CDN, which sit
 * downstream of PHP, and served to shoppers. Keep it that way.
 */
class RunFinalizer
{
    public const HEADER = 'X-Muon-Profile';

    /** A page request, served by App\Http. */
    public const KIND_PAGE = 'page';

    /** A static asset that had to be built, served by App\StaticResource. */
    public const KIND_STATIC = 'static';

    /**
     * @param \Muon\DevProfiler\Model\Run\RunContext $context
     * @param \Muon\DevProfiler\Model\Store\RunStore $store
     * @param \Magento\Framework\App\Request\Http $request
     * @param \Magento\Store\Model\StoreManagerInterface $storeManager
     * @param \Magento\Framework\View\DesignInterface $design
     * @param \Magento\Framework\View\Design\Theme\FlyweightFactory $themeFactory
     * @param \Psr\Log\LoggerInterface $logger
     */
    public function __construct(
        private readonly RunContext $context,
        private readonly RunStore $store,
        private readonly \Magento\Framework\App\Request\Http $request,
        private readonly StoreManagerInterface $storeManager,
        private readonly DesignInterface $design,
        private readonly FlyweightFactory $themeFactory,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Persist the run and tag the response.
     *
     * @param \Magento\Framework\App\ResponseInterface $result
     * @return void
     */
    public function finalize(ResponseInterface $result, string $kind = self::KIND_PAGE): void
    {
        try {
            $this->context->setMeta('request_kind', $kind);
            $this->context->freeze();

            $token = $this->context->token();
            $this->store->write($token, $this->assemble($result));

            if (method_exists($result, 'setHeader')) {
                $result->setHeader(self::HEADER, $token, true);
            }
        } catch (\Throwable $e) {
            $this->logger->debug('Muon_DevProfiler could not close the run: ' . $e->getMessage());
        }
    }

    /**
     * Build the stored document. Recorded facts only — nothing derived.
     *
     * Analysis is left to read time so it can be improved and re-run against runs captured today.
     *
     * @param \Magento\Framework\App\ResponseInterface $result
     * @return array<string, mixed>
     */
    private function assemble(ResponseInterface $result): array
    {
        return [
            'schema' => 1,
            'token' => $this->context->token(),
            'captured_at' => gmdate('c'),
            'request' => [
                'method' => (string)$this->request->getMethod(),
                'url' => (string)$this->request->getRequestUri(),
                'full_action' => $this->fullActionName(),
                'status' => $this->statusCode($result),
                'is_ajax' => $this->request->isXmlHttpRequest(),
                'kind' => (string)$this->context->meta('request_kind', self::KIND_PAGE),
                'duration_ms' => $this->durationMs(),
                'memory_peak_kb' => (int)round(memory_get_peak_usage(true) / 1024),
            ],
            'context' => $this->storeContext(),
            'layout' => [
                'generated' => (bool)$this->context->meta('layout_generated', false),
                'cacheable' => $this->context->meta('layout_cacheable'),
                'handles' => $this->context->meta('layout_handles', []),
                'uncacheable_blocks' => $this->context->all('uncacheable_blocks'),
                'constructor_optouts' => $this->context->all('constructor_optouts'),
            ],
            'fallback' => $this->context->all('fallback'),
            // Resolved from a provider the SQL collector registered, not written per statement.
            'queries' => $this->queries(),
            'truncated' => [
                'fallback' => $this->context->truncated('fallback'),
                'queries' => $this->context->truncated('queries'),
            ],
        ];
    }

    /**
     * Statement groups recorded by the SQL collector, or an empty list when it recorded nothing.
     *
     * @return list<array<string, mixed>>
     */
    private function queries(): array
    {
        $queries = $this->context->meta('queries', []);

        return is_array($queries) ? array_values(array_filter($queries, 'is_array')) : [];
    }

    /**
     * @return array<string, mixed>
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) One guarded branch per fact recorded about the
     * store and theme. Every read here can legitimately fail on a request that never resolved an
     * area, so each is defended individually — collapsing them would trade a readable list of
     * independent lookups for a shorter method that throws on the paths this exists to survive.
     */
    private function storeContext(): array
    {
        $context = [
            'store_code' => null,
            'store_id' => null,
            'website_id' => null,
            'theme_path' => null,
            // How we learned the theme. "observed" means this request actually used it;
            // "configured" means we had to look it up afterwards and are reporting what the store
            // is set to, which is not quite the same claim.
            'theme_source' => null,
        ];

        try {
            $store = $this->storeManager->getStore();
            $context['store_code'] = $store->getCode();
            $context['store_id'] = (int)$store->getId();
            $context['website_id'] = (int)$store->getWebsiteId();
        } catch (\Throwable) {
            // A request that never resolved a store still has a profile worth keeping.
        }

        try {
            $theme = $this->design->getDesignTheme();
            $context['theme_path'] = $theme->getThemePath() ?: ($theme->getCode() ?: null);
        } catch (\Throwable) {
            $context['theme_path'] = null;
        }

        // A static-resource request never loads the design, so DesignInterface has nothing to
        // report — but every fallback resolution carries the theme it was resolved against, which
        // is the same answer from the other direction.
        if ($context['theme_path'] === null) {
            foreach ($this->context->all('fallback') as $entry) {
                if (!empty($entry['theme'])) {
                    $context['theme_path'] = (string)$entry['theme'];
                    break;
                }
            }
        }

        if ($context['theme_path'] !== null) {
            $context['theme_source'] = 'observed';

            return $context;
        }

        // Nothing observed the theme: on a full-page-cache hit Magento loads no design and
        // resolves no files at all. The store's configured theme is still knowable from scope
        // config without loading anything, and "the theme this store is set to" beats "?" — but it
        // is a weaker claim than the two paths above, so it is labelled as such rather than
        // presented as though the request used it.
        $context['theme_path'] = $this->configuredTheme($context['store_id']);
        $context['theme_source'] = $context['theme_path'] !== null ? 'configured' : null;

        return $context;
    }

    /**
     * The theme this store is configured to use, read without loading the design.
     *
     * @param int|null $storeId
     * @return string|null
     */
    private function configuredTheme(?int $storeId): ?string
    {
        if ($storeId === null) {
            return null;
        }

        try {
            $configured = $this->design->getConfigurationDesignTheme(
                Area::AREA_FRONTEND,
                ['store' => $storeId]
            );

            if (!$configured) {
                return null;
            }

            // getConfigurationDesignTheme() yields a theme id; the flyweight turns either an id or
            // a path into a theme.
            $theme = $this->themeFactory->create((string)$configured, Area::AREA_FRONTEND);

            return $theme->getThemePath() ?: ($theme->getCode() ?: null);
        } catch (\Throwable $e) {
            $this->logger->debug('Muon_DevProfiler could not read the configured theme: ' . $e->getMessage());

            return null;
        }
    }

    /**
     * @return string|null
     */
    private function fullActionName(): ?string
    {
        try {
            $action = (string)$this->request->getFullActionName();
        } catch (\Throwable) {
            return null;
        }

        // getFullActionName() joins route, controller and action with underscores, so a request
        // that never routed — a cache hit, a static asset — comes back as "__". That is not a
        // value, it is the absence of one, and storing it as a string makes it look like data.
        return trim($action, '_') === '' ? null : $action;
    }

    /**
     * @param \Magento\Framework\App\ResponseInterface $result
     * @return int|null
     */
    private function statusCode(ResponseInterface $result): ?int
    {
        // ResponseInterface does not declare this; the concrete HTTP response does.
        return method_exists($result, 'getHttpResponseCode')
            ? (int)$result->getHttpResponseCode()
            : null;
    }

    /**
     * How long the request took, measured from the timestamp PHP stamps before Magento starts.
     *
     * @return float
     */
    private function durationMs(): float
    {
        $started = $this->request->getServer('REQUEST_TIME_FLOAT');

        if (!is_numeric($started)) {
            return 0.0;
        }

        return round((microtime(true) - (float)$started) * 1000, 1);
    }
}
