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
{ "rejected": [ { "id": "node:12:fr", "message": "Too large" } ] }
```

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
