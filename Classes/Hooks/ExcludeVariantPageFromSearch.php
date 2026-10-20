<?php

declare(strict_types=1);

namespace Proudnerds\ABTestPages\Hooks;

use TYPO3\CMS\Core\DataHandling\DataHandler;

/**
 * Sets "no_search" on a page as soon as it is chosen as another page's "B" page, so it
 * does not show up as a duplicate of A in the search results.
 *
 * Deliberately not "no_index": the middleware renders B under A's URL, and a robots
 * noindex on B would then be served on A's URL.
 */
final class ExcludeVariantPageFromSearch
{
    public function processDatamap_beforeStart(DataHandler $dataHandler): void
    {
        foreach ($dataHandler->datamap['pages'] ?? [] as $pageId => $fields) {
            if (!isset($fields['tx_abtestpages_b_id'])) {
                continue;
            }
            $variantPageId = $this->parseGroupValue((string)$fields['tx_abtestpages_b_id']);
            if ($variantPageId === null || $variantPageId === (int)$pageId) {
                continue;
            }
            $dataHandler->datamap['pages'][$variantPageId]['no_search'] = 1;
        }
    }

    /**
     * FormEngine submits a group field as "pages_20", other callers as "20".
     */
    private function parseGroupValue(string $value): ?int
    {
        if (preg_match('/^(?:pages_)?(\d+)$/', trim(explode(',', $value)[0]), $matches) !== 1) {
            return null;
        }
        $pageId = (int)$matches[1];
        return $pageId > 0 ? $pageId : null;
    }
}
