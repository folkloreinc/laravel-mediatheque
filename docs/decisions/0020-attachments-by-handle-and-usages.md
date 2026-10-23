# 0020: Attachments by handle, and the usage graph

- Status: accepted
- Date: 2026-10-22

## Context

[The architecture](../architecture.md#attachments-and-usages) plans a `HasMedias` trait for application models, on the 1.x `medias_pivot` table (`media_id`, `morphable`, `handle`, `order`), with `attachMedia()`, `syncMedias()` and `media()`. The pivot is also the usage graph: a media in use can't be deleted unless the deletion is forced.

In 1.x, `HasMedias` only declares the `medias()` relation. Applications write the pivot through the relation (`attach()`, `sync()`), and `laravel-folklore` syncs the media references found in its models' JSON columns (`medias://{id}`) with `$model->medias()->sync($ids)`, which writes rows without a handle and replaces every row of the model.

`laravel-folklore` 2 keeps that sync in its `JsonDataCast`, for `medias://` and `media://` references. Once the pivot is a usage graph that other code writes too (a form attaches a cover with `attachMedia()`), a sync of the whole relation would detach the media that this other code attached. `laravel-folklore`'s spec left this open: how JSON references sync into `medias_pivot` without detaching media attached otherwise.

## Decision

### Schema

- A new migration, `create_mediatheque_medias_pivot_table`, creates `medias_pivot` with the table prefix: `id`, `media_id` (foreign key, cascade on delete), `morphable` (morph, indexed), `handle` (64, nullable), `order`, timestamps, and a unique index on the model, the handle and the media.
- `mediatheque:install` publishes it under the 1.x migration's name, so a project upgraded from 1.x, which has that migration, doesn't get it again. If it runs anyway, it leaves an existing table as it is. The upgrade adds the foreign key once orphan rows are removed ([upgrade.md](../upgrade.md)); until then, the 1.x columns work as they are.
- `handle` stays nullable, as on 1.x tables: rows written by 1.x have none. `HasMedias` itself always writes a handle (see below).

### `HasMedias`

`Folklore\Mediatheque\Concerns\HasMedias`, on Eloquent application models:

| Method | |
|---|---|
| `medias()` | The `MorphToMany` relation to the media, with `handle` and `order` on its pivot, ordered by `order`. 1.x code that uses the relation keeps working |
| `medias($handle)` | The media attached under a handle, in their order, read from the loaded relation: eager loading `medias` serves every handle |
| `media($handle)` | The first of them, or null |
| `attachMedia($media, $handle)` | Attach after the media already under the handle; a media already there stays where it is |
| `syncMedias([$handle => $medias, …])` | Replace the media of each handle given, in the order given; the other handles are left as they are |
| `detachMedia($media, ?$handle)` | Detach from one handle, or from all of them |

- **Writes go by handle.** `syncMedias()` only touches the handles it names, so the code that owns a handle (a form, the sync of a JSON column) never detaches the media another handle holds. An empty list or null clears a handle. There is no method that replaces every handle at once: the relation's own `sync()` still does, for 1.x code.
- **The handle is required.** It says which code owns a row, which is what lets `syncMedias()` replace one owner's rows without touching another's. A row without a handle belongs to no one, so no sync could replace it safely. Media without a particular role go under a generic handle (`medias`). The relation's own `attach()` can still write a row without one, as 1.x code does; that row is a usage, but no method of the trait reads or syncs it alone.
- Media are given as media models or as their keys (the internal `id`, which is what `medias://` references and the pivot hold). Keys that no media has are ignored by `syncMedias()`, as a stale reference in JSON data would be; `attachMedia()` throws `ModelNotFoundException` for one. A trashed media can be attached: a reference to it is kept, and counts as a usage.
- The media of `Mediatheque::fake()` are not stored, so they can't be attached: the trait throws `InvalidArgumentException`.
- A deleted model no longer uses its media: its rows are removed. A soft-deleted model keeps them, and uses its media again when it is restored.

### Usages

- The `Media` contract has `usages()` and `isUsed()`. `usages()` returns a `MediaUsage` per pivot row: the model's morph type and key, the handle, the order, and the model, loaded without its global scopes, as the package's workers load models. A model that uses a media under two handles has two usages. The model is null when it no longer exists (a row an upgraded table still holds); the row still counts.
- The fake's media take their usages from tests (`$media->usages[] = new MediaUsage(…)`).

### Deleting a media in use

- `$media->delete()` throws `MediaInUse` when the media is used, before any event, and nothing is deleted. The application detaches it first, or forces the deletion.
- `$media->forceDelete()` deletes it whatever its usages, and removes its pivot rows: the foreign key does it on a new install, the model does it on a table upgraded from 1.x, which has no foreign key yet.
- Deletions through a query (`Media::query()->delete()`) don't load models, and are not checked.

### Syncing references from JSON data (`laravel-folklore`)

`laravel-folklore`'s `JsonDataCast` syncs the references of each JSON column under a handle of its own, `json:{column}`, with `syncMedias()` instead of the relation's `sync()`:

```php
// After the model is saved, for each JSON column with media references
$model->syncMedias(['json:data' => $mediaIds]);    // the media keys its references resolve to, in their order
```

- It only replaces its own rows: a cover attached with `attachMedia($media, 'cover')` stays, and so do the rows of another JSON column.
- The references of `medias://{id}` and `media://med_…` are resolved to media keys first (`Mediatheque::resolveMany()`), and synced together.
- The pivot's `order` is the order of the references in the column.
- A column without references syncs an empty list, which clears its handle.
- A sync that gathers the references of several columns together can use one handle (`json`) the same way: what matters is that no other code writes under it.
- Rows that 1.x wrote for JSON references have no handle, and the first sync under `json:{column}` leaves them. Only `laravel-folklore` knows which rows came from JSON data, so its upgrade decides what becomes of them (for example, removes them before the first sync).

## Consequences

- The media of a model can be read by role (`media('cover')`) in one query for every handle, and written by several pieces of code without one undoing the other.
- Every attachment is a usage, so the library can show where a media is used and refuse to delete it. An application that let users delete any media detaches it first, or forces the deletion, which removes it from the content that used it.
- A project on an earlier alpha runs `mediatheque:install` and `migrate` before deleting a media: `delete()` reads `medias_pivot`, which must exist.
- `mediatheque:prune` (alpha 2) only force-deletes trashed media; a trashed media that a sync attached again is in use, and prune has to decide whether to keep it.
