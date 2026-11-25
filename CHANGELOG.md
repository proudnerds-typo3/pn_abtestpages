# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).
Versions follow the TYPO3 core version the extension targets.

## [Unreleased]

## [14.3.1] – 2026-11-25

### Added
- `ext_tables.sql` — index on `pages.tx_abtestpages_b_id`. `CanonicalUrlEventListener` looks up the page testing the rendered page on every uncached render, which was a full scan of `pages`. Run the database analyzer to add it.

### Fixed
- Visitors drawn into B got a 404 on every URL of A with a cHash (pagination, plugin parameters, route enhancers such as news detail pages). `PageArgumentValidator` checks the cHash against the page id, and the cHash in the URL was calculated for A. The middleware now runs after `typo3/cms-frontend/page-argument-validator` instead of before it.
- B is only served when it is a standard page (`doktype` 1) in the same site as A. A folder as B gave a 404, a shortcut redirected to its target, and a page from another site was rendered with the site configuration of A.
- The test only runs for page type 0. Other page types of A, such as the XML sitemap on a root page or JSON/AJAX types, got the variant swap, a `Set-Cookie` and `no-store`.

## [14.3.0] – 2026-11-25

First release as a separate package. The entries below describe the work done
while the extension lived in the typo3-seed installation.

### Changed
- Unit tests use a stub for `PageRepository` unless a test sets an expectation, so they run without notices on PHPUnit 13.

## Development in typo3-seed — 2026-10-20

### Added
- **Backend manual** via `proudnerds/pn-backend-manual` (now a dependency). `Resources/Private/Manual/AbTestManual.md` explains how to set up, measure and stop a test, and is registered as the dashboard widget `pnAbtestpagesManual` in `Configuration/Services.yaml`. The A/B fields are exclude fields, so the manual is meant for administrators; grant the widget to a group only together with those fields. Update `version` and `date` in the front matter on every change.
- `Classes/Hooks/ExcludeVariantPageFromSearch.php` (registered in the new `ext_localconf.php`) — choosing a page as "B" page sets `no_search = 1` on it, so it no longer shows up in Solr as a duplicate of A. Deliberately not `no_index`: B is rendered under A's URL, and B's robots noindex would then be served on A's URL. The flag is not reset when the test stops; the manual says so.
- `Classes/Domain/Repository/AbTestPageRepository.php` — finds the visible default-language page that tests a given page as its "B" page.
- `Tests/Functional/AbTestPagesTest.php` — frontend sub-requests against a real site for the canonical in all three cases, the variant swap, the no_index guard, and the DataHandler hook. Replaces `Tests/Unit/EventListener/CanonicalUrlEventListenerTest.php`, which could only cover the guards.
- `Classes/Middleware/AbTestPageResolver.php` — visitors taking part in a test get a `dataLayer.push({event: 'ab_test_view', abTestId, abTestName, abTestVariant})`, added through the AssetCollector with the `csp` option so TYPO3 puts its sha256 hash in `script-src`. Test name and id come from page A; the title is JSON-encoded with `JSON_HEX_*`, so a title cannot close the script tag.
- `Classes/EventListener/PageCacheIdentifierEventListener.php` — a page served as part of a test gets its own page cache entry (`BeforePageCacheIdentifierIsHashedEvent`).
- `Tests/Functional/MeasurementScriptTest.php` — runs with an enforced hash-based CSP and a database page cache: the script hash is in the CSP header on both cache miss and hit, there is no nonce, and a crawler or direct visit does not strip the script from served variants.

### Removed
- **Breaking:** the `tx_abtestpages_header` field (TCA, `ext_tables.sql`, labels). It put editor-entered HTML unchecked into `<head>`: an inline script there has no nonce or hash, so a CSP blocks it, and any editor with access to the field could add arbitrary scripts. Replaced by the automatic `dataLayer` event above. The database analyzer offers to drop the column. It was empty in the seed; check an installation for filled values before dropping it there.
- `README.md` — the Universal Analytics `ga()` example (UA was shut down in 2023) and the screenshot hot-linked from illusion-factory.de, which still showed the removed field.

### Fixed
- `Classes/Middleware/AbTestPageResolver.php` — EXT:solr indexes through an in-process frontend request without User-Agent or cookies, so the Solr document of page A got B's content at random. Requests carrying the `solr.indexingInstructions` attribute now always get page A.
- `Classes/EventListener/CanonicalUrlEventListener.php` — page B opened on its own URL now also gets a canonical pointing at A; before, it was an indexable duplicate of A. The listener no longer adds a canonical to a page with `no_index` or `disableCanonical`.
- The page cache entry of page B was shared between visitors served B under A's URL and direct visits to B. Whichever came first after a cache flush won: after one direct visit, every visitor served variant B got B without its measurement code. The same held for page A and crawler visits. Fixed by the cache identifier listener above.
- Visitors of a translated page A were sent to an untranslated page B and got a 404, for every visitor drawn into B when the language has no fallback. The middleware now checks B with `PageRepository::isPageSuitableForLanguage()`, the same check the core uses for links and menus. The context's language aspect is only set after this middleware (by `PageInformationFactory`), so the language comes from the request's `language` attribute.
- `Configuration/TCA/Overrides/pages.php` — `tx_abtestpages_cookie_time` had no TCA `default`, so FormEngine started new pages on the first option, 1 month, while SQL, README and manual said 1 day. Now `'default' => 86400`.
- Crawler detection no longer treats every "bot" substring as a crawler: phone models such as "CUBOT_X30" or "Cubot P50" always got A and no measurement script. "bot" now has to be followed by a separator, `/`, a version or the end; a `+http://` info URL also counts.

### Changed
- `Configuration/TCA/Overrides/pages.php` — the three test fields are `l10n_mode => 'exclude'`. The middleware always uses the default-language settings, so in a translation the fields were shown but had no effect.
- Labels: "Site B" is "B page", "AB Test Settings" is "A/B test settings", Dutch "Cookie levensduur" is "Cookielevensduur". The option key `cookie_12_day` ("1/2 day") is `cookie_12_hours` ("12 hours"); the stored value, 43200, is unchanged.
- `README.md` — typos ("wheter", "want", "by random").

## Development in typo3-seed — 2026-07-10

> **Current state, corrected 2026-10-20.** Some names below have changed since: the package is `proudnerds/pn-abtestpages` and the middleware `proudnerds/pn-abtestpages/select-page`; there is no `ext_emconf.php` (composer mode only); the PHPUnit configuration is the root `phpunit.xml` (unit) and `phpunit.functional.xml`, run through `ddev ci`.

TYPO3 14.3 upgrade. This extension had been dormant since 2017 (TYPO3 7.6–8.7, alpha quality) and was taken over and rewritten to run on TYPO3 v14.3 / PHP 8.4. The original mechanism relied on a TYPO3 hook (`checkAlternativeIdMethods-PostProc`) removed years ago, and on the long-dead `realurl` extension to resolve the root page ID — both are gone. TYPO3's core routing now resolves the real, final page ID before any custom middleware runs, so the realurl-based workaround was obsolete and dropped rather than ported.

### Added
- `Classes/Middleware/AbTestPageResolver.php` — new PSR-15 middleware replacing the removed `checkAlternativeIdMethods-PostProc` hook. Runs after TYPO3's own `page-resolver` middleware and before `page-argument-validator`; swaps the request's `routing` attribute to the "B" page's `PageArguments` when applicable, reads/sets the `abtestpages-<id>` selection cookie via PSR-7, and injects the selected page's `tx_abtestpages_header` markup via the singleton `PageRenderer`. Validates the cookie value against the two legitimate page IDs (rejecting tampered values) and verifies the "B" page is actually renderable (not deleted/hidden/access-restricted) before swapping — falls back to the original page otherwise.
- `Configuration/RequestMiddlewares.php` — registers `AbTestPageResolver` in the `frontend` middleware stack as `proudnerds/abtestpages/select-page`.
- `Configuration/Services.yaml` — registers the `Proudnerds\ABTestPages\` namespace for DI autowiring (required for the middleware's constructor-injected `PageRepository`/`PageRenderer`; without it TYPO3 throws `ArgumentCountError` on every frontend request — caught via a live functional test against this project's ddev instance).

### Changed
- `composer.json` — package renamed `proudnerds/abtestpages` (was `illusionfactory/abtestpages`), namespace `Proudnerds\ABTestPages\` (was `IllusionFACTORY\ABTestPages\`), requires `typo3/cms-core`/`typo3/cms-frontend` `^14.3`.
- `ext_emconf.php` — maintainer updated (IllusionFACTORY, maintained by Proudnerds), version bumped to `2.0.0`, state `stable`, TYPO3 constraint `14.3.0-14.99.99`, `realurl` dependency removed.
- `Configuration/TCA/Overrides/pages.php` — modernized to current TYPO3 14 TCA syntax: `group` field drops the obsolete `internal_type` key, `select` items use associative `['label' => ..., 'value' => ...]` pairs, boolean `exclude`, `declare(strict_types=1)`. The three `pages` columns (`tx_abtestpages_b_id`, `tx_abtestpages_cookie_time`, `tx_abtestpages_header`) and the `tx_abtestpages` palette are unchanged, so no database migration is needed.
- `README.md` — "Info" section now states the TYPO3 14.3+ requirement and the middleware-based implementation instead of the realurl dependency.
- Root `composer.json` (project-level, not this package) — added `"proudnerds/abtestpages": "@dev"` to `require`. The extension had never been active in this project before (not even under its old name), so this was required to functionally test it; activating it adds the `tx_abtestpages` palette and its three columns to every page's edit form site-wide.

### Removed
- `ext_localconf.php` — only contained the `SC_OPTIONS` registration for the hook removed by TYPO3 core years ago.
- `ext_tables.php` — only contained a commented-out static TypoScript include.
- `Classes/ShowPage.php` — legacy hook class, replaced by `Classes/Middleware/AbTestPageResolver.php`.
- `realurl` dependency (from `composer.json` and `ext_emconf.php` constraints) — obsolete; TYPO3's core routing already resolves the real page ID by the time this extension's middleware runs.

### Added (follow-up review)
- `Classes/Middleware/AbTestPageResolver.php` — the response now carries `Cache-Control: private, no-cache, no-store, must-revalidate`, `Pragma: no-cache` and `Vary: Cookie` whenever a page actually runs an A/B test. This project's `base_sitepackage` sends a blanket `Pragma: public` "for varnish" on every page (`Configuration/TypoScript/Page/Config.typoscript`); without this override, a shared/edge cache in front of the site (Varnish, a CDN) would cache the first visitor's randomly-selected variant by URL and serve it to every subsequent visitor regardless of their own cookie, silently breaking the split. Confirmed live: the `pragma: public` header is present on non-test pages and correctly replaced on A/B-tested ones. TYPO3's own internal page cache (keyed per resolved page ID) is unaffected.
- `Configuration/TCA/Overrides/pages.php`, `ext_tables.sql` — new `tx_abtestpages_b_percentage` field (1–99, default 50) lets editors weight the split instead of a fixed 50/50 `random_int(0, 1)`.
- Crawler detection broadened from a Googlebot-only `stripos()` check to a regex covering the common search/social crawlers (`CRAWLER_USER_AGENT_PATTERN`). A crawler that got cookied into the "B" variant would index/canonicalize that variant's own URL instead of the one actually linked.
- `Classes/EventListener/CanonicalUrlEventListener.php` — new PSR-14 listener on EXT:seo's `ModifyUrlForCanonicalTagEvent`. Because the middleware swaps the `routing` page ID but never the request URI, TYPO3's canonical-tag generator would otherwise point `<link rel="canonical">` at page B's own URL whenever B was served under A's URL — confusing crawlers about which URL is the indexable one. The middleware exposes the originally-requested page ID as the `abtestpages.originalPageId` request attribute; the listener rebuilds the canonical URL for that page whenever the rendered page differs from it. Verified live: requesting the site root with a B-page configured renders B's content with `<link rel="canonical" href="https://.../">` still pointing at the root, not at B's own URL. Requires `typo3/cms-seo`, now declared in `composer.json`.
- Cookie now sets `HttpOnly` (it is only ever read server-side) and `Secure` when the request is HTTPS (via `normalizedParams`, respecting the project's reverse-proxy config).
- `Tests/Unit/` — first test coverage for this extension: `AbTestPageResolverTest` (crawler bypass, missing/unrenderable B page, cookie honoring and tampering, 0%/100% split determinism, cache headers, cookie flags, header injection) and `CanonicalUrlEventListenerTest` (guard branches only — the URL-rebuild branch needs a live TypoScript/site context and was instead verified manually, see above). Runs via `vendor/bin/phpunit --testsuite Unit`. This is the first PHPUnit setup in the whole project: added `phpunit/phpunit` to the root `composer.json` `require-dev`, a root `autoload-dev` PSR-4 mapping for `Proudnerds\ABTestPages\Tests\`, and a root `phpunit.xml.dist` picking up `packages/*/Tests/Unit` — worth reusing for other extensions' future test suites.
- `phpstan.neon` — registered the custom `abtestpages.originalPageId` request attribute under `parameters.typo3.requestGetAttributeMapping` so `saschaegerer/phpstan-typo3` can type it.

### Not addressed
- No configurable exclusion list for the crawler pattern (it's a hardcoded regex) and no admin UI showing current split counts — flagged as possible nice-to-haves, not implemented.

[Unreleased]: https://github.com/proudnerds-typo3/pn_abtestpages/compare/14.3.0...HEAD
[14.3.0]: https://github.com/proudnerds-typo3/pn_abtestpages/releases/tag/14.3.0
