---
id: atlas-ai-self-improvement-domain-alias
type: engineering_knowledge
title: Atlas AI Self Improvement Domain Alias
status: active
category: architecture
priority: 87
summary: Alias canonico para ferramentas que resolvem o dominio self_improvement pelo nome com underscore. A fonte autoritativa continua sendo domains/self-improvement.md.
tags:
  - atlas-ai
  - domains
  - self-improvement
  - provider-projection
capabilities:
  - self_improvement_domain_alias
  - feature_placement
decisions:
  - O domain id canonico em codigo e self_improvement.
  - O documento autoritativo humano continua sendo domains/self-improvement.md.
  - Este alias existe para alinhar feature-placement, session-bootstrap e provider projections sem duplicar a especificacao.
maintenance:
  - Atualizar apenas quando o owner real ou os comandos de placement mudarem.
related_paths:
  - docs/engineering-knowledge-base/domains/self-improvement.md
  - docs/engineering-knowledge-base/domains/self-improvement-runtime.md
  - app/Services/Ai/AtlasDomainProfileRegistry.php
  - app/Services/Ai/Kernel/Architecture/AtlasFeaturePlacementService.php
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-ai-self-improvement-domain-alias
graph_title: Atlas AI Self Improvement Domain Alias
graph_world: atlas
graph_layer: system
graph_kind: module
graph_parent: atlas-ai-self-improvement-domain
graph_status: active
graph_source: repo
human_name: Atlas AI Self Improvement Domain Alias
canonical_name: Atlas AI Self Improvement Domain Alias
technical_name: atlas-ai-self-improvement-domain-alias
cartography_type: module
canonical_source: docs/engineering-knowledge-base/domains/self_improvement.md
owner: domains
repo_paths:
  - docs/engineering-knowledge-base/domains/self_improvement.md
allowed_changes:
  - Manter ponte curta para o doc autoritativo do dominio self_improvement.
forbidden_changes:
  - Duplicar contratos, flows, schedules ou regras ja definidos em domains/self-improvement.md.
  - Declarar prontidao nova sem evidencia verificavel no doc autoritativo e nos testes.
depends_on:
  - atlas-ai-self-improvement-domain
flows_to:
  - atlas-ai-self-improvement-domain
  - atlas-ai-product-certification
unlocks:
  - ai-safe-implementation-context
governs:
  - feature-placement-owner-resolution
evidence:
  - docs/engineering-knowledge-base/domains/self_improvement.md
  - docs/engineering-knowledge-base/domains/self-improvement.md
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
  - "php artisan atlas:ai:place-feature \"Atlas macro runtime governance certification gaps desktop UX agent control plane external execution autonomous company capability evolution\" --json"
requires_evidence: true
risk_level: low
visual_tags:
  - system
  - module
  - domains
ai_entrypoints:
  - Leia Resumo, Contratos, Regras para IA e Proximas Acoes antes de implementar no dominio self_improvement.
ai_usage_notes:
  - Use este arquivo apenas como alias de resolucao; leia domains/self-improvement.md para implementar.
quality_gates:
  - "php artisan atlas:engineering:knowledge docs-health --json"
failure_modes:
  - Uma IA tratar o alias como especificacao completa e ignorar o owner real.
observability_signals:
  - feature-placement deixa de reportar owner_doc_missing para self_improvement.
next_actions:
  - Se o resolver de placement passar a aceitar hyphen e underscore, manter este alias como compatibilidade ou arquivar com decisao explicita.
---
# Atlas AI Self Improvement Domain Alias

## Resumo

Este documento e um alias canonico para o dominio `self_improvement`.

A especificacao autoritativa permanece em
`docs/engineering-knowledge-base/domains/self-improvement.md`.

## Papel no Atlas

Permite que ferramentas que resolvem owner docs por `domain_id` com underscore
encontrem um arquivo valido sem criar um segundo dominio.

## Onde Se Encaixa

Fica no Domain Plane como ponte entre `AtlasDomainProfileRegistry`,
`AtlasFeaturePlacementService`, provider projections e o documento humano
principal com hyphen.

## Contratos

- `self_improvement` e o id de dominio em codigo.
- `self-improvement.md` e o owner doc autoritativo.
- Este arquivo nao define flows novos.
- Este arquivo nao substitui runtime, schedules, APs ou gates.

## Fluxo

1. Ferramenta ou IA pede owner doc de `self_improvement`.
2. Este arquivo satisfaz a resolucao pelo nome com underscore.
3. A IA deve abrir `self-improvement.md` antes de alterar codigo ou docs.

## Regras para IA

- Nao implemente a partir deste alias sozinho.
- Leia o owner doc real e seus docs relacionados.
- Preserve a regra: tudo repetido vira Core, nao copia local.

## Escopo de Implementacao

Alteracoes aqui devem ser limitadas a alias, ponte de governanca e
compatibilidade de tooling.

## Dependencias

Depende do dominio Self-Improvement real, do Documentation Operating System e
do Feature Placement Gate.

## Evidencias

Evidencia valida inclui docs-health verde e feature-placement deixando de
marcar o owner doc `domains/self_improvement.md` como ausente.

## Riscos

O risco principal e duplicacao: duas specs divergentes para o mesmo dominio.
Por isso este arquivo deve permanecer curto e apontar para o owner real.

## Exemplos

Use `self-improvement.md` para entender `nightly_review`,
`capability_gap_scan`, `docs_drift_review` e `proposal_generation`.

## Proximas Acoes

Rode docs-health e reexecute feature-placement da meta macro para confirmar
que o bloqueio documental foi removido.
