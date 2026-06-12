# T008 — Placement/scope gate resolution

## Objetivo

Resolver, sem editar código de produto, o bloqueio de placement/scope encontrado em T003 antes de liberar qualquer Worker.

## Owner docs lidos

Foram lidas as aberturas/frontmatter e seções iniciais dos owner docs exigidos pelo bootstrap/placement:

- `docs/engineering-knowledge-base/atlas-ai-knowledge-governance-system.md`
- `docs/engineering-knowledge-base/atlas-ai-canonical-architecture-index.md`
- `docs/engineering-knowledge-base/atlas-ai-core-vs-domain.md`
- `docs/engineering-knowledge-base/atlas-ai-master-architecture.md`

Sinais relevantes desses docs:

- repo docs canônicos governam sobre chat, Obsidian, KB, Postgres e provider projections;
- Postgres KB/Code Intelligence são read models vivos, não fonte autoral primária;
- Evidence Ledger é verdade runtime append-only, mas não substitui specs canônicas;
- toda IA estrutural deve rodar bootstrap e localizar doc dono antes de código;
- não se deve declarar maturidade/prontidão sem evidência verificável e gates verdes;
- Core contém capacidades horizontais; Domains contêm semântica/critério especializado; Surfaces não são lugar de capability reutilizável.

## Placement específico executado

Workspace: `/Users/vitorepf/develop/Atlas/atlas-server`

```bash
/opt/homebrew/bin/php artisan atlas:ai:place-feature "O-1 certification sweep existing Atlas engineering spine: audit Dev pipe provider manager conductor workspace mutating providers, Forge gates, Loop stack drivers, compounding flywheel capture quality gate, and Marco Zero evidence integrity; no new runtime or surface" --json
```

Resultado:

- exit code: `0`
- `schema_version`: `atlas.feature_placement.v1`
- `status`: `ok`
- placement:
  - `layer`: `evidence`
  - `domain`: `programming`
  - `flow`: `programming.dev`
  - `requires_ap`: `false`
  - `status_hint`: `implementation_candidate`
- `gate_status`: `blocked`
- `blocked_when`: `high_overlap_duplicate_candidate_requires_reuse_or_explicit_supersede_decision`

Owner docs encontrados pelo placement:

- `docs/engineering-knowledge-base/atlas-ai-knowledge-governance-system.md`
- `docs/engineering-knowledge-base/atlas-ai-canonical-architecture-index.md`
- `docs/engineering-knowledge-base/atlas-ai-core-vs-domain.md`
- `docs/engineering-knowledge-base/atlas-ai-telemetry-evidence-performance.md`
- `docs/engineering-knowledge-base/domains/programming.md`
- `docs/engineering-knowledge-base/atlas-programming-governance-system.md`

Duplicate review:

- `duplicate_review.status`: `blocking_collision`
- `blocking_candidate_count`: `2`
- blocking candidates:
  - `routes/api.php` — score `788`
  - `tests/Feature/Ai/SelfConstruction/AtlasSelfConstructionOsCompletionAuditTest.php` — score `448`
- reuse context candidates include backlog/docs/historical docs, but only the two code/test paths are hard blockers.

Other gates in the output:

- `documentation_reality_gate.status`: `ready`
- `code_intelligence_automatic_gate.status`: `ready`
- Code Intelligence summary:
  - `module_count`: `23`
  - `symbol_count`: `118099`
  - `doc_link_count`: `170846`
  - `route_count`: `601`
  - `command_count`: `964`
  - `migration_count`: `1447`
  - `test_count`: `23192`
  - `last_indexed_at`: `2026-06-11T19:15:55.000000Z`
- ACRUI anti-duplicate decision: `proceed_with_owner_lookup`, `match_count=0`

## Decisão PM

`still_blocked`.

O placement agora está específico o suficiente para classificar layer/domain/flow, mas ainda bloqueia implementação porque há colisão dura com:

1. `routes/api.php`
2. `tests/Feature/Ai/SelfConstruction/AtlasSelfConstructionOsCompletionAuditTest.php`

Portanto:

- não ativar Worker ainda;
- não editar código/produto ainda;
- criar próxima tarefa read-only para inspecionar essas colisões e decidir se são:
  - falsa colisão por vocabulário genérico;
  - owner/reuse obrigatório;
  - necessidade de escopo mais estreito;
  - decisão `[OPERADOR]` de supersede/override.

## Próxima continuação recomendada

Ativar `T009`: Judge/PM read-only para analisar as duas colisões hard-blocking e, se possível, converter o bloqueio em um Worker seguro com `allowed_files`, `verify` e `stop_if`.
