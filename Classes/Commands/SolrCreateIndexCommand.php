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

namespace Code711\SolrTools\Commands;

use ApacheSolrForTypo3\Solr\ConnectionManager;
use ApacheSolrForTypo3\Solr\Domain\Site\Site;
use ApacheSolrForTypo3\Solr\Domain\Site\SiteRepository;
use ApacheSolrForTypo3\Solr\IndexQueue\Queue;
use Doctrine\DBAL\ConnectionException;
use Doctrine\DBAL\Exception;

use function in_array;

use InvalidArgumentException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class SolrCreateIndexCommand extends Command
{
    /**
     * @inheritDoc
     */
    protected function configure(): void
    {
        $this->setDescription('This tool creates reindex tasks for solr per table and site or all sites

        For example:
        This will create an index-task for all sites and all pages and will clean the index first
        ./vendor/bin/typo3 solr:tools:createindex -w pages --cleanup ALL

        This will create index-entried for all sites and all configured tables:
        ./vendor/bin/typo3 solr:tools:createindex -w all all

        ');

        $this->addArgument(
            'site',
            InputArgument::REQUIRED | InputArgument::IS_ARRAY,
            'Site identifier or ALL for all sites'
        );

        $this->addOption('what', 'w', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'what to index (eq pages). Add "all" to index all configured table types');

        $this->addOption('cleanup', 'c', InputOption::VALUE_NONE, 'clean the solr index per site');
    }

    /**
     * @throws ConnectionException
     * @throws Exception
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var string[] $sitesToLoad */
        $sitesToLoad = (array)$input->getArgument('site');

        $siteFinder = GeneralUtility::makeInstance(SiteFinder::class);
        if (in_array('all', $sitesToLoad) || in_array('ALL', $sitesToLoad)) {
            $sites = $siteFinder->getAllSites();
        } else {
            $sites = [];
            foreach ($sitesToLoad as $key) {
                try {
                    $sites[] = $siteFinder->getSiteByIdentifier($key);
                } catch (SiteNotFoundException $e) {
                    $output->writeln($e->getMessage());
                }
            }
        }

        /** @var string[] $options */
        $options = (array)$input->getOption('what');

        if (in_array('ALL', $options) || in_array('all', $options)) {
            $options = ['*'];
        }

        $solrSiteFinder = GeneralUtility::makeInstance(SiteRepository::class);

        foreach ($sites as $site) {
            try {
                /** @var ?Site $solrSite */
                $solrSite = $solrSiteFinder->getSiteByRootPageId($site->getRootPageId());
            } catch (InvalidArgumentException $e) {
                $output->writeln('Skipping ' . $solrSite->getTitle() . ' ' . $solrSite->getDomain() . ' (no site)');
                continue;
            } catch (\Exception $e) {
                $output->writeln("\n\r" . 'Skipping site with identifier ' . $site->getIdentifier() . '.');
                $output->writeln('ERROR: ' . $e->getMessage() . "\n\r");
                continue;
            }
            if ($solrSite instanceof Site) {
                if ($solrSite->getAllSolrConnectionConfigurations() === []) {
                    $output->writeln('Skipping ' . $solrSite->getTitle() . ' ' . $solrSite->getDomain() . ' (no config)');
                    continue;
                }

                $output->writeln('Running ' . $solrSite->getTitle() . ' ' . $solrSite->getDomain());

                if ($input->getOption('cleanup')) {
                    $output->writeln('Cleaning ' . $solrSite->getTitle() . ' ' . $solrSite->getDomain());
                    $this->cleanUpIndex($solrSite, $options);
                }
                // initialize for re-indexing
                /* @var Queue $indexQueue */
                $indexQueue                      = GeneralUtility::makeInstance(Queue::class);

                $indexQueue->getInitializationService()
                           ->initializeBySiteAndIndexConfigurations($solrSite, $options);
            }
        }

        return 0;
    }

    /**
     * @param Site $site
     * @param string[] $what
     * @return bool
     */
    protected function cleanUpIndex(Site $site, array $what): bool
    {
        $cleanUpResult = true;
        $solrConfiguration = $site->getSolrConfiguration();
        $solrServers = GeneralUtility::makeInstance(ConnectionManager::class)->getConnectionsBySite($site);
        $typesToCleanUp = [];
        $enableCommitsSetting = $solrConfiguration->getEnableCommits();

        foreach ($what as $indexingConfigurationName) {
            $type = $solrConfiguration->getIndexQueueTypeOrFallbackToConfigurationName($indexingConfigurationName);
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
