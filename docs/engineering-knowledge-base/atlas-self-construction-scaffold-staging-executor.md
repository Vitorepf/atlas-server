---
id: atlas-self-construction-scaffold-staging-executor
type: engineering_knowledge
doc_schema: atlas_canonical_module_doc.v1
title: Atlas Self-Construction Scaffold Staging Executor
slug: atlas-self-construction-scaffold-staging-executor
status: building
implementation_state: runtime_available_staging_only
category: self_construction
priority: 92
summary: Executor que transforma proposta ASCB aprovada em arquivos sob storage staging, nunca no source tree, com Kernel, Admission, approval check e anti-tamper.
tags: [atlas-ai, self-construction, staging, scaffold, governance]
capabilities: [approved_proposal_staging, scaffold_staging_receipt, source_tree_write_lock]
decisions:
  - Staging executor nunca escreve direto em app/docs/tests.
  - Operador promove arquivos de staging manualmente depois de revisao.
  - Proposal hash e approval sao obrigatorios para evitar tamper.
maintenance:
  - Atualizar antes de mudar staging root, receipt schema, approval lookup ou generated file policy.
  - Manter path traversal protection e source tree write lock cobertos por teste.
risk_level: high
owner: atlas-ai
graph_id: atlas-self-construction-scaffold-staging-executor
graph_title: Atlas Self-Construction Scaffold Staging Executor
graph_world: atlas
graph_layer: module
graph_kind: module
graph_parent: atlas-self-construction-subsystem-builder
graph_status: building
graph_source: repo
depends_on: [atlas-self-construction-subsystem-builder, atlas-constitutional-kernel, atlas-autonomy-admission]
flows_to: [atlas-ai-self-construction-os]
unlocks: [approved_scaffold_staging, self_construction_staging_receipt]
governs: [scaffold_staging_receipts, staged_self_construction_files]
authority_class: executor
related_paths:
  - docs/engineering-knowledge-base/atlas-self-construction-scaffold-staging-executor.md
  - docs/engineering-knowledge-base/atlas-self-construction-subsystem-builder.md
  - docs/engineering-knowledge-base/atlas-constitutional-kernel.md
  - docs/engineering-knowledge-base/atlas-autonomy-admission.md
  - app/Services/Ai/SelfConstruction/AtlasSelfConstructionScaffoldStagingExecutorService.php
  - app/Console/Commands/AtlasScaffoldStageCommand.php
  - tests/Unit/Ai/SelfConstruction/AtlasSelfConstructionScaffoldStagingExecutorServiceTest.php
repo_paths:
  - docs/engineering-knowledge-base/atlas-self-construction-scaffold-staging-executor.md
  - app/Services/Ai/SelfConstruction/AtlasSelfConstructionScaffoldStagingExecutorService.php
evidence:
  - app/Services/Ai/SelfConstruction/AtlasSelfConstructionScaffoldStagingExecutorService.php
  - app/Console/Commands/AtlasScaffoldStageCommand.php
  - tests/Unit/Ai/SelfConstruction/AtlasSelfConstructionScaffoldStagingExecutorServiceTest.php
required_tests:
  - "php artisan test tests/Unit/Ai/SelfConstruction/AtlasSelfConstructionScaffoldStagingExecutorServiceTest.php"
  - "php artisan atlas:engineering:knowledge docs-health --json"
next_actions:
  - Add operator promotion workflow documentation without automating source writes.
allowed_changes:
  - Add staged file templates and receipts while preserving source-tree write lock.
forbidden_changes:
  - auto_promote_to_source_tree
  - write_outside_staging_root
  - skip_approval_check
requires_evidence: true
line_limit: 520
schema:
  - atlas.self_construction.scaffold_staging_receipt.v1
---

# Atlas Self-Construction Scaffold Staging Executor

## Resumo

Executor de staging para propostas ASCB aprovadas. Ele escreve somente em `storage/atlas/self_construction/staged/`.

## Papel no Atlas

Fechar o loop autonomo de proposta para artifact revisavel sem tocar source tree.

## Onde Se Encaixa

Fica depois do Subsystem Builder e antes de qualquer promocao manual feita pelo operador.

## Contratos

Schema `atlas.self_construction.scaffold_staging_receipt.v1`.

## Fluxo

Proposal aprovada + hash -> Kernel -> Admission -> staging files -> receipt JSONL.

## Regras para IA

Nao escrever em `app/`, `docs/` ou `tests/` por este runtime. Nao pular approval check.

## Escopo de Implementacao

Service, comando e teste unitario existem; promocao source-tree e manual/fora de escopo.

## Dependencias

ASCB, Constitutional Kernel e Autonomy Admission.

## Evidencias

`AtlasSelfConstructionScaffoldStagingExecutorService`, `AtlasScaffoldStageCommand` e teste unitario.

## Riscos

Transformar staging em auto-programacao real sem operador.

## Exemplos

`php artisan atlas:scaffold:stage --proposal-id=<id> --proposal-hash=<hash> --json`

## Proximas Acoes

Documentar promocao manual do operador e manter source-tree write lock.

## Safety

- Confirma approval em `approvals.jsonl`.
- Valida hash da proposal.
- Bloqueia path traversal.
- Nunca promove direto para source.
