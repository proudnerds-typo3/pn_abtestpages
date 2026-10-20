<?php

declare(strict_types=1);

namespace Proudnerds\ABTestPages\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Context\LanguageAspectFactory;
use TYPO3\CMS\Core\Domain\Repository\PageRepository;
use TYPO3\CMS\Core\Page\AssetCollector;
use TYPO3\CMS\Core\Routing\PageArguments;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;

/**
 * Randomly serves either the requested page ("A") or its configured
 * alternative ("B") for A/B testing, remembering the choice in a cookie.
 *
 * Runs after TYPO3's own page-resolver middleware, so `$request`'s
 * "routing" attribute already carries the real, final page ID for this
 * request (no realurl/rootpage lookup is needed any more).
 */
final class AbTestPageResolver implements MiddlewareInterface
{
    /**
     * Matches the common search-engine and link-preview crawlers: a crawler cookied into
     * the "B" variant would index that variant. "bot" only counts when followed by a
     * version or separator, so phone models like "CUBOT_X30" or "Cubot P50" do not match.
     */
    private const CRAWLER_USER_AGENT_PATTERN = '#bot(?:[/\\-;)]|\\s\\d|$)|\\+https?://|crawler|spider|slurp|facebookexternalhit|ia_archiver#i';

    public function __construct(
        private readonly PageRepository $pageRepository,
        private readonly AssetCollector $assetCollector,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // Never cloak content for crawlers.
        if (preg_match(self::CRAWLER_USER_AGENT_PATTERN, $request->getHeaderLine('User-Agent')) === 1) {
            return $handler->handle($request);
        }

        // EXT:solr indexes through an in-process frontend request without User-Agent or
        // cookies, so without this the document of page A would get B's content at random.
        if ($request->getAttribute('solr.indexingInstructions') !== null) {
            return $handler->handle($request);
        }

        $routing = $request->getAttribute('routing');
        if (!$routing instanceof PageArguments) {
            return $handler->handle($request);
        }

        $currentPageId = $routing->getPageId();
        $currentPage = $this->pageRepository->getPage($currentPageId);
        $alternativePageId = (int)($currentPage['tx_abtestpages_b_id'] ?? 0);

        if ($alternativePageId <= 0) {
            return $handler->handle($request);
        }

        $alternativePage = $this->pageRepository->getPage($alternativePageId);
        // B is deleted, hidden, access-restricted, or not available in the visitor's language
        // (which would otherwise 404 for every visitor drawn into B).
        if ($alternativePage === [] || !$this->isAvailableInRequestLanguage($alternativePage, $request)) {
            return $handler->handle($request);
        }

        // The requested page is the "true" canonical page for this request, even
        // when its "B" variant ends up being rendered below — read by
        // EventListener/CanonicalUrlEventListener.php to correct the SEO canonical tag.
        $request = $request->withAttribute('abtestpages.originalPageId', $currentPageId);

        $cookieName = 'abtestpages-' . $currentPageId;
        $cookieValue = $request->getCookieParams()[$cookieName] ?? null;
        $cookieLifetime = (int)($currentPage['tx_abtestpages_cookie_time'] ?? 86400);
        $bPercentage = max(0, min(100, (int)($currentPage['tx_abtestpages_b_percentage'] ?? 50)));
        $setCookie = false;

        if ($cookieValue !== null && in_array((int)$cookieValue, [$currentPageId, $alternativePageId], true)) {
            $selectedPageId = (int)$cookieValue;
        } else {
            $selectedPageId = random_int(1, 100) <= $bPercentage ? $alternativePageId : $currentPageId;
            $setCookie = true;
        }

        if ($selectedPageId !== $currentPageId) {
            $routing = new PageArguments(
                $selectedPageId,
                $routing->getPageType(),
                $routing->getRouteArguments(),
                $routing->getStaticArguments(),
                $routing->getDynamicArguments(),
            );
            $request = $request->withAttribute('routing', $routing);
        }

        $variant = $selectedPageId === $currentPageId ? 'A' : 'B';
        // Read by EventListener/PageCacheIdentifierEventListener.php: without it, the page cache
        // entry of B is shared with direct visits to B, which carry no measurement script.
        $request = $request->withAttribute('abtestpages.variant', $variant);
        $this->assetCollector->addInlineJavaScript(
            'pn-abtestpages',
            $this->measurementScript($currentPageId, (string)($currentPage['title'] ?? ''), $variant),
            [],
            ['csp' => true],
        );

        $response = $handler->handle($request);

        // This response depends on the visitor's cookie, so a shared cache (Varnish, CDN)
        // keyed by URL alone would serve one visitor's variant to everyone. Overrides the
        // public headers that `config.sendCacheHeaders` sends for cacheable pages.
        $response = $response
            ->withHeader('Cache-Control', 'private, no-cache, no-store, must-revalidate')
            ->withHeader('Pragma', 'no-cache')
            ->withAddedHeader('Vary', 'Cookie');

        if ($setCookie) {
            $isHttps = $request->getAttribute('normalizedParams')?->isHttps() ?? false;
            $response = $response->withAddedHeader(
                'Set-Cookie',
                sprintf(
                    '%s=%d; Max-Age=%d; Path=/; SameSite=Lax; HttpOnly%s',
                    $cookieName,
                    $selectedPageId,
                    $cookieLifetime,
                    $isHttps ? '; Secure' : '',
                ),
            );
        }

        return $response;
    }

    /**
     * The context's language aspect is only set after this middleware, by PageInformationFactory,
     * so the language is taken from the request attribute the site resolver sets.
     *
     * @param array<string, mixed> $page
     */
    private function isAvailableInRequestLanguage(array $page, ServerRequestInterface $request): bool
    {
        $siteLanguage = $request->getAttribute('language');
        if (!$siteLanguage instanceof SiteLanguage || $siteLanguage->getLanguageId() === 0) {
            return true;
        }
        $languageAspect = LanguageAspectFactory::createFromSiteLanguage($siteLanguage);
        return $this->pageRepository->isPageSuitableForLanguage(
            $this->pageRepository->getPageOverlay($page, $languageAspect),
            $languageAspect,
        );
    }

    /**
     * Inline on purpose: added through the AssetCollector with the "csp" option, TYPO3 adds
     * its sha256 hash to script-src, which keeps the page cacheable (a nonce would not).
     */
    private function measurementScript(int $testPageId, string $testName, string $variant): string
    {
        $data = json_encode(
            [
                'event' => 'ab_test_view',
                'abTestId' => $testPageId,
                'abTestName' => $testName,
                'abTestVariant' => $variant,
            ],
            JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE,
        );

        return 'window.dataLayer = window.dataLayer || [];window.dataLayer.push(' . $data . ');';
    }
}
