---
id: atlas-ai-self-construction-command-surface-catalog
type: engineering_knowledge
title: Atlas Self-Construction OS — Command Surface Catalog
status: active
category: reference
priority: 70
summary: Detailed read-only command surface catalog extracted from the Self-Construction OS parent doc so the parent stays compact while every governed runtime command remains discoverable.
tags:
  - atlas-ai
  - self-construction
  - command-surface
  - read-only
  - reference
capabilities:
  - self_construction_command_catalog
  - self_construction_runtime_map
decisions:
  - The detailed Current Runtime Surface command table lives here; the parent doc holds the compact architectural overview and links here.
  - Every command in this catalog is read-only with execution_allowed=false; names that mention operator/human/Codex describe bootstrap and receipt chains, not permanent dependencies.
maintenance:
  - Update whenever a new --json projection is added to AtlasAiSelfConstructionCommand or any of its receipt/dispatch chains.
  - Re-link from the parent atlas-ai-self-construction-os.md whenever the section is renamed or split further.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-self-construction-os.md
  - docs/engineering-knowledge-base/self-construction/constitution.md
  - docs/engineering-knowledge-base/atlas-canonical-module-doc-v1.md
owner: atlas-ai
layer: 0.8-self-construction
line_limit: 260
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-ai-self-construction-command-surface-catalog
graph_title: Atlas Self-Construction OS - Command Surface Catalog
graph_world: atlas
graph_layer: gear
graph_kind: reference
graph_parent: atlas-ai-self-construction-os
graph_status: active
graph_source: repo
human_name: Atlas Self-Construction OS - Command Surface Catalog
canonical_name: Atlas Self-Construction OS - Command Surface Catalog
technical_name: atlas-ai-self-construction-command-surface-catalog
cartography_type: reference
canonical_source: docs/engineering-knowledge-base/self-construction/command-surface-catalog.md
repo_paths:
  - docs/engineering-knowledge-base/self-construction/command-surface-catalog.md
allowed_changes:
  - Atualizar este catalogo quando uma nova projeção `--json` da família AtlasAiSelfConstructionCommand for adicionada ou renomeada.
forbidden_changes:
  - Listar superfície que execute, persista receipts, escreva ledger, inicie provider ou mute estado de runtime.
depends_on:
  - atlas-ai-self-construction-os
flows_to:
  - atlas-cartography
unlocks:
  - ai-safe-implementation-context
governs:
  - self-construction
evidence:
  - docs/engineering-knowledge-base/self-construction/command-surface-catalog.md
evidence_refs:
  - command: atlas:ai:self-construction
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
requires_evidence: true
risk_level: low
visual_tags:
  - gear
  - reference
  - self-construction
ai_entrypoints:
  - Leia Current Runtime Surface para o nome exato de cada projeção `--json`.
ai_usage_notes:
  - Toda superfície aqui mantém execution_allowed=false; mutação real exige Decision Receipt fora deste catálogo.
quality_gates:
  - "php artisan atlas:engineering:knowledge docs-health --json"
failure_modes:
  - Drift entre nome do comando no catálogo e implementação real.
observability_signals:
  - docs-health status ok
next_actions:
  - Manter o catálogo sincronizado com AtlasAiSelfConstructionCommand.
---

## Resumo

Catalog completo das superfícies read-only do Self-Construction OS. Existe para
permitir que `atlas-ai-self-construction-os.md` permaneça focado na arquitetura
sem perder a discoverability dos comandos.

## Papel no Atlas

Surface de referência detalhada para AIs que precisam saber EXATAMENTE qual
projeção `--json` cobre cada etapa do governed pipeline (research → docs → SDD →
execução → evidence → repair → learning).

## Current Runtime Surface

All commands below are read-only and keep `execution_allowed=false`.
Some command names mention operator, human review, Codex or provider identity
because they document the current bootstrap and receipt chain. Those names must
not be interpreted as a permanent requirement for Self-Construction progress.

| Command | Purpose |
|---|---|
| `php artisan atlas:ai:self-construction --json` | Readiness, docs, maturity, build graph, priority bias and safety contract. |
| `php artisan atlas:ai:self-construction --meta-sdd --json` | Candidate Meta-SDD packet with assumptions, priority, tasks and gates. |
| `php artisan atlas:ai:self-construction --receipt-preview --json` | Preview receipt with allowed/forbidden scope, rollback and evidence. |
| `php artisan atlas:ai:self-construction --traceability --json` | Required-doc reachability, tag and layer audit; related cross-layer docs must declare a `layer:` value, but do not need to claim `0.8-self-construction`. |
| `php artisan atlas:ai:self-construction --promotion-gate --json` | Consolidated promotion recommendation for human-reviewed planning. |
| `php artisan atlas:ai:self-construction --execution-candidate --json` | Deterministic Phase 5 candidate for docs/tests/report scope only. |
| `php artisan atlas:ai:self-construction --approval-packet --json` | Human review packet with checklist, reviewers and decision fields. |
| `php artisan atlas:ai:self-construction --receipt-draft --json` | Unsigned receipt draft with hash and preview signature. |
| `php artisan atlas:ai:self-construction --execution-preflight --json` | Expected blocked preflight while no valid human signature exists. |
| `php artisan atlas:ai:self-construction --signature-request --json` | Signable payload, hashes, signer roles and confirmations. |
| `php artisan atlas:ai:self-construction --execution-runbook --json` | Post-signature ordered steps, stop conditions, evidence, gates and rollback. |
| `php artisan atlas:ai:self-construction --evidence-packet --json` | Required proof template, claim checks and failure policy for a future signed run. |
| `php artisan atlas:ai:self-construction --completion-readiness --json` | Blocks false completion until signed execution evidence exists. |
| `php artisan atlas:ai:self-construction --residual-risk --json` | Classifies residual blockers before promotion or completion claims. |
| `php artisan atlas:ai:self-construction --handoff-packet --json` | Gives the next operator hashes, blockers, commands and forbidden hot scope. |
| `php artisan atlas:ai:self-construction --next-action --json` | Selects the next safe action while execution remains blocked. |
| `php artisan atlas:ai:self-construction --surface-matrix --json` | Lists every command surface, schema and read-only invariant. |
| `php artisan atlas:ai:self-construction --external-blockers --json` | Reports hot-file blockers outside Self-Construction ownership. |
| `php artisan atlas:ai:self-construction --cold-lane-certification --json` | Certifies the Self-Construction cold lane with external blockers separated. |
| `php artisan atlas:ai:self-construction --operator-checklist --json` | Orders the next human/operator review steps without signing or execution. |
| `php artisan atlas:ai:self-construction --promotion-blockers --json` | Consolidates promotion and completion blockers without execution. |
| `php artisan atlas:ai:self-construction --readiness-digest --json` | Emits a compact hashable handoff digest for operators and other AIs. |
| `php artisan atlas:ai:self-construction --governance-scorecard --json` | Scores governed readiness while execution, promotion and completion stay blocked. |
| `php artisan atlas:ai:self-construction --integrity-manifest --json` | Bundles governed packet hashes for audit and handoff integrity checks. |
| `php artisan atlas:ai:self-construction --continuation-token --json` | Emits a compact audited resume token with must-run and must-not-touch constraints. |
| `php artisan atlas:ai:self-construction --ownership-boundary --json` | Declares cold allowed files, hot forbidden scopes and required operator behavior. |
| `php artisan atlas:ai:self-construction --phase-ledger --json` | Summarizes phase status, hard blocks and promotion boundaries. |
| `php artisan atlas:ai:self-construction --implementation-packet --json` | Emits a read-only packet so another AI can continue one bounded block. |
| `php artisan atlas:ai:self-construction --work-splitter --json` | Emits disjoint read-only packets for parallel AI sessions and withholds hot work. |
| `php artisan atlas:ai:self-construction --scope-validator --json` | Classifies current diff against packet scope before any completion claim. |
| `php artisan atlas:ai:self-construction --assignment-preview --json` | Selects one safe packet for one AI session without persisting a claim. |
| `php artisan atlas:ai:self-construction --packet-runbook --json` | Emits ordered consumption steps, gates and evidence for the selected packet. |
| `php artisan atlas:ai:self-construction --packet-evidence-report --json` | Reviews packet evidence and blocks completion when gates or scope are unsafe. |
| `php artisan atlas:ai:self-construction --packet-completion-gate --json` | Converts packet evidence into a blocked/review/candidate completion decision. |
| `php artisan atlas:ai:self-construction --reservation-ledger-preview --json` and related `--durable-reservation-*` variants | Plans durable reservation approval, implementation contracts and blueprints without writes. |
| `php artisan atlas:ai:self-construction --ai-session-bootstrap --json` | Bundles packet, reservation, runbook, scope and gates for a new AI session. |
| `php artisan atlas:ai:self-construction --packet-queue --json` | Lists available, blocked and withheld packets without changing state. |
| `php artisan atlas:ai:self-construction --parallel-session-plan --json` | Plans up to five AI session slots without claims or dispatch. |
| `php artisan atlas:ai:self-construction --collision-matrix --json` | Proves packet overlap and parallel safety without claims or dispatch. |
| `php artisan atlas:ai:self-construction --dependency-unlock-plan --json` | Shows which completed packets would unlock later work without mutating queue state. |
| `php artisan atlas:ai:self-construction --multi-session-readiness-gate --json` and the `--agent-*` family | Projects Forge Workspace and Agent Control Plane; syncs agent runs/heartbeats/cost events; exposes adapter invocation contracts, wakeup queue projections, dispatch executor release/authorization templates, post-start liveness/evidence gates, fresh-authorization recovery cycles and the post-monitoring review / health decision / disable execution / repair-evidence chain. All projections are read-only and cannot sign receipts, persist ledger writes, mutate hot runtime files, start providers, dispatch work or grant a later cycle. |

## Read-only invariants

None of the surfaces above signs, patches, approves, persists approval, mutates
policy, touches hot runtime files, enables autonomous self-programming, starts
or dispatches Codex, becomes signature authority, persists receipts, writes
ledger events, executes disable, mutates writer state, creates writer files,
merges or dispatches. They emit unsigned drafts, signature requests,
post-signature runbooks, signed-receipt templates and post-monitoring review /
health decision / disable-execution / repair-evidence templates so external
governance can later sign and persist with full evidence.

## Onde Se Encaixa

Filho de `atlas-ai-self-construction-os.md`. O pai mantém a tese, leis,
authority map e core loop; este catálogo mantém a discoverability completa das
superfícies `--json` da família `AtlasAiSelfConstructionCommand`.

## Contratos

- Cada linha do catálogo deve descrever uma superfície read-only existente em
  `AtlasAiSelfConstructionCommand` ou em uma família `--agent-*` adjacente.
- Nenhuma superfície listada aqui pode escrever ledger, persistir receipt,
  iniciar provider ou mover state — todas mantêm `execution_allowed=false`.

## Fluxo

1. AI lê o pai (`atlas-ai-self-construction-os.md`) para entender arquitetura.
2. AI vem aqui quando precisa do nome exato da projeção `--json` para uma fase.
3. AI roda o comando, lê o JSON e segue o pipeline governado.

## Regras para IA

- Agente jamais deve confundir nome de comando com permissão de execução.
- Toda mutação real exige Decision Receipt + signed authorization fora deste
  catálogo.
- Surface novo só pode ser adicionado quando o comando existir em runtime e
  honrar as invariantes read-only.

## Escopo de Implementacao

Catalog only — sem código, sem mutação. Toda alteração deve permanecer dentro
de `docs/engineering-knowledge-base/self-construction/command-surface-catalog.md`
ou ser refletida no pai como ponteiro.

## Dependencias

- `app/Console/Commands/AtlasAiSelfConstructionCommand.php`
- famílias `--agent-*` em `app/Services/Ai/SelfConstruction/`

## Evidencias

- `php artisan atlas:docs:lint-file docs/engineering-knowledge-base/self-construction/command-surface-catalog.md`
- `php artisan atlas:engineering:knowledge docs-health --json`

## Riscos

- Drift entre nome do comando aqui e implementação real: mitigado pela revisão
  ao adicionar nova projeção `--json`.

## Exemplos

```bash
php artisan atlas:ai:self-construction --json
php artisan atlas:ai:self-construction --next-action --json
php artisan atlas:ai:self-construction --ai-session-bootstrap --json
```

## Proximas Acoes

- Manter alinhado com `AtlasAiSelfConstructionCommand` quando novas projeções
  `--json` forem adicionadas.
- Reavaliar split adicional se o catálogo ultrapassar o limite do canonical
  module.
