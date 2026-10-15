# 0010: A canonical JSON resource

- Status: accepted
- Date: 2026-10-15

## Context

1.x has no JSON resource. The shape front ends consume comes from `laravel-folklore`'s `MediaResource`, and applications write their own variants. `files` is a list by default, while `panneau-js` reads it as an object keyed by handle, so video playback only works when the resource is asked for a map. Panneau 5's protocol defines how media items look in fields and lists.

## Decision

- The package ships **`MediaResource`** and **`FileResource`**, the canonical shape of a media.
- `id` is the `uid`. The resource includes `type`, `name`, `status`, `visibility`, `url`, `thumbnail_url`, `metadata` and `files`.
- **`files` is an object keyed by handle**; a group is a list under its handle.
- The shape follows Panneau 5's protocol for media items, and the two are kept in sync through shared fixtures.
- A **`legacy`** option emits the 1.x `laravel-folklore` shape (internal `id`, `files` as a list), for applications during their upgrade.
- Applications extend the resource to add fields, rather than writing their own.

## Consequences

- `laravel-folklore` 2 (and a 1.x minor, if needed) delegates to this resource.
- Front ends that read `files` as a list move to the object shape, or use the `legacy` option until they do.
