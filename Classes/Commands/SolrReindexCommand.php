<?php

/*
 * This file is part of the TYPO3 project.
 * (c) 2022 B-Factor GmbH
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 * The TYPO3 project - inspiring people to share!
 *
 */

namespace SUDHAUS7\SolrTools\Commands;

use ApacheSolrForTypo3\Solr\ConnectionManager;
use ApacheSolrForTypo3\Solr\Domain\Site\Site;
use ApacheSolrForTypo3\Solr\Domain\Site\SiteRepository;
use ApacheSolrForTypo3\Solr\IndexQueue\Queue;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use TYPO3\CMS\Core\FormProtection\Exception;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class SolrReindexCommand extends \Symfony\Component\Console\Command\Command
{
    /**
     * @inheritDoc
     */
    protected function configure(): void
    {
        $this->setDescription('This tool creates reindex tasks for solr per table and site or all sites

        For example:
        This will create an index-task for all sites and all pages and will clean the index first
        ./vendor/bin/typo3 solr:tools:reindex -w pages --cleanup ALL

        ');

        $this->addArgument(
            'site',
            InputArgument::REQUIRED | InputArgument::IS_ARRAY,
            'Sites or ALL for all sites'
        );

        $this->addOption('what', 'w', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'what to index (eq pages)');

        $this->addOption('cleanup', 'c', InputOption::VALUE_NONE, 'clean the solr index per site');
    }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $sitesToLoad = $input->getArgument('site');

        $siteFinder = GeneralUtility::makeInstance(SiteFinder::class);
        if (in_array('all', $sitesToLoad)) {
            $sites = $siteFinder->getAllSites();
        } else {
            $sites = [];
            foreach ($sitesToLoad as $key) {
                try {
                    $sites[] = $siteFinder->getSiteByIdentifier($key);
                } catch (Exception $e) {
                    $output->writeln($e->getMessage());
                }
            }
        }

        $options = $input->getOption('what');

        $solrSiteFinder = GeneralUtility::makeInstance(SiteRepository::class);

        foreach ($sites as $site) {
            $solrSite = $solrSiteFinder->getSiteByRootPageId($site->getRootPageId());
            $output->writeln('Running ' . $solrSite->getTitle() . ' ' . $solrSite->getDomain());
            if ($input->getOption('cleanup')) {
                $output->writeln('Cleaning ' . $solrSite->getTitle() . ' ' . $solrSite->getDomain());
                $this->cleanUpIndex($solrSite, $options);
            }
            // initialize for re-indexing
            /* @var Queue $indexQueue */
            $indexQueue = GeneralUtility::makeInstance(Queue::class);
            $indexQueueInitializationResults = $indexQueue->getInitializationService()
                                                          ->initializeBySiteAndIndexConfigurations($solrSite, $options);
        }

        return 0;
    }
    protected function cleanUpIndex(Site $site, array $what): bool
    {
        $cleanUpResult = true;
        $solrConfiguration = $site->getSolrConfiguration();
        $solrServers = GeneralUtility::makeInstance(ConnectionManager::class)->getConnectionsBySite($site);
        $typesToCleanUp = [];
        $enableCommitsSetting = $solrConfiguration->getEnableCommits();

        foreach ($what as $indexingConfigurationName) {
            $type = $solrConfiguration->getIndexQueueTableNameOrFallbackToConfigurationName($indexingConfigurationName);
            $typesToCleanUp[] = $type;
        }

        foreach ($solrServers as $solrServer) {
            $deleteQuery = 'type:(' . implode(' OR ', $typesToCleanUp) . ')' . ' AND siteHash:' . $site->getSiteHash();
            $solrServer->getWriteService()->deleteByQuery($deleteQuery);

            if (!$enableCommitsSetting) {
                // Do not commit
                continue;
            }

            $response = $solrServer->getWriteService()->commit(false, false);
            if ($response->getHttpStatus() != 200) {
                $cleanUpResult = false;
                break;
            }
        }

        return $cleanUpResult;
    }
}
