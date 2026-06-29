# Development workflow

## The atlas CLI

Day-to-day work uses the `atlas` CLI (`bin/atlas`), a thin bash launcher that resolves Homebrew PHP 8.4+, injects the caller's working directory as `--workspace`, and dispatches to artisan commands. The launcher is the single entry point for the local terminal product.

```bash
# One-shot question
atlas ask "how does the AI Gateway select a provider?"

# Dev Cockpit (no task = interactive, with task = plan to token to run)
atlas dev
atlas dev "add a health check to the finance strategy loop"

# Max-power profile
atlas forge "refactor the merge governor for clarity"

# Plan only, no execution
atlas plan "wire the new sensor into the capture pipeline"
```

The CLI preserves context, decisions, memory, permissions, and traces across provider changes. "Atlas is the surface; Claude, Codex, Gemini are interchangeable engines."

## Plan to token to run

The Atlas Dev Efficient pipeline (`config/atlas_dev.php`) is the canonical work flow. With a task, `atlas dev` runs:

1. **Preflight and plan** — Atlas builds a plan and prints it. It stops here unless you pass `--yes`.
2. **Confirmation token** — a short-lived token confirms you reviewed the plan.
3. **Run** — Atlas dispatches the plan through the AI Gateway (provider selection, model resolution, prompt build), streams the result with a phase ribbon, and runs the quality gate.

This three-step flow prevents blind execution. You see the plan before the work starts.

## Governance before code

Before writing code, run the governance gates described in [how to contribute](index.md):

```bash
php artisan atlas:ai:session-bootstrap --task="<task>" --json
php artisan atlas:ai:place-feature "<feature>" --json
```

Then read the owner docs the bootstrap returns. If you skip this, you risk duplicating a capability that already exists or ignoring a decision already made.

## Branch and commit

Atlas works on a shared local main. The autonomous loop merges certified changes to main directly. Human contributors can branch for larger work:

```bash
git checkout -b feature/my-feature
# ... work ...
git add -A
git commit -m "finance(strategy): add risk-adjusted position sizing to the strategy loop

Co-authored-by: factory-droid[bot] <138933559+factory-droid[bot]@users.noreply.github.com>"
```

### Commit style

Commits follow `subsystem(scope): description`. The subsystem is the area (finance, brain, loop, engineering, cli). The scope is the specific module or feature. The description is imperative and concise.

```
loop(merge): add staleness refuser to the merge governor
brain(seed): wire cycle capsule into the live surface
finance(strategy): add campaign runner command
```

AI-authored commits include a co-author line. The autonomous loop's commits carry `merged_sha` evidence proving the change was certified and merged.

## Test before pushing

```bash
php artisan test              # full suite
vendor/bin/paratest           # parallel
./vendor/bin/pint --test      # formatting check
```

See [testing](testing.md) for the full testing setup.

## The PR cycle

This is a solo-operator repo with an autonomous loop. There is no traditional PR review queue for human work. The loop itself uses a frozen out-of-process judge instead of a human reviewer. For human work, the flow is:

1. Branch or work on main (the loop merges only certified, re-proven changes).
2. Run tests and quality gates.
3. Commit with the proper style.
4. Push if working across machines.

The autonomous loop's merge process is separate and governed by [quality gates and certification](../systems/evolution-loop/quality-gates-and-certification.md).

## Related pages

- [How to contribute](index.md) — governance gates and definition of done
- [Testing](testing.md) — the full test suite
- [Tooling](tooling.md) — Pint, Larastan, CI workflows
- [Patterns and conventions](patterns-and-conventions.md) — DB and ledger conventions
- [Getting started](../overview/getting-started.md) — install and run
