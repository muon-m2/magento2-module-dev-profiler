<?php
/**
 * Copyright © Muon. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Muon\DevProfiler\Model\Run;

use Magento\Framework\App\Area;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\App\Response\HttpInterface as HttpResponseInterface;
use Magento\Framework\View\DesignInterface;
use Magento\Framework\View\Design\Theme\FlyweightFactory;
use Magento\Store\Model\StoreManagerInterface;
use Muon\DevProfiler\Model\Sql\ValueMasker;
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
 *
 * @api
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
     * @param \Muon\DevProfiler\Model\Sql\ValueMasker $masker
     * @param list<string> $excludedActions Full action names whose runs are never recorded.
     */
    public function __construct(
        private readonly RunContext $context,
        private readonly RunStore $store,
        private readonly \Magento\Framework\App\Request\Http $request,
        private readonly StoreManagerInterface $storeManager,
        private readonly DesignInterface $design,
        private readonly FlyweightFactory $themeFactory,
        private readonly LoggerInterface $logger,
        private readonly ValueMasker $masker,
        private readonly array $excludedActions = []
    ) {
    }

    /**
     * Persist the run and tag the response.
     *
     * @param \Magento\Framework\App\ResponseInterface $result
     * @param string $kind One of self::KIND_PAGE or self::KIND_STATIC.
     * @return void
     */
    public function finalize(ResponseInterface $result, string $kind = self::KIND_PAGE): void
    {
        try {
            if ($this->isExcluded()) {
                return;
            }

            $this->context->setMeta('request_kind', $kind);
            $this->context->freeze();

            $token = $this->context->token();
            $this->store->write($token, $this->assemble($result));

            // instanceof, not method_exists: both methods are declared on HttpInterface, which
            // every response reaching here implements — App\Http returns Response\Http, and
            // StaticResource returns Response\FileInterface, which extends it. A string lookup
            // gives static analysis nothing to narrow and no guarantee on the call that follows.
            if ($result instanceof HttpResponseInterface) {
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
                'url' => $this->requestUrl(),
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
     * The request URI with sensitive query values masked.
     *
     * getRequestUri() returns the query string verbatim, and Magento puts single-use credentials
     * in it: customer/account/createPassword carries `token`, Confirm carries `key`, and both are
     * enough to take over an account. A search page carries whatever the visitor typed. None of
     * that should sit in a file on disk for the next fifty requests.
     *
     * The path is kept whole — it is the thing being profiled. Only the query is filtered, and it
     * is filtered by ValueMasker, so there is one definition of "sensitive" rather than a second
     * list here that drifts from the first.
     *
     * @return string
     */
    private function requestUrl(): string
    {
        $uri = (string)$this->request->getRequestUri();
        $split = strpos($uri, '?');

        if ($split === false) {
            return $uri;
        }

        $path = substr($uri, 0, $split);
        $query = substr($uri, $split + 1);

        if ($query === '') {
            return $path;
        }

        parse_str($query, $params);

        if ($params === []) {
            return $path;
        }

        return $path . '?' . http_build_query($this->masker->maskBinds($params));
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
     * Whether this request's own run must never be recorded.
     *
     * A companion module that reads the ring over HTTP — the board — is itself a frontend request,
     * so its collectors run and its run would be written like any other. Left alone, opening the
     * board and letting it poll would evict the runs being inspected within seconds: the tool would
     * destroy its own evidence.
     *
     * This is a constructor argument and deliberately **not** a plugin. Intercepting anything in
     * this module's constructor graph makes the object manager generate an interceptor inside
     * ___callPlugins(), which is the documented cause of the "Undefined array key
     * Magento\Framework\App\Http" failure that took the storefront down in 1.0.0 — and it is
     * invisible whenever generated/ is populated. Consumers contribute their own action names via
     * a di.xml argument; nothing is generated.
     *
     * A request that never routed has no action name, so a static-asset run can never be excluded
     * by accident — the strict comparison also keeps an empty string in the list from matching it.
     *
     * @return bool
     */
    private function isExcluded(): bool
    {
        if ($this->excludedActions === []) {
            return false;
        }

        $action = $this->fullActionName();

        if ($action === null) {
            return false;
        }

        // Both sides lower-cased. Router\Base sets the controller and action names from the raw URL
        // path segments with case preserved, while ActionList lower-cases only for class
        // resolution — so /muon_profiler/Run/View resolves correctly and yields
        // muon_profiler_Run_View, which a case-sensitive comparison misses. A consumer's own run
        // then gets recorded and evicts an entry from the ring it was opened to read.
        $excluded = array_map(static fn (string $name): string => strtolower($name), $this->excludedActions);

        return in_array(strtolower($action), $excluded, true);
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
        // ResponseInterface does not declare this; HttpInterface does, and every response that
        // reaches this point implements it.
        return $result instanceof HttpResponseInterface
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
