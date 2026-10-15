# 0001: PHP 8.3+ and Laravel 12+

- Status: accepted
- Date: 2026-10-15

## Context

1.x supports PHP 8.2+ and Laravel 9 to 13, because it serves every active project. A new major line can require more when the projects that will use it are all more recent.

The projects that will use 2.0:

| Group | Laravel | PHP |
|---|---|---|
| The pilot project | 13 | 8.5 |
| Active 1.x projects on Laravel 12 or 13 (about ten) | 12 or 13 | 8.3 to 8.5 |
| Active 1.x projects on Laravel 11 (a few) | 11 | 8.2 or 8.3 |

Projects on Laravel 9 to 11 stay on 1.x until they upgrade Laravel. Laravel 12 gets security fixes until February 2027, PHP 8.3 until the end of 2027. Panneau 5 made the same choice.

## Decision

- 2.x requires **PHP 8.3+** and **Laravel 12+**.
- CI tests PHP 8.3 to 8.5 against Laravel 12 and 13.
- Dropping Laravel 12 or PHP 8.3 happens in a minor release, once no active 2.x project uses them.

## Consequences

- The code uses PHP 8.3 features: enums, readonly properties, typed class constants.
- A 1.x project on Laravel 11 upgrades Laravel first, then the mediatheque.
- 1.x keeps PHP 8.2+ and Laravel 9+, for fixes and security only.
