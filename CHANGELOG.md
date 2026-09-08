# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

---

## [Unreleased] — 2026-07-10

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
