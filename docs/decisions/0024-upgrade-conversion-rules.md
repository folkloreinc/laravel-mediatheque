# 0024: How the upgrade from 1.x converts the data

- Status: proposed
- Date: 2026-10-29
- Amends: [0002](0002-evolve-the-schema-in-place.md)

## Context

[0002](0002-evolve-the-schema-in-place.md) evolves the 1.x tables in place: a migration adds columns, `mediatheque:upgrade` converts what a migration can't, with `--check` to preview, and the 1.x config keeps working. It leaves open what the conversion does with what 1.x data holds and 2.0 has no place for ([#139](https://github.com/folkloreinc/laravel-mediatheque/issues/139)):

- 1.x tables have rows that no 2.0 rule allows: `null` handles, runs that never ended, files attached to other models, rows of deleted media.
- 1.x morph types are the class names of the 1.x models, or their alias, which may not be the class the application configures in 2.0.
- 1.x stored pipeline definitions with `serialize()`, as objects of classes 2.0 doesn't have.
- A 1.x config file was usually published whole, so most of its values are 1.x defaults rather than the application's choices, and its sources are not Laravel disks.

A project upgrading now has about 3,300 media in tables of 2017–2018; about forty others follow.

## Decision

### The migration

- `mediatheque:install --upgrade` publishes `upgrade_mediatheque_tables_from_1x` instead of `create_mediatheque_tables`, then the other migrations. The two stand for each other: an application that has one never gets the other, and `mediatheque:install` without `--upgrade` refuses a database whose `medias` table has no `uid`. The 1.x config is kept: `--config` rewrites it.
- The migration **only adds columns** and widens two (`files.mime` to 255 characters, `files.size` to 64 bits). It renames nothing, converts no data, and leaves a column the table has already. Columns that every row of a new install has (`uid`, a run's `transcoder`, `output_source`) are **nullable** on upgraded tables: existing rows get them from the upgrade, and the models set them on every new row. Foreign keys that existing rows can't break (`derived_from_id`, `pipeline_job_id`, all null) are added by the migration; the others by the upgrade.

### The conversion

- **Each step converts the rows it hasn't converted**, in batches of `--batch` rows, one transaction per batch: media and runs without a `uid`, files without a `media_id`. An interrupted upgrade resumes; running it again changes nothing. Most 1.x columns are only read, and stay until 3.0; those that are 2.0 columns too are overwritten: `files.handle` (split), and a run's `definition` (the serialized value is replaced by the JSON snapshot), `name`, `pipelinable_type` and `progress`. Going back to 1.x means restoring the backup: the migration's `down()` doesn't undo a conversion.
- **Morph types.** The rows of media are those whose type is the configured media model's morph class, its class name, or 1.x's `Folklore\Mediatheque\Models\Media`, and the same for files. `--media-morph` and `--file-morph` add others; `--check` lists the morph types it doesn't recognize. **The conversion refuses to start while `metadatas` has rows of a morph type it doesn't recognize**, unless `--accept-unknown-morphs` says to convert without them: once a media has its `uid`, its metadata is never copied again. A run gets the media's current morph class.
- **Files.** A file without a `source` gets the default source (`config('mediatheque.source')`): 1.x left the column empty when it stored a file there ([#144](https://github.com/folkloreinc/laravel-mediatheque/issues/144)). 2.0 doesn't read an empty source as the default one: the URL generator, deletions and visibility changes act on the disk a file names, and the upgrade writes it. A file belongs to the first media that `files_pivot` attaches it to. Its handle is its own, else its pivot's, split into handle and index (`thumbnails.0`). Its role comes from its handle: `original`, `thumbnail` for handles starting with `thumbnail`, `poster`, else `main`. Its EAV metadata goes to `width`, `height` and `duration`, and the rest to `metadata`. A handle that breaks the 2.0 rule stays as it is: `--check` lists it.
- **Media.** A `uid` made at their creation date, so it sorts with them. `public`. `ready`, or `failed` when their last 1.x run failed. Their metadata in JSON with the canonical keys (`font_family_name` → `font_names`, as a list; `colors` → `palette`), plus `kind` from their type and the original's `filename`, `mime` and `size`, which 2.0's conditions read.
- **Runs.** `completed` or `failed` from the 1.x booleans, and **`canceled` for a run or a job that hadn't ended**: the 1.x queue jobs that would have ended them don't exist in 2.0. A failure is `operation_failed`, with the 1.x exception as its message. The definition becomes a JSON snapshot of the renditions the run ran: the operation of each of the package's 1.x jobs, or the operation `v1.job` with the job's class and options for a custom job, `WebFonts` and `MediaConvert`. The renditions come from the serialized definition, and from the jobs' own JSON definitions when it can't be read. The transcoder is `ffmpeg`.
- **Serialized definitions are read with `allowed_classes: false`**: no object is created, so a stored value runs no code, and the properties are read from the incomplete object.
- **Orphan rows are removed**, then the foreign keys added: attachments (`medias_pivot`) and runs of deleted media, jobs of deleted runs, and files that no media and no other model attaches. Stored files are never deleted. A file that `files_pivot` attaches to other models only is kept, without a media: `files.media_id` stays nullable on upgraded tables. `metadatas` and `files_pivot` are left as they are. `--cleanup` drops `metadatas` once every media and run is converted, and **refuses while it holds metadata that no converted media or file has**: of an unrecognized morph type, of a media not converted, of a file without a media. Rows of deleted media and files don't hold it back.
- Once every run is converted, the 1.x `definition` column becomes `json` (`jsonb` on PostgreSQL).

### The config

- A config of the 1.x shape is **read in the 2.0 shape when the package boots**, with a deprecation notice. Its values equal to the 1.x defaults are left out, so the 2.0 defaults apply. The others are translated: a type's mimes become patterns (minus `application/octet-stream`), a pipeline's jobs become the renditions the `ffmpeg` driver can produce, `uploads.max_size`, `process_timeout`, `jobs` and the ffmpeg binaries move to their 2.0 keys. Routes, metadata readers, services and a 1.x `file_path_format` are dropped.
- **A 1.x source becomes a Laravel disk of the same name**, unless the application defines one: files keep the `source` they were stored with, and resolve as they did. A local source is a local disk at its path, a `filesystem` source its disk under its path, and the source's `url` is the disk's.
- **A disk of the same name that doesn't store files as the source did is a conflict**: another driver, root or URL (a relative URL compares by path). Laravel always defines `public` (`storage/app/public`, `/storage`), while the default `public` source of 1.x in 2017 and 2018 was `public/files`, `/files`. The disk is left as it is, and the conflict is logged as a warning when the package boots, and listed by `--check` and `--config`: the application defines the disk as the source was.
- `mediatheque:upgrade --config` writes that translation to `config/mediatheque.php`, without the values equal to the 2.0 defaults and with the `env()` calls of the 1.x defaults it kept, keeps the 1.x file in `config/mediatheque.php.1x.bak`, and prints the disks to add to `config/filesystems.php` and what it dropped.

## Consequences

- A project upgrades with its queue drained: runs in progress are canceled, and can be run again with `Mediatheque::runPipeline()`.
- Upgraded tables differ from a new install's: nullable `uid` and run columns, a nullable `files.media_id`, 255-character `files.path`, and the 1.x columns. The models don't depend on the difference.
- The history of runs keeps what 1.x ran, custom jobs included, without a transcoder that can run it again: retrying a 1.x run runs the renditions the 2.0 pipeline has.
- Roles are guessed from handles: a rendition whose handle doesn't say what it is gets `main`.
- A project whose 1.x config was published whole gets the 2.0 defaults: the 1.x video pipeline's `webm` and `hevc` renditions are not produced for new media unless the project chose them.
