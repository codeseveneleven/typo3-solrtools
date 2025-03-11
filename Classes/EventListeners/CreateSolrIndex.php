<?php

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

use Code711\SolrTools\Interfaces\SolrEntityInterface;
use Code711\SolrTools\Services\SolrEntityService;
use TYPO3\CMS\Extbase\DomainObject\DomainObjectInterface;
use TYPO3\CMS\Extbase\Event\Persistence\EntityAddedToPersistenceEvent;

class CreateSolrIndex extends SolrEntityService
{
    public function __invoke(EntityAddedToPersistenceEvent $event): void
    {
        if ($event->getObject() instanceof SolrEntityInterface && $event->getObject() instanceof DomainObjectInterface) {
            $this->handleObject($event->getObject());
        }
    }
}
