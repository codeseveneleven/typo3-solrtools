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

use ApacheSolrForTypo3\Solr\Domain\Site\Site;
use ApacheSolrForTypo3\Solr\Domain\Site\SiteRepository;
use ApacheSolrForTypo3\Solr\Exception\InvalidArgumentException;
use ApacheSolrForTypo3\Solr\System\Environment\CliEnvironment;
use Code711\SolrTools\Domain\Index\IndexService;
use Doctrine\DBAL\ConnectionException;
use Doctrine\DBAL\Exception;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Logger\ConsoleLogger;
use Symfony\Component\Console\Output\OutputInterface;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class IndexQueueWorkerCommand extends Command
{
    protected bool $purgeInvalidSites = false;

    /**
     * @inheritDoc
     */
    protected function configure(): void
    {
        $this->setDescription('Process Solr index queue for multiple TYPO3 sites efficiently in a single run')
            ->setHelp(
                <<<EOT
The <info>%command.name%</info> command processes the Solr index queue for multiple TYPO3 sites in a single execution.

<comment>Purpose:</comment>
This command serves as an efficient replacement for the default TYPO3 Solr QueueWorker Task,
which is limited to processing one site per execution. It handles multiple sites in a single
run by intelligently selecting sites with the oldest pending items and processing them in
priority order.

<comment>Usage:</comment>
  <info>%command.full_name%</info>
  <info>%command.full_name% --sites=10 --documents=50</info>
  <info>%command.full_name% --purgeinvalidsites --cleanupdisconnectedpages -vv</info>

<comment>Options:</comment>
  <info>--sites=N</info>
      Maximum number of sites to process per run (default: 5)
      Sites with the oldest changed items in the queue are selected first,
      ensuring that outdated content gets prioritized for indexing.

  <info>--documents=N</info>
      Maximum number of documents to index per site (default: 25)
      Each selected site will process up to this many documents from its queue.
      Adjust this value based on your server resources and indexing requirements.

  <info>--purgeinvalidsites</info>
      Remove index queue entries for sites that no longer exist in the system
      Use this option to automatically clean up orphaned queue items from
      deleted or misconfigured sites. Helps maintain queue integrity.

  <info>--cleanupdisconnectedpages</info>
      Remove index queue entries for pages that are no longer in the pages table
      Cleans up queue items referencing deleted pages before processing begins.
      Runs as a pre-processing step to ensure queue data consistency.

  <info>-v, -vv, -vvv</info>
      Increase verbosity of output messages
      -v:   Normal output
      -vv:  Detailed logging including site processing information
      -vvv: Debug output with comprehensive execution details

<comment>How it works:</comment>
1. Optional cleanup: Removes disconnected pages if --cleanupdisconnectedpages is set
2. Query the index queue (tx_solr_indexqueue_item) for sites with pending items
3. Select up to N sites based on oldest changed timestamps (prioritizes stale content)
4. For each site, process up to M documents from the queue
5. Skip invalid/missing sites and optionally purge them if --purgeinvalidsites is set
6. Log detailed information when verbosity level is -vv or higher
7. Restore CLI environment and complete execution

<comment>Example workflows:</comment>
  # Basic usage with default settings (5 sites, 25 documents each)
  <info>%command.full_name%</info>

  # Process more sites and documents for larger installations
  <info>%command.full_name% --sites=20 --documents=100</info>

  # Perform maintenance with full cleanup and verbose logging
  <info>%command.full_name% --purgeinvalidsites --cleanupdisconnectedpages -vv</info>

  # Quick index update with minimal processing
  <info>%command.full_name% --sites=3 --documents=10</info>

<comment>Performance considerations:</comment>
- Adjust --sites and --documents based on your server capacity and time constraints
- Use --cleanupdisconnectedpages periodically to maintain queue health
- Enable -vv verbosity for troubleshooting but disable for scheduled tasks
- Consider running with higher values during off-peak hours

<comment>Scheduler integration:</comment>
This command is designed to be run as a TYPO3 scheduler task or cron job.
For best results, schedule it to run at regular intervals (e.g., every 5-15 minutes)
with appropriate --sites and --documents values to keep your search index up-to-date.

<comment>Note:</comment>
This command is heavily influenced by the IndexQueueWorkerTask of the jwtools2 extension.
EOT
            );

        $this->addOption(
            'sites',
            null,
            InputOption::VALUE_REQUIRED,
            'Maximum number of sites to process per run (sites with oldest pending items are selected first)',
            5
        );

        $this->addOption(
            'documents',
            null,
            InputOption::VALUE_REQUIRED,
            'Maximum number of documents to index per site',
            25
        );

        $this->addOption(
            'purgeinvalidsites',
            null,
            InputOption::VALUE_NONE,
            'Remove index queue entries for sites that no longer exist in the system'
        );

        $this->addOption(
            'cleanupdisconnectedpages',
            null,
            InputOption::VALUE_NONE,
            'Remove index queue entries for pages that are no longer in the pages table (runs before queue processing)'
        );
    }

    /**
     * @throws ConnectionException
     * @throws Exception
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($input->getOption('purgeinvalidsites')) {
            $this->purgeInvalidSites = true;
        }

        $logger = new NullLogger();
        if ($output->getVerbosity() >= OutputInterface::VERBOSITY_VERY_VERBOSE) {
            $logger = new ConsoleLogger($output);
        }
        $GLOBALS['CLILOGGER'] = $logger;

        $cliEnvironment = GeneralUtility::makeInstance(CliEnvironment::class);
        $cliEnvironment->backup();
        $cliEnvironment->initialize(Environment::getPublicPath() . '/');

        if ($input->getOption('cleanupdisconnectedpages')) {
            //select * from tx_solr_indexqueue_item where item_uid not in (select uid from pages where deleted=0) and item_type='pages'
            $conn = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('tx_solr_indexqueue_item');
            $affectedRows = $conn->executeStatement('delete from tx_solr_indexqueue_item where item_uid not in (select uid from pages where deleted=0) and item_type="pages"');

            $logger->info('removed ' . $affectedRows . ' disconnected pages from index queue');

        }

        $availableSites = $this->getAvailableSites((int)$input->getOption('sites'), $logger);
        foreach ($availableSites as $availableSite) {
            $logger->info('running indexer on ' . $availableSite->getTitle() . ' ' . $availableSite->getDomain() . ' ' . $availableSite->getRootPageId());
            try {
                $indexService = GeneralUtility::makeInstance(IndexService::class, $availableSite);
                $indexService->setRealLogger($logger);
                $indexService->indexItems((int)$input->getOption('documents'));
            } catch (\Exception $e) {
                $logger->error($e->getMessage(), ['code' => $e->getCode(), 'root' => $availableSite->getRootPageId()]);
                continue;
            }
        }
        $cliEnvironment->restore();
        return 0;
    }

    /**
     * Gets all available TYPO3 sites with Solr configured.
     *
     * @return Site[] An array of available sites
     * @throws \Doctrine\DBAL\Driver\Exception
     * @throws \Throwable
     */
    public function getAvailableSites(int $maxResults, LoggerInterface $logger): array
    {
        $repository = GeneralUtility::makeInstance(SiteRepository::class);

        $query = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('tx_solr_indexqueue_item');
        $stmt  = $query->select('root')
                       ->from('tx_solr_indexqueue_item')
                       ->where(
                           $query->expr()->gt('changed', 'indexed'),
                           $query->expr()->lte('changed', time()),
                           $query->expr()->like('errors', $query->createNamedParameter(''))
                       )
                       ->groupBy('root')
                       ->orderBy('changed', 'ASC')
                       //->setMaxResults($maxResults)
                       ->executeQuery();

        $result = [];
        while ($row = $stmt->fetchAssociative()) {
            if (count($result) >= $maxResults) {
                break;
            }
            try {
                $site = $repository->getSiteByRootPageId($row['root']);
                if ($site instanceof Site) {
                    $logger->debug('found Site ' . $site->getRootPageId() . ' ' . $row['root'] . ' ' . $site->getDomain());
                    $result[] = $site;
                } else {
                    $this->purgeIfNeeded($logger, $row['root']);
                }
            } catch (InvalidArgumentException $e) {
                $this->purgeIfNeeded($logger, $row['root']);
            } catch (\Throwable $e) {
                $logger->error($e->getMessage(), ['code' => $e->getCode(), 'root' => $row['root']]);
            }
        }

        return $result;
    }

    /**
     * @param LoggerInterface $logger
     * @param $root
     */
    private function purgeIfNeeded(LoggerInterface $logger, $root): void
    {
        if ($this->purgeInvalidSites) {
            $logger->info('Site ' . $root . ' not found - deleting from queue');
            GeneralUtility::makeInstance(ConnectionPool::class)
              ->getConnectionForTable('tx_solr_indexqueue_item')
              ->delete(
                  'tx_solr_indexqueue_item',
                  [ 'root' => $root ]
              );
        }
    }
}
