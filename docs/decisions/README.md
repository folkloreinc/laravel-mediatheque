# Decisions

One file per decision. A decision is not edited once accepted: a later one replaces or amends it and links to it, and only the earlier one's status changes.

| # | Decision | Status |
|---|---|---|
| [0001](0001-supported-versions.md) | PHP 8.3+ and Laravel 12+ | Accepted |
| [0002](0002-evolve-the-schema-in-place.md) | Evolve the schema in place, with a compatibility layer | Accepted |
| [0003](0003-identifiers-and-references.md) | Prefixed ULIDs and `media://` references | Amended by 0018 |
| [0004](0004-renditions.md) | Renditions: groups, metadata outputs, remote renditions | Amended by 0011 |
| [0005](0005-asynchronous-transcoders.md) | An asynchronous `Transcoder` contract and declarative renditions | Amended by 0012 |
| [0006](0006-ingest-and-uploads.md) | One ingest step, with optional upload adapters | Amended by 0018 |
| [0007](0007-visibility-and-urls.md) | Per-media visibility and temporary URLs | Accepted |
| [0008](0008-secure-http.md) | HTTP routes are opt-in and authorized | Accepted |
| [0009](0009-package-boundaries.md) | Package boundaries | Accepted |
| [0010](0010-json-resource.md) | A canonical JSON resource | Accepted |
| [0011](0011-renditions-on-remote-transcoders.md) | Renditions on remote transcoders | Accepted |
| [0012](0012-pipeline-runs-recorder-and-probe.md) | Pipeline runs, the recorder and an asynchronous probe | Amended by 0014 and 0017 |
| [0013](0013-media-resolver-for-laravel-image.md) | The `media` resolver for `laravel-image` | Accepted |
| [0014](0014-run-outcomes-and-model-contracts.md) | How runs end, the media's status, and model contracts | Accepted |
| [0015](0015-new-install-schema-attributes-and-uploads.md) | The schema of a new install, application attributes, and uploads | Amended by 0016 and 0018 |
| [0016](0016-uploads-signed-for-uppy-6.md) | Direct uploads signed for Uppy 6 | Replaced by 0018 |
| [0017](0017-monitoring-dashboard.md) | A monitoring dashboard | Amended by 0018 |
| [0018](0018-ingest-only.md) | The package ingests files; applications take uploads | Accepted |
| [0019](0019-replace-original.md) | Replacing a media's original | Accepted |
| [0020](0020-attachments-by-handle-and-usages.md) | Attachments by handle, and the usage graph | Accepted, amended by 0021 |
| [0021](0021-json-references-handle.md) | JSON references sync under one handle, `json` | Accepted |

Template:

```markdown
# NNNN: Title

- Status: proposed | accepted | accepted, amended by NNNN | replaced by NNNN
- Date: YYYY-MM-DD
- Amends: NNNN (optional)

## Context
## Decision
## Consequences
```
