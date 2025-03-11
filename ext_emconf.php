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

$EM_CONF['solr_tools'] = [
    'title' => '(Code711) Solr Tools',
    'description' => 'This Extension provides CLI Tools to initialize the EXT:solr index queues from the command line, and to scan for file-references in the content to add the corresponding sites to the sys_file metadata where a file is being used.
    Additionally a SolrEntityInterface is available for Extbase Models to update the solr index of a record if it has been changed through extbase in the frontend (similar to EXT:slug_extbase )',
    'category' => 'plugin',
    'version' => '2.0.8',
    'state' => 'stable',
    'author' => 'Frank Berger',
    'author_email' => 'fberger@code711.de',
    'author_company' => 'Code711, a label of Sudhaus7, B-Factor GmbH and 12bis3 GbR',
    'constraints' => [
        'depends' => [
            'typo3' => '11.5.0-12.4.99',
        ],
        'conflicts' => [
        ],
        'suggests' => [
        ],
    ],
    'autoload' => [
        'psr-4' => [
            'Code711\\SolrTools\\' => 'Classes',
        ],
    ],
];
