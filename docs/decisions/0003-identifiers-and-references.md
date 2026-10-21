# 0003: Prefixed ULIDs and `media://` references

- Status: accepted, amended by [0018](0018-ingest-only.md)
- Date: 2026-10-15

## Context

1.x exposes auto-increment ids. They leak volumes, can be enumerated, and change when data moves between databases. Content that refers to a media (rich text, JSON blocks) stores `medias://{id}` (`laravel-folklore`), and some applications store URLs, which break when a file moves or its visibility changes.

The pilot project already uses prefixed ULIDs for its public ids (`med_…`) and `media://` references.

## Decision

- Each media has a **`uid`**: `med_` followed by a ULID (`med_01JAB3…`, 30 characters), unique and indexed. It is generated in memory before the media is saved, so the path formatter can use it.
- `uid` is the route key and the `id` of the JSON resource. The integer `id` stays internal.
- Content refers to a media with **`media://med_…`**. `MediaReference::parse()` and `Mediatheque::resolve()` / `resolveMany()` read it.
- **`medias://{id}`** (1.x) keeps resolving, by internal id, through the compatibility layer.
- Uploads use `upl_` ULIDs. Files keep internal ids; they are reached through their media and handle.
- The prefix is configurable (`ids.media_prefix`) for applications that already have their own convention.

## Consequences

- `mediatheque:upgrade` backfills `uid` for existing media. ULIDs generated for old media use their `created_at`, so they sort in creation order.
- Applications can move from `medias://` to `media://` at their own pace; a helper rewrites references in JSON columns.
- Stored URLs are discouraged in the docs: references are resolved to URLs at render time.
