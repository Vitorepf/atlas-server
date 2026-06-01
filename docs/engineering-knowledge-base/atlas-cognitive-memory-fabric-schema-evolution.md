---
id: atlas-cognitive-memory-fabric-schema-evolution
type: engineering_knowledge
doc_schema: atlas_canonical_module_doc.v1
title: Atlas ACMF Schema Evolution Proposer
slug: atlas-cognitive-memory-fabric-schema-evolution
status: building
implementation_state: runtime_available_proposer
category: cognition
priority: 90
summary: Proposer append-only de evolucao de schemas ACMF que exige Kernel, Admission e aprovacao humana, sem aplicar migration automaticamente.
tags: [atlas-ai, acmf, schema, governance, proposal]
capabilities: [acmf_schema_proposal, schema_evolution_preflight, human_approval_schema_gate]
decisions:
  - Propostas de schema sao append-only e sempre requerem aprovacao humana.
  - Este runtime nunca aplica migration nem altera source automaticamente.
  - Triggers sao sinais de proposta, nao autorizacao de mudanca.
maintenance:
  - Atualizar antes de mudar triggers, threshold, proposal schema ou storage.
  - Manter testes provando Kernel/Admission e requires_human_approval.
risk_level: high
owner: atlas-ai
graph_id: atlas-cognitive-memory-fabric-schema-evolution
graph_title: Atlas ACMF Schema Evolution Proposer
graph_world: atlas
graph_layer: module
graph_kind: module
graph_parent: atlas-constitutional-kernel
graph_status: building
graph_source: repo
depends_on: [atlas-constitutional-kernel, atlas-autonomy-admission, atlas-cognitive-memory-fabric]
flows_to: [atlas-cognitive-memory-fabric]
unlocks: [governed_schema_evolution_proposal, acmf_schema_pressure_audit]
governs: [acmf_schema_proposals]
authority_class: proposer
related_paths:
  - docs/engineering-knowledge-base/atlas-cognitive-memory-fabric-schema-evolution.md
  - docs/engineering-knowledge-base/atlas-cognitive-memory-fabric.md
  - docs/engineering-knowledge-base/atlas-constitutional-kernel.md
  - docs/engineering-knowledge-base/atlas-autonomy-admission.md
  - app/Services/Ai/Cognition/AtlasCognitiveMemoryFabricSchemaEvolutionService.php
  - app/Console/Commands/AtlasAcmfSchemaEvolutionCommand.php
  - tests/Unit/Ai/Cognition/AtlasCognitiveMemoryFabricSchemaEvolutionServiceTest.php
repo_paths:
  - docs/engineering-knowledge-base/atlas-cognitive-memory-fabric-schema-evolution.md
  - app/Services/Ai/Cognition/AtlasCognitiveMemoryFabricSchemaEvolutionService.php
evidence:
  - app/Services/Ai/Cognition/AtlasCognitiveMemoryFabricSchemaEvolutionService.php
  - app/Console/Commands/AtlasAcmfSchemaEvolutionCommand.php
  - tests/Unit/Ai/Cognition/AtlasCognitiveMemoryFabricSchemaEvolutionServiceTest.php
evidence_refs:
  - symbol: AtlasCognitiveMemoryFabricSchemaEvolutionService
  - command: atlas:acmf:schema-evolution
required_tests:
  - "php artisan test tests/Unit/Ai/Cognition/AtlasCognitiveMemoryFabricSchemaEvolutionServiceTest.php"
  - "php artisan atlas:engineering:knowledge docs-health --json"
next_actions:
  - Add owner review flow for approved schema proposals before migrations exist.
allowed_changes:
  - Add new triggers only when they remain proposal-only and approval-gated.
forbidden_changes:
  - auto_apply_schema_migration
  - propose_without_kernel_gate
  - silent_schema_bump
requires_evidence: true
line_limit: 520
schema:
  - atlas.acmf.schema_proposal.v1
---

# Atlas ACMF Schema Evolution Proposer

## Resumo

Runtime de proposta para evolucao de schema ACMF, nunca executor de migration.

## Papel no Atlas

Capturar pressao de evolucao e gerar proposal auditavel para operador/owner.

## Onde Se Encaixa

Fica sob Constitutional Kernel e Autonomy Admission, ao lado do Cognitive Memory Fabric.

## Contratos

Schema `atlas.acmf.schema_proposal.v1`; storage `storage/atlas/acmf/schema_proposals.jsonl`.

## Fluxo

Trigger -> Kernel -> Admission -> proposal append-only com `requires_human_approval=true`.

## Regras para IA

Nao aplicar migration. Nao bump silencioso. Nao criar schema novo sem owner decision.

## Escopo de Implementacao

Service, comando e teste unitario existem.

## Dependencias

Constitutional Kernel, Autonomy Admission e ACMF.

## Evidencias

`AtlasCognitiveMemoryFabricSchemaEvolutionService`, `AtlasAcmfSchemaEvolutionCommand` e teste unitario.

## Riscos

Confundir proposal com autorizacao de schema migration.

## Exemplos

`php artisan atlas:acmf:schema-evolution --action=propose --input-json='{"current_schema":"atlas.x.v1","trigger":"operator_request"}' --json`

## Proximas Acoes

Adicionar fluxo de revisao/aprovacao antes de qualquer migration gerada.

## Triggers

- `operator_request`
- `frontmatter_drift`
- `extension_pressure`
