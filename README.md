# WahoSearch Connector

Sends selected Drupal content and its files to the hosted WahoSearch platform,
keeps them up to date, and embeds search and the chatbot inside your pages.

Works on Drupal 10.2+ and 11, PHP 8.1+. Needs Node, Taxonomy and User. For
French content also enable Language and Content Translation, and Locale for the
French admin screens. See [docs/COMPATIBILITY.md](docs/COMPATIBILITY.md).

## Install

The Composer package is hosted in a private GitHub repository. Team and
customer installations both require read access to that repository. Use a
fine-grained GitHub token scoped to this repository with read-only Contents
permission. Store it as `github-oauth.github.com` in Composer's private auth
configuration, or supply it through `COMPOSER_AUTH` from your CI secret store.
Keep it out of `composer.json` and Git. See [Composer's GitHub authentication
guide](https://getcomposer.org/doc/articles/authentication-for-private-packages.md#github-oauth).

Once `v1.4.0` or a later `1.x` release is tagged on GitHub, run these commands
from the Drupal project's root:

    composer config repositories.wahosearch-connector vcs https://github.com/waho-innovations/wahosearch-drupal-module.git
    composer require --prefer-dist 'waho-innovations/wahosearch-connector:^1.4'
    drush en wahosearch_connector

The Drupal recommended project already allows the `composer/installers` plugin
and installs this module to `web/modules/contrib/wahosearch_connector`. For a
custom project layout, allow that plugin in the root project's Composer
configuration and make sure its `drupal-module` installer path is under the
Drupal web root.

Or copy this folder to `modules/custom/wahosearch_connector` and enable it on the
Extend page.

If you are upgrading from 1.0.0, run `drush updb`.

## Setup

Go to Configuration > Search and metadata > WahoSearch Connector.

1. Connection: enter the platform URL, site ID and secret, then click Save and
   test connection. The page shows the connection status and the last
   successful synchronization.
2. Content types: tick what should be indexed. Anything not ticked is not sent.
   Tick Include files to index the PDF, Word, Excel and PowerPoint files
   attached to that content type.
3. Choose the fields for each content type and their names in the index.
   Paragraphs fields are offered too: the text of their paragraphs, also
   nested ones, is sent as page content in the language of each translation.
   Tick "Only without PDF" for a field that holds a copy of the attached
   file's text, so that text is not indexed twice.
4. Indexing rules (optional): limit indexing by URL path and set priority
   sections.
5. Synchronization: click Run full synchronization once.

Changes are sent automatically after that.

The site ID and secret together are the API key for one website. To rotate the
key, create a new one on the platform and enter it on the Connection page. The
change is written to the log.

Two permissions control access: "Administer WahoSearch Connector" for the screens
and sync controls, and "Exclude individual content from WahoSearch" for the
checkbox on edit forms.

### Dev, test, and production connections

Use a separate site ID and secret issued by the platform for each deployment.
Set `WAHOSEARCH_ENDPOINT` to the full platform URL for that deployment:

| Environment | Platform URL |
| --- | --- |
| Dev | `https://dev.wahosearch.app` |
| Test | `https://test.wahosearch.ai` |
| Production | `https://wahosearch.app` |

Set `WAHOSEARCH_SITE_ID` and `WAHOSEARCH_SECRET` in the deployment environment,
then add these overrides to Drupal's `settings.php`:

    $config['wahosearch_connector.settings']['endpoint'] = getenv('WAHOSEARCH_ENDPOINT');
    $config['wahosearch_connector.settings']['site_id'] = getenv('WAHOSEARCH_SITE_ID');
    $settings['wahosearch_connector_secret'] = getenv('WAHOSEARCH_SECRET');

Set all three variables before running Drupal or Drush. An unset or empty
`WAHOSEARCH_SECRET` lets the connector fall back to the secret stored in the
database. A nonempty override takes precedence and hides the secret field. The
secret is excluded from config exports and browser output.

Drupal's Connection form may display the stored platform URL and site ID. To
see the effective values before saving and testing the connection, run:

    drush config:get wahosearch_connector.settings endpoint --include-overridden
    drush config:get wahosearch_connector.settings site_id --include-overridden

## Indexing rules

Paths go one per line and `*` is a wildcard. A path can be the URL alias or the
internal path such as `/node/12`.

- Only index these paths: leave empty to allow everything.
- Never index these paths: always wins.
- Priority sections: whole sections only, such as `/admissions/*`, most
  important first. Single pages cannot be prioritized.

Preview changes shows what would be added or removed before you save. Saving
rules that remove content, or unticking a content type that has indexed content,
needs a confirmation tick. Each change is written to the log.

## Files

PDF, Word, Excel and PowerPoint files (pdf, docx, xlsx, pptx) in file fields
and in file media items are indexed as separate documents, with the same access
as their page. A media file that anonymous
visitors may view counts as public, even when logged-in roles lack the "View
media" permission. The platform extracts the text,
and only asks for a file when it does not already have that exact version.

The text of Word, Excel and PowerPoint files is searched on the Enterprise
plan. On other plans the platform lists them without searching their text, and
the files stay findable through the page they are attached to. After a move to
Enterprise, run a full sync so the platform can ask for the files.

Scanned PDFs need OCR, which is not supported. The platform reports those as
held back. Files over the size limit on the Connection page are skipped and
logged. Other file types, including the old doc, xls and ppt formats, are not
sent.

## How sync behaves

- Created or edited content is queued and sent after the page is delivered.
  Saving without a real change sends nothing.
- Unpublished, deleted or excluded content is removed from the index. Removing
  one translation only removes that language.
- Unticking a content type removes its content from the index.
- Renaming or deleting a term updates the term and the content using it.
- If the platform is down, items stay queued and retry on the next cron run.
- If the platform refuses an item, it shows in the log and can be retried.
- If the platform holds an item back, for example over quota, it shows on the
  Synchronization page and is offered again later.

Whatever is not sent right away goes out on cron, so run cron every 5 to 15
minutes.

A full sync also removes old content from the platform. If any item fails during
the run that cleanup is skipped, so a bad run cannot delete good content.

Reconciliation runs every 7 days by default. It compares all content with what
was sent and fixes differences, without resending anything unchanged. Change the
interval on the Connection page, or enter 0 to turn it off.

## Restricted content

Only content anonymous visitors can open is sent by default. With Include
restricted content ticked for a content type, items go out with the roles
allowed to view them. A field is left out if any of those roles cannot view it.

Changing role permissions starts a reconciliation straight after the change.
Other modules that change node access without saving a role are picked up by the
next scheduled reconciliation, or by a full sync.

## Search display

On the Search display tab:

- Provide a search page: turns on the search page. By default it is at
  `/site-search`. Give it another address in each language, such as `/search`
  in English and `/recherche` in French. Drupal adds the language prefix, so
  the French page is at `/fr/recherche`.
- How results are shown: inside your Drupal pages, or the page hosted by
  WahoSearch in a frame. The Free plan only allows the hosted page, and custom
  page addresses come with the paid plans. The module learns the plan from the
  platform when the connection is tested, and again once a day on cron; if the
  platform refuses a search because of the plan, the hosted page is shown
  straight away.
- Let visitors filter and sort the results (on by default, search inside
  Drupal only): a bar above the results to choose smart search or the exact
  words, pages or files, a content type or a topic, and the order (best match,
  newest first, title).
- Suggest searches while visitors type (on by default): a list under the box
  with earlier searches on this site that found something, and titles of pages
  the visitor may see.
- Show the chatbot on public pages.

The WahoSearch box block sends visitors to the search page and can go in any
region. Your theme, menus and language switcher stay in control.

How the search page works:

- The search is sent by this server, signed with the secret. The secret never
  reaches the browser, and the page works without JavaScript.
- The language of the page decides the language of the results: the English
  page shows English content only, the French page French content only. They
  are never mixed.
- Logged-in visitors also see restricted content their roles may view, if that
  content was synced (see Restricted content). Anonymous visitors see public
  content only.
- The platform applies the organization's plan: results per page, searches per
  minute and per month, and priority when busy. Its messages, such as "too many
  searches", are shown to visitors in the language of the page.
- Search pages are never cached, so every search is counted and filtered for
  the visitor.
- Languages other than English and French show a message instead of results.
- When smart search cannot answer (the plan's monthly smart searches are used
  up, or the AI service is busy or down), the platform searches the exact words
  instead, and the page says so above the results. Search never stops.
- Words of the question are marked in the passage under each title, ignoring
  capitals and accents ("etude" marks "Études").
- Filters: the content types and topics offered are the ones in your own
  content that the visitor may see, with counts, sent by the platform with the
  first page of results and kept 10 minutes. Nothing changes until the visitor
  presses Apply, so the bar works without JavaScript. The next pages keep the
  choices.
- Suggestions are asked for by this server too (`/wahosearch/suggest`, signed,
  in the language of the page, with the visitor's roles), at most 60 per minute
  per visitor, and reused for 5 minutes. The list follows the ARIA combobox
  pattern: arrow keys, Enter and Escape work, and screen readers announce how
  many suggestions there are. A search suggestion searches; a page title opens
  the page.
- Clicks: when a visitor opens a result, a small script tells WahoSearch which
  page and its place in the list (`/wahosearch/click`, passed on signed), for
  the reports of the most clicked pages. Nothing about the visitor is sent, and
  opening the result is not slowed down. Without JavaScript the page works the
  same; the click is just not counted.

### Changing how results look

The page uses three Twig templates from the module's `templates` folder:

- `wahosearch-search-page.html.twig`: the box, the messages, the result list
  and the links to more results.
- `wahosearch-search-filters.html.twig`: the filter and sort bar.
- `wahosearch-search-result.html.twig`: one result (title, file type, section,
  passage with the matching words marked, content type, and for files the page
  they are attached to). Keep `data-wahosearch-position` on the title link, and
  `data-wahosearch-click` on the result list in the page template, or clicks
  are not counted in the reports.

To change them, copy either file into your theme's `templates` folder, edit the
copy and clear the cache. Each template lists the variables it receives. The
styles in `css/search-page.css` are kept light, and its colours are CSS custom
properties (`--wahosearch-accent` and others) that a theme can override. To
replace the styles completely, add this to your theme's `.info.yml`:

    libraries-override:
      wahosearch_connector/search_page: false

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

The Paragraphs tests run only when the Paragraphs module is in the codebase
(`composer require drupal/paragraphs`), otherwise they are skipped.

## API

The platform API is described in [docs/API_CONTRACT.md](docs/API_CONTRACT.md).
