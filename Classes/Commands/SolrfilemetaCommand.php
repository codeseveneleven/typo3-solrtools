<?php
declare(strict_types=1);

/*
 * This file is part of the TYPO3 project.
 * (c) 2022 B-Factor GmbH
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 * The TYPO3 project - inspiring people to share!
 *
 */

namespace Code711\SolrTools\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class SolrfilemetaCommand extends Command
{
    protected function configure(): void
    {
        $this->setDescription('This tool searches for relations of files in the database and adds the correct site reference in the Files metadata, in order for EXT:solr to index those files correctly
        ');
        $this->addOption('ext', null, InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY, 'File extensions to allow', ['doc', 'docx', 'pdf']);
        $this->addOption('cleanup', 'c', InputOption::VALUE_NONE, 'clean the site field in all meta data');

    }

    public function run(InputInterface $input, OutputInterface $output)
    {

        if (!ExtensionManagementUtility::isLoaded( 'solr_file_indexer')) {
            $output->writeln( 'solr_file_indexer needs to be installed!',OutputInterface::VERBOSITY_QUIET);
            exit;
        }

        if ($input->getOption( 'cleanup')) {
            $output->writeln( 'Cleaning the metadata from all sites');
            GeneralUtility::makeInstance( ConnectionPool::class )->getConnectionForTable( 'sys_file_metadata' )
                          ->update(
                              'sys_file_metadata',
                              ['enable_indexing'=>null],
                              ['deleted'=>0]
                          );
        }

        $query = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('sys_file_reference');
        $stmt = $query->select('*')
                        ->from('sys_file_reference')
                      ->where(
                          $query->expr()->eq('hidden', 0),
                          $query->expr()->eq('deleted', 0),
                      )
                      ->executeQuery();


        /** @var string[] $extensions */
        $extensions = $input->getOption('ext');

        while ($row = $stmt->fetchAssociative()) {
            /** @var array<string,string|int> $row */
            $record = BackendUtility::getRecord((string)$row['tablenames'], (int)$row['uid_foreign']);
            if ($record) {
                /**
                 * @var array<string,string|int|array<string,string|int>> $ctrl
                 * @psalm-suppress  MixedArrayAccess
                 */
                $ctrl = $GLOBALS['TCA'][(string)$row['tablenames']]['ctrl'];
                $dorun = true;
                if (isset($ctrl['enablecolumns']['disabled'])) {
                    if ($record[(string)$ctrl['enablecolumns']['disabled']] !== 0) {
                        $dorun = false;
                    }
                }
                if ($dorun) {
                    $this->runForFile((int)$row['uid_local'], (int)$row['pid'], $output,$extensions );
                }
            }
        }

        $query = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('tt_content');
        $stmt = $query->select('*')
                      ->from('tt_content')
                      ->where(
                          $query->expr()->like('bodytext', '"%t3://file?uid=%"'),
                          $query->expr()->eq('deleted', 0),
                          $query->expr()->eq('hidden', 0)
                      )
                      ->executeQuery();

        while ($row = $stmt->fetchAssociative()) {
            /** @var array<string,string|int> $row */

            /** @var int[] $matches */
            \preg_match_all('/t3:\/\/file\?uid=(\d+)/', (string)$row['bodytext'], $matches);


            foreach ($matches[1] as $fileid) {
                $this->runForFile((int)$fileid, (int)$row['pid'], $output, $extensions);
            }
        }

        return 0;
    }

    /**
     * @param int $fileid
     * @param int $pid
     * @param OutputInterface $output
     * @param string[] $extensions
     *
     * @return void
     * @throws \Doctrine\DBAL\Driver\Exception
     */
    private function runForFile(int $fileid, int $pid, OutputInterface $output, array $extensions): void
    {
        if($sys_file = BackendUtility::getRecord('sys_file', $fileid)) {

            if ( \in_array( $sys_file['extension'], $extensions ) ) {
                $output->writeln( sprintf('testing file %s', (string)$sys_file['identifier']), OutputInterface::VERBOSITY_VERBOSE );
                $rl        = BackendUtility::BEgetRootLine( $pid );
                $rootfound = false;
                /** @var array<string,string|int> $p */
                foreach ( $rl as $p ) {

                    if ( $rootfound ) {
                        continue;
                    }
                    if ( (int) $p['hidden'] !== 0 ) {
                        $output->writeln( sprintf('hidden page in root - skipping file %s', (string)$sys_file['identifier']),
                            OutputInterface::VERBOSITY_VERBOSE );

                        return;
                    }
                    if ( $p['is_siteroot'] > 0 ) {
                        $rootfound = true;
                    }
                }

                if ( ! $rootfound ) {
                    $output->writeln(sprintf('no root connection - skipping file %s' , (string)$sys_file['identifier']),
                        OutputInterface::VERBOSITY_VERBOSE );

                    return;
                }

                $query = GeneralUtility::makeInstance( ConnectionPool::class )
                                       ->getConnectionForTable( 'sys_file_metadata' );
                $res = $query->select(
                    [ '*' ],
                    'sys_file_metadata',
                    [ 'file' => $fileid ]
                );



                if ( $sys_file_meta = $res->fetchAssociative() ) {
                    /** @var array<string,int|string> $sys_file_meta */
                    try {
                        $site = GeneralUtility::makeInstance( SiteFinder::class )->getSiteByPageId( $pid );

                        $indexing = GeneralUtility::intExplode( ',', (string)$sys_file_meta['enable_indexing'] );
                        if ( count( $indexing ) === 1 && $indexing[0] === 0 ) {
                            $indexing = [];
                        }
                        if ( $site->getRootPageId() > 0 && ! \in_array( $site->getRootPageId(), $indexing ) ) {
                            $indexing[] = $site->getRootPageId();
                            GeneralUtility::makeInstance( ConnectionPool::class )
                                          ->getConnectionForTable( 'sys_file_metadata' )
                                          ->update(
                                              'sys_file_metadata',
                                              [ 'enable_indexing' => implode( ',', $indexing ) ],
                                              [ 'uid' => $sys_file_meta['uid'] ],
                                          );
                            $output->writeln(
                                sprintf('Added %s to Site %s meta id %d sites %s',
                                    (string)$sys_file['identifier'],
                                    $site->getIdentifier(),
                                    $sys_file_meta['uid'],
                                    implode(',',$indexing)
                                ));
                        }
                    } catch ( SiteNotFoundException $e ) {
                        $output->writeln( $e->getMessage() );
                    }
                }
            }
        }
    }
}
