<?php

namespace Code711\SolrTools\Services;

use ApacheSolrForTypo3\Solr\Domain\Index\Queue\UpdateHandler\Events\RecordUpdatedEvent;
use ApacheSolrForTypo3\Solr\System\Configuration\ExtensionConfiguration;
use Doctrine\DBAL\Exception;
use Doctrine\DBAL\Result;
use Psr\EventDispatcher\EventDispatcherInterface;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Exception\Page\PageNotFoundException;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\RootlineUtility;
use TYPO3\CMS\Extbase\DomainObject\DomainObjectInterface;
use TYPO3\CMS\Extbase\Persistence\Generic\Mapper\DataMapper;

class SolrEntityService {
    /**
     * @throws Exception
     */
    public function handleObject(DomainObjectInterface $object)
    {
        // get the table name
        $tableName = GeneralUtility::makeInstance(DataMapper::class)->convertClassNameToTableName($object::class);

        if ($this->skipMonitoringOfTable( $tableName) || $this->skipRecordByRootlineConfiguration( $object->getPid())) {
            return;
        }

        /** @var Connection $query */
        $query = GeneralUtility::makeInstance( ConnectionPool::class )->getConnectionForTable( $tableName );
        /** @var Result $res */
        $fields = $query->select( [ '*' ], $tableName,
            ['uid'=>$object->getUid()]
        )->fetchAssociative();

        $eventDispatcher =  GeneralUtility::makeInstance(EventDispatcherInterface::class);

        $eventDispatcher->dispatch(
            new RecordUpdatedEvent((int)$object->getUid(), $tableName, $fields)
        );
    }
    protected function skipMonitoringOfTable(string $table): bool
    {
        static $configurationMonitorTables;

        if (empty($configurationMonitorTables)) {
            $configuration = GeneralUtility::makeInstance(ExtensionConfiguration::class);
            $configurationMonitorTables = $configuration->getIsUseConfigurationMonitorTables();
        }

        // No explicit configuration => all tables should be monitored
        if (empty($configurationMonitorTables)) {
            return false;
        }

        return !in_array($table, $configurationMonitorTables);
    }

    /**
     * Check if at least one page in the record's rootline is configured to exclude sub-entries from indexing
     *
     * @return bool
     */
    protected function skipRecordByRootlineConfiguration(int $pid): bool
    {
        /** @var RootlineUtility $rootlineUtility */
        $rootlineUtility = GeneralUtility::makeInstance(RootlineUtility::class, $pid);
        try {
            $rootline = $rootlineUtility->get();
        } catch (PageNotFoundException) {
            return true;
        }
        foreach ($rootline as $page) {
            if (isset($page['no_search_sub_entries']) && $page['no_search_sub_entries']) {
                return true;
            }
        }
        return false;
    }
}
