# How to contribute

Atlas Server is a large, fast-moving codebase where an autonomous loop merges changes to main 24/7. Human contributors (and external AI tools) work alongside this loop. The governance rules exist to keep human and AI work from diverging, duplicating, or overriding canonical decisions.

## The context-pack-first approach

Before any implementation, architecture recommendation, or multi-module refactor, call the brain. This is not optional. The codebase has 1,227 commands, 404 models, 330 migrations, and ~1,866 docs. Grepping blindly reinvents context that already exists. The Open Brain indexes this and returns curated, provider-safe context for a task.

```bash
# CLI fallback (auto-scopes workspace from CWD)
php artisan atlas:context-pack "<your task>" --json

# Or the atlas launcher
bin/atlas open-brain context "<your task>" --json
```

If an MCP server is registered (Claude Code, Codex, Cursor), call `atlas_context_pack` through it. The context pack fuses code-graph, reality graph, and semantic memory into one provider-bound assembly.

See [knowledge governance](../concepts/knowledge-governance.md) for why this matters and what the authority hierarchy is.

## Governance gates

Three governance commands structure work in this repo:

### Session bootstrap

Run before any structural work. It returns a docs split plan filtered for the task, so you read the owner docs first:

```bash
php artisan atlas:ai:session-bootstrap --task="<task>" --json
```

### Feature placement

Run before creating a new feature, flow, domain, surface, runtime, or AP. It places the feature in the correct layer:

```bash
php artisan atlas:ai:place-feature "<feature>" --json
```

The placement protocol answers: does this serve several surfaces (Core), is it specialized in one vertical (Domain), does it only collect input or present output (Surface), does it execute a provider or worker (Runtime), does it generate proof or audit (Evidence), or does it learn and calibrate (Learning)?

### Docs authority audit

Run before creating a new name, runtime, OS, engine, factory, layer, or doc macro. If it returns `blocked`, resolve the conflict or declare supersede/reuse first:

```bash
php artisan atlas:ai:docs-authority-audit --json
```

## Definition of done

A change is ready when:

1. It has a clear owner doc or AP.
2. It declares the correct status and verifiable operational state.
3. It does not create a parallel flow.
4. It updates relevant canonical indices (README, START_HERE, canonical index, owner doc).
5. It links docs to code/tests/commands when claiming implementation.
6. It preserves Obsidian as a human surface, not a runtime source.
7. It updates provider projections if the rule impacts external sessions.
8. It runs `docs-health`, `architecture-validate`, `sync`, and `index-code`.

```bash
composer atlas:docs-gate   # runs docs-health + documentation enforcement
```

## Where to start

- [Development workflow](development-workflow.md) — the branch, commit, test, and PR cycle
- [Testing](testing.md) — PHPUnit, paratest, infection, frozen contracts
- [Debugging](debugging.md) — logs, evidence ledger, doctor, health endpoints
- [Tooling](tooling.md) — Pint, Larastan, Scribe, CI workflows, git hooks
- [Patterns and conventions](patterns-and-conventions.md) — DB conventions, ledgers, anti-Goodhart discipline
- [Getting started](../overview/getting-started.md) — install, build, migrate, run
