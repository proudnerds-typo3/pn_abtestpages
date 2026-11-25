<?php

declare(strict_types=1);

return [
    'frontend' => [
        'proudnerds/pn-abtestpages/select-page' => [
            'target' => \Proudnerds\ABTestPages\Middleware\AbTestPageResolver::class,
            // After the validator: it checks the cHash against the page id, and the cHash in
            // the URL was calculated for page A, so it would 404 every visitor drawn into B.
            'after' => [
                'typo3/cms-frontend/page-argument-validator',
            ],
            'before' => [
                'typo3/cms-frontend/prepare-tsfe-rendering',
            ],
        ],
    ],
];
