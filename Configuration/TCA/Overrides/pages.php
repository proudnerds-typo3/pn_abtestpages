<?php

declare(strict_types=1);

defined('TYPO3') or die();

(static function (): void {
    \TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addTCAcolumns('pages', [
        'tx_abtestpages_b_id' => [
            'exclude' => true,
            'l10n_mode' => 'exclude',
            'label' => 'LLL:EXT:pn_abtestpages/Resources/Private/Language/locallang_db.xlf:pages.tx_abtestpages_b_id',
            'config' => [
                'type' => 'group',
                'allowed' => 'pages',
                'maxitems' => 1,
                'size' => 1,
            ],
        ],
        'tx_abtestpages_b_percentage' => [
            'exclude' => true,
            'l10n_mode' => 'exclude',
            'label' => 'LLL:EXT:pn_abtestpages/Resources/Private/Language/locallang_db.xlf:pages.tx_abtestpages_b_percentage',
            'config' => [
                'type' => 'number',
                'default' => 50,
                'range' => [
                    'lower' => 1,
                    'upper' => 99,
                ],
            ],
        ],
        'tx_abtestpages_cookie_time' => [
            'exclude' => true,
            'l10n_mode' => 'exclude',
            'label' => 'LLL:EXT:pn_abtestpages/Resources/Private/Language/locallang_db.xlf:pages.tx_abtestpages_cookie_time',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'default' => 86400,
                'items' => [
                    ['label' => 'LLL:EXT:pn_abtestpages/Resources/Private/Language/locallang_db.xlf:cookie_1_month', 'value' => 2419200],
                    ['label' => 'LLL:EXT:pn_abtestpages/Resources/Private/Language/locallang_db.xlf:cookie_1_week', 'value' => 604800],
                    ['label' => 'LLL:EXT:pn_abtestpages/Resources/Private/Language/locallang_db.xlf:cookie_1_day', 'value' => 86400],
                    ['label' => 'LLL:EXT:pn_abtestpages/Resources/Private/Language/locallang_db.xlf:cookie_12_hours', 'value' => 43200],
                    ['label' => 'LLL:EXT:pn_abtestpages/Resources/Private/Language/locallang_db.xlf:cookie_1_hour', 'value' => 3600],
                    ['label' => 'LLL:EXT:pn_abtestpages/Resources/Private/Language/locallang_db.xlf:cookie_1_minute', 'value' => 60],
                ],
            ],
        ],
    ]);

    \TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addToAllTCAtypes(
        'pages',
        '--palette--;LLL:EXT:pn_abtestpages/Resources/Private/Language/locallang_db.xlf:palette_title;tx_abtestpages',
        '',
        'after:subtitle',
    );

    $GLOBALS['TCA']['pages']['palettes']['tx_abtestpages'] = [
        'showitem' => 'tx_abtestpages_b_id,tx_abtestpages_b_percentage,--linebreak--,tx_abtestpages_cookie_time',
    ];
})();
