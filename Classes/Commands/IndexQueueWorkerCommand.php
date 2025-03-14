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
use Sudhaus7\Logformatter\Logger\ConsoleLogger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
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
        $this->setDescription('This tool will work on the index queue for all sites. This can be used as a replacement for the Task QueueWorker, which runs only on one site per setup.

        This Command is heavily influenced by the IndexQueueWorkerTask of the jwtools2 extension

        ');

        $this->addOption('sites', null, InputOption::VALUE_REQUIRED, 'how many sites to run per run', 5);

        $this->addOption('documents', null, InputOption::VALUE_REQUIRED, 'how many documents to run per site', 25);

        $this->addOption('purgeinvalidsites', null,InputOption::VALUE_NONE, 'Purge Invalid Sites');
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
                       ->setMaxResults($maxResults)
                       ->executeQuery();

        $result = [];
        while ($row = $stmt->fetchAssociative()) {
            try {
                $site = $repository->getSiteByRootPageId($row['root']);
                if ($site instanceof Site) {
                    $logger->debug('found Site ' . $site->getRootPageId() . ' ' . $row['root'] . ' ' . $site->getDomain());
                    $result[] = $site;
                } else {
                    if ($this->purgeInvalidSites) {
                        $logger->info( 'Site ' . $row['root'] . ' not found - deleting from queue' );
                        GeneralUtility::makeInstance( ConnectionPool::class )
                                      ->getConnectionForTable( 'tx_solr_indexqueue_item' )
                                      ->delete(
                                          'tx_solr_indexqueue_item',
                                          [ 'root' => $row['root'] ]
                                      );
                    }
                }
            } catch (\Exception $e) {
                $logger->error($e->getMessage(), ['code' => $e->getCode(), 'root' => $row['root']]);
            }
        }

        return $result;
    }
}
