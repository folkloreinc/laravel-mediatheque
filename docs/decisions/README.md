# Decisions

One file per decision. A decision is not edited once accepted: a later one replaces it and links to it.

| # | Decision | Status |
|---|---|---|
| [0001](0001-supported-versions.md) | PHP 8.3+ and Laravel 12+ | Accepted |
| [0002](0002-evolve-the-schema-in-place.md) | Evolve the schema in place, with a compatibility layer | Accepted |
| [0003](0003-identifiers-and-references.md) | Prefixed ULIDs and `media://` references | Accepted |
| [0004](0004-renditions.md) | Renditions: groups, metadata outputs, remote renditions | Accepted |
| [0005](0005-asynchronous-transcoders.md) | An asynchronous `Transcoder` contract and declarative renditions | Accepted |
| [0006](0006-ingest-and-uploads.md) | One ingest step, with optional upload adapters | Accepted |
| [0007](0007-visibility-and-urls.md) | Per-media visibility and temporary URLs | Accepted |
| [0008](0008-secure-http.md) | HTTP routes are opt-in and authorized | Accepted |
| [0009](0009-package-boundaries.md) | Package boundaries | Accepted |
| [0010](0010-json-resource.md) | A canonical JSON resource | Accepted |

Template:

```markdown
# NNNN: Title

- Status: proposed | accepted | replaced by NNNN
- Date: YYYY-MM-DD

## Context
## Decision
## Consequences
```
