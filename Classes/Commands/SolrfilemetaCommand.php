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

namespace Sudhaus7\SolrTools\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class SolrfilemetaCommand extends Command
{
    protected function configure(): void
    {
        $this->setDescription('This tool searches for relations of files in the database and adds the correct site reference in the Files metadata, in order for EXT:solr to index those files correctly
        ');
        $this->addOption('ext', null, InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY, 'File extensions to allow', ['doc', 'docx', 'pdf']);
    }

    public function run(InputInterface $input, OutputInterface $output)
    {
        /** @var \TYPO3\CMS\Core\Database\Query\QueryBuilder $query */
        $query = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('sys_file_reference');
        /** @var \Doctrine\DBAL\Result $stmt */
        $stmt = $query->select('*')
                        ->from('sys_file_reference')
                      ->where(
                          $query->expr()->eq('hidden', 0),
                          $query->expr()->eq('deleted', 0),
                      )
                      ->execute();

        while ($row = $stmt->fetchAssociative()) {
            $record = BackendUtility::getRecord($row['tablenames'], $row['uid_foreign']);
            if ($record) {
                $ctrl = $GLOBALS['TCA'][$row['tablenames']]['ctrl'];
                $dorun = true;
                if (isset($ctrl['enablecolumns']['disabled'])) {
                    if ($record[$ctrl['enablecolumns']['disabled']] !== 0) {
                        $dorun = false;
                    }
                }
                if ($dorun) {
                    $this->runForFile($row['uid_local'], $row['pid'], $output, $input->getOption('ext'));
                }
            }
        }

        /** @var \TYPO3\CMS\Core\Database\Query\QueryBuilder $query */
        $query = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('tt_content');
        /** @var \Doctrine\DBAL\Record $stmt */
        $stmt = $query->select('*')
                      ->from('tt_content')
                      ->where(
                          $query->expr()->like('bodytext', '"%t3://file?uid=%"'),
                          $query->expr()->eq('deleted', 0),
                          $query->expr()->eq('hidden', 0)
                      )
                      ->execute();
        while ($row = $stmt->fetchAssociative()) {
            \preg_match_all('/t3:\/\/file\?uid=(\d+)/', $row['bodytext'], $matches);

            foreach ($matches[1] as $fileid) {
                $this->runForFile($fileid, $row['pid'], $output, $input->getOption('ext'));
            }
        }

        return 0;
    }

    private function runForFile(int $fileid, int $pid, OutputInterface $output, array $extensions)
    {
        $sys_file = BackendUtility::getRecord('sys_file', $fileid);

        if (\in_array($sys_file['extension'], $extensions)) {
            $output->writeln('testing file ' . $sys_file['identifier'], OutputInterface::VERBOSITY_VERBOSE);
            $rl = BackendUtility::BEgetRootLine($pid);
            $rootfound = false;
            foreach ($rl as $p) {
                if ($rootfound) {
                    continue;
                }
                if ((int)$p['hidden'] !== 0) {
                    $output->writeln('hidden page in root - skipping file ' . $sys_file['identifier'], OutputInterface::VERBOSITY_VERBOSE);
                    return;
                }
                if ($p['is_siteroot'] > 0) {
                    $rootfound = true;
                }
            }

            if (!$rootfound) {
                $output->writeln('no root connection - skipping file ' . $sys_file['identifier'], OutputInterface::VERBOSITY_VERBOSE);
                return;
            }
            /** @var \TYPO3\CMS\Core\Database\Connection $query */
            $query = GeneralUtility::makeInstance(ConnectionPool::class)
                                   ->getConnectionForTable('sys_file_metadata');
            /** @var \Doctrine\DBAL\Result $res */
            $res           = $query->select(
                [ '*' ],
                'sys_file_metadata',
                [ 'file' => $fileid ]
            );
            $sys_file_meta = $res->fetchAssociative();

            try {
                $site = GeneralUtility::makeInstance(SiteFinder::class)->getSiteByPageId($pid);

                $indexing = GeneralUtility::intExplode(',', $sys_file_meta['enable_indexing']);
                if (count($indexing) === 1 && $indexing[0] === 0) {
                    $indexing = [];
                }
                if ($site->getRootPageId() > 0 && !\in_array($site->getRootPageId(), $indexing)) {
                    $indexing[] = $site->getRootPageId();
                    GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('sys_file_metadata')
                      ->update(
                          'sys_file_metadata',
                          ['enable_indexing'=>implode(',', $indexing)],
                          ['uid'=>$sys_file_meta['uid']],
                      );
                    $output->writeln('Added ' . $sys_file['identifier'] . ' to Site ' . $site->getIdentifier() . ' meta id ' . $sys_file_meta['uid'] . ' sites ' . implode(',', $indexing));
                }
            } catch (SiteNotFoundException $e) {
                $output->writeln($e->getMessage());
            }
        }
    }
}
