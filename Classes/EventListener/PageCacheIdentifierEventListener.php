<?php

declare(strict_types=1);

namespace Proudnerds\ABTestPages\EventListener;

use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Frontend\Event\BeforePageCacheIdentifierIsHashedEvent;

/**
 * Gives a page served as part of a running test its own page cache entry, separate from
 * the same page rendered for a crawler or opened directly, which has no measurement script.
 */
#[AsEventListener(identifier: 'pn-abtestpages/page-cache-identifier')]
final class PageCacheIdentifierEventListener
{
    public function __invoke(BeforePageCacheIdentifierIsHashedEvent $event): void
    {
        $request = $event->getRequest();
        $variant = $request->getAttribute('abtestpages.variant');
        if ($variant === null) {
            return;
        }

        $parameters = $event->getPageCacheIdentifierParameters();
        $parameters['abtestpages'] = $request->getAttribute('abtestpages.originalPageId') . '-' . $variant;
        $event->setPageCacheIdentifierParameters($parameters);
    }
}
