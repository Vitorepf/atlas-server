---
id: atlas-ai-sdd-templates-and-schemas
type: engineering_knowledge
title: Atlas SDD Templates And Schemas
status: active
category: contracts
priority: 98
summary: Minimal schemas for operational spec, assumption ledger, task and SDD policy.
tags:
  - atlas-ai
  - sdd
  - schemas
  - templates
capabilities:
  - sdd_schemas
  - spec_templates
decisions:
  - Templates define required structure but do not create parallel authority outside canonical docs.
maintenance:
  - Update before creating migrations, DTOs, commands or UI for SDD.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-spec-operating-system.md
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-sdd-templates-and-schemas

graph_title: Atlas SDD Templates And Schemas

graph_world: atlas

graph_layer: gear

graph_kind: contract

graph_parent: atlas-ai-self-construction-os

graph_status: active

graph_source: repo
human_name: Atlas SDD Templates And Schemas
canonical_name: Atlas SDD Templates And Schemas
technical_name: atlas-ai-sdd-templates-and-schemas
cartography_type: contract
canonical_source: docs/engineering-knowledge-base/spec-operating-system/templates-and-schemas.md

owner: spec-operating-system

repo_paths:
  - docs/engineering-knowledge-base/spec-operating-system/templates-and-schemas.md

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
  - docs/engineering-knowledge-base/spec-operating-system/templates-and-schemas.md
evidence_refs:
  - symbol: AtlasSddTemplatesAndSchemasService
  - command: atlas:aaeos:sdd-templates-and-schemas
  - test: AtlasSddTemplatesAndSchemasTest

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: low

visual_tags:
  - gear
  - contract
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
# Atlas SDD Templates And Schemas

## Operational Spec

```yaml
spec:
  id:
  title:
  status: draft|approved|implemented|superseded
  type: feature|bugfix|refactor|ui_change|api_change|data_change
  risk: low|medium|high|critical
intent:
  raw_user_request:
  interpreted_goal:
  non_goals:
context:
  product_area:
  current_screen:
  code_area:
  related_specs:
business:
  actors:
  objects:
  rules:
requirements: []
acceptance_criteria: []
technical_constraints:
  backend:
  frontend:
  database:
  security:
  design_system:
assumptions: []
test_strategy:
  unit:
  integration:
  e2e:
  visual:
  accessibility:
```

## SDD Policy

```yaml
sdd_policy:
  default_mode: auto_spec_then_execute
  clarification:
    ask_only_when:
      - missing_target_context
      - ambiguous_business_object
      - security_or_permission_unclear
      - irreversible_or_high_risk_action
  design_system:
    forbid_hardcoded_colors: true
    prefer_tokens: true
    require_accessibility_for_ui_changes: true
  evidence:
    require_diff: true
    require_test_output: true
    require_traceability: true
```

## Projection Rule

`.atlas/` templates, AGENTS.md and local project files may mirror these schemas
for agent ergonomics. Canonical authority remains repo docs, APs, Knowledge DB,
Decision Receipts and Evidence Ledger.

## Resumo

Minimal schemas for operational spec, assumption ledger, task and SDD policy.

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
