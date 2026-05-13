---
id: atlas-ai-os-governance-and-dod
type: engineering_knowledge
title: Atlas AI OS - Governance And DoD
status: active
category: architecture
priority: 99
summary: Horizontal layers, anti-duplication rules, ownership model and Definition of Done for Atlas AI flows.
tags:
  - atlas-ai
  - governance
  - dod
capabilities:
  - anti_duplication_governance
  - unified_capability_pipeline
decisions:
  - Horizontal capabilities belong to Core when useful across surfaces/domains.
  - Mature flows have owner, policy, gates, evidence, repair and docs.
maintenance:
  - Update when ownership or DoD rules change.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-operating-system.md
  - docs/engineering-knowledge-base/atlas-ai-core-vs-domain.md
  - docs/engineering-knowledge-base/atlas-ai-pipeline.md
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-os-governance-and-dod

graph_title: Atlas AI OS - Governance And DoD

graph_world: atlas

graph_layer: system

graph_kind: policy

graph_parent: atlas-ai-canonical-architecture-index

graph_status: active

graph_source: repo

owner: operating-system

repo_paths:
  - docs/engineering-knowledge-base/operating-system/governance-and-dod.md

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
  - operating-system

evidence:
  - docs/engineering-knowledge-base/operating-system/governance-and-dod.md

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: medium

visual_tags:
  - system
  - policy
  - operating-system

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
# Atlas AI OS - Governance And DoD

## Horizontal Layers

- Intent;
- Context;
- Policy;
- Decide;
- Tools;
- Memory;
- Validation;
- Repair;
- Evidence;
- Evolution.

If a capability is useful to more than one surface/domain, move it to Core or a
shared runtime before expanding behavior.

## Anti-Duplication Rules

1. Multi-surface features belong to Core.
2. Provider/model/permission/gate decisions pass through Policy/Decide.
3. State-changing tasks need evidence.
4. Code work enters Programming.
5. Sensitive memory passes privacy/provider-safety.
6. Local tools enter Tool Runtime when registry/normalizer applies.
7. Forge gates relevant to Dev must be shared or explicitly justified.
8. Aliases must not implement their own logic when canonical flow exists.

## Ownership

| Layer | Owner |
|---|---|
| Domain/intent | Atlas AI Core |
| Provider/model/policy | Atlas Decide + policy profiles |
| Prompt/context | Context assembly + Open Brain |
| Programming | Atlas AI Programming |
| Engineering harness | Engineering Harness |
| Memory | Memory Core / Open Brain |
| Tools | Super Tool Runtime |
| Evidence | Domain evidence packet |
| Documentation | Engineering Knowledge Base |

## Flow Definition Of Done

A mature Atlas AI flow has:

- clear domain and owner;
- canonical pipeline;
- governed context and memory;
- policy profile;
- executor;
- gates;
- repair/escalation;
- evidence packet;
- learning when applicable;
- canonical docs;
- tests preventing duplicated flow regression.

## Resumo

Horizontal layers, anti-duplication rules, ownership model and Definition of Done for Atlas AI flows.

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
