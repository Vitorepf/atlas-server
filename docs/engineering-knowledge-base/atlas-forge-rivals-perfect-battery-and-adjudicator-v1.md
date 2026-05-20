---
id: atlas-forge-rivals-perfect-battery-and-adjudicator-v1
type: engineering_knowledge
title: Atlas Forge Rivals · Perfect Battery & Adjudicator v1
status: active
category: programming-forge
priority: 88
summary: Bateria única e adjudicator determinístico local para `atlas:forge:rivals`. Encadeia doctor→setup→preflight→dry-run→plan-real→run-real→collect-evidence→replay→adjudicate→report em um comando auditável, com winner/tie/invalid honesto. Nunca destrava external_rivals_certification.
tags:
  - atlas
  - forge
  - rivals
  - run-battery
  - adjudicator
capabilities:
  - forge_rivals_run_battery_v1
  - forge_rivals_local_deterministic_adjudicator
  - forge_rivals_perfect_battery_certification
decisions:
  - Bateria é um único entrypoint humano; aliases não escondem o canon `run-battery`.
  - Adjudicator é local determinístico; nunca delega para provider externo.
  - `external_rivals_certification` permanece BLOCKED por construção independentemente do veredito.
maintenance:
  - Atualizar quando `AtlasForgeRivalsRunBatteryService`, `AtlasForgeRivalsAdjudicatorService` ou cert v1 mudarem de invariantes.
  - Não introduzir alias novo sem aliasing list no command.
related_paths:
  - docs/engineering-knowledge-base/atlas-forge-rivals-battery-modes-and-human-prompts-v1.md
  - docs/engineering-knowledge-base/atlas-forge-rivals-benchmark-strategy-v1.md
  - app/Console/Commands/AtlasForgeRivalsCommand.php
  - app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsRunBatteryService.php
  - app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsAdjudicatorService.php
  - app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsReportService.php
  - docs/engineering-knowledge-base/atlas-forge-rivals-evidence-replay-adjudicator-hardening-v2.md
  - docs/engineering-knowledge-base/atlas-forge-rivals-operator-battery-v2.md
  - docs/engineering-knowledge-base/atlas-forge-rivals-real-battery-operator-harness-v1.md
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
  - docs/engineering-knowledge-base/atlas-forge-rivals-perfect-battery-and-adjudicator-v1-part-01.md
  - docs/engineering-knowledge-base/atlas-forge-rivals-perfect-battery-and-adjudicator-v1-part-02.md
  - docs/engineering-knowledge-base/atlas-forge-rivals-perfect-battery-and-adjudicator-v1-part-03.md
doc_schema: atlas_canonical_module_doc.v1
owner: programming_rivals
graph_id: atlas-forge-rivals-perfect-battery-and-adjudicator-v1
graph_title: Atlas Forge Rivals · Perfect Battery & Adjudicator v1
graph_world: atlas
graph_layer: system
graph_kind: runbook
graph_parent: atlas-forge-rivals-operator-battery-v2
graph_status: active
graph_source: repo
repo_paths:
  - app/Console/Commands/AtlasForgeRivalsCommand.php
  - app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsRunBatteryService.php
  - app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsAdjudicatorService.php
  - app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsReportService.php
  - docs/engineering-knowledge-base/atlas-forge-rivals-perfect-battery-and-adjudicator-v1.md
allowed_changes:
  - Adicionar aliases novos ao command com aliasing list explícita.
  - Endurecer invariantes do adjudicator determinístico local.
forbidden_changes:
  - Delegar o veredito do adjudicator para provider externo.
  - Esconder safety strip ou pular confirmações em `fair`/`full_power`.
  - Promover `external_rivals_certification` a partir do veredito da bateria.
depends_on:
  - atlas-forge-rivals-benchmark-strategy-v1
  - atlas-forge-rivals-operator-battery-v2
  - atlas-forge-rivals-real-battery-operator-harness-v1
flows_to:
  - atlas-forge-rivals-evidence-replay-adjudicator-hardening-v2
  - atlas-forge-rivals-provider-arena-core-v1
unlocks:
  - operator_runs_atlas_vs_rival_in_single_auditable_command
governs:
  - forge_rivals_run_battery_pipeline
evidence:
  - tests/Feature/Ai/Programming/AtlasForgeRivalsRunBatteryTest.php
  - tests/Unit/Ai/Programming/ForgeRivals/AtlasForgeRivalsAdjudicatorServiceTest.php
required_tests:
  - tests/Feature/Ai/Programming/AtlasForgeRivalsRunBatteryTest.php
  - tests/Unit/Ai/Programming/ForgeRivals/AtlasForgeRivalsAdjudicatorServiceTest.php
requires_evidence: true
risk_level: high
next_actions:
  - Manter aliasing list sincronizada com novos modos.
  - Acompanhar invariantes do adjudicator quando novas categorias forem introduzidas.
---line_limit: 520

# Atlas Forge Rivals · Perfect Battery & Adjudicator v1

**Strategy canon:** `atlas-forge-rivals-benchmark-strategy-v1.md`
**Schema:** `atlas.forge.rivals.run_battery.v1`
**Adjudicator schema:** `atlas.forge.rivals.adjudication.v1` (legacy, intact)
**Adjudicator schema v2:** `atlas.forge.rivals.adjudication.v2` (category scoring + triage + ledger projection)
**Batch input schema:** `atlas.forge.rivals.adjudication_batch_input.v1`
**Report schema:** `atlas.forge.rivals.report.v3` (additive over v2; see `atlas-forge-rivals-reporting-v1.md`)
**Certification:** `atlas_forge_rivals_perfect_battery_certification` (v1)
**Entrypoint:** `php artisan atlas:forge:rivals`
**Status:** Slice 7 — Perfect Battery & Adjudicator delivered (2026-05-15)
**v2 Adjudicator layer:** delivered 2026-05-15 (per-category scoring, hard gates,
suspicious triage, confidence ladder, ledger projection — sits on top of v1
without breaking it).

This doc is the canonical contract for running Atlas Forge vs a rival in a
single auditable command, with a deterministic local adjudicator that
declares an honest winner / tie / invalid verdict. It supersedes nothing —
it builds on top of `atlas-forge-rivals-operator-battery-v2.md` and
`atlas-forge-rivals-real-battery-operator-harness-v1.md`. Read those first.

> **External rivals canon.** This battery NEVER unlocks
> `external_rivals_certification`. That cert remains operator-approval-gated
> and separately tracked. Every layer (run-battery, adjudicator, report,
> certification, this doc) restates the separation explicitly.

---

## Resumo

Slice 7 do Forge Rivals: bateria única `atlas:forge:rivals run-battery` que orquestra todo o pipeline de comparação Atlas vs rival com adjudicator determinístico local e cert v1. Aliases consolidados (`battery`, `run-battery-real`, `score`, `adjudicator`). `external_rivals_certification` continua BLOCKED.

## Papel no Atlas

Cabine humana do Forge Rivals. Substitui a sequência manual de 9 comandos por uma única chamada com `--strict`, retornando o pipeline parcial até o ponto de falha e preservando evidência em `runs/<run_id>/`.

## Onde Se Encaixa

Acima de `atlas-forge-rivals-operator-battery-v2.md` e `atlas-forge-rivals-real-battery-operator-harness-v1.md`. Companheiro direto de `atlas-forge-rivals-evidence-replay-adjudicator-hardening-v2.md`.

## Contratos

Schemas: `atlas.forge.rivals.run_battery.v1` (envelope), `atlas.forge.rivals.adjudication.v1` (adjudicator determinístico local), `atlas.forge.rivals.report.v2` (report). Cert: `atlas_forge_rivals_perfect_battery_certification` (v1). Invariantes: adjudicator nunca delega para provider externo; safety strip nunca é escondida; 3 confirmações para `fair`/`full_power`; `external_rivals_certification` permanece BLOCKED por construção.

## Fluxo

`doctor → setup → preflight → dry-run → plan-real → run-real → collect-evidence → replay → adjudicate → report`, parando na primeira fase com status diferente de `ok`.

## Regras Para IA

Não esconder safety strip. Não pular as três confirmações em modos `fair`/`full_power`. Não promover `external_rivals_certification` a partir do veredito.

## Escopo De Implementacao

`AtlasForgeRivalsCommand`, `AtlasForgeRivalsRunBatteryService`, `AtlasForgeRivalsAdjudicatorService`, `AtlasForgeRivalsReportService`, `AtlasForgeRivalsCollectEvidenceService`, `AtlasForgeRivalsReplayService`.

## Dependencias

Operator battery v2, real battery operator harness v1, evidence pack v2 hardening, perfect battery certification v1.

## Evidencias

Cert v1 `atlas_forge_rivals_perfect_battery_certification` e 186 testes Forge Rivals verdes (Slice 7 delivered 2026-05-15).

## Riscos

Operador interpretar `winner` como completion claim. Alias novo escapar do controle do command. Promoção indevida de `external_rivals_certification`.

## Exemplos

`php artisan atlas:forge:rivals run-battery --mode=fair --atlas-model=sonnet --rival=claude_sonnet --preset=release --prompt-mode=human-normal --confirm-runbook-reviewed --confirm-provider-cost --confirm-real-provider-call --json --strict`.

## Proximas Acoes

Acompanhar futuros polish em adjudicator e report. Mantersuit de testes sincronizada com mudanças de invariantes.

## Detalhes Extraidos

O contrato detalhado de bateria, adjudicator, report, certificação e interpretação foi movido para recortes filhos para manter este módulo legível na cartografia.

- `docs/engineering-knowledge-base/atlas-forge-rivals-perfect-battery-and-adjudicator-v1-part-01.md` — 1. The single button ate 4. Adjudicator (deterministic, local).
- `docs/engineering-knowledge-base/atlas-forge-rivals-perfect-battery-and-adjudicator-v1-part-02.md` — 4b. Adjudicator v2 (per-category, suspicious triage, confidence ladder) ate 4c. Truth Guard v1 — Calibration & Score Separation.
- `docs/engineering-knowledge-base/atlas-forge-rivals-perfect-battery-and-adjudicator-v1-part-03.md` — 5. Premium report (`report.md` + JSON) ate 11. Related docs.
