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

namespace Code711\SolrTools\Domain\Index;

use ApacheSolrForTypo3\Solr\IndexQueue\Item;

class IndexService extends \ApacheSolrForTypo3\Solr\Domain\Index\IndexService
{
    protected ?\Psr\Log\LoggerInterface $realLogger = null;

    public function setRealLogger(\Psr\Log\LoggerInterface $logger)
    {
        $this->realLogger = $logger;
    }

    protected function generateIndexingErrorLog(Item $itemToIndex, \Throwable $e): void
    {
        $message = 'Failed indexing Index Queue item ' . $itemToIndex->getIndexQueueUid();
        $data = ['code' => $e->getCode(), 'message' => $e->getMessage(), 'trace' => $e->getTraceAsString(), 'item' => (array)$itemToIndex];

        $this->logger->error($message, $data);
        $this->realLogger->error($message, $data);
    }
}
