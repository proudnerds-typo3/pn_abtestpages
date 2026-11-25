<?php

declare(strict_types=1);

namespace Proudnerds\ABTestPages\Tests\Unit\Middleware;

use GuzzleHttp\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Proudnerds\ABTestPages\Middleware\AbTestPageResolver;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Domain\Repository\PageRepository;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Page\AssetCollector;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Routing\PageArguments;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\CMS\Core\Site\SiteFinder;

final class AbTestPageResolverTest extends TestCase
{
    private const PAGE_A = 10;
    private const PAGE_B = 20;

    private PageRepository&Stub $pageRepository;
    private AssetCollector $assetCollector;
    private SiteFinder&Stub $siteFinder;

    protected function setUp(): void
    {
        $this->pageRepository = $this->createStub(PageRepository::class);
        $this->assetCollector = new AssetCollector();
        $this->siteFinder = $this->createStub(SiteFinder::class);
        $this->siteFinder->method('getSiteByPageId')->willReturn($this->site('main'));
    }

    private function site(string $identifier): Site&Stub
    {
        $site = $this->createStub(Site::class);
        $site->method('getIdentifier')->willReturn($identifier);
        return $site;
    }

    private function expectNoPageLookup(): void
    {
        $pageRepository = $this->createMock(PageRepository::class);
        $pageRepository->expects(self::never())->method('getPage');
        $this->pageRepository = $pageRepository;
    }

    /**
     * @param array<string, mixed> $pageAOverrides
     * @param array<string, mixed> $pageBOverrides
     */
    private function configurePages(array $pageAOverrides = [], array $pageBOverrides = []): void
    {
        $pageA = array_merge(['uid' => self::PAGE_A, 'tx_abtestpages_b_id' => self::PAGE_B], $pageAOverrides);
        $pageB = array_merge(['uid' => self::PAGE_B, 'doktype' => PageRepository::DOKTYPE_DEFAULT, 'tx_abtestpages_b_id' => 0], $pageBOverrides);

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
        return new AbTestPageResolver($this->pageRepository, $this->assetCollector, $this->siteFinder);
    }

    private static function selectedPageId(CapturingRequestHandler $handler): int
    {
        $routing = $handler->lastRequest?->getAttribute('routing');
        self::assertInstanceOf(PageArguments::class, $routing);
        return $routing->getPageId();
    }

    /**
     * @param array<string, string> $cookies
     */
    private function requestForPageA(array $cookies = [], string $userAgent = 'Mozilla/5.0', string $pageType = '0'): ServerRequestInterface
    {
        return (new ServerRequest('GET', 'https://example.com/page-a'))
            ->withAttribute('routing', new PageArguments(self::PAGE_A, $pageType, []))
            ->withAttribute('site', $this->site('main'))
            ->withHeader('User-Agent', $userAgent)
            ->withCookieParams($cookies);
    }

    public function testCrawlerRequestBypassesSplitEntirely(): void
    {
        $this->expectNoPageLookup();

        $request = $this->requestForPageA(userAgent: 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)');
        $handler = new CapturingRequestHandler();
        $response = $this->subject()->process($request, $handler);

        self::assertSame(self::PAGE_A, self::selectedPageId($handler));
        self::assertFalse($response->hasHeader('Set-Cookie'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function crawlerUserAgentProvider(): array
    {
        return [
            'Bing' => ['Mozilla/5.0 (compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm)'],
            'DuckDuckGo' => ['DuckDuckBot/1.1; (+http://duckduckgo.com/duckduckbot.html)'],
            'Twitter' => ['Twitterbot/1.0'],
            'Facebook' => ['facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)'],
            'Slack' => ['Slackbot-LinkExpanding 1.0 (+https://api.slack.com/robots)'],
            'Slack without info URL' => ['Slackbot 1.0'],
            'Baidu' => ['Mozilla/5.0 (compatible; Baiduspider/2.0; +http://www.baidu.com/search/spider.html)'],
            'Applebot' => ['Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_5) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/13.1.1 Safari/605.1.15 (Applebot/0.1; +http://www.apple.com/go/applebot)'],
        ];
    }

    #[DataProvider('crawlerUserAgentProvider')]
    public function testCrawlerUserAgentsAreExcluded(string $userAgent): void
    {
        $this->expectNoPageLookup();

        $handler = new CapturingRequestHandler();
        $this->subject()->process($this->requestForPageA(userAgent: $userAgent), $handler);

        self::assertSame(self::PAGE_A, self::selectedPageId($handler));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function visitorUserAgentProvider(): array
    {
        return [
            'Cubot phone, underscore' => ['Mozilla/5.0 (Linux; Android 10; CUBOT_X30) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Mobile Safari/537.36'],
            'Cubot phone, space' => ['Mozilla/5.0 (Linux; Android 12; Cubot P50) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Mobile Safari/537.36'],
            'desktop Chrome' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'],
        ];
    }

    #[DataProvider('visitorUserAgentProvider')]
    public function testVisitorUserAgentsTakePartInTheTest(string $userAgent): void
    {
        $this->configurePages(['tx_abtestpages_b_percentage' => 100]);

        $handler = new CapturingRequestHandler();
        $this->subject()->process($this->requestForPageA(userAgent: $userAgent), $handler);

        self::assertSame(self::PAGE_B, self::selectedPageId($handler));
    }

    public function testAlternativePageUnavailableInTheVisitorsLanguageSkipsTheTest(): void
    {
        $this->configurePages(['tx_abtestpages_b_percentage' => 100]);
        $this->pageRepository->method('getPageOverlay')->willReturnArgument(0);
        $this->pageRepository->method('isPageSuitableForLanguage')->willReturn(false);
        $german = new SiteLanguage(1, 'de_DE.UTF-8', new Uri('https://example.com/de/'), ['fallbackType' => 'strict']);

        $handler = new CapturingRequestHandler();
        $response = $this->subject()->process($this->requestForPageA()->withAttribute('language', $german), $handler);

        self::assertSame(self::PAGE_A, self::selectedPageId($handler));
        self::assertFalse($response->hasHeader('Set-Cookie'));
    }

    public function testSolrIndexingRequestAlwaysGetsPageA(): void
    {
        $this->expectNoPageLookup();

        $request = $this->requestForPageA(userAgent: '')
            ->withAttribute('solr.indexingInstructions', new \stdClass());
        $handler = new CapturingRequestHandler();
        $response = $this->subject()->process($request, $handler);

        self::assertSame(self::PAGE_A, self::selectedPageId($handler));
        self::assertFalse($response->hasHeader('Set-Cookie'));
    }

    public function testPageWithoutBVariantPassesThrough(): void
    {
        $this->pageRepository->method('getPage')->willReturnCallback(
            static fn(int $uid): array => $uid === self::PAGE_A ? ['uid' => self::PAGE_A, 'tx_abtestpages_b_id' => 0] : [],
        );

        $handler = new CapturingRequestHandler();
        $response = $this->subject()->process($this->requestForPageA(), $handler);

        self::assertSame(self::PAGE_A, self::selectedPageId($handler));
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

        self::assertSame(self::PAGE_A, self::selectedPageId($handler));
        self::assertFalse($response->hasHeader('Set-Cookie'));
    }

    public function testExistingCookieIsHonoredWithoutSettingItAgain(): void
    {
        $this->configurePages(['tx_abtestpages_cookie_time' => 86400]);

        $request = $this->requestForPageA(cookies: ['abtestpages-' . self::PAGE_A => (string)self::PAGE_B]);
        $handler = new CapturingRequestHandler();
        $response = $this->subject()->process($request, $handler);

        self::assertSame(self::PAGE_B, self::selectedPageId($handler));
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
        self::assertSame(self::PAGE_A, self::selectedPageId($handler));
        self::assertTrue($response->hasHeader('Set-Cookie'));
    }

    public function testZeroPercentSplitAlwaysSelectsPageAAndSetsCookie(): void
    {
        $this->configurePages(['tx_abtestpages_b_percentage' => 0, 'tx_abtestpages_cookie_time' => 3600]);

        $handler = new CapturingRequestHandler();
        $response = $this->subject()->process($this->requestForPageA(), $handler);

        self::assertSame(self::PAGE_A, self::selectedPageId($handler));
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

        self::assertSame(self::PAGE_B, self::selectedPageId($handler));
        self::assertStringContainsString('abtestpages-' . self::PAGE_A . '=' . self::PAGE_B, $response->getHeaderLine('Set-Cookie'));
    }

    public function testOriginalPageIdIsExposedForCanonicalUrlCorrection(): void
    {
        $this->configurePages(['tx_abtestpages_b_percentage' => 100]);

        $handler = new CapturingRequestHandler();
        $this->subject()->process($this->requestForPageA(), $handler);

        self::assertSame(self::PAGE_A, $handler->lastRequest?->getAttribute('abtestpages.originalPageId'));
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

    public function testMeasurementScriptReportsTheServedVariantAndIsHashedForCsp(): void
    {
        $this->configurePages(['tx_abtestpages_b_percentage' => 100, 'title' => 'Home </script>']);

        $handler = new CapturingRequestHandler();
        $this->subject()->process($this->requestForPageA(), $handler);

        $script = $this->assetCollector->getInlineJavaScripts()['pn-abtestpages'] ?? [];
        self::assertSame(['csp' => true], $script['options'] ?? null);
        self::assertSame(
            'window.dataLayer = window.dataLayer || [];window.dataLayer.push('
            . '{"event":"ab_test_view","abTestId":10,"abTestName":"Home \\u003C\\/script\\u003E","abTestVariant":"B"});',
            $script['source'] ?? null,
        );
        self::assertSame('B', $handler->lastRequest?->getAttribute('abtestpages.variant'));
    }

    public function testCrawlerGetsNoMeasurementScript(): void
    {
        $this->configurePages();

        $this->subject()->process($this->requestForPageA(userAgent: 'Googlebot/2.1'), new CapturingRequestHandler());

        self::assertSame([], $this->assetCollector->getInlineJavaScripts());
    }

    public function testOtherPageTypesAreNotPartOfTheTest(): void
    {
        $this->expectNoPageLookup();

        $handler = new CapturingRequestHandler();
        $response = $this->subject()->process($this->requestForPageA(pageType: '1533906435'), $handler);

        self::assertSame(self::PAGE_A, self::selectedPageId($handler));
        self::assertFalse($response->hasHeader('Set-Cookie'));
        self::assertSame([], $this->assetCollector->getInlineJavaScripts());
    }

    /**
     * @return array<string, array{int}>
     */
    public static function nonStandardDoktypeProvider(): array
    {
        return [
            'shortcut' => [PageRepository::DOKTYPE_SHORTCUT],
            'external link' => [PageRepository::DOKTYPE_LINK],
            'folder' => [PageRepository::DOKTYPE_SYSFOLDER],
        ];
    }

    #[DataProvider('nonStandardDoktypeProvider')]
    public function testAlternativePageThatIsNotAStandardPageSkipsTheTest(int $doktype): void
    {
        $this->configurePages(['tx_abtestpages_b_percentage' => 100], ['doktype' => $doktype]);

        $handler = new CapturingRequestHandler();
        $response = $this->subject()->process($this->requestForPageA(), $handler);

        self::assertSame(self::PAGE_A, self::selectedPageId($handler));
        self::assertFalse($response->hasHeader('Set-Cookie'));
    }

    public function testAlternativePageInAnotherSiteSkipsTheTest(): void
    {
        $this->configurePages(['tx_abtestpages_b_percentage' => 100]);
        $this->siteFinder = $this->createStub(SiteFinder::class);
        $this->siteFinder->method('getSiteByPageId')->willReturn($this->site('other'));

        $handler = new CapturingRequestHandler();
        $response = $this->subject()->process($this->requestForPageA(), $handler);

        self::assertSame(self::PAGE_A, self::selectedPageId($handler));
        self::assertFalse($response->hasHeader('Set-Cookie'));
    }

    public function testAlternativePageOutsideAnySiteSkipsTheTest(): void
    {
        $this->configurePages(['tx_abtestpages_b_percentage' => 100]);
        $this->siteFinder = $this->createStub(SiteFinder::class);
        $this->siteFinder->method('getSiteByPageId')->willThrowException(new SiteNotFoundException('none', 1));

        $handler = new CapturingRequestHandler();
        $this->subject()->process($this->requestForPageA(), $handler);

        self::assertSame(self::PAGE_A, self::selectedPageId($handler));
    }
}
