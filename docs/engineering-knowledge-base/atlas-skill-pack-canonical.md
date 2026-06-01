---
id: atlas-skill-pack-canonical
type: engineering_knowledge
title: Atlas Skill Pack Canonical
status: active
category: atlas-ai
priority: 99
summary: Doc canonico do Skill Pack do Atlas. Define schema, versionamento, persistencia, sharing local-first, gates de promotion (skill nova vira parte do core) e marketplace pessoal. AiSkillStore atual (32 KB de codigo) recebe contrato formal aqui.
tags:
  - atlas-ai
  - skill-pack
  - versioning
  - local-marketplace
  - skill-promotion
capabilities:
  - skill_pack_schema
  - skill_versioning
  - skill_promotion_gating
  - personal_marketplace
decisions:
  - Skill Pack e local-first; sharing nunca cruza sovereignty boundary.
  - Skill nova esta em namespace `atlas.skill.user.*` ate promocao para `atlas.skill.core.*`.
  - Promocao exige 30 usos consecutivos sem failure + Architect review.
maintenance:
  - Atualize ao adicionar campo, mudar promotion gate.
related_paths:
  - app/Services/Ai/AiSkillStoreService.php
  - docs/engineering-knowledge-base/atlas-ai-skills.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-skill-pack-canonical
graph_title: Atlas Skill Pack Canonical
graph_world: atlas
graph_layer: system
graph_kind: contract
graph_parent: atlas-agentic-engineering-os
graph_status: active
graph_source: repo
human_name: Atlas Skill Pack Canonical
canonical_name: Atlas Skill Pack Canonical
technical_name: atlas-skill-pack-canonical
cartography_type: contract
canonical_source: docs/engineering-knowledge-base/atlas-skill-pack-canonical.md
owner: atlas-ai
product_name: Atlas Skill Pack Canonical
internal_product_name: Atlas Skill Pack Canonical
runtime_acronym: ASKP
technical_runtime: atlas.skill_pack
repo_paths:
  - docs/engineering-knowledge-base/atlas-skill-pack-canonical.md
allowed_changes:
  - Refinar schema, promotion criteria.
forbidden_changes:
  - Sharing fora da sovereignty boundary.
depends_on:
  - atlas-agentic-engineering-os
flows_to:
  - atlas-domain-expansion-spec
unlocks:
  - skill-pack-runtime
governs:
  - atlas_ai.skill_pack
evidence:
  - docs/engineering-knowledge-base/atlas-skill-pack-canonical.md
evidence_refs:
  - symbol: AtlasSkillPackCanonicalService
  - command: atlas:aaeos:skill-pack-canonical
  - test: AtlasSkillPackCanonicalTest
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
requires_evidence: true
risk_level: low
visual_tags:
  - skill-pack
  - canonical
quality_gates:
  - schema-versioned
  - promotion-criteria-defined
  - sovereignty-respected
failure_modes:
  - Skill cross-sovereignty.
  - Promotion sem evidencia.
observability_signals:
  - skill_pack_count
  - skill_promotion_count
next_actions:
  - Implementar `php artisan atlas:skill:list --json`.
---
# Atlas Skill Pack Canonical

## Resumo

Schema canonico de Skill Pack local-first do Atlas.

## Papel no Atlas

`AiSkillStoreService.php` (32 KB) tem skills implementadas mas sem contrato formal. Este doc fornece.

## Onde Se Encaixa

```text
atlas-agentic-engineering-os
  +-- atlas-skill-pack-canonical (este doc)
```

## Contratos

### Schema (`atlas.skill_pack.v1`)

```text
{
  "schema": "atlas.skill_pack.v1",
  "skill_id": "atlas.skill.<core|user>.<name>",
  "version": "v<int>",
  "namespace": "core|user",
  "human_name": "<string>",
  "purpose": "<string>",
  "inputs": [{"name":"...","type":"..."}],
  "outputs": [{"name":"...","type":"..."}],
  "code_path": "<service_class_or_path>",
  "evidence_required": ["..."],
  "promotion": {
    "current_namespace": "user|core",
    "uses_count": <int>,
    "failure_count": <int>,
    "last_promoted_at": "<iso8601_or_null>"
  },
  "tags": ["..."],
  "sovereignty_class": "ok_to_share|sensitive|secret|cyber"
}
```

### Promotion gate user -> core

- 30 uses consecutivos sem failure.
- Sovereignty class != sensitive/secret/cyber.
- Architect review.
- 0 incident registrado.

### Marketplace pessoal

- 100% local. Nenhum upload externo.
- Index visivel em `php artisan atlas:skill:list --json`.
- Versioning via git por baixo.

## Fluxo

```mermaid
flowchart LR
  New[skill nova user namespace]
  New --> Use[uso registrado]
  Use --> Threshold{30 uses 0 failure?}
  Threshold -->|yes| Review[Architect review]
  Threshold -->|no| Use
  Review -->|approve| Core[promote core namespace]
  Review -->|reject| Refine[refine skill]
```

## Regras para IA

- Criar skill nova em `atlas.skill.user.*`.
- Promocao exige threshold + Architect review.
- Sovereignty sensitive/secret/cyber NUNCA promove para core.

## Escopo de Implementacao

`AtlasSkillPackService` (refator de AiSkillStore para schema novo).

## Dependencias

Sovereignty OS, Architect dept.

## Evidencias

Comando + ledger uso.

## Riscos

Promocao prematura, drift namespace, share cruzando sovereignty.

## O que este doc NAO e

Nao implementa skills; define contrato.

## Exemplos

Skill `atlas.skill.user.expense_categorizer` com 45 usos 0 failure, Architect aprova -> vira `atlas.skill.core.expense_categorizer.v1`.

## Proximas Acoes

1. Refator AiSkillStore para schema novo.
2. Implementar promotion gate.
3. Marketplace local index.
