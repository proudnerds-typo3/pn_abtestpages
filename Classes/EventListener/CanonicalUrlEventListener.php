<?php

declare(strict_types=1);

namespace Proudnerds\ABTestPages\EventListener;

use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Frontend\ContentObject\ContentObjectRenderer;
use TYPO3\CMS\Seo\Event\ModifyUrlForCanonicalTagEvent;

/**
 * When AbTestPageResolver swapped the response to the "B" page, TYPO3's own
 * canonical-tag generator (EXT:seo) would otherwise point the canonical URL at
 * page B's own address instead of the URL the visitor actually requested —
 * confusing crawlers into treating the split-test variant as the indexable one.
 */
#[AsEventListener(identifier: 'pn-abtestpages/canonical-url')]
final class CanonicalUrlEventListener
{
    public function __invoke(ModifyUrlForCanonicalTagEvent $event): void
    {
        $request = $event->getRequest();
        $originalPageId = $request->getAttribute('abtestpages.originalPageId');

        if ($originalPageId === null || $originalPageId === $event->getPage()->getPageId()) {
            return;
        }

        $cObj = GeneralUtility::makeInstance(ContentObjectRenderer::class);
        $cObj->setRequest($request);
        $cObj->start(['uid' => $originalPageId], 'pages');

        $event->setUrl($cObj->createUrl([
            'parameter' => $originalPageId,
            'forceAbsoluteUrl' => true,
        ]));
    }
}
