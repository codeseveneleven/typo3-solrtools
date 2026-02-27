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
        $this->setDescription('Process the Solr index queue for multiple sites in a single run')
            ->setHelp(
                <<<EOT
The <info>%command.name%</info> command processes the Solr index queue for multiple TYPO3 sites.

This command is designed as a replacement for the default QueueWorker Task, which can only
process one site per execution. It efficiently handles multiple sites in a single run by
processing documents from sites with the oldest changed items first.

<comment>Usage:</comment>
  <info>%command.full_name%</info>
  <info>%command.full_name% --sites=10 --documents=50</info>
  <info>%command.full_name% --purgeinvalidsites -vv</info>

<comment>Options:</comment>
  <info>--sites=N</info>           Maximum number of sites to process per run (default: 5)
                        Sites are selected by oldest changed items in the queue

  <info>--documents=N</info>       Maximum number of documents to index per site (default: 25)
                        Each selected site will process up to this many documents

  <info>--purgeinvalidsites</info> Remove queue entries for sites that no longer exist
                        Automatically cleans up orphaned index queue items

  <info>-v, -vv, -vvv</info>       Increase verbosity of output
                        Use -vv or higher to enable detailed logging

<comment>How it works:</comment>
1. Queries the index queue (tx_solr_indexqueue_item) for sites with pending items
2. Selects up to N sites with the oldest changed timestamps
3. Processes up to M documents for each selected site
4. Skips invalid sites (optionally purges them from queue)
5. Logs detailed information when verbosity is enabled (-vv or higher)

<comment>Example workflow:</comment>
  # Process default number of sites and documents
  <info>%command.full_name%</info>

  # Process more sites with more documents per site
  <info>%command.full_name% --sites=20 --documents=100</info>

  # Clean up invalid sites with verbose output
  <info>%command.full_name% --purgeinvalidsites -vv</info>

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
            } catch (\InvalidArgumentException $e) {
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
