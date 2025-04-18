# TYPO3 Solr tools

[![Latest Stable Version](https://poser.pugx.org/code711/solrtools/v/stable.svg)](https://extensions.typo3.org/extension/solr_tools)
[![TYPO3 11](https://img.shields.io/badge/TYPO3-11-orange.svg)](https://get.typo3.org/version/11)
[![TYPO3 12](https://img.shields.io/badge/TYPO3-12-orange.svg)](https://get.typo3.org/version/12)
[![Total Downloads](https://poser.pugx.org/code711/solrtools/d/total.svg)](https://packagist.org/packages/code711/solrtools)
[![Monthly Downloads](https://poser.pugx.org/code711/solrtools/d/monthly)](https://packagist.org/packages/sudhaus7/logformatter)
![PHPSTAN:Level 9](https://img.shields.io/badge/PHPStan-level%209-brightgreen.svg?style=flat])
![build:passing](https://img.shields.io/badge/build-passing-brightgreen.svg?style=flat])
![Psalm coverage](https://shepherd.dev/github/sudhaus7/typo3-logformatter/coverage.svg)

This Extension provides CLI Tools to initialize the EXT:solr index queues from the command line, and to scan for file-references in the content to add the corresponding sites to the sys_file metadata where a file is being used.

The tools are aimed for installations with multiple sites. They will not do anything what could not be done in the TYPO3 Backend, but might save some time, or might be usefull in certain CI/CD Situations.

Additionally, a SolrEntityInterface is available for Extbase Models to update the solr index of a record if it has been changed through extbase in the frontend (similar to EXT:slug_extbase )

## Changelog
2.0.8
- added command solr:tools:indexqueueworker - this runs the indexer on all sites

2.0.1
- Support of TYPO3 v11-v12
- SolrfilemetaCommand.php does not set 'enable_indexing' for files on hidden pages or pages with restricted FE user access anymore

2.0.0
- Support of TYPO3 v11-v12
- SolrfilemetaCommand.php does not set 'enable_indexing' for files on hidden pages or pages with restricted FE user access anymore

1.2.0
- better handling of non-solr-configured sites
- added possibility to use the keyword 'all' with option -w/--what to create indexes for all configured tables in a site

1.1.1
- better identifier for events

1.1.0
- added `Code711\SolrTools\Interfaces\SolrEntityInterface` to enable Extbase models to update its index when persisted through extbase

1.0.2
- added promised but missing scan for file-references in tt_content:header_link

1.0.1
- Updated TER Description

1.0.0
- Initial release

## `Code711\SolrTools\Interfaces\SolrEntityInterface`

To enable this feature for your models simply add to the models class definition of your model, for example:

```php

class MyModel extends TYPO3\CMS\Extbase\DomainObject\AbstractEntity implements \Code711\SolrTools\Interfaces\SolrEntityInterface

```

now when persisting an object of this class, it will check if it is indexed in solr and if yes that its index will be added or updated through the normal scheduler process.
The Interface itself has no further requirements and is only used as a marker to identify the models to watch out for.

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
  -w, --what=WHAT       what to index (eq pages) (multiple values allowed). Enter "all" to index all configured pages
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

The following will create index tasks for all tables in all sites

<pre>./vendor/bin/typo3 solr:tools:createindex -w all all</pre>

## solr:tools:filemeta

This tool will search the file references table and the header_link and bodytext fields for files which match the given file extensions. It will then look up the corresponding site based on the page-id associated with the record and add that site id to the enable_indexing field provided by [EXT:solr_file_indexer](https://extensions.typo3.org/extension/solr_file_indexer).

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




