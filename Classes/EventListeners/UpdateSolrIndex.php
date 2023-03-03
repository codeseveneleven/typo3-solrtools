<?php

namespace Code711\SolrTools\EventListeners;

use Code711\SolrTools\Interfaces\SolrEntityInterface;
use Code711\SolrTools\Services\SolrEntityService;
use TYPO3\CMS\Extbase\DomainObject\DomainObjectInterface;
use TYPO3\CMS\Extbase\Event\Persistence\EntityAddedToPersistenceEvent;

class UpdateSolrIndex extends SolrEntityService
{
    public function __invoke(EntityAddedToPersistenceEvent $event): void
    {

        if ($event->getObject() instanceof SolrEntityInterface && $event->getObject() instanceof DomainObjectInterface) {
            $this->handleObject( $event->getObject());
        }
    }
}
