# WahoSearch Connector

Sends selected Drupal content to the hosted WahoSearch platform and keeps it up
to date.

Works on Drupal 10.2+ and 11, PHP 8.1+. Needs Node, Taxonomy and User. For
French content also enable Language and Content Translation, and Locale for the
French admin screens.

## Install

With Composer, if the package is available to your project:

    composer require drupal/wahosearch_connector
    drush en wahosearch_connector

Or copy this folder to `modules/custom/wahosearch_connector` and enable it on the
Extend page.

## Setup

Go to Configuration > Search and metadata > WahoSearch Connector.

1. Connection: enter the platform URL, site ID and secret, then click Save and
   test connection.
2. Content types: tick what should be indexed. Anything not ticked is not sent.
3. Choose the fields for each content type and their names in the index.
   Paragraphs fields are offered too: the text of their paragraphs, also
   nested ones, is sent as page content in the language of each translation.
4. Synchronization: click Run full synchronization once.

Changes are sent automatically after that.

Two permissions control access: "Administer WahoSearch Connector" for the screens
and sync controls, and "Exclude individual content from WahoSearch" for the
checkbox on edit forms.

On production, keep the secret in `settings.php`. It overrides the stored value
and hides the field:

    $settings['wahosearch_connector_secret'] = getenv('WAHOSEARCH_SECRET');

The secret is never included in a config export.

## How sync behaves

- Created or edited content is queued and sent after the page is delivered.
  Saving without a real change sends nothing.
- Unpublished, deleted or excluded content is removed from the index. Removing
  one translation only removes that language.
- Unticking a content type removes its content from the index.
- Renaming or deleting a term updates the term and the content using it.
- If the platform is down, items stay queued and retry on the next cron run.
- If the platform refuses an item, it shows in the log and can be retried.

Whatever is not sent right away goes out on cron, so run cron every 5 to 15
minutes.

A full sync also removes old content from the platform. If any item fails during
the run that cleanup is skipped, so a bad run cannot delete good content.

## Restricted content

Only content anonymous visitors can open is sent by default. With Include
restricted content ticked for a content type, items go out with the roles
allowed to view them. A field is left out if any of those roles cannot view it.

Changing role permissions does not update the index on its own. Run a full sync
afterwards.

## Drush

Drush needs `--uri` to build correct links. The same applies if cron runs
through Drush.

    drush --uri=https://example.ca wahosearch:status
    drush --uri=https://example.ca wahosearch:test
    drush --uri=https://example.ca wahosearch:full-sync
    drush --uri=https://example.ca wahosearch:send --seconds=60

## Tests

From the Drupal web root, with `SIMPLETEST_DB` and `SIMPLETEST_BASE_URL` set:

    ../vendor/bin/phpunit -c core modules/custom/wahosearch_connector/tests

## API

The platform API is described in [docs/API_CONTRACT.md](docs/API_CONTRACT.md).
