---
apply: always
---

## Project Description

This is a TYPO3 extension with the extension key `solr_tools`. It offers Symfony Command Tools to manage a TYPO3 ext:solr installation more efficently when dealing with multiple site setups and queues.

## Installation

install with `composer require code711/solrtools`.

## Author

Frank Berger (fberger@sudhaus7.de)
https://sudhaus7.de/
https://code711.de

## Notes for AI Assistance

When working on this project:
- Follow TYPO3 best practices, found here: `https://docs.typo3.org/m/typo3/reference-coreapi/main/en-us/ApiOverview/ContentElements/BestPractices.html`
- Follow the TYPO3  naming conventions and coding standard found here: `https://docs.typo3.org/m/typo3/reference-coreapi/main/en-us/CodingGuidelines/Index.html`
- Maintain PSR-4 autoloading structure
- Use type hints and return types (PHP 8.3 features are available)
- describe arrays with array shapes for phpstan
- Add appropriate error handling and user feedback
- Write testable code with dependency injection where appropriate
- use `vendor/bin/php-cs-fixer fix` to maintain code style
- ensure code quality with `vendor/bin/phpstan
analyse`
- AI Assistance must not change tests
- AI Assistance must not change the database schema
