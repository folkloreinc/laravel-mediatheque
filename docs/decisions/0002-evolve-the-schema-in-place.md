# 0002: Evolve the schema in place, with a compatibility layer

- Status: accepted, amended by [0024](0024-upgrade-conversion-rules.md)
- Date: 2026-10-15

## Context

About forty applications use 1.x, fewer than twenty of them actively, most through `laravel-folklore`. They depend on more than the contracts (see [current-state.md](../current-state.md)):

- their own `Media` and `File` subclasses, bound to the contracts;
- the `HasMedias` trait and the `medias_pivot` table;
- `medias://{id}` references stored in their JSON data;
- the table names, which their published migrations created;
- file handles (`original`, `h264`, `thumbnails.0`);
- the model methods (`getFile`, `setOriginalFile`, `getMetadatas`, `runPipeline`).

Three strategies were considered:

1. **Evolve in place**: same tables, a migration that converts them, a deprecated compatibility layer, an upgrade command and Rector rules.
2. **Clean break**: new tables and classes, and a command that copies 1.x data. A cleaner API, but every project rewrites its media code and its stored references.
3. **New projects only**: existing projects stay on 1.x for good.

The implementation behind the tables needs a rewrite either way: serialized definitions, EAV metadata and the pipeline orchestration can't be fixed additively.

## Decision

- **Evolve in place.** 2.0 keeps the table names, the prefix, `medias_pivot` and the internal integer ids. Its migration adds and converts columns; it never renames a table.
- The engine (ingest, probe, pipelines, URLs, HTTP) is rewritten behind the tables.
- A **compatibility layer** (`Folklore\Mediatheque\Compat`) keeps the 1.x API working with deprecation notices: the model methods, the 1.x handles, the `medias://` references and the 1.x config shapes. It is removed in 3.0.
- `mediatheque:upgrade` converts the data that a migration can't convert alone (serialized definitions, EAV metadata, grouped handles), with `--check` to preview.
- Rector rules rename what moved.
- **Target:** `laravel-folklore` 1.1 passes its test suite on mediatheque 2.0 without changes. Its projects can then upgrade the mediatheque without waiting for `laravel-folklore` 2.

## Consequences

- The upgrade is: upgrade Laravel if needed, require `^2.0`, publish and run the migration, run `mediatheque:upgrade`, run the Rector rules, fix the deprecations at their own pace.
- Some 1.x choices stay in the schema: integer ids next to `uid`, the `medias` and `metadatas` spellings, the `pipelines` table name.
- The EAV `metadatas` table is converted, then left in place and unused; `mediatheque:upgrade --cleanup` drops it once the project has checked its data.
- The compatibility layer is tested on its own, with 1.x fixtures, so it does not decay before 3.0.
