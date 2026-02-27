<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 project.
 *
 * @author Frank Berger <fberger@code711.de>
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

namespace Code711\SolrTools\Widgets;

use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Dashboard\Widgets\WidgetConfigurationInterface;
use TYPO3\CMS\Dashboard\Widgets\WidgetInterface;
use TYPO3\CMS\Fluid\View\StandaloneView;

class UnindexedSolrItemsWidget implements WidgetInterface
{
    public function __construct(
        private readonly WidgetConfigurationInterface $configuration,
        private readonly ConnectionPool $connectionPool,
        private readonly StandaloneView $view,
        private readonly array $options = []
    ) {}

    public function renderWidgetContent(): string
    {
        $this->view->setTemplate('Widget/UnindexedSolrItems');
        $this->view->assignMultiple([
            'configuration' => $this->configuration,
            'items' => $this->getUnindexedItems(),
            'options' => $this->options,
        ]);

        return $this->view->render();
    }

    private function getUnindexedItems(): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tx_solr_indexqueue_item');

        $result = $queryBuilder
            ->select('indexing_configuration')
            ->addSelectLiteral('COUNT(*) as count')
            ->from('tx_solr_indexqueue_item')
            ->where(
                $queryBuilder->expr()->gt('changed', $queryBuilder->quoteIdentifier('indexed'))
            )
            ->groupBy('indexing_configuration')
            ->orderBy('count', 'DESC')
            ->executeQuery();

        $items = [];
        while ($row = $result->fetchAssociative()) {
            $items[] = [
                'type' => $row['indexing_configuration'],
                'count' => (int)$row['count'],
            ];
        }

        return $items;
    }

    public function getOptions(): array
    {
        return $this->options;
    }
}
