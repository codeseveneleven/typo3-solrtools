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

namespace Code711\SolrTools\EventListeners;

use ApacheSolrForTypo3\Solr\Event\Indexing\AfterItemHasBeenIndexedEvent;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Core\Environment;

class AfterItemHasBeenIndexedEventListener
{
    public function __invoke(AfterItemHasBeenIndexedEvent $event)
    {
        if (Environment::isCli() && isset($GLOBALS['CLILOGGER']) && $GLOBALS['CLILOGGER'] instanceof LoggerInterface) {
            $info = sprintf('Indexed %d | %s (%d)', $event->getItem()->getIndexQueueUid(), $event->getItem()->getType(), $event->getItem()->getRecordUid());

            if ($event->getItem()->getHasErrors()) {
                $info .= ' Errors ' . $event->getItem()->getErrors();
            }

            $GLOBALS['CLILOGGER']->info($info);
        }
    }
}
