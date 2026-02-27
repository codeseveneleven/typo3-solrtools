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
        $this->setDescription('Creates Solr reindex tasks for specified tables and sites')
            ->setHelp(
                <<<EOT
The <info>%command.name%</info> command creates reindex tasks for Apache Solr search indexes.
It allows you to selectively reindex specific content types across one or multiple TYPO3 sites.

<comment>Usage Examples:</comment>

  <info>Reindex pages for all sites with index cleanup:</info>
  %command.full_name% -w pages --cleanup all

  <info>Reindex all configured table types for all sites:</info>
  %command.full_name% -w all all

  <info>Reindex pages and news for a specific site:</info>
  %command.full_name% -w pages -w news mysite

  <info>Reindex multiple sites without cleanup:</info>
  %command.full_name% -w pages site1 site2 site3

<comment>Arguments:</comment>

  <info>site</info>
    One or more TYPO3 site identifiers to process.
    Use <info>all</info> or <info>ALL</info> to process all configured sites.
    Multiple site identifiers can be specified, separated by spaces.

<comment>Options:</comment>

  <info>-w, --what</info>
    Specifies which table types to index (e.g., pages, news, tt_content).
    Can be specified multiple times to index multiple types.
    Use <info>all</info> or <info>ALL</info> to index all configured table types.
    This option is required.

  <info>-c, --cleanup</info>
    When specified, removes existing index entries for the selected types
    from the Solr index before creating new reindex tasks.
    This ensures a clean reindex without duplicate or stale entries.

<comment>Notes:</comment>

  • This command only creates reindex tasks in the queue; it does not execute indexing
  • To execute the indexing, run the index queue worker command afterwards
  • Sites without Solr configuration will be automatically skipped
  • Invalid site identifiers will be reported and skipped

EOT
            );

        $this->addArgument(
            'site',
            InputArgument::REQUIRED | InputArgument::IS_ARRAY,
            'One or more TYPO3 site identifiers, or "all"/"ALL" to process all sites'
        );

        $this->addOption(
            'what',
            'w',
            InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
            'Table type(s) to index (e.g., pages, news). Use "all"/"ALL" for all configured types. Can be specified multiple times.'
        );

        $this->addOption(
            'cleanup',
            'c',
            InputOption::VALUE_NONE,
            'Remove existing index entries before creating reindex tasks'
        );
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
        if (\in_array('all', $sitesToLoad) || \in_array('ALL', $sitesToLoad)) {
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

        if (\in_array('ALL', $options) || \in_array('all', $options)) {
            $options = ['*'];
        }

        $solrSiteFinder = GeneralUtility::makeInstance(SiteRepository::class);

        foreach ($sites as $site) {
            try {
                /** @var ?Site $solrSite */
                $solrSite = $solrSiteFinder->getSiteByRootPageId($site->getRootPageId());
            } catch (\Throwable $e) {
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
