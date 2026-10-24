# laravel-mediatheque 2

`folklore/laravel-mediatheque` stores media (video, audio, images, documents, fonts, captions), probes them, produces their renditions through interchangeable transcoders, and serves them through public or temporary URLs.

Version 2 is in development on the `v2.x` branch. Its pilot project is Niche. 1.x stays maintained on `v1.1` for fixes and security.

| Document | Content |
|---|---|
| [current-state.md](current-state.md) | Audit of 1.x: schema, public API, pipelines, known limits |
| [architecture.md](architecture.md) | Design of 2.0: model, ingest, probe, renditions, transcoders, URLs, HTTP, events, testing |
| [decisions/](decisions/) | One file per decision |
| [roadmap.md](roadmap.md) | The plan of 2.0: slices by theme, and the release that shipped each item |
| [upgrade.md](upgrade.md) | Upgrading a project from 1.x (draft, filled as 2.0 lands) |

Tracking issue: [#1](https://github.com/folkloreinc/laravel-mediatheque/issues/1), the index of [roadmap.md](roadmap.md) and of 1.x. The kickoff was [#23](https://github.com/folkloreinc/laravel-mediatheque/issues/23) (closed).

Related work:

- `laravel-image` 2: [folkloreinc/laravel-image#3](https://github.com/folkloreinc/laravel-image/issues/3)
- Eclair and `laravel-eclair`: [urbania-media/eclair#13](https://github.com/urbania-media/eclair/issues/13)
- Panneau 5, whose media libraries are on this package (`MediathequeResource`): [folkloreinc/laravel-panneau#35](https://github.com/folkloreinc/laravel-panneau/issues/35)
- `laravel-folklore` 2, which syncs JSON references into the usage graph: [folkloreinc/laravel-folklore#113](https://github.com/folkloreinc/laravel-folklore/issues/113)
