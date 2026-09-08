<?php

declare(strict_types=1);

return [
    'frontend' => [
        'proudnerds/pn-abtestpages/select-page' => [
            'target' => \Proudnerds\ABTestPages\Middleware\AbTestPageResolver::class,
            'after' => [
                'typo3/cms-frontend/page-resolver',
            ],
            'before' => [
                'typo3/cms-frontend/page-argument-validator',
            ],
        ],
    ],
];
