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

$EM_CONF['solr_tools'] = [
    'title' => '(Code711) Solr Tools',
    'description' => 'A CLI tool to set file meta-data for solr',
    'category' => 'plugin',
    'version' => '1.0.0',
    'state' => 'stable',
    'clearcacheonload' => 1,
    'author' => 'Frank Berger',
    'author_email' => 'fberger@code711.de',
    'author_company' => 'Code711, ein Label der B-Factor GmbH',
    'constraints' => [
        'depends' => [
            'typo3' => '11.5.0-11.5.99',
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
