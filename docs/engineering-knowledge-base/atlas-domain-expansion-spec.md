---
id: atlas-domain-expansion-spec
type: engineering_knowledge
title: Atlas Domain Expansion Spec
status: active
category: atlas-ai
priority: 99
summary: Spec canonica para criar novo domain do zero no Atlas (Cyber, Trading, Health, Mobile, etc.) seguindo contratos canonicos. Define checklist obrigatorio, schema do domain, gates de prontidao, dependencias com 11 departamentos e exemplos worked-through.
tags:
  - atlas-ai
  - domain-expansion
  - new-domain-spec
  - 15-domains
  - extensibility
capabilities:
  - new_domain_creation
  - domain_canonical_contract
  - domain_governance_floor
decisions:
  - Novo domain nasce com schema preenchido + gates + evidence.
  - Domain orfao (sem doc canonico) nao opera.
maintenance:
  - Atualize ao mudar checklist ou adicionar gate.
related_paths:
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
  - docs/engineering-knowledge-base/atlas-agentic-engineering-os-department-contract.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-domain-expansion-spec
graph_title: Atlas Domain Expansion Spec
graph_world: atlas
graph_layer: system
graph_kind: contract
graph_parent: atlas-agentic-engineering-os
graph_status: active
graph_source: repo
human_name: Atlas Domain Expansion Spec
canonical_name: Atlas Domain Expansion Spec
technical_name: atlas-domain-expansion-spec
cartography_type: spec
canonical_source: docs/engineering-knowledge-base/atlas-domain-expansion-spec.md
owner: atlas-ai
product_name: Atlas Domain Expansion Spec
internal_product_name: Atlas Domain Expansion Spec
runtime_acronym: ADES
technical_runtime: atlas.domain_expansion
repo_paths:
  - docs/engineering-knowledge-base/atlas-domain-expansion-spec.md
allowed_changes:
  - Refinar checklist, gates.
forbidden_changes:
  - Aceitar domain sem schema preenchido.
depends_on:
  - atlas-agentic-engineering-os
flows_to:
  - atlas-aaeos-department-maturity-matrix
unlocks:
  - new-domain-canonical
governs:
  - atlas_ai.domain_expansion
evidence:
  - docs/engineering-knowledge-base/atlas-domain-expansion-spec.md
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
requires_evidence: true
risk_level: medium
visual_tags:
  - spec
  - extensibility
quality_gates:
  - checklist-12-items-complete
  - schema-domain-v1-filled
  - gates-defined
failure_modes:
  - Domain sem doc canonico.
  - Schema parcial.
  - Sem cross-dept dependencies.
observability_signals:
  - domain_count
  - domain_orphan_count
next_actions:
  - Criar template de domain doc.
---
# Atlas Domain Expansion Spec

## Resumo

Como criar novo domain do zero respeitando contratos canonicos.

## Papel no Atlas

15 domains "ready" (programming, self-improvement, finance, etc.). Falta runbook para criar 16o.

## Onde Se Encaixa

```text
atlas-agentic-engineering-os
  +-- atlas-domain-expansion-spec (este doc)
       +-- novos domains seguem template
```

## Contratos

### Schema (`atlas.domain.v1`)

```text
{
  "schema": "atlas.domain.v1",
  "id": "<snake_case>",
  "human_name": "<string>",
  "scope": "<unique no-overlap>",
  "intents_supported": ["..."],
  "departments_touched": ["product","architect","dev","forge","..."],
  "sovereignty_class": "ok_to_share|sensitive|secret|cyber",
  "data_sources": [{"id":"...","kind":"..."}],
  "skills_required": ["atlas.skill.*"],
  "evidence_required": ["..."],
  "gates": ["..."],
  "maturity_level": "L0|...|L7"
}
```

### Checklist obrigatorio (12 items)

1. Doc canonico criado em `docs/engineering-knowledge-base/domains/<id>.md`.
2. Schema `atlas.domain.v1` preenchido.
3. `scope` confirmado sem overlap com 15 atuais.
4. `intents_supported` declarados.
5. `departments_touched` mapeados (minimo 3).
6. `sovereignty_class` declarado.
7. `data_sources` listados com sovereignty class.
8. `skills_required` referenciam Skill Pack Canonical (T4.3).
9. `evidence_required` minimo 3 evidence kinds.
10. `gates` minimo 5 gates (3 universais + 2 domain-specific).
11. `maturity_level=L0` inicial.
12. Architect review approval registrado.

### Cross-dept dependencies obrigatorias

| Domain kind | Depts minimos |
|-------------|---------------|
| programming | product, architect, dev, forge, review, qa, security, delivery, memory |
| finance | product, architect, security, review, qa, delivery, memory |
| marketing | product, research, dev, review, qa, delivery, memory |
| trading | architect, security, qa, delivery, memory + Cyber se novo |
| health | architect, security, qa, delivery, memory (HIPAA-class sovereignty) |

## Fluxo

```mermaid
flowchart LR
  Propose[propor domain novo]
  Propose --> Doc[criar doc canonico]
  Doc --> Schema[preencher schema]
  Schema --> Checklist[12 items]
  Checklist --> Architect[Architect review]
  Architect -->|approve| L0[domain L0 ativo]
  Architect -->|reject| Refine[refine doc]
```

## Regras para IA

- Domain sem doc canonico nao recebe roteamento.
- Sovereignty sensitive/secret/cyber exige Security dept gate adicional.
- Promote L0 -> L1 segue Department Quality Bar Matrix.

## Escopo de Implementacao

Template doc + `AtlasDomainRegistryService`.

## Dependencias

Department Contract (T1.2), Skill Pack (T4.3), Quality Bar Matrix (T3.3).

## Evidencias

Comando: `atlas:domain:list --json`.

## Riscos

Overlap silencioso, sovereignty mismatch, gate insuficiente.

## O que este doc NAO e

Nao registra domains existentes; e como criar novos.

## Exemplos

Criar domain `cyber`: doc novo, schema preenchido, scope=defense+threat-intel, depts=architect+security+memory, sovereignty=cyber, gates=secret_scan+sandbox_only+no_external_call.

## Proximas Acoes

1. Criar template doc.
2. `AtlasDomainRegistryService`.
3. Validar 15 domains atuais batem schema.
