<?php

declare(strict_types=1);

namespace Proudnerds\ABTestPages\Tests\Unit\Middleware;

use GuzzleHttp\Psr7\ServerRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Proudnerds\ABTestPages\Middleware\AbTestPageResolver;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Domain\Repository\PageRepository;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Routing\PageArguments;

final class AbTestPageResolverTest extends TestCase
{
    private const PAGE_A = 10;
    private const PAGE_B = 20;

    private PageRepository&MockObject $pageRepository;
    private PageRenderer&MockObject $pageRenderer;

    protected function setUp(): void
    {
        $this->pageRepository = $this->createMock(PageRepository::class);
        $this->pageRenderer = $this->createMock(PageRenderer::class);
    }

    /**
     * @param array<string, mixed> $pageAOverrides
     * @param array<string, mixed> $pageBOverrides
     */
    private function configurePages(array $pageAOverrides = [], array $pageBOverrides = []): void
    {
        $pageA = array_merge(['uid' => self::PAGE_A, 'tx_abtestpages_b_id' => self::PAGE_B], $pageAOverrides);
        $pageB = array_merge(['uid' => self::PAGE_B, 'tx_abtestpages_b_id' => 0], $pageBOverrides);

        $this->pageRepository->method('getPage')->willReturnCallback(
            static fn(int $uid): array => match ($uid) {
                self::PAGE_A => $pageA,
                self::PAGE_B => $pageB,
                default => [],
            },
        );
    }

    private function subject(): AbTestPageResolver
    {
        return new AbTestPageResolver($this->pageRepository, $this->pageRenderer);
    }

    private function requestForPageA(array $cookies = [], string $userAgent = 'Mozilla/5.0'): ServerRequestInterface
    {
        return (new ServerRequest('GET', 'https://example.com/page-a'))
            ->withAttribute('routing', new PageArguments(self::PAGE_A, '0', []))
            ->withHeader('User-Agent', $userAgent)
            ->withCookieParams($cookies);
    }

    public function testCrawlerRequestBypassesSplitEntirely(): void
    {
        $this->pageRepository->expects(self::never())->method('getPage');

        $request = $this->requestForPageA(userAgent: 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)');
        $handler = new CapturingRequestHandler();
        $response = $this->subject()->process($request, $handler);

        self::assertSame(self::PAGE_A, $handler->lastRequest->getAttribute('routing')->getPageId());
        self::assertFalse($response->hasHeader('Set-Cookie'));
    }

    public function testOtherCrawlerUserAgentsAreAlsoExcluded(): void
    {
        $this->pageRepository->expects(self::never())->method('getPage');

        foreach (['bingbot/2.0', 'DuckDuckBot/1.1', 'Twitterbot/1.0', 'facebookexternalhit/1.1'] as $userAgent) {
            $request = $this->requestForPageA(userAgent: $userAgent);
            $handler = new CapturingRequestHandler();
            $this->subject()->process($request, $handler);
            self::assertSame(self::PAGE_A, $handler->lastRequest->getAttribute('routing')->getPageId(), $userAgent);
        }
    }

    public function testPageWithoutBVariantPassesThrough(): void
    {
        $this->pageRepository->method('getPage')->willReturnCallback(
            static fn(int $uid): array => $uid === self::PAGE_A ? ['uid' => self::PAGE_A, 'tx_abtestpages_b_id' => 0] : [],
        );

        $handler = new CapturingRequestHandler();
        $response = $this->subject()->process($this->requestForPageA(), $handler);

        self::assertSame(self::PAGE_A, $handler->lastRequest->getAttribute('routing')->getPageId());
        self::assertFalse($response->hasHeader('Set-Cookie'));
        self::assertFalse($response->hasHeader('Cache-Control'));
    }

    public function testUnrenderableAlternativePageSkipsTheTest(): void
    {
        // Page B is "not renderable" (deleted/hidden/access-restricted): getPage() returns an empty row for it.
        $this->pageRepository->method('getPage')->willReturnCallback(
            static fn(int $uid): array => $uid === self::PAGE_A ? ['uid' => self::PAGE_A, 'tx_abtestpages_b_id' => self::PAGE_B] : [],
        );

        $handler = new CapturingRequestHandler();
        $response = $this->subject()->process($this->requestForPageA(), $handler);

        self::assertSame(self::PAGE_A, $handler->lastRequest->getAttribute('routing')->getPageId());
        self::assertFalse($response->hasHeader('Set-Cookie'));
    }

    public function testExistingCookieIsHonoredWithoutSettingItAgain(): void
    {
        $this->configurePages(['tx_abtestpages_cookie_time' => 86400]);

        $request = $this->requestForPageA(cookies: ['abtestpages-' . self::PAGE_A => (string)self::PAGE_B]);
        $handler = new CapturingRequestHandler();
        $response = $this->subject()->process($request, $handler);

        self::assertSame(self::PAGE_B, $handler->lastRequest->getAttribute('routing')->getPageId());
        self::assertFalse($response->hasHeader('Set-Cookie'));
    }

    public function testTamperedCookieValueIsIgnored(): void
    {
        $this->configurePages(['tx_abtestpages_b_percentage' => 0]);

        // Cookie points at neither the A nor the B page id.
        $request = $this->requestForPageA(cookies: ['abtestpages-' . self::PAGE_A => '999']);
        $handler = new CapturingRequestHandler();
        $response = $this->subject()->process($request, $handler);

        // 0% chance of B, so the fresh (re-rolled) selection deterministically lands on A.
        self::assertSame(self::PAGE_A, $handler->lastRequest->getAttribute('routing')->getPageId());
        self::assertTrue($response->hasHeader('Set-Cookie'));
    }

    public function testZeroPercentSplitAlwaysSelectsPageAAndSetsCookie(): void
    {
        $this->configurePages(['tx_abtestpages_b_percentage' => 0, 'tx_abtestpages_cookie_time' => 3600]);

        $handler = new CapturingRequestHandler();
        $response = $this->subject()->process($this->requestForPageA(), $handler);

        self::assertSame(self::PAGE_A, $handler->lastRequest->getAttribute('routing')->getPageId());
        $setCookie = $response->getHeaderLine('Set-Cookie');
        self::assertStringContainsString('abtestpages-' . self::PAGE_A . '=' . self::PAGE_A, $setCookie);
        self::assertStringContainsString('Max-Age=3600', $setCookie);
        self::assertStringContainsString('HttpOnly', $setCookie);
        self::assertStringNotContainsString('Secure', $setCookie);
    }

    public function testHundredPercentSplitAlwaysSelectsPageBAndSetsCookie(): void
    {
        $this->configurePages(['tx_abtestpages_b_percentage' => 100]);

        $handler = new CapturingRequestHandler();
        $response = $this->subject()->process($this->requestForPageA(), $handler);

        self::assertSame(self::PAGE_B, $handler->lastRequest->getAttribute('routing')->getPageId());
        self::assertStringContainsString('abtestpages-' . self::PAGE_A . '=' . self::PAGE_B, $response->getHeaderLine('Set-Cookie'));
    }

    public function testOriginalPageIdIsExposedForCanonicalUrlCorrection(): void
    {
        $this->configurePages(['tx_abtestpages_b_percentage' => 100]);

        $handler = new CapturingRequestHandler();
        $this->subject()->process($this->requestForPageA(), $handler);

        self::assertSame(self::PAGE_A, $handler->lastRequest->getAttribute('abtestpages.originalPageId'));
    }

    public function testResponseIsMarkedUncacheableByShareCachesWhenTestIsActive(): void
    {
        $this->configurePages(['tx_abtestpages_b_percentage' => 100]);

        $response = $this->subject()->process($this->requestForPageA(), new CapturingRequestHandler());

        self::assertStringContainsString('private', $response->getHeaderLine('Cache-Control'));
        self::assertStringContainsString('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertSame('no-cache', $response->getHeaderLine('Pragma'));
        self::assertStringContainsString('Cookie', $response->getHeaderLine('Vary'));
    }

    public function testSecureFlagIsAddedForHttpsRequests(): void
    {
        $this->configurePages(['tx_abtestpages_b_percentage' => 100]);

        $normalizedParams = new class () {
            public function isHttps(): bool
            {
                return true;
            }
        };
        $request = $this->requestForPageA()->withAttribute('normalizedParams', $normalizedParams);

        $response = $this->subject()->process($request, new CapturingRequestHandler());

        self::assertStringContainsString('Secure', $response->getHeaderLine('Set-Cookie'));
    }

    public function testAdditionalHeaderDataOfSelectedVariantIsInjected(): void
    {
        $this->configurePages(
            ['tx_abtestpages_b_percentage' => 100],
            ['tx_abtestpages_header' => '<meta name="variant" content="B">'],
        );

        $this->pageRenderer->expects(self::once())
            ->method('addHeaderData')
            ->with('<meta name="variant" content="B">');

        $this->subject()->process($this->requestForPageA(), new CapturingRequestHandler());
    }
}
