<?php

declare(strict_types=1);

namespace Proudnerds\ABTestPages\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Domain\Repository\PageRepository;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Routing\PageArguments;

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
     * Matches the common search-engine and link-preview crawlers. Deliberately broad
     * (not just Googlebot) — any crawler that got cookied into the "B" variant would
     * index/canonicalize that variant's own URL instead of the requested one.
     */
    private const CRAWLER_USER_AGENT_PATTERN = '/bot|crawler|spider|slurp|facebookexternalhit|ia_archiver/i';

    public function __construct(
        private readonly PageRepository $pageRepository,
        private readonly PageRenderer $pageRenderer,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // Never cloak content for crawlers.
        if (preg_match(self::CRAWLER_USER_AGENT_PATTERN, $request->getHeaderLine('User-Agent')) === 1) {
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
        if ($alternativePage === []) {
            // The configured "B" page is not renderable (deleted/hidden/access-restricted) — skip the test.
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

        $selectedPage = $selectedPageId === $currentPageId ? $currentPage : $alternativePage;
        $additionalHeaderData = (string)($selectedPage['tx_abtestpages_header'] ?? '');
        if ($additionalHeaderData !== '') {
            $this->pageRenderer->addHeaderData($additionalHeaderData);
        }

        $response = $handler->handle($request);

        // This response depends on the visitor's cookie, so it must never be
        // cached by a shared/edge cache (e.g. Varnish) keyed by URL alone —
        // that would serve one random visitor's variant to everyone else.
        // Deliberately overrides the sitepackage's blanket "cache as public
        // for varnish" header for pages that actually run an A/B test.
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
}
