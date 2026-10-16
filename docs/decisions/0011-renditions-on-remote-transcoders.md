# 0011: Renditions on remote transcoders

- Status: accepted
- Date: 2026-10-16
- Amends: [0004](0004-renditions.md)

## Context

Eclair reviewed the `Transcoder` contract against its protocol ([comment on #24](https://github.com/folkloreinc/laravel-mediatheque/pull/24#issuecomment-6204765550)). Several rules of [0004](0004-renditions.md) let drivers behave differently, or allow pipelines that work on `ffmpeg` and fail on `eclair`:

- the handle rule allows `1080p` or `_tmp`, but Eclair task names must start with a letter and are at most 64 characters;
- metadata outputs go "to the media's or the file's `metadata`" without saying which;
- Eclair reports metadata in camelCase (`frameRate`, `pageCount`), the media in snake_case (`frame_rate`, `pages_count`), and no list of keys says how one maps to the other;
- a file's `width`, `height`, `duration` and `bitrate` come "from the rendition's probe or from the transcoder's output", without saying which one wins or whether every rendition is probed;
- a failure propagates to the renditions that read from it, but nothing says what a skip does;
- `keep: false` has no recorded outcome;
- `when.kind` doesn't say whether it matches the mediatheque's types (which include `captions` and custom types) or the probe's kinds.

## Decision

- **Handles** match `^[A-Za-z][A-Za-z0-9_-]{0,63}$`: they start with a letter, then letters, digits, `_` and `-`, 64 characters at most. This replaces the rule in 0004.
- **Where metadata outputs go:** a rendition without files writes its metadata outputs to the media's `metadata`; a rendition with files writes them to its main file's `metadata` (index 0 for a group). The rule is the same on every driver; there is no per-rendition setting.
- **Metadata keys** are snake_case, from a canonical list in [architecture.md](../architecture.md#metadata-keys). Each driver and prober maps its own names to it (`frameRate` → `frame_rate`, `pageCount` → `pages_count`). A key that the list doesn't name is stored converted to snake_case.
- **A produced file's `width`, `height`, `duration` and `bitrate`** come from the transcoder's output. When the output doesn't give them, the file is probed for the missing fields. Renditions are not probed otherwise.
- **`skipped` propagates like a failure:** a rendition that reads from a skipped rendition is skipped too, with the skipped input as its reason. A rendition that reads from a failed one still fails, with `dependency_failed`.
- **`keep: false`:** the rendition is recorded as `completed` with no file. Its metadata outputs are written as usual. Renditions of the same run read it; a later run that needs it produces it again.
- **`when.kind`** takes the probe's kinds, which every prober and transcoder share: `image`, `video`, `audio`, `document`, `font`, `other`. It doesn't take the mediatheque's types: a type selects its pipeline, so a condition on the type is not needed inside one. The config reader and `mediatheque:doctor` refuse any other value.

## Consequences

- Rendition names that start with a digit or `_`, or are longer than 64 characters, are refused by the config reader with a clear message. `mediatheque:upgrade --check` lists the 1.x handles that break the rule; the files keep them and stay readable through the compatibility layer, and the application renames the rendition.
- `mediatheque:upgrade` maps the 1.x metadata names to the canonical keys (`font_family_name` → `font_names`, `colors` → `palette`).
- Until Eclair reports `width`, `height`, `duration` and `bitrate` on its output files, the `eclair` driver's renditions are probed after they complete: one more request per file.
- Until Eclair gives files of a group an explicit `index`, the `eclair` driver infers it from the file names.
- Until Eclair gives skipped tasks a reason, the `eclair` driver reports a generic one.
