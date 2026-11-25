# pn_abtestpages

This extension supports TYPO3 administrators in performing A/B tests. This is useful when a site owner wants to measure whether a new version improves or reduces user interaction compared to the current version.

Page properties get a new field "B page" where you can provide the alternative page version. If the page is requested by the user, the extension checks whether there is a B version specified. If this is the case, the version is selected at random. A cookie is set that remembers which version the user got (so there is no flip-flop if the user requests the page repeatedly). Once the cookie expires, the user is back to random at the next request.

Every visitor taking part in a test gets a `dataLayer` event with the variant served, so a web analytics tool can tell the variants apart. No editor-entered code is involved, which keeps the page compatible with a hash-based Content Security Policy.

#### Info:

Requires TYPO3 v14.3+. Implemented as a PSR-15 frontend middleware
(`Proudnerds\ABTestPages\Middleware\AbTestPageResolver`) — no realurl dependency.

#### Measuring results:

The page pushes this event for visitors taking part in a test (not for crawlers, the Solr
indexer, or direct visits to page B):

```javascript
window.dataLayer.push({
  event: 'ab_test_view',
  abTestId: 12,          // uid of page A
  abTestName: 'Home',    // title of page A, in the default language
  abTestVariant: 'B'     // 'A' or 'B'
});
```

In Google Tag Manager, create a custom event trigger on `ab_test_view` and Data Layer
variables for `abTestId` and `abTestVariant`, and send them to GA4 as event parameters.

The script is added through the AssetCollector with the `csp` option, so TYPO3 adds its
sha256 hash to `script-src`. This requires `behavior.useHash: true` in the site's `csp.yaml`;
TYPO3 does not apply the collected hashes when `useHash` is unset.

#### Installation:

```bash
composer require proudnerds/pn-abtestpages
```

Update the database schema afterwards (**Analyze Database Structure** in the Install Tool, or
`typo3 database:updateschema`).

#### Development:

```bash
composer install
.Build/bin/phpunit -c phpunit.xml             # unit tests
.Build/bin/phpunit -c phpunit.functional.xml  # functional tests, SQLite
```

#### License:

GPL-2.0-or-later, see [LICENSE](LICENSE).
