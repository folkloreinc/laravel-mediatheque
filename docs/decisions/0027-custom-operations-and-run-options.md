# 0027: Custom operations of the `ffmpeg` driver, and the options of one run

- Status: proposed
- Date: 2026-11-05
- Amends: [0005](0005-asynchronous-transcoders.md), [0012](0012-pipeline-runs-recorder-and-probe.md)

## Context

In 1.x, an application could write a `PipelineJob` subclass that did whatever it needed, often by running ffmpeg with its own arguments, and add its output to the media under a handle. It could also run a one-off pipeline: `Pipeline::fromJobs([...])`, with the job's options built for that run, then `$media->runPipeline($definition)`. A site's AI tool works this way: an agent writes the ffmpeg arguments for a media, a custom job runs them, and the tool reads the pipeline's status until it ends.

The upgrade guide said that such a job becomes a custom operation of the `ffmpeg` driver, registered in `transcoders.ffmpeg.operations`. Nothing reads that key: `FfmpegTranscoder::OPERATIONS` is a constant, and `operation()` throws for any other name. 2.0's `runPipeline()` takes the name of a configured pipeline and rendition names, so a rendition's options are those of the config, the same for every run ([#157](https://github.com/folkloreinc/laravel-mediatheque/issues/157)).

Three answers were weighed:

1. **Read `transcoders.ffmpeg.operations`.** Classes that implement `Transcoders\Ffmpeg\Operation`, the contract of the built-in operations, under names a rendition's `op` uses. The driver already does the rest for them: it copies the input to the worker, gives the operation a temporary directory, writes its outputs under the run's prefix with the media's visibility, probes them, reports progress and failures through the recorder, and cleans up. Alone, it doesn't serve the site's case: the options would still be the config's.
2. **Options for one run.** `runPipeline()` takes options for some of the run's renditions, merged into theirs. The run's JSON snapshot already stores each rendition's definition, so the merged options are recorded with the run. Without the other answer, it only lets a run change the options of the built-in operations.
3. **Say that a custom job stays in the application.** A queued job of the application runs ffmpeg and ingests its output as a new media. It needs nothing from the package, and it is what the site does for now. But the application rebuilds what the driver already does (temporary files, retries, status, progress), its status is not a run that the dashboard and `retry()` know, and its output is a new media rather than a file of the one it was made from.

## Decision

The package does the first two. The third stays the answer when the output is a media of its own.

### Custom operations

- `transcoders.ffmpeg.operations` maps an operation name to a class that implements `Transcoders\Ffmpeg\Operation`, made through the container. A rendition names it in `op`, like a built-in one: `'bumper' => ['op' => 'app.bumper', 'text' => '…']`.
- The config reader validates the key with the rest of the config, so `mediatheque:install` and the first use show a mistake with the key that is wrong: a name is segments of letters, digits, `_` and `-` separated by dots, the class exists and implements the contract, and a name can't replace a built-in operation (`video.transcode`…). A pipeline means the same thing on every transcoder ([0005](0005-asynchronous-transcoders.md)): an application that wants another `video.transcode` names it otherwise. Names in a namespace of the application's (`app.`) are recommended, so that a later built-in operation can't collide with them.
- Only the `ffmpeg` driver runs them. A pipeline that names one on another driver is refused when its run is created, as for any operation its transcoder doesn't support.
- A custom operation can implement `ChecksRequirements`, as the built-in ones do, to fail before its input is copied. It finds binaries through `MediathequeConfig::binary()` or its own config, never a hardcoded path. Its progress weighs one second in the run's progress, like any operation that doesn't transcode the whole input ([0023](0023-progress-of-runs.md)).

### Options of one run

- `Mediatheque::runPipeline($media, $pipeline, $renditions, $force, options: [...])` takes options keyed by rendition name: `['bumper' => ['text' => 'Episode 4']]`. A rendition's options replace the config's options of the same name; others keep the config's value. The merge is shallow: an option whose value is an array replaces the config's array.
- A key that is not a rendition of the pipeline, a value that is not an array of options keyed by name, an option named like a rendition's own keys (`op`, `from`, `when`, `keep`, `queue`, `role`) or a value that JSON can't hold (an object, a resource, `NAN`) throws `InvalidPipeline`, before the run is created.
- **Recorded in the run.** The run's snapshot stores the merged definition of each rendition, which the driver's jobs read, and the run's options under `options`, for the renditions of the run. Everything the run needs is JSON, as before ([0012](0012-pipeline-runs-recorder-and-probe.md)): a queue retry of a job reads the same snapshot.
- **Retried with them.** `retry()` creates the new run with the options of the run it retries, so a failed rendition is produced again as it was asked for, not with the config's options.
- **Idempotent with them.** The merged options are part of the rendition's `definition_hash`. Running the pipeline again with the same options produces nothing for a rendition whose file has that hash. Running it with other options, or with none, produces it again: a later run of the same pipeline without the options replaces the file with the config's definition.
- The options are passed to the operation as they are. An application that builds them from a request, or from an agent, validates them as it would any command it runs; the package only checks that they are JSON. The operation receives its input and output paths from the driver, never from its options.

### When it stays in the application

An output that is a media of its own, which the application lists, attaches and deletes apart from the media it was made from, is ingested as a new media by a job of the application, as the site does: the driver keeps one file per rendition of a media, and a later run of the same rendition replaces it. Derived media ([architecture](../architecture.md#derived-media)) will cover clips when they ship.

## Consequences

- A 1.x custom job moves to an operation class, a pipeline that names it, and a call to `runPipeline()` with its options. Its status is the run's, which the dashboard shows and `retry()` and `cancel()` act on.
- The pipeline of such a rendition is usually not a type's pipeline, so that ingest and `replaceOriginal()` don't run it with the config's options. Its rendition takes a role other than `main` (`'role' => 'edit'`), so that its failure doesn't make the media `failed` ([0014](0014-run-outcomes-and-model-contracts.md)).
- `Mediatheque::runPipeline()` gains a last parameter with a default. Callers are unchanged; a class that implements the `Mediatheque` contract adds it. No package that uses this one implements the contract.
- `FfmpegTranscoder::OPERATIONS` stays the list of built-in operations; `FfmpegTranscoder::operations()` gives them with the application's.
- `laravel-eclair` and the other drivers are unchanged: they receive the merged definitions in the run, as they receive the config's.
