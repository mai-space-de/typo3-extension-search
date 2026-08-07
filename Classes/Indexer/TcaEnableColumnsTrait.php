<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Indexer;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;

/**
 * Provides helper methods for building queries that respect TCA enable-columns.
 *
 * Use this trait in indexers to ensure queries exclude hidden, deleted, and
 * time-restricted records according to the table's TCA configuration.
 */
trait TcaEnableColumnsTrait
{
    /**
     * Add WHERE constraints for TCA enable-columns (hidden, deleted, starttime, endtime).
     *
     * Reads the table's TCA configuration and adds appropriate constraints to the query builder.
     * This ensures only "enabled" records are indexed.
     */
    protected function addEnableColumnConstraints(QueryBuilder $queryBuilder, string $tableName): void
    {
        $tca = $GLOBALS['TCA'][$tableName] ?? null;
        if ($tca === null) {
            return;
        }

        $ctrl = $tca['ctrl'] ?? [];
        $enableColumns = $ctrl['enablecolumns'] ?? [];

        if (isset($enableColumns['disabled'])) {
            $hiddenField = $enableColumns['disabled'];
            $queryBuilder->andWhere(
                $queryBuilder->expr()->eq(
                    $hiddenField,
                    $queryBuilder->createNamedParameter(0, Connection::PARAM_INT),
                ),
            );
        }

        if (isset($ctrl['delete'])) {
            $deleteField = $ctrl['delete'];
            $queryBuilder->andWhere(
                $queryBuilder->expr()->eq(
                    $deleteField,
                    $queryBuilder->createNamedParameter(0, Connection::PARAM_INT),
                ),
            );
        }

        if (isset($enableColumns['starttime'])) {
            $starttimeField = $enableColumns['starttime'];
            $queryBuilder->andWhere(
                $queryBuilder->expr()->or(
                    $queryBuilder->expr()->eq($starttimeField, 0),
                    $queryBuilder->expr()->lte(
                        $starttimeField,
                        $queryBuilder->createNamedParameter(time(), Connection::PARAM_INT),
                    ),
                ),
            );
        }

        if (isset($enableColumns['endtime'])) {
            $endtimeField = $enableColumns['endtime'];
            $queryBuilder->andWhere(
                $queryBuilder->expr()->or(
                    $queryBuilder->expr()->eq($endtimeField, 0),
                    $queryBuilder->expr()->gt(
                        $endtimeField,
                        $queryBuilder->createNamedParameter(time(), Connection::PARAM_INT),
                    ),
                ),
            );
        }

        if ($tableName === 'pages') {
            $queryBuilder->andWhere(
                $queryBuilder->expr()->eq(
                    'no_search',
                    $queryBuilder->createNamedParameter(0, Connection::PARAM_INT),
                ),
            );
        }
    }

    /**
     * Add language constraint if a specific language is requested.
     */
    protected function addLanguageConstraint(QueryBuilder $queryBuilder, ?int $languageUid): void
    {
        if ($languageUid === null) {
            return;
        }

        $queryBuilder->andWhere(
            $queryBuilder->expr()->eq(
                'sys_language_uid',
                $queryBuilder->createNamedParameter($languageUid, Connection::PARAM_INT),
            ),
        );
    }
}
