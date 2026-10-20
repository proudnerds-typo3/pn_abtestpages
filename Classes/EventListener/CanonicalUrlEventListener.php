<?php

declare(strict_types=1);

namespace Proudnerds\ABTestPages\EventListener;

use Proudnerds\ABTestPages\Domain\Repository\AbTestPageRepository;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Frontend\ContentObject\ContentObjectRenderer;
use TYPO3\CMS\Seo\Event\ModifyUrlForCanonicalTagEvent;

/**
 * Points the canonical URL of a "B" page at its "A" page, both when the middleware
 * serves B under A's URL and when B is opened on its own URL. Otherwise B is an
 * indexable duplicate of A.
 */
#[AsEventListener(identifier: 'pn-abtestpages/canonical-url')]
final readonly class CanonicalUrlEventListener
{
    public function __construct(
        private AbTestPageRepository $abTestPageRepository,
    ) {}

    public function __invoke(ModifyUrlForCanonicalTagEvent $event): void
    {
        // Set when the page has no_index or disableCanonical: it must get no canonical at all.
        if ($event->getCanonicalGenerationDisabledException() !== null) {
            return;
        }

        $request = $event->getRequest();
        $renderedPageId = $event->getPage()->getPageId();
        $canonicalPageId = $request->getAttribute('abtestpages.originalPageId')
            ?? $this->abTestPageRepository->findPageIdTestingVariant($renderedPageId);

        if ($canonicalPageId === null || $canonicalPageId === $renderedPageId) {
            return;
        }

        $cObj = GeneralUtility::makeInstance(ContentObjectRenderer::class);
        $cObj->setRequest($request);
        $cObj->start(['uid' => $canonicalPageId], 'pages');

        $event->setUrl($cObj->createUrl([
            'parameter' => $canonicalPageId,
            'forceAbsoluteUrl' => true,
        ]));
    }
}
