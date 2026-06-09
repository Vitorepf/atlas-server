---
id: atlas-forge-rivals-provider-performance-ledger-v1
type: engineering_knowledge
title: Atlas Forge Rivals · Provider Performance Ledger v1
status: active
category: architecture
priority: 80
summary: Append-only local ledger + decide-signal/decide-map projection that turns Rivals scorecards into Atlas-Decide-grade measured evidence by provider, model, role, task category and difficulty.
tags:
  - atlas-forge
  - rivals
  - provider-performance-ledger
  - atlas-decide
  - audit
  - certification
capabilities:
  - provider_performance_ledger
  - decide_signal_projection
  - decide_model_intelligence_map
  - category_difficulty_role_model_aggregate
  - statistical_repeat_readiness
  - cost_quality_frontier
  - fair_vs_full_power_delta
  - atlas_forge_vs_raw_provider_delta
  - aggregated_evidence_for_atlas_decide
decisions:
  - The ledger is read-side intelligence on top of adjudicator scorecards; it never calls a provider.
  - Atlas Decide consumes decide-signal and decide-map as advisory input only — the ledger never claims authority.
  - Hard-failed runs are recorded as invalid negative signal but excluded from rankings and the cost/quality frontier.
  - external_rivals_certification stays blocked forever as far as this ledger is concerned.
maintenance:
  - When the adjudicator schema changes, update the entry mapping and the docs section that lists the canonical scorecard fields.
  - Keep the nine-invariant certification in sync with the service contract; never relax an invariant without a follow-up doc.
related_paths:
  - docs/engineering-knowledge-base/atlas-forge-rivals-benchmark-strategy-v1.md
  - docs/engineering-knowledge-base/atlas-forge-rivals-intelligence-ledger-v1.md
  - app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsProviderPerformanceLedgerService.php
  - app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsDecideSignalProjectionService.php
  - app/Services/Ai/Kernel/Architecture/AtlasForgeRivalsProviderPerformanceLedgerCertification.php
  - docs/engineering-knowledge-base/atlas-forge-rivals-perfect-battery-and-adjudicator-v1.md
  - docs/engineering-knowledge-base/atlas-forge-rivals-operator-battery-v2.md
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-forge-rivals-provider-performance-ledger-v1
graph_title: Atlas Forge Rivals · Provider Performance Ledger v1
graph_world: atlas
graph_layer: system
graph_kind: module
graph_parent: atlas-forge-rivals-perfect-battery-and-adjudicator-v1
graph_status: active
graph_source: repo
human_name: "Atlas Forge Rivals · Provider Performance Ledger v1"
canonical_name: "Atlas Forge Rivals · Provider Performance Ledger v1"
technical_name: atlas-forge-rivals-provider-performance-ledger-v1
cartography_type: module
canonical_source: docs/engineering-knowledge-base/atlas-forge-rivals-provider-performance-ledger-v1.md
owner: programming

repo_paths:
  - app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsProviderPerformanceLedgerService.php
  - app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsDecideSignalProjectionService.php
  - app/Services/Ai/Kernel/Architecture/AtlasForgeRivalsProviderPerformanceLedgerCertification.php

allowed_changes:
  - Atualizar quando o adjudicator emitir novos campos no scorecard ou quando os thresholds de confidence/stale precisarem evoluir.
  - Manter os 9 invariantes em sincronia com o contrato exposto na cert.
forbidden_changes:
  - Permitir que o ledger ou o decide-signal chamem provider externo, gastem token, destravem external_rivals_certification, ou promovam claim_ready=true.
  - Ranquear runs com hard_failures em qualquer agregado ou no cost_quality_frontier.
depends_on:
  - atlas-forge-rivals-benchmark-strategy-v1
  - atlas-forge-rivals-perfect-battery-and-adjudicator-v1
  - atlas-forge-rivals-operator-battery-v2
flows_to:
  - atlas-decide
unlocks:
  - provider-performance-ranking-by-task-category-and-role
governs:
  - rivals_provider_performance_intelligence
evidence:
  - tests/Unit/Ai/Programming/ForgeRivals/AtlasForgeRivalsProviderPerformanceLedgerServiceTest.php
  - tests/Feature/Ai/Programming/AtlasForgeRivalsProviderPerformanceLedgerCertificationTest.php
  - "php artisan atlas:forge:rivals audit --json"
  - "php artisan atlas:forge:rivals ledger --json"
  - "php artisan atlas:forge:rivals decide-signal --task-category=frontend --role=builder --json"
  - "php artisan atlas:forge:rivals decide-map --json"

evidence_refs:
  - test: AtlasForgeRivalsProviderPerformanceLedgerServiceTest
  - symbol: AtlasForgeRivalsProviderPerformanceLedgerService
required_tests:
  - "php artisan test --filter='ProviderPerformanceLedger'"
  - "php artisan atlas:forge:rivals audit --json"
requires_evidence: true
risk_level: medium
next_actions:
  - Conectar atlas:decide para consumir decide-signal e decide-map como input advisory.
  - Evoluir snapshot para Intelligence Ledger historico segmentado com intervalo de confianca completo.
  - Adicionar retention/compaction ao entries.jsonl quando volume justificar.
  - Surface UI Atlas Code Premium (ranking, provider cards, cost/quality scatter).
---
# Atlas Forge Rivals · Provider Performance Ledger v1

Strategy canon: `atlas-forge-rivals-benchmark-strategy-v1.md`.
Status: available, delivered 2026-05-15. The ledger records adjudicator scorecards
append-only and projects advisory evidence for Atlas Decide. It never calls a provider,
spends tokens, promotes external certification or changes topology.

Canonical commands:

```bash
php artisan atlas:forge:rivals ledger --json --strict
php artisan atlas:forge:rivals ledger-record --run-id=<id> --task-category=<cat> --role=<role> --json --strict
php artisan atlas:forge:rivals decide-signal --task-category=<cat> --role=<role> --difficulty=L5 --json --strict
php artisan atlas:forge:rivals decide-map --json --strict
php artisan atlas:forge:rivals audit --json --strict
```

Detailed schema, aggregate, decide-map, recording and UI notes were moved to
`archive/source-material/atlas-forge-rivals-provider-performance-ledger-v1-full-2026-06-09.md`.
That snapshot is reference material; this file is the active canonical contract.

## Resumo

Ledger append-only que transforma scorecards do Rivals em inteligência para
Atlas Decide, sem chamar provider, gastar token ou destravar
`external_rivals_certification`.

## Papel no Atlas

Camada entre Rivals (mede) e Atlas Decide (decide). O `decide-signal` é
advisory para escolher provider/model/role/modo em runs futuros.

## Onde Se Encaixa

Encaixa entre `atlas:forge:rivals adjudicate` e `atlas:decide`. Lê evidência
local já produzida pela pipeline Rivals.

## Contratos

- Snapshot: `atlas.forge.rivals.provider_performance_ledger.v1`.
- Entry: `atlas.forge.rivals.provider_performance_ledger_entry.v1`.
- Decide signal: `atlas.forge.rivals.decide_signal.v1`.
- Cert: `atlas_forge_rivals_provider_performance_ledger_certification`.

## Fluxo

```
scorecard.json ──► ledger-record ──► entries.jsonl + entries/<id>.json
                                          │
                                          ▼
                                       snapshot
                                          │
                                          ▼
                                    decide-signal (advisory)
                                          │
                                          ▼
                                     Atlas Decide
```

## Regras para IA

- Nunca chamar provider externo a partir do ledger ou projeção.
- Nunca promover claim; entradas têm `claim_ready=false`.
- Nunca rankear hard failures nem score com replay/evidence/hash quebrado.
- Nunca destravar `external_rivals_certification`.
- Faltou `task_category`, `role` ou `evidence_pack_hash`: bloquear.

## Escopo de Implementacao

- `AtlasForgeRivalsProviderPerformanceLedgerService`.
- `AtlasForgeRivalsDecideSignalProjectionService`.
- `AtlasForgeRivalsProviderPerformanceLedgerCertification`.
- Actions: `ledger`, `ledger-record`, `decide-signal`.
- Storage local: `storage/app/rivals-forge-ledger/`.

## Dependencias

- `AtlasForgeRivalsRunPathResolver` para localizar manifest/scorecard.
- Scorecards produzidos pelo `AtlasForgeRivalsAdjudicatorService`.
- `AtlasForgeRivalsResponseBuilder` para expor a cert no `audit`.

## Evidencias

- Tests unit do ledger service e feature da certification.
- `php artisan atlas:forge:rivals audit --json`.

## Riscos

- Scorecards sem hashes viram sinal inválido, não ranking.
- `entries.jsonl` ainda não tem rotation; planejar retention futura.

## Exemplos
`php artisan atlas:forge:rivals decide-signal --task-category=frontend --role=builder --difficulty=L5 --json --strict`

## Proximas Acoes
Conectar Decide, retention e UI quando houver volume real suficiente.
