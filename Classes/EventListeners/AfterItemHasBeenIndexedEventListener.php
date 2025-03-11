<?php
declare( strict_types=1 );


namespace Code711\SolrTools\EventListeners;

use ApacheSolrForTypo3\Solr\Event\Indexing\AfterItemHasBeenIndexedEvent;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Core\Environment;

class AfterItemHasBeenIndexedEventListener {

    public function __invoke(AfterItemHasBeenIndexedEvent $event) {
        if (Environment::isCli() && isset($GLOBALS['CLILOGGER']) && $GLOBALS['CLILOGGER'] instanceof LoggerInterface)  {

            $GLOBALS['CLILOGGER']->info('Indexed '.$event->getItem()->getRecordUid().' pid '.$event->getItem()->getRecordPageId().' Errors: '.$event->getItem()->getErrors());

        }
    }
}
