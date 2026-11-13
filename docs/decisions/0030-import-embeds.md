# 0030: Importing an embed's file

- Status: proposed
- Date: 2026-11-13
- Issue: [#171](https://github.com/folkloreinc/laravel-mediatheque/issues/171)
- Amends: [0029](0029-embeds.md)

## Context

An embed is a media whose original is a platform's page ([0029](0029-embeds.md)): nothing is downloaded, and it plays from its platform. Applications that publish their own videos and audio on these platforms also want their file: to archive it, or to move it into another product, where the platform's page is only where it came from.

Eclair downloads an embed with its `embed.download` operation, for a project that allows it, under a maximum duration and size, and for the providers whose terms the project accepted ([urbania-media/eclair, decision 0022](https://github.com/urbania-media/eclair/blob/main/docs/decisions/0022-embeds.md)). Whether a video may be downloaded (its rights, the account it belongs to) is not something the package or Eclair can tell: it is the application's to check.

Two uses ask for two results. Archiving keeps the embed, playing from its platform, with a copy of its file. Moving it into a product makes it an ordinary video or audio, whose original is the file.

## Decision

- **`Mediatheque::importEmbed($media, $mode, $pipeline)`** imports an embed's file. It runs the import pipeline, `mediatheque.embeds.import_pipeline` or `$pipeline`, forced, so a file imported before is downloaded again. The pipeline has a rendition of `embed.download`, on a transcoder that runs it (Eclair's, through `laravel-eclair`), and may have others that read from it.
- **Two modes**, `ImportMode`:
  - `Rendition` (the default): the file is a rendition of the embed, under the name of its rendition in the pipeline. The media stays an embed, and plays from its platform.
  - `ReplaceOriginal`: once the run completed, its downloaded file replaces the embed's remote original, through the same replacement as `replaceOriginal()`, which may change the type. The media is an ordinary video or audio: it is probed again, then runs its type's pipeline. Its metadata keeps where it came from (`provider`, `provider_id`, `provider_url`); the rest of the provider's metadata goes, until the probe of the file.
- **The run records the import** in its snapshot (`import`: the mode and the rendition of `embed.download`), so a duplicate or late completion is handled once, and `retry()` keeps the mode. `PipelineRunner::run()` and `create()` take this `context`, recorded next to the snapshot.
- **The replacement listens to `PipelineCompleted`**: a run that failed or was canceled leaves the embed as it was. A media that isn't an embed any more (replaced already) is left alone.
- **The application decides.** `importEmbed()` throws for a media that isn't an embed, without an import pipeline, or with a pipeline that has no rendition of `embed.download`. It never checks the rights to download: the application calls it only for its own videos, and its transcoder's project only allows what it may download.

## Consequences

- Archiving and moving a media into another product use the same call, and the same pipeline.
- The `Mediatheque` contract has `importEmbed()`: an application's own implementation adds it (alphas can break, [0001](0001-supported-versions.md)). The fake imports too, at once.
- A transcoder that runs `embed.download` needs the embed's URL as the run's input, not the original's file: `laravel-eclair` sends it for a remote original ([folkloreinc/laravel-eclair#30](https://github.com/folkloreinc/laravel-eclair/issues/30)). The `ffmpeg` driver doesn't run it.
- In `ReplaceOriginal` mode, the media's id, uid and references stay: what referenced the embed now references the file.
