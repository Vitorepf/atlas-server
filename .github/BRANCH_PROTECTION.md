# Branch Protection Policy

This is a **private, single-tenant** repository. GitHub Pro features (required
status checks, required reviews, push restrictions) are not enabled by
default, but the following rules are enforced by convention and by CI.

## Default branch

- `main` is the default branch.
- All changes flow through pull requests.

## Required checks

The following CI jobs must be green before a PR is merged:

- `Atlas CLI` (tests, final readiness, release preflight)
- `Security gates` (gitleaks, Semgrep, CodeQL)
- `Quality gates` (PHPStan, Pint, coverage, complexity, N+1, flaky detection)

## Merge rules

- Linear history is preferred: rebase or squash merges.
- Merge commits are allowed but not required.
- Autonomous merges (Atlas Loop) are only allowed for certified, re-proven patches.

## Local branch naming

- `feature/<name>` or `feat/<name>` — new capabilities
- `fix/<name>` — bug fixes
- `refactor/<name>` — behavior-preserving restructures
- `chore/<name>` — tooling, CI, deps
- `docs/<name>` — documentation
- `agent-readiness/<name>` — scorecard improvements
