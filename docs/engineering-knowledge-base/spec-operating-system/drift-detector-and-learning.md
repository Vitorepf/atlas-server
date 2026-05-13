---
id: atlas-ai-sdd-drift-detector-learning
type: engineering_knowledge
title: Atlas SDD Drift Detector And Learning
status: active
category: learning
priority: 99
summary: Spec drift detection and proposal-only learning contract for Atlas SDD.
tags:
  - atlas-ai
  - sdd
  - drift
  - learning
capabilities:
  - spec_drift_detector
  - sdd_learning_proposals
decisions:
  - Specs are living contracts, not dead markdown.
  - Learning from SDD patterns remains proposal-only until reviewed.
maintenance:
  - Update when drift detector or learning proposal flow becomes executable.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-spec-operating-system.md
  - docs/engineering-knowledge-base/domains/self-improvement.md
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-sdd-drift-detector-learning

graph_title: Atlas SDD Drift Detector And Learning

graph_world: atlas

graph_layer: gear

graph_kind: module

graph_parent: atlas-ai-self-construction-os

graph_status: active

graph_source: repo

owner: spec-operating-system

repo_paths:
  - docs/engineering-knowledge-base/spec-operating-system/drift-detector-and-learning.md

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
  - spec-operating-system

evidence:
  - docs/engineering-knowledge-base/spec-operating-system/drift-detector-and-learning.md

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: low

visual_tags:
  - gear
  - module
  - spec-operating-system

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
# Atlas SDD Drift Detector And Learning

## Drift Types

- code changed without spec;
- spec changed without test;
- test covers behavior without requirement;
- endpoint exists without contract;
- business rule exists only in code;
- design-system token violated;
- Decision Receipt allowed X but patch did Y;
- evidence missing for implemented requirement.

## Drift Output

```json
{
  "schema_version": "atlas.sdd_drift.v1",
  "status": "pass|warn|fail",
  "spec_id": "string",
  "findings": [],
  "recommended_action": "none|repair|create_spec|update_test|proposal"
}
```

## Learning Rule

Repeated patterns become proposals, not automatic policy changes.

Example:

```text
Observation: 14 form specs needed loading, disabled, success and error states.
Proposal: update UI form action template.
Gate: human review + docs update + tests.
```

Learning can update templates after approval. It cannot silently alter Kernel,
Policy, provider routing, memory truth or runtime critical behavior.

## Resumo

Spec drift detection and proposal-only learning contract for Atlas SDD.

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
