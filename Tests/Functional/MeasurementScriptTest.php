<?php

declare(strict_types=1);

namespace Proudnerds\ABTestPages\Tests\Functional;

use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Cache\Backend\Typo3DatabaseBackend;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\TestingFramework\Core\Functional\Framework\Frontend\InternalRequest;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Runs with an enforced, hash-based CSP and a real page cache, because both the CSP hash
 * and the cache split only show on a second request.
 */
final class MeasurementScriptTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = [
        'typo3/cms-seo',
    ];

    protected array $testExtensionsToLoad = [
        'proudnerds/pn-abtestpages',
    ];

    protected array $configurationToUseInTestInstance = [
        'SYS' => [
            'features' => [
                'security.frontend.enforceContentSecurityPolicy' => true,
            ],
            'caching' => [
                'cacheConfigurations' => [
                    'pages' => ['backend' => Typo3DatabaseBackend::class],
                ],
            ],
        ],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/Fixtures/pages.csv');
        $this->setUpFrontendRootPage(1, ['EXT:pn_abtestpages/Tests/Functional/Fixtures/Basic.typoscript']);
        $this->get(SiteWriter::class)->write('test', [
            'rootPageId' => 1,
            'base' => 'https://example.com/',
            'languages' => [
                [
                    'languageId' => 0,
                    'title' => 'English',
                    'locale' => 'en_US.UTF-8',
                    'base' => '/',
                ],
            ],
        ]);
        file_put_contents(
            Environment::getConfigPath() . '/sites/test/csp.yaml',
            "inheritDefault: true\nbehavior:\n  useNonce: false\n  useHash: true\n",
        );
    }

    public function testScriptHashIsInTheCspHeaderOnCacheMissAndHit(): void
    {
        $hashSource = "'sha256-" . base64_encode(hash('sha256', self::scriptFor(2, 'Page A', 'B'), true)) . "'";

        foreach (['miss', 'hit'] as $attempt) {
            $response = $this->request('/page-a');
            self::assertStringContainsString(self::scriptFor(2, 'Page A', 'B'), (string)$response->getBody(), $attempt);
            self::assertStringContainsString($hashSource, $response->getHeaderLine('Content-Security-Policy'), $attempt);
            self::assertStringNotContainsString('nonce-', $response->getHeaderLine('Content-Security-Policy'), $attempt);
        }
    }

    public function testDirectVisitToBDoesNotStripTheScriptFromServedVariants(): void
    {
        self::assertStringNotContainsString('dataLayer', (string)$this->request('/page-b')->getBody());

        self::assertStringContainsString(self::scriptFor(2, 'Page A', 'B'), (string)$this->request('/page-a')->getBody());
    }

    public function testCrawlerVisitDoesNotStripTheScriptFromVariantA(): void
    {
        self::assertStringNotContainsString('dataLayer', (string)$this->request('/tester-of-noindex-variant', 'Googlebot/2.1')->getBody());

        self::assertStringContainsString(
            self::scriptFor(8, 'Tester of no_index variant', 'A'),
            (string)$this->request('/tester-of-noindex-variant')->getBody(),
        );
    }

    private function request(string $path, string $userAgent = 'Mozilla/5.0'): ResponseInterface
    {
        return $this->executeFrontendSubRequest(
            (new InternalRequest('https://example.com' . $path))->withHeader('User-Agent', $userAgent),
        );
    }

    private static function scriptFor(int $testPageId, string $testName, string $variant): string
    {
        return 'window.dataLayer = window.dataLayer || [];window.dataLayer.push('
            . '{"event":"ab_test_view","abTestId":' . $testPageId . ',"abTestName":"' . $testName . '","abTestVariant":"' . $variant . '"});';
    }
}
