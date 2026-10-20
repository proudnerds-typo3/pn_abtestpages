<?php

declare(strict_types=1);

namespace Proudnerds\ABTestPages\Domain\Repository;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

final readonly class AbTestPageRepository
{
    public function __construct(
        private ConnectionPool $connectionPool,
    ) {}

    /**
     * Returns the default-language uid of the visible page that tests $variantPageId as its "B" page.
     */
    public function findPageIdTestingVariant(int $variantPageId): ?int
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $pageId = $queryBuilder
            ->select('uid')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->eq('tx_abtestpages_b_id', $queryBuilder->createNamedParameter($variantPageId, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('sys_language_uid', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $queryBuilder->expr()->neq('uid', $queryBuilder->createNamedParameter($variantPageId, Connection::PARAM_INT)),
            )
            ->orderBy('uid')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();

        return $pageId === false ? null : (int)$pageId;
    }
}
