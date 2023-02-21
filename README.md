# TYPO3 Solr tools

[![Latest Stable Version](https://poser.pugx.org/code711/solrtools/v/stable.svg)](https://extensions.typo3.org/code711/solrtools/)
[![TYPO3 11](https://img.shields.io/badge/TYPO3-11-orange.svg)](https://get.typo3.org/version/11)
[![Total Downloads](https://poser.pugx.org/code711/solrtools/d/total.svg)](https://packagist.org/packages/code711/solrtools)
[![Monthly Downloads](https://poser.pugx.org/code711/solrtools/d/monthly)](https://packagist.org/packages/sudhaus7/logformatter)
![PHPSTAN:Level 9](https://img.shields.io/badge/PHPStan-level%209-brightgreen.svg?style=flat])
![build:passing](https://img.shields.io/badge/build-passing-brightgreen.svg?style=flat])
![Psalm coverage](https://shepherd.dev/github/sudhaus7/typo3-logformatter/coverage.svg)

This Extension provides CLI Tools to initialize the EXT:solr index queues from the command line, and to scan for file-references in the content to add the corresponding sites to the sys_file metadata where a file is being used.

The tools are aimed for installations with multiple sites. They will not do anything what could not be done in the TYPO3 Backend, but might save some time, or might be usefull in certain CI/CD Situations.

## Changelog

1.0.0
- Initial release

## solr:tools:createindex

<pre>
./vendor/bin/typo3 solr:tools:createindex --help

Description:
  This tool creates (re)index tasks for solr per table and site or all sites

Usage:
  solr:tools:createindex [options] [--] <site>...

Arguments:
  site                  Site identifier or ALL for all sites

Options:
  -w, --what=WHAT       what to index (eq pages) (multiple values allowed)
  -c, --cleanup         clean the solr index per site
  -h, --help            Display help for the given command. When no command is given display help for the list command
  -q, --quiet           Do not output any message
  -V, --version         Display this application version
      --ansi|--no-ansi  Force (or disable --no-ansi) ANSI output
  -n, --no-interaction  Do not ask any interactive question
  -v|vv|vvv, --verbose  Increase the verbosity of messages: 1 for normal output, 2 for more verbose output and 3 for debug
</pre>

This tool expects [EXT:solr](https://extensions.typo3.org/extension/solr) to be set up and configured in TYPO3.

### Examples

This will create index-tasks for the table pages in all Sites and it will run cleanup before that

<pre>./vendor/bin/typo3 solr:tools:createindex -w pages --cleanup ALL</pre>

This will create index-tasks for the tables tx_news and sys_file_metadata in the sites with the identifiers customer1 and customer3

<pre>./vendor/bin/typo3 solr:tools:createindex -w tx_news -w sys_file_metadata customer1 customer3</pre>

## solr:tools:filemeta

This tool will search the file references table and the header_link and bodytext fields for files which match the given file extensions. It will then lookup the corresponding site based on the page-id associated with the record and add that site id to the enable_indexing field provided by [EXT:solr_file_indexer](https://extensions.typo3.org/extension/solr_file_indexer).

The tool will check if the element is visible (deleted=0, hidden=0) and if the page and its parent pages are visible, to ensure only 'active' files are added to the solr index.

<pre>
./vendor/bin/typo3 solr:tools:filemeta --help

Description:
This tool searches for relations of files in the database and adds the correct site reference in the Files metadata, in order for EXT:solr to index those files correctly

Usage:
solr:tools:filemeta [options]

Options:
      --ext[=EXT]       File extensions to allow [default: ["doc","docx","pdf"]] (multiple values allowed)
  -c, --cleanup         clean the site field in all meta data
  -h, --help            Display help for the given command. When no command is given display help for the list command
  -q, --quiet           Do not output any message
  -V, --version         Display this application version
      --ansi|--no-ansi  Force (or disable --no-ansi) ANSI output
  -n, --no-interaction  Do not ask any interactive question
  -v|vv|vvv, --verbose  Increase the verbosity of messages: 1 for normal output, 2 for more verbose output and 3 for debug
</pre>




