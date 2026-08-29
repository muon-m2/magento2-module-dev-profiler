<?php
/**
 * Copyright © Muon. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Muon\DevProfiler\Test\Unit\Model\Run;

use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\Response\Http as HttpResponse;
use Magento\Framework\View\Design\Theme\FlyweightFactory;
use Magento\Framework\View\Design\ThemeInterface;
use Magento\Framework\View\DesignInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use Muon\DevProfiler\Model\Run\RunContext;
use Muon\DevProfiler\Model\Run\RunFinalizer;
use Muon\DevProfiler\Model\Sql\ValueMasker;
use Muon\DevProfiler\Model\Store\RunStore;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @see RunFinalizer
 */
class RunFinalizerTest extends TestCase
{
    /** @var MockObject&RunStore */
    private RunStore $store;

    /** @var MockObject&HttpResponse */
    private HttpResponse $response;

    /** @var Stub&HttpRequest */
    private HttpRequest $request;

    protected function setUp(): void
    {
        $this->store = $this->createMock(RunStore::class);
        $this->response = $this->createMock(HttpResponse::class);
        $this->request = $this->createStub(HttpRequest::class);

        $this->request->method('getMethod')->willReturn('GET');
        $this->request->method('getRequestUri')->willReturn('/en-us/');
        $this->request->method('isXmlHttpRequest')->willReturn(false);
        $this->request->method('getServer')->willReturn(microtime(true));
        $this->response->method('getHttpResponseCode')->willReturn(200);
    }

    public function testWritesTheRunAndTagsTheResponseWhenNothingIsExcluded(): void
    {
        $this->request->method('getFullActionName')->willReturn('cms_index_index');

        $written = null;
        $this->store->expects(self::once())
            ->method('write')
            ->willReturnCallback(static function (string $token, array $run) use (&$written): void {
                $written = $run;
            });

        $this->response->expects(self::once())
            ->method('setHeader')
            ->with(RunFinalizer::HEADER, self::isString(), true);

        $this->finalizer()->finalize($this->response);

        self::assertIsArray($written);
        self::assertSame('cms_index_index', $written['request']['full_action']);
    }

    public function testWritesNothingWhenTheActionIsExcluded(): void
    {
        $this->request->method('getFullActionName')->willReturn('muon_profiler_run_view');

        $this->store->expects(self::never())->method('write');
        $this->response->expects(self::never())->method('setHeader');

        $this->finalizer(['muon_profiler_index_index', 'muon_profiler_run_view'])
            ->finalize($this->response);
    }

    /**
     * A request that never routed reports "__", which the finalizer normalises to null. An empty
     * string in the exclusion list must not match it, or one careless di.xml entry would silence
     * every static-asset run — the runs this module exists to capture.
     */
    public function testARunThatNeverRoutedIsNotExcludedByAnEmptyActionName(): void
    {
        $this->request->method('getFullActionName')->willReturn('__');

        $this->store->expects(self::once())->method('write');
        $this->response->expects(self::once())->method('setHeader');

        $this->finalizer([''])->finalize($this->response, RunFinalizer::KIND_STATIC);
    }

    public function testAnExcludedActionStillLeavesOtherActionsRecorded(): void
    {
        $this->request->method('getFullActionName')->willReturn('catalog_category_view');

        $this->store->expects(self::once())->method('write');
        $this->response->expects(self::once())->method('setHeader');

        $this->finalizer(['muon_profiler_run_view'])->finalize($this->response);
    }

    /**
     * @param list<string> $excludedActions
     * @return RunFinalizer
     */
    private function finalizer(array $excludedActions = []): RunFinalizer
    {
        $store = $this->createStub(StoreInterface::class);
        $store->method('getCode')->willReturn('en-us');
        $store->method('getId')->willReturn(3);
        $store->method('getWebsiteId')->willReturn(2);

        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        $theme = $this->createStub(ThemeInterface::class);
        $theme->method('getThemePath')->willReturn('Muon/cosmic-custom');

        $design = $this->createStub(DesignInterface::class);
        $design->method('getDesignTheme')->willReturn($theme);

        return new RunFinalizer(
            new RunContext(),
            $this->store,
            $this->request,
            $storeManager,
            $design,
            $this->createStub(FlyweightFactory::class),
            $this->createStub(LoggerInterface::class),
            new ValueMasker(),
            $excludedActions
        );
    }

    /**
     * getRequestUri() returns the query string verbatim, and Magento puts single-use credentials in
     * it: customer/account/createPassword carries `token` and Confirm carries `key`, either of which
     * is enough to take over an account. Stored, they sit on disk for the next fifty requests and
     * are printed by muon:profile:list.
     */
    #[AllowMockObjectsWithoutExpectations] // setUp()'s shared fixtures are unused here.
    public function testCredentialsInTheQueryStringAreNotStored(): void
    {
        $request = $this->createStub(HttpRequest::class);
        $request->method('getMethod')->willReturn('GET');
        $request->method('isXmlHttpRequest')->willReturn(false);
        $request->method('getServer')->willReturn(microtime(true));
        $request->method('getFullActionName')->willReturn('customer_account_createpassword');
        $request->method('getRequestUri')->willReturn(
            '/en-us/customer/account/createPassword/?id=42&token=6f1d8ac09b2e4f7a&email=shopper%40example.com'
        );

        $written = null;
        $store = $this->createMock(RunStore::class);
        $store->expects(self::once())->method('write')->willReturnCallback(
            static function (string $token, array $run) use (&$written): void {
                $written = $run;
            }
        );

        $this->finalizerWith($request, $store)->finalize($this->rawResponse(), RunFinalizer::KIND_PAGE);

        self::assertIsArray($written);
        self::assertIsArray($written['request'] ?? null);

        $url = (string)($written['request']['url'] ?? '');

        self::assertStringContainsString('/en-us/customer/account/createPassword/', $url, 'the path is the subject');
        self::assertStringNotContainsString('6f1d8ac09b2e4f7a', $url, 'the reset token was stored');
        self::assertStringNotContainsString('shopper%40example.com', $url);
        self::assertStringNotContainsString('shopper@example.com', $url);
        self::assertStringContainsString('id=42', $url, 'a numeric id is diagnostic and must survive');
    }

    #[AllowMockObjectsWithoutExpectations] // setUp()'s shared fixtures are unused here.
    public function testAUrlWithNoQueryStringIsUntouched(): void
    {
        $request = $this->createStub(HttpRequest::class);
        $request->method('getMethod')->willReturn('GET');
        $request->method('isXmlHttpRequest')->willReturn(false);
        $request->method('getServer')->willReturn(microtime(true));
        $request->method('getFullActionName')->willReturn('cms_index_index');
        $request->method('getRequestUri')->willReturn('/en-us/nb-home');

        $written = null;
        $store = $this->createMock(RunStore::class);
        $store->expects(self::once())->method('write')->willReturnCallback(
            static function (string $token, array $run) use (&$written): void {
                $written = $run;
            }
        );

        $this->finalizerWith($request, $store)->finalize($this->rawResponse(), RunFinalizer::KIND_PAGE);

        self::assertIsArray($written);
        self::assertIsArray($written['request'] ?? null);
        self::assertSame('/en-us/nb-home', $written['request']['url'] ?? null);
    }

    /**
     * A response the finalizer may tag, with no expectations of its own.
     *
     * @return HttpResponse
     */
    private function rawResponse(): HttpResponse
    {
        $response = $this->createStub(HttpResponse::class);
        $response->method('getHttpResponseCode')->willReturn(200);

        return $response;
    }

    /**
     * @param HttpRequest $request
     * @param RunStore $store
     * @return RunFinalizer
     */
    private function finalizerWith(HttpRequest $request, RunStore $store, array $excluded = []): RunFinalizer
    {
        $store2 = $this->createStub(StoreInterface::class);
        $store2->method('getCode')->willReturn('en_us');
        $store2->method('getId')->willReturn(1);
        $store2->method('getWebsiteId')->willReturn(1);

        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store2);

        $theme = $this->createStub(ThemeInterface::class);
        $theme->method('getThemePath')->willReturn('Muon/cosmic');
        $design = $this->createStub(DesignInterface::class);
        $design->method('getDesignTheme')->willReturn($theme);

        return new RunFinalizer(
            new RunContext(),
            $store,
            $request,
            $storeManager,
            $design,
            $this->createStub(FlyweightFactory::class),
            $this->createStub(LoggerInterface::class),
            new ValueMasker(),
            $excluded
        );
    }

    /**
     * Router\Base preserves the case of the URL's path segments; only class resolution lower-cases.
     * A hand-typed /muon_profiler/Run/View therefore routes correctly and reports
     * muon_profiler_Run_View, which a case-sensitive comparison misses — and the consumer's own run
     * is recorded, evicting an entry from the ring it was opened to read.
     */
    #[AllowMockObjectsWithoutExpectations] // setUp()'s shared fixtures are unused here.
    public function testAnExcludedActionMatchesWhateverCaseTheUrlUsed(): void
    {
        $request = $this->createStub(HttpRequest::class);
        $request->method('getMethod')->willReturn('GET');
        $request->method('isXmlHttpRequest')->willReturn(false);
        $request->method('getServer')->willReturn(microtime(true));
        $request->method('getRequestUri')->willReturn('/muon_profiler/Run/View');
        $request->method('getFullActionName')->willReturn('muon_profiler_Run_View');

        $store = $this->createMock(RunStore::class);
        $store->expects(self::never())->method('write');

        $finalizer = $this->finalizerWith($request, $store, ['muon_profiler_run_view']);
        $finalizer->finalize($this->rawResponse(), RunFinalizer::KIND_PAGE);
    }
}
