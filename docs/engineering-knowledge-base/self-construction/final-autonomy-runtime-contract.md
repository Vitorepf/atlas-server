---
id: atlas-ai-self-construction-final-autonomy-runtime-contract
type: engineering_knowledge
title: Atlas Self-Construction OS — Final Autonomy Runtime Contract
status: active
category: architecture
priority: 100
summary: Implementation reference for the final Atlas-native autonomy runtime — Task Fabric, Maestro, worker swarm, Verification Court, Merge Governor, receipts, learning transfer, docs sync, and multi-project lanes — extracted from the Self-Construction OS overview.
tags:
  - atlas-ai
  - self-construction
  - final-autonomy
  - multi-project
  - governance
capabilities:
  - final_autonomy_runtime_contract
  - multi_project_stewardship
decisions:
  - The final steady-state owner of Self-Construction is Atlas-native; external coding tools (Claude Code, Codex, Cursor, Loop) are bootstrap and surge aids only and never the permanent engine.
  - Each project instance gets its own contract, mainline, Cortex model, task queue, Maestro lane, worker policy, Verification Court gates, Merge/Release Governor policy, receipts and docs sync, so one 24/7 lane for Atlas can run beside another 24/7 lane for a separate project without cross-leakage.
maintenance:
  - Update whenever the final autonomy runtime contract, separation-of-powers split, or multi-project stewardship boundary changes.
  - Keep aligned with atlas-autonomous-engineering-government.md and self-construction/constitution.md.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-self-construction-os.md
  - docs/engineering-knowledge-base/atlas-autonomous-engineering-government.md
  - docs/engineering-knowledge-base/self-construction/constitution.md
owner: atlas-ai
layer: 0.8-self-construction
line_limit: 260
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-ai-self-construction-final-autonomy-runtime-contract
graph_title: Atlas Self-Construction OS - Final Autonomy Runtime Contract
graph_world: atlas
graph_layer: gear
graph_kind: contract
graph_parent: atlas-ai-self-construction-os
graph_status: active
graph_source: repo
human_name: Atlas Self-Construction OS - Final Autonomy Runtime Contract
canonical_name: Atlas Self-Construction OS - Final Autonomy Runtime Contract
technical_name: atlas-ai-self-construction-final-autonomy-runtime-contract
cartography_type: contract
canonical_source: docs/engineering-knowledge-base/self-construction/final-autonomy-runtime-contract.md
repo_paths:
  - docs/engineering-knowledge-base/self-construction/final-autonomy-runtime-contract.md
allowed_changes:
  - Atualizar este contrato quando a divisão de poderes do runtime final, os limites de stewardship multi-projeto ou a definição de owner final mudarem.
forbidden_changes:
  - Declarar que IA externa (Claude Code, Codex, Cursor, Loop) é o owner final ou steady-state.
  - Misturar arquivos, segredos, políticas ou releases entre lanes de projetos distintos.
depends_on:
  - atlas-ai-self-construction-os
  - atlas-autonomous-engineering-government
  - atlas-self-construction-constitution
flows_to:
  - atlas-cartography
unlocks:
  - ai-safe-implementation-context
governs:
  - self-construction
evidence:
  - docs/engineering-knowledge-base/self-construction/final-autonomy-runtime-contract.md
evidence_refs:
  - doc: atlas-autonomous-engineering-government.md
  - doc: self-construction/constitution.md
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
requires_evidence: true
risk_level: high
visual_tags:
  - gear
  - contract
  - self-construction
ai_entrypoints:
  - Leia Final Architecture Position e Multi-Project Stewardship antes de propor mudança que afete owner final ou lanes paralelas.
ai_usage_notes:
  - O final owner é Atlas-native; ferramentas externas são bootstrap/surge.
  - Cada projeto tem lane independente — nunca compartilhar fila, mainline ou governor entre projetos.
quality_gates:
  - "php artisan atlas:engineering:knowledge docs-health --json"
failure_modes:
  - Adotar dependência permanente em provider externo no steady state.
  - Cross-leak de arquivos/segredos entre lanes de projetos diferentes.
observability_signals:
  - docs-health status ok
next_actions:
  - Manter este contrato sincronizado com Government e Constitution.
---

## Resumo

Contrato canônico do runtime final Atlas-native do Self-Construction OS: como
Atlas constrói Atlas (e outros projetos) com owner final Atlas-native, lanes
multi-projeto independentes e separação rígida de poderes.

## Papel no Atlas

Sub-doc detalhado de `atlas-ai-self-construction-os.md`. O pai segura a visão
arquitetural compacta; este contrato segura a referência de implementação para
quem precisa decidir owner final, autoridade por papel, ou setup de novo lane de
projeto.

## Final Architecture Position

Self-Construction OS is the governed operating system for Atlas building Atlas.
It is not a monolithic Loop. It is a separation-of-powers system:

```text
Atlas Autonomous Engineering Government
  -> Atlas Self-Construction OS
      -> Constitution / Kernel
      -> Control Plane
      -> Cortex / World Model
      -> Goal & Value System
      -> Strategy Council
      -> Architecture Council
      -> Task Fabric / Task Economy
      -> Maestro Scheduler
      -> Worker Swarm
      -> Verification Court
      -> Merge / Release Governor
      -> Receipts / Evidence / Memory
      -> Learning Transfer System
      -> Autopoiesis Lab / Loop
      -> Docs / Knowledge Sync
```

Responsibilities are deliberately split:

- `Control Plane` decides scope, risk, budget, priority and mode.
- `Cortex` supplies read-only understanding, not authority.
- `Strategy Council` selects highest-leverage directions.
- `Architecture Council` converts strategy into contracts and invariants.
- `Task Fabric` creates executable packets with `allowed_files`, dependencies,
  risk, gates, evidence and rollback.
- `Maestro` schedules, routes, repairs queue health and learns worker affinity.
- `Workers` execute packets and may include Loop, Claude Code, Codex, Cursor
  and internal agents during bootstrap; the final target is Atlas-native
  workers without operator/human/provider dependency. External workers are
  training wheels and surge capacity, never the permanent engine.
- `Verification Court` re-runs gates and treats worker output as an allegation
  until independently verified.
- `Merge / Release Governor` owns entry into main/release.
- `Learning Transfer System` promotes proven lessons into future packets,
  docs, memory and context packs.
- `Autopoiesis Lab / Loop` proposes recursive self-improvement under gates.

## Multi-Project Stewardship

Self-Construction is Atlas-first, but the architecture must also run as a
project stewardship system. A project instance gets its own:

- objective and value contract;
- repository/mainline boundary;
- Cortex project model;
- task queue and Maestro lane;
- worker pool policy;
- Verification Court gates;
- Merge / Release Governor policy;
- receipts, learning transfer and docs sync.

This allows one 24/7 lane for Atlas and another 24/7 lane for a separate
software project without mixing files, secrets, policies or releases.

Each project starts with a bounded scope, proves value, then expands by
evidence. The goal is exceptional engineering throughput and quality: clean
code, tests, refactors that unlock capability, bug prevention, documentation,
architecture hardening and compounding delivery speed.

## External Coding Tools Are Bootstrap And Surge Only

Claude Code, Codex, Cursor, the Loop and any other external coding tool are
**bootstrap and surge aids only**. They may help Atlas during early build-out
or absorb a temporary capacity spike, but the final steady-state owner of every
Self-Construction stage (Task Fabric → Maestro → Worker Swarm → Verification
Court → Merge Governor → Receipts/Learning/Docs Sync) remains **Atlas-native**.
Any future surface that requires a human operator, an external IDE session, an
external coding agent or an external provider product to keep the cycle alive
is a regression, not progress, and must be rebuilt as Atlas-native before it
can be promoted past `atlas_native_bounded`.

## Onde Se Encaixa

Filho de `atlas-ai-self-construction-os.md`. Pares: `atlas-autonomous-engineering-government.md`
e `self-construction/constitution.md`. Não substitui constituição nem governo —
detalha a forma do runtime final.

## Contratos

- Owner final do steady state = Atlas-native.
- Cada projeto = lane completa e independente.
- Workers externos = bootstrap e surge only.
- Verification Court independente de quem executou o trabalho.
- Merge/Release Governor é a única autoridade de entrada em main/release.

## Fluxo

1. Control Plane define escopo/risco/orçamento/prioridade/modo.
2. Strategy + Architecture Council convertem em contratos.
3. Task Fabric emite packets com allowed_files/gates/evidence.
4. Maestro escala lanes e workers (Atlas-native preferred; externos só como surge).
5. Workers executam pacotes.
6. Verification Court re-roda gates.
7. Merge/Release Governor decide entrada em main.
8. Receipts/Learning Transfer/Docs Sync absorvem o aprendizado.

## Regras para IA

- Nenhuma autoridade nova de owner final pode pertencer a provider externo.
- Lanes de projeto diferentes nunca compartilham fila, mainline ou Governor.
- Worker externo é descartável; arquitetura nunca pode depender dele para sobreviver.

## Escopo de Implementacao

Mudanças devem permanecer em
`docs/engineering-knowledge-base/self-construction/final-autonomy-runtime-contract.md`
ou se refletir no pai como ponteiro.

## Dependencias

- `atlas-ai-self-construction-os.md` (pai)
- `atlas-autonomous-engineering-government.md` (governo)
- `self-construction/constitution.md` (constituição)

## Evidencias

- `php artisan atlas:docs:lint-file --path=docs/engineering-knowledge-base/self-construction/final-autonomy-runtime-contract.md`
- `php artisan atlas:engineering:knowledge docs-health --json`

## Riscos

- Adotar dependência permanente de provider externo no steady state.
- Cross-leak de estado entre lanes de projetos diferentes.

## Exemplos

- Atlas roda lane própria 24/7 enquanto outro projeto cliente roda lane separada.
- Worker externo (Codex) absorve surge mas todo packet final é provado por Atlas-native Verification Court.

## Proximas Acoes

- Manter sincronizado com Government e Constitution sempre que owner final ou stewardship multi-projeto mudar.
