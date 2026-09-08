<?php

declare(strict_types=1);

namespace Proudnerds\ABTestPages\Tests\Unit\EventListener;

use GuzzleHttp\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Proudnerds\ABTestPages\EventListener\CanonicalUrlEventListener;
use TYPO3\CMS\Core\Domain\Page;
use TYPO3\CMS\Seo\Event\ModifyUrlForCanonicalTagEvent;

/**
 * Only covers the listener's early-return guards. Actually exercising the
 * URL-building branch requires a `ContentObjectRenderer::createUrl()` call,
 * which needs a real TypoScript/site/router context — out of reach of a unit
 * test without typo3/testing-framework; verify that branch functionally
 * (request a page with a "B" page configured and inspect the rendered
 * <link rel="canonical"> tag) instead.
 */
final class CanonicalUrlEventListenerTest extends TestCase
{
    private const PAGE_A = 10;

    public function testUrlIsLeftUntouchedWhenNoAbTestIsActive(): void
    {
        $request = new ServerRequest('GET', 'https://example.com/page-a');
        $event = new ModifyUrlForCanonicalTagEvent(
            $request,
            new Page(['uid' => self::PAGE_A]),
            'https://example.com/page-a',
            null,
        );

        (new CanonicalUrlEventListener())($event);

        self::assertSame('https://example.com/page-a', $event->getUrl());
    }

    public function testUrlIsLeftUntouchedWhenTheOriginalPageWasActuallyRendered(): void
    {
        $request = (new ServerRequest('GET', 'https://example.com/page-a'))
            ->withAttribute('abtestpages.originalPageId', self::PAGE_A);
        $event = new ModifyUrlForCanonicalTagEvent(
            $request,
            new Page(['uid' => self::PAGE_A]),
            'https://example.com/page-a',
            null,
        );

        (new CanonicalUrlEventListener())($event);

        self::assertSame('https://example.com/page-a', $event->getUrl());
    }
}
