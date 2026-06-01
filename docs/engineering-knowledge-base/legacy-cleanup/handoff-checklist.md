---
id: legacy-cleanup-handoff-checklist
type: engineering_knowledge
title: Legacy Cleanup Handoff Checklist
status: active
category: documentation-governance
priority: 85
summary: Checklist for finishing an Atlas legacy cleanup session or PR with enough evidence for the next AI.
tags:
  - atlas
  - documentation
  - handoff
capabilities:
  - documentation_quality_gate
  - legacy_cleanup_handoff_checklist
decisions:
  - Cleanup handoff must report files changed, source material preserved and validation commands.
maintenance:
  - Keep this checklist short and executable.
related_paths:
  - docs/engineering-knowledge-base/legacy-documentation-cleanup-plan.md
  - docs/engineering-knowledge-base/atlas-ai-documentation-operating-system.md
doc_schema: atlas_canonical_module_doc.v1

graph_id: legacy-cleanup-handoff-checklist

graph_title: Legacy Cleanup Handoff Checklist

graph_world: atlas

graph_layer: system

graph_kind: policy

graph_parent: atlas-ai-canonical-architecture-index

graph_status: active

graph_source: repo
human_name: Legacy Cleanup Handoff Checklist
canonical_name: Legacy Cleanup Handoff Checklist
technical_name: legacy-cleanup-handoff-checklist
cartography_type: policy
canonical_source: docs/engineering-knowledge-base/legacy-cleanup/handoff-checklist.md

owner: legacy-cleanup

repo_paths:
  - docs/engineering-knowledge-base/legacy-cleanup/handoff-checklist.md

allowed_changes:
  - Atualizar este doc quando codigo, arquitetura, fluxo, evidencia ou decisao canonica mudar.

forbidden_changes:
  - Declarar runtime, maturidade ou prontidao sem evidencia verificavel e gates verdes.

depends_on:
  - atlas-ai-documentation-operating-system

flows_to:
  - atlas-cartography
  - atlas-code

unlocks:
  - ai-safe-implementation-context

governs:
  - legacy-cleanup

evidence:
  - docs/engineering-knowledge-base/legacy-cleanup/handoff-checklist.md
evidence_refs:
  - symbol: AtlasHandoffChecklistService
  - command: atlas:aaeos:handoff-checklist
  - test: AtlasHandoffChecklistTest

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: medium

visual_tags:
  - system
  - policy
  - legacy-cleanup

ai_entrypoints:
  - Leia Resumo, Contratos, Regras para IA, Evidencias e Riscos antes de implementar.

ai_usage_notes:
  - Use repo_paths, allowed_changes, forbidden_changes e required_tests como limites operacionais.

quality_gates:
  - "php artisan atlas:engineering:knowledge docs-health --json"

failure_modes:
  - Contexto desatualizado entre doc, codigo, teste e evidencia.

observability_signals:
  - docs-health status ok

next_actions:
  - Manter este doc sincronizado com codigo, testes, evidencias e Cartografia.
---
# Legacy Cleanup Handoff Checklist

## Before Final Message

- [ ] State the cleanup wave executed.
- [ ] List active docs changed.
- [ ] List archived source material created or used.
- [ ] List redirects or canonical replacements added.
- [ ] Confirm no runtime files were changed, or explain why runtime was in scope.
- [ ] Confirm no personal/vault material was promoted without review.

## Required Validation

```bash
atlas engineering knowledge docs-health --json
php artisan atlas:ai:architecture-validate --json
git diff --check -- docs/engineering-knowledge-base
atlas engineering knowledge sync --prune --json
atlas engineering knowledge index-code --prune --workspace=/Users/vitorepf/develop/Atlas/atlas-server --json
```

## Final Report Shape

Use this shape:

```md
Concluido:
- compactei X;
- preservei Y;
- criei Z child docs.

Validacao:
- docs-health: ok;
- architecture-validate: ok;
- diff check: ok;
- sync/index: ok.

Restante:
- N docs split_required.
```

## Stop Conditions

Stop and report if:

- canonical authority conflicts with another doc;
- a source contains sensitive personal material;
- a delete candidate still has live references;
- validation fails in a way unrelated to the cleanup.

## Resumo

Checklist for finishing an Atlas legacy cleanup session or PR with enough evidence for the next AI.

## Papel no Atlas

Define a responsabilidade desta peca dentro da arquitetura Atlas.

## Onde Se Encaixa

Relaciona esta peca com seu sistema, camada, fluxo ou modulo pai.

## Contratos

Declara invariantes, entradas, saidas, limites e obrigacoes relevantes.

## Fluxo

Descreve o caminho operacional ou a sequencia de uso quando aplicavel.

## Regras para IA

Agentes devem respeitar escopo, evidencias, testes e proibicoes antes de alterar codigo.

## Escopo de Implementacao

Mudancas devem permanecer nos caminhos e limites declarados no frontmatter.

## Dependencias

Dependencias canonicas vivem em frontmatter e no corpo deste documento.

## Evidencias

Evidencias aceitas incluem docs, comandos, testes, receipts, reports e paths verificaveis.

## Riscos

Riscos principais devem ser tratados antes de promover status, runtime ou claims de prontidao.

## Exemplos

Exemplos concretos devem ser adicionados quando reduzirem ambiguidade para humanos ou IAs.

## Proximas Acoes

Proximas acoes devem ser concretas, verificaveis e ligadas a gates de qualidade.
