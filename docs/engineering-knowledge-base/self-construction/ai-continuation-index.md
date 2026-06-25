---
id: atlas-ai-self-construction-ai-continuation-index
type: engineering_knowledge
title: Atlas Self-Construction AI Continuation Index
status: active
category: architecture
priority: 99
summary: Compact reading order so a fresh AI session can pick up Self-Construction work without chat history.
tags:
  - atlas-ai
  - self-construction
  - continuation
  - onboarding
capabilities:
  - self_construction_ai_continuation_index
decisions:
  - New AI sessions implementing Self-Construction tasks MUST start here; chat history is not a load-bearing context.
  - Index points to canonical docs only; it never duplicates their contents.
  - Reading order is fixed so any worker arrives at the same shared mental model.
maintenance:
  - Update whenever a canonical doc in the index moves or is replaced.
related_paths:
  - docs/atlas-task-serving-runbook.md
  - docs/engineering-knowledge-base/atlas-ai-self-construction-os.md
  - docs/engineering-knowledge-base/self-construction/command-surface-catalog.md
  - docs/engineering-knowledge-base/self-construction/final-autonomy-runtime-contract.md
  - docs/engineering-knowledge-base/self-construction/runtime-final-autonomy-gates.md
  - docs/engineering-knowledge-base/self-construction/runtime-implementation-roadmap.md
  - docs/engineering-knowledge-base/self-construction/runtime-implementation-roadmap-waves.md
  - docs/loop-canonical-definition.md
owner: atlas-ai
layer: 0.8-self-construction
line_limit: 240
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-self-construction-ai-continuation-index

graph_title: Atlas Self-Construction AI Continuation Index

graph_world: atlas

graph_layer: gear

graph_kind: module

graph_parent: atlas-ai-self-construction-os

graph_status: active

graph_source: repo
human_name: Atlas Self-Construction AI Continuation Index
canonical_name: Atlas Self-Construction AI Continuation Index
technical_name: atlas-ai-self-construction-ai-continuation-index
cartography_type: module
canonical_source: docs/engineering-knowledge-base/self-construction/ai-continuation-index.md

repo_paths:
  - docs/engineering-knowledge-base/self-construction/ai-continuation-index.md

allowed_changes:
  - Atualizar a ordem de leitura e ponteiros quando docs canonicos mudarem.

forbidden_changes:
  - Duplicar conteudo dos docs canonicos aqui.

depends_on:
  - atlas-ai-self-construction-os

flows_to:
  - atlas-cartography
  - atlas-code

unlocks:
  - ai-safe-implementation-context

governs:
  - self-construction

evidence:
  - docs/engineering-knowledge-base/self-construction/ai-continuation-index.md
evidence_refs:
  - command: atlas:task

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: medium

visual_tags:
  - gear
  - module
  - self-construction

ai_entrypoints:
  - Comece SEMPRE por esta sessao antes de qualquer implementacao Self-Construction.

ai_usage_notes:
  - Siga a ordem de leitura abaixo; nao confie em historico de chat para arquitetura ou contratos.

quality_gates:
  - "php artisan atlas:engineering:knowledge docs-health --json"

failure_modes:
  - Sessao nova implementando sem ler arquitetura canonica.

observability_signals:
  - docs-health status ok

next_actions:
  - Manter ponteiros sincronizados com os docs reais.
---
# Atlas Self-Construction AI Continuation Index

A new AI session implementing Self-Construction work MUST start here. Read the
canonical docs in the order below before touching code or proposing changes;
chat history is not a load-bearing source of context.

## Reading Order

1. **Final architecture** ->
   [atlas-ai-self-construction-os.md](../atlas-ai-self-construction-os.md):
   what Self-Construction OS is, its layers, its non-goals.

2. **Final autonomy runtime contract** ->
   [final-autonomy-runtime-contract.md](final-autonomy-runtime-contract.md):
   the contract every final-autonomy slice must satisfy (owners, evidence,
   refusal modes).

3. **Final autonomy gates** ->
   [runtime-final-autonomy-gates.md](runtime-final-autonomy-gates.md):
   the ready / hold / blocked evidence classes that gate promotion to 24/7
   for server-side verification, rollback, kill switch, backlog depth,
   give-back learning, Code Intelligence, knowledge sync and multi-project
   isolation.

4. **Implementation roadmap (overview)** ->
   [runtime-implementation-roadmap.md](runtime-implementation-roadmap.md):
   compact phase goals + Phase Gate summary; per-wave detail lives in the
   companion catalog:
   [runtime-implementation-roadmap-waves.md](runtime-implementation-roadmap-waves.md).

5. **Task-serving worker contract** ->
   [../../../atlas-task-serving-runbook.md](../../atlas-task-serving-runbook.md):
   how `atlas:task next` / `atlas:task report` work, scoped commit semantics,
   give-back rules, poison-packet handling.

6. **Command surface catalog** ->
   [command-surface-catalog.md](command-surface-catalog.md):
   the full set of Self-Construction CLI commands a worker may call.

7. **Loop canonical definition (only for evolution-loop work)** ->
   [../../../loop-canonical-definition.md](../../loop-canonical-definition.md):
   what the autonomous evolution loop is and is NOT; read only if you are
   modifying the loop itself.

## Evidence Expectations

- Every Self-Construction change carries machine-verifiable evidence: tests,
  gate outputs, or receipts referenced in the task acceptance criteria.
- No scalar scoring; verdicts are typed (ready / hold / blocked, pass / fail).
- Refusal under uncertainty is a first-class outcome, not a failure.

## Multi-Project Stewardship Mapping

- Atlas-server is the home project. External-project lanes are isolated by
  workspace, queue and docs root; see the Multi-Project Isolation gate in
  [runtime-final-autonomy-gates.md](runtime-final-autonomy-gates.md).

## Resumo

Ponto de partida unico para sessoes novas de IA implementando Self-Construction.

## Papel no Atlas

Garante que toda IA chega ao mesmo modelo mental antes de tocar codigo.

## Onde Se Encaixa

Filho de atlas-ai-self-construction-os.md; ponto de entrada da camada Self-Construction.

## Contratos

Lista canonica de docs de leitura obrigatoria + evidencia esperada por slice.

## Fluxo

IA abre este indice -> le na ordem -> abre a task -> implementa nos allowed_files -> reporta.

## Regras para IA

Nao implemente Self-Construction sem ler este indice; nao duplique conteudo aqui dos docs canonicos.

## Escopo de Implementacao

Edicoes ficam limitadas a este arquivo e ao atlas-ai-self-construction-os.md (para o ponteiro de entrada).

## Dependencias

Depende dos docs canonicos listados na reading order.

## Evidencias

docs-health verde, lint-file verde, e cada task carrega seus proprios receipts.

## Riscos

Indice desatualizado em relacao aos docs canonicos; mitigado pelo docs-health.

## Exemplos

Use esta secao + a reading order como exemplo concreto de bootstrap de sessao.

## Proximas Acoes

Atualizar ponteiros quando docs canonicos forem renomeados ou movidos.
