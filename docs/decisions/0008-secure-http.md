# 0008: HTTP routes are opt-in and authorized

- Status: accepted
- Date: 2026-10-15

## Context

1.x loads its routes by default; their hardening is tracked privately. Applications that don't want them must know to set `routes.map` to `null`. Most applications upload through their own controllers anyway.

Panneau 5 made the same choice for its own routes.

## Decision

- The package registers **no route by default**. An application calls `Route::mediatheque()` in its own routes file, inside its own middleware group, and can pick the routes it wants.
- Every action is authorized through a **policy on the media model** (`create`, `view`, `update`, `delete`). Without a policy, every action is denied.
- Requests are validated. Only validated attributes are written.
- Responses use the JSON resource ([0010](0010-json-resource.md)); errors use `application/problem+json`.
- The package's tests assert that a fresh install exposes no route.

## Consequences

- 1.x applications that relied on the default routes register them explicitly and write a policy ([upgrade.md](../upgrade.md)).
- Panneau 5's media adapter uses the same policy.
