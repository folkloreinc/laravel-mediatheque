# 0004: Renditions: groups, metadata outputs, remote renditions

- Status: accepted
- Date: 2026-10-15

## Context

In 1.x, a pipeline job's output is attached under the job's name, and a job with several files attaches them as `{name}.{index}` (`thumbnails.0`). Consumers find thumbnails by matching handles with a regex. Nothing records what produced a file, so a rerun can't tell what is already done.

The transcoders 2.0 targets produce more than single files:

- multi-part outputs (an HLS playlist and its segments, a thumbnail series, a storyboard and its VTT);
- outputs that are not files (palette, blurhash, scenes, a transcript's words);
- skipped outputs, when a condition does not match the input;
- with hosted video services, renditions that live with the provider and are played from its URLs.

Eclair names outputs after task names that can't contain dots.

## Decision

- A file has a **`handle`** (letters, digits, `_`, `-`, no dots) and an optional **`index`** for files in a group. `thumbnails.0` becomes `thumbnails` with index 0.
- A file has a **`role`** (`main`, `thumbnail`, `poster`, `captions`, `playlist`…). `$media->thumbnail()` uses it, so consumers stop matching handles.
- A multi-part rendition stores its **main file** (`master.m3u8`) and `parts_count`; its parts sit next to it on the source.
- A file records its **`definition_hash`** and the **pipeline job** that produced it. A rendition whose definition did not change is not produced again.
- **Metadata outputs** are written to the media's or the file's `metadata` JSON.
- **`skipped`** is a job status, not a failure.
- A file can be **remote** (`provider`, `provider_ref`, no source or path). The column exists in 2.0; the first driver that uses it (Cloudflare Stream) comes in 2.x.

## Consequences

- `mediatheque:upgrade` converts `name.index` handles into handle and index, and keeps the 1.x handle readable through the compatibility layer (`getFile('thumbnails.0')`).
- Rendition names in configs must follow the handle rule; the config reader rejects dots with a clear message.
- The JSON resource exposes groups as lists under their handle.
