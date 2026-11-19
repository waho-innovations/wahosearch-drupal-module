# Connector API contract (v1)

This is the API the Drupal module calls on the SaaS platform. The Laravel side
must implement these endpoints. The module has been tested against a mock of
this contract.

Base path: `{platform URL}/api/v1/`. All bodies are JSON (UTF-8).

## Authentication

Every request carries these headers:

| Header | Value |
|---|---|
| `X-Site-Id` | The website ID issued by the platform |
| `X-Timestamp` | Unix time of the request, in seconds |
| `X-Signature` | Lowercase hex HMAC-SHA256, see below |
| `X-Connector-Version` | For example `drupal/1.0.0` |

The signature is computed with the website's secret over this string, using
real line feeds:

```
{timestamp}\n{METHOD}\n{path}\n{raw body}
```

`path` is the URL path only, for example `/api/v1/content/bulk`. For a GET the
body is the empty string. The secret is never sent.

The platform must:

1. Reject requests whose timestamp is more than 5 minutes from server time.
2. Compare signatures in constant time.
3. Scope every read and write to the website identified by `X-Site-Id`.

## Status codes

| Code | What the module does |
|---|---|
| 2xx | Success |
| 408, 429, 5xx, network error | Keeps the item queued and retries later |
| Any other 4xx | Marks the item as failed and shows it in the log |

Error responses may include `{"message": "..."}`, which is shown in the log.
Do not put secrets or stack traces in it. Redirects are not followed.

## Endpoints

### `GET connector/ping`

Checks the credentials. Optional response: `{"site_name": "Example site"}`.

### `POST content/bulk`

Creates or replaces documents by `id`. Must be idempotent.

```json
{ "sync_id": "only during a full sync", "documents": [ { } ] }
```

Optional response, to refuse single documents without failing the others:

```json
{
  "rejected": [ { "id": "node:12:fr", "message": "Too large" } ],
  "deferred": [ { "id": "node:13:en", "message": "Page quota reached" } ],
  "need_files": [ "node:14:en:file:44" ]
}
```

- `rejected` is a failure. It shows in the log and can be retried.
- `deferred` is not a failure and does not stop a full sync from finishing.
  Use it for quota limits and for PDFs with no extractable text. The module
  offers these documents again on later runs.
- `need_files` lists PDF documents whose contents the platform needs. Leave out
  any document whose `file.sha256` it already holds.

### `POST content/file`

Sent once for each ID in `need_files`, with a 120 second timeout.

```json
{
  "id": "node:14:en:file:44",
  "filename": "handbook.pdf",
  "mime": "application/pdf",
  "sha256": "...",
  "content_base64": "..."
}
```

Verify `sha256` before storing the file.

### `POST content/delete`

```json
{ "ids": ["node:12:en", "node:12:fr"] }
```

Deleting an ID that does not exist must succeed.

### `POST taxonomy/bulk`

```json
{
  "sync_id": "only during a full sync",
  "terms": [
    {
      "id": "taxonomy_term:5",
      "tid": 5,
      "uuid": "...",
      "vocabulary": "topics",
      "vocabulary_label": "Topics",
      "names": { "en": "Scholarships", "fr": "Bourses" },
      "parents": [2],
      "weight": 0
    }
  ]
}
```

### `POST taxonomy/delete`

```json
{ "ids": ["taxonomy_term:5"] }
```

### `POST sync/full/start`

Body: `{"started": 1800000000}`. Response must include `{"sync_id": "..."}`.

### `POST sync/full/finish`

Body: `{"sync_id": "..."}`. The platform removes every document and term of
this website that was not received with this `sync_id`.

The module only calls this when every item of the run was delivered. An
unfinished run must change nothing except the documents it sent.

### `POST search`

Sent when a visitor searches on the Drupal search page. Times out after 10
seconds.

```json
{"query": "logement des souris", "langcode": "fr", "roles": ["authenticated", "member"], "page": 1}
```

- `langcode` is the language of the page. Results must be in that language
  only.
- `roles` are the visitor's roles, empty for anonymous visitors. Only public
  content and content allowed for one of these roles may be returned.
- `page` is 1 to 20.

Response:

```json
{
  "has_more": true,
  "results": [
    {"id": "node:412:fr:file:977", "type": "file", "title": "...", "url": "https://...",
     "snippet": "...", "heading": ["..."], "content_type": "Document", "tags": [],
     "parent": {"id": "node:412:fr", "title": "...", "url": "https://..."}}
  ]
}
```

Errors are `{"code": "...", "message": "..."}` with `message` in the language
of the request. `403 hosted_page_only` means the plan (Free) only allows the
hosted page: the module then shows `{platform URL}/embed/{site ID}/search` in a
frame instead.

`GET connector/ping` also returns `plan` and `search_page` (`drupal` or
`hosted`), which the module keeps to choose the page. The module shows `message` to visitors for `rate_limited`,
`search_limit_reached`, `search_busy` and `empty_query`, and a general message
for anything else.

## Chatbot

When an administrator switches it on, Drupal loads
`{platform URL}/widget/chatbot.js` on public pages. The script tag has
`data-site-id` and `data-language`. The secret is never given to the browser.

## Document format

One document per published translation.

```json
{
  "id": "node:12:fr",
  "entity_type": "node",
  "entity_id": 12,
  "uuid": "...",
  "bundle": "article",
  "bundle_label": "Article",
  "langcode": "fr",
  "is_default_translation": false,
  "translations": { "en": "node:12:en", "fr": "node:12:fr" },
  "title": "Bourses en ingenierie",
  "url": "https://example.ca/fr/node/12",
  "created": "2026-11-08T10:00:00+00:00",
  "changed": "2026-11-08T11:00:00+00:00",
  "fields": {
    "body": {
      "type": "text_with_summary",
      "label": "Body",
      "role": "content",
      "values": [ { "html": "<p>...</p>", "text": "...", "summary": "..." } ]
    }
  },
  "taxonomy": [
    {
      "field": "topics",
      "tid": 5,
      "uuid": "...",
      "vocabulary": "topics",
      "name": "Bourses",
      "ancestors": [ { "tid": 2, "name": "Enseignement" } ]
    }
  ],
  "access": { "public": true, "roles": ["anonymous", "authenticated", "editor"] },
  "hash": "sha256 of the content, without the changed date"
}
```

`role` is one of `content`, `summary` or `metadata`.

A document can also carry `priority_section`, for example
`{"pattern": "/admissions/*", "rank": 1}`, where rank 1 is the most important.
It only ever applies to whole sections. Treat it as a capped signal inside
relevance scoring, and when choosing what to index first under a quota.

### PDF documents

A PDF is its own document with the same access as the page it is attached to.
Web pages have no `document_type` key.

```json
{
  "id": "node:14:en:file:44",
  "document_type": "pdf",
  "entity_type": "node",
  "entity_id": 14,
  "uuid": "...",
  "bundle": "page",
  "bundle_label": "Basic page",
  "langcode": "en",
  "title": "Admissions handbook",
  "url": "https://example.ca/sites/default/files/handbook.pdf",
  "created": "2026-11-08T10:00:00+00:00",
  "changed": "2026-11-08T11:00:00+00:00",
  "parent": { "id": "node:14:en", "title": "Admissions", "url": "https://example.ca/admissions" },
  "file": { "fid": 44, "filename": "handbook.pdf", "mime": "application/pdf", "size": 120400, "sha256": "..." },
  "taxonomy": [],
  "access": { "public": true, "roles": ["anonymous", "authenticated"] },
  "hash": "..."
}
```

The platform extracts the text. The module only sends files with the PDF MIME
type, and it cannot tell whether a PDF is scanned.

### Value shape by field type

| Field type | Each value |
|---|---|
| `text`, `text_long`, `text_with_summary` | `{html, text, summary?}` |
| `string`, `string_long`, `email`, `telephone`, `datetime` | string |
| `list_string`, `list_integer`, `list_float` | `{value, label}` |
| `integer` / `decimal`, `float` / `boolean` | number / number / boolean |
| `timestamp` | ISO 8601 string |
| `daterange` | `{start, end}` |
| `link` | `{url, title}` |
| `image` | `{url, filename, mime, alt}` |
| `file` | `{url, filename, mime}` |
| `entity_reference` (not taxonomy) | `{target_type, target_id, label}` |

### Access

`access.roles` lists every Drupal role allowed to view the document, and
`access.public` is true when anonymous visitors may view it.

The platform must apply these before any content reaches search results or
generated answers. Restricted content is only sent if an administrator ticks
"Include restricted content" for that content type.
