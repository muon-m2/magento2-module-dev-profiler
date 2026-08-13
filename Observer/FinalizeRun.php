<?php
/**
 * Copyright © Muon. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Muon\DevProfiler\Observer;

use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Muon\DevProfiler\Model\Run\Gate;
use Muon\DevProfiler\Model\Run\RunFinalizer;

/**
 * Closes the run for a page request, on the event Magento provides for exactly this moment.
 *
 * This replaced a plugin on `App\Http::launch()`, which was the obvious hook and the wrong one.
 * `App\Http` is constructed during bootstrap, so its plugins must be registered globally — and a
 * globally registered plugin is instantiated inside `___callPlugins()`. Our constructor graph
 * reaches `StoreManagerInterface`, which `Magento_Store` plugs, so building it forced the object
 * manager to *generate* an interceptor part-way through `___callPlugins()`. That re-enters the
 * object manager and resets `PluginList::$_data`, and the next lookup fails with:
 *
 *     Undefined array key "Magento\Framework\App\Http" in PluginList.php:174
 *
 * The storefront returned 500 on every page. It was invisible with DI compiled, because then the
 * interceptor already exists and nothing is generated — so it only appeared once `generated/` was
 * empty, which is a completely ordinary state in development.
 *
 * `controller_front_send_response_before` is dispatched inside `launch()` after the response is
 * assembled, and is documented as the point to act "before sending output". Because it is an
 * event, this observer is registered in `etc/frontend/events.xml` and is therefore only wired in
 * the storefront area, instantiated long after bootstrap, where constructing anything is safe.
 *
 * It also fires on a full-page-cache hit, so hit detection is unaffected.
 */
class FinalizeRun implements ObserverInterface
{
    /**
     * @param \Muon\DevProfiler\Model\Run\Gate $gate
     * @param \Muon\DevProfiler\Model\Run\RunFinalizer $finalizer
     */
    public function __construct(
        private readonly Gate $gate,
        private readonly RunFinalizer $finalizer
    ) {
    }

    /**
     * Persist the run and tag the response.
     *
     * @param \Magento\Framework\Event\Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        if (!$this->gate->isProfiled()) {
            return;
        }

        $response = $observer->getEvent()->getData('response');

        if ($response instanceof ResponseInterface) {
            $this->finalizer->finalize($response, RunFinalizer::KIND_PAGE);
        }
    }
}
