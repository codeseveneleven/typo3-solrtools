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

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\View\BackendViewFactory;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Dashboard\Widgets\RequestAwareWidgetInterface;
use TYPO3\CMS\Dashboard\Widgets\WidgetConfigurationInterface;
use TYPO3\CMS\Dashboard\Widgets\WidgetInterface;

class UnindexedSolrItemsWidget implements WidgetInterface, RequestAwareWidgetInterface
{
    private ServerRequestInterface $request;
    public function setRequest(ServerRequestInterface $request): void
    {
        $this->request = $request;
    }
    public function __construct(
        private readonly WidgetConfigurationInterface $configuration,
        private readonly ConnectionPool $connectionPool,
        private readonly BackendViewFactory $backendViewFactory,
        private readonly array $options = []
    ) {}

    public function renderWidgetContent(): string
    {
        $view = $this->backendViewFactory->create($this->request);
        $view->assignMultiple([
            'configuration' => $this->configuration,
            'items' => $this->getUnindexedItems(),
            'options' => $this->options,
        ]);

        return $view->render('Widget/UnindexedSolrItems');
    }

    private function getUnindexedItems(): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tx_solr_indexqueue_item');

        $result = $queryBuilder
            ->select('indexing_configuration')
            ->addSelectLiteral('COUNT(*) as count')
            ->from('tx_solr_indexqueue_item')
            ->where(
                $queryBuilder->expr()->gt('changed', $queryBuilder->quoteIdentifier('indexed')),
                $queryBuilder->expr()->notLike('errors', $queryBuilder->createNamedParameter('%:%')),
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
