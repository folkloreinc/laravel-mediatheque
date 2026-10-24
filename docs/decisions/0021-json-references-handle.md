# 0021: JSON references sync under one handle, `json`

- Status: accepted
- Date: 2026-10-23
- Amends: [0020](0020-attachments-by-handle-and-usages.md)

## Context

[0020](0020-attachments-by-handle-and-usages.md) has `laravel-folklore`'s `JsonDataCast` sync the media references of each JSON column under a handle of its own, `json:{column}`, and allows one handle, `json`, for a sync that gathers the references of several columns.

The cast was built that second way. It gathers the references of a relation across every JSON column of a model before it syncs, as 1.x did, so a reference is synced once per relation, not once per column. `laravel-json-data`, where the cast now lives, and `laravel-folklore` 2's medias module sync under `json` (`JsonDataCast::MEDIAS_HANDLE`, `MediaUsagesSync::HANDLE`), and the pilot project relies on it. This package's docs still said `json:{column}` ([#104](https://github.com/folkloreinc/laravel-mediatheque/issues/104)).

## Decision

- **JSON references sync under one handle, `json`**, for every JSON column of a model: `$model->syncMedias(['json' => $mediaIds])`.
- Everything else in 0020 stays: the sync only replaces its own rows, keeps the order of the references, and clears the handle when no reference is left. Rows that 1.x wrote without a handle are left to `laravel-folklore`'s upgrade.
- No other code writes under `json`.

## Consequences

- The usage graph doesn't say which JSON column of a model uses a media, only that the model does. `laravel-json-data`'s reference listing (`JsonData::references()`) gives the column and the path when that is needed.
- The architecture and `HasMedias`' docblock use `json`. 0020 keeps its text, with a note that this decision amends it.
