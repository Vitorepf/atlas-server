---
id: atlas-documentation-reality-block-registry
type: engineering_knowledge
title: Atlas Documentation Reality Block Registry
status: active
category: documentation-governance
priority: 100
summary: Registro canonico navegavel dos 52 blocos ADRS, com ids estaveis, planes, tipos, status, owners, fontes e avaliadores para Cartografia, Context Pack e IAs implementadoras.
human_name: Registro dos 52 Blocos ADRS
canonical_name: Atlas Documentation Reality Block Registry
technical_name: AtlasDocumentationRealitySystemService.block_acceptance_matrix
cartography_type: registry
canonical_source: docs/engineering-knowledge-base/atlas-documentation-reality-block-registry.md
tags:
  - atlas-ai
  - documentation-reality
  - cartography
  - block-registry
  - ai-context
capabilities:
  - adrs_block_registry
  - cartography_block_metadata
  - ai_safe_block_navigation
decisions:
  - Este registro e a fonte compacta de metadados navegaveis dos 52 blocos ADRS.
  - O ADRS continua sendo a doc mae; este arquivo detalha ids e leitura operacional por bloco.
  - Todo bloco precisa ter id estavel, plane primario, tipo, status, owner doc, fonte e evaluation ref.
  - Cartografia deve usar este registro para nomear blocos sem inventar patamar, versao, status ou fonte.
maintenance:
  - Manter sincronizado com `atlas-documentation-reality-system.md` e `AtlasDocumentationRealitySystemService`.
  - Rodar docs-health e testes ADRS apos alterar ids, quantidade ou evaluation refs.
related_paths:
  - docs/engineering-knowledge-base/atlas-documentation-reality-system.md
  - docs/engineering-knowledge-base/atlas-documentation-reality-block-upgrade-map.md
  - app/Services/Engineering/AtlasDocumentationRealitySystemService.php
  - tests/Feature/Engineering/AtlasDocumentationRealitySystemServiceTest.php
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-documentation-reality-block-registry
graph_title: Atlas Documentation Reality Block Registry
graph_world: atlas
graph_layer: system
graph_kind: contract
graph_parent: atlas-documentation-reality-system
graph_status: active
graph_source: repo
owner: documentation-governance
repo_paths:
  - docs/engineering-knowledge-base/atlas-documentation-reality-block-registry.md
allowed_changes:
  - Atualizar blocos quando ADRS ou runtime ADRS mudarem o catalogo oficial.
  - Corrigir plane, tipo, owner ou evaluation ref quando a Cartografia expuser drift.
forbidden_changes:
  - Adicionar bloco sem atualizar ADRS, runtime, testes e matriz de aceite.
  - Usar este registro como runtime mutativo.
  - Inferir patamar a partir de ordem, numero, plane, status ou evaluation ref.
depends_on:
  - atlas-documentation-reality-system
  - atlas-documentation-reality-block-upgrade-map
flows_to:
  - atlas-universal-reality-cartography
  - atlas-code-reality-usage-intelligence
  - atlas-cartography
unlocks:
  - cartography-block-drilldown
  - adrs-context-pack-projection
governs:
  - adrs-blocks
  - cartography.block_catalog
evidence:
  - docs/engineering-knowledge-base/atlas-documentation-reality-block-registry.md
  - app/Services/Engineering/AtlasDocumentationRealitySystemService.php
evidence_refs:
  - symbol: AtlasDocumentationRealitySystemService
  - command: atlas:documentation-reality
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
  - "php artisan test tests/Feature/Engineering/AtlasDocumentationRealitySystemServiceTest.php"
requires_evidence: true
risk_level: critical
visual_tags:
  - system
  - registry
  - documentation-reality
ai_entrypoints:
  - Leia este registro para listar, navegar ou projetar os 52 blocos ADRS na Cartografia.
ai_usage_notes:
  - Use `block_id` como id visual estavel. Use `source_path` para abrir a verdade. Use `evaluation_ref` para ligar ao runtime read-only.
quality_gates:
  - "php artisan atlas:engineering:knowledge docs-health --json"
  - "php artisan atlas:documentation-reality acceptance --strict --json"
failure_modes:
  - Bloco aparece na Cartografia sem owner, fonte ou evaluation ref.
  - IA cria bloco 53 sem atualizar runtime e testes.
observability_signals:
  - adrs block_count
  - accepted_block_count
  - cartography orphan nodes count
next_actions:
  - Manter o teste de sincronismo entre registry, ADRS e runtime como gate obrigatorio da Cartografia.
  - Conectar parser da Cartografia a este registro como catalogo compacto dos blocos ADRS.
line_limit: 520
---
# Atlas Documentation Reality Block Registry

## Resumo

Este registro torna os 52 blocos ADRS legiveis para Cartografia, Context Pack e
IAs. O ADRS explica a area; este arquivo da ids estaveis e metadados minimos
para cada bloco virar peca navegavel sem ambiguidade.

## Papel no Atlas

Este registro e o catalogo compacto dos blocos. Ele reduz ambiguidade para a
Cartografia e impede que uma IA derive ids, status ou owners a partir de texto
solto.

## Onde Se Encaixa

```text
ADRS -> Block Registry -> AURC/Cartografia -> Context Pack/Atlas Code
ADR-BUM -> upgrades dos blocos
ADRS runtime -> evaluation_ref e acceptance
```

## Contratos

Campos obrigatorios por bloco:

| Campo | Regra |
|---|---|
| `block_id` | slug estavel usado por Cartografia e Context Pack |
| `plane` | plane primario; participacoes extras continuam no ADRS |
| `kind` | tipo funcional: kernel, registry, gate, engine, runtime, meter, queue, corpus |
| `status` | `active_l4_integrated` enquanto a matriz ADRS aceitar o bloco |
| `owner_doc` | doc canonico dono |
| `source_path` | fonte principal verificavel |
| `evaluation_ref` | chave no runtime ADRS |

## Fluxo

1. ADRS define os 52 blocos.
2. Este registro fornece ids e metadados navegaveis.
3. ADRS runtime emite evaluation refs e acceptance.
4. AURC/Cartografia renderiza blocos com fonte e status.
5. Context Pack usa os mesmos ids para handoff e retrieval.

## Registro Dos 52 Blocos

| # | block_id | plane | kind | evaluation_ref |
|---:|---|---|---|---|
| 1 | documentation-authority-kernel | truth_authority | kernel | authority_kernel |
| 2 | canonical-source-registry | truth_authority | registry | authority_kernel |
| 3 | documentation-operating-system | truth_authority | policy | documentation_operating_system |
| 4 | knowledge-governance-system | truth_authority | policy | knowledge_governance_system |
| 5 | acrui-operational-reality | operational_reality | runtime | acrui_operational_reality |
| 6 | aurc-visual-reality | human_cartography | runtime | aurc_visual_reality |
| 7 | human-modal-contract | human_cartography | contract | human_modal_contract |
| 8 | semantic-zoom-contract | human_cartography | contract | semantic_zoom_contract |
| 9 | visual-grammar-nomenclature | human_cartography | contract | visual_grammar_nomenclature |
| 10 | ai-context-projection | ai_context_efficiency | projection | ai_context_projection |
| 11 | drift-duplication-guard | operational_reality | guard | drift_duplication_guard |
| 12 | legacy-quarantine-governance | operational_reality | policy | legacy_quarantine_governance |
| 13 | cross-organization-boundary | governance_lifecycle | resolver | cross_organization_boundary |
| 14 | evidence-runtime-proof-bridge | operational_reality | bridge | evidence_runtime_proof_bridge |
| 15 | human-correction-loop | feedback_learning | loop | human_correction_loop |
| 16 | documentation-reality-score | operational_reality | meter | implementation_readiness_matrix |
| 17 | context-minimality-ledger | ai_context_efficiency | ledger | ai_context_projection |
| 18 | visual-completeness-auditor | human_cartography | auditor | visual_completeness_auditor |
| 19 | source-freshness-gate | operational_reality | gate | source_freshness_gate |
| 20 | contradiction-resolver | operational_reality | resolver | contradiction_resolver |
| 21 | implementation-readiness-matrix | operational_reality | matrix | implementation_readiness_matrix |
| 22 | multi-agent-handoff-projection | ai_context_efficiency | projection | multi_agent_handoff_projection |
| 23 | documentation-budget-governor | ai_context_efficiency | governor | documentation_budget_governor |
| 24 | reality-change-journal | operational_reality | journal | reality_change_journal |
| 25 | retrieval-audit-trail | ai_context_efficiency | trail | retrieval_audit_trail |
| 26 | human-attention-heatmap | human_cartography | telemetry | human_attention_heatmap |
| 27 | semantic-deduplication-engine | operational_reality | engine | semantic_deduplication_engine |
| 28 | documentation-compression-tiers | ai_context_efficiency | compressor | documentation_compression_tiers |
| 29 | cartography-task-simulator | human_cartography | simulator | cartography_task_simulator |
| 30 | provider-misread-defense | ai_context_efficiency | defense | provider_misread_defense |
| 31 | documentation-working-set-cache | ai_context_efficiency | cache | documentation_working_set_cache |
| 32 | cross-modal-consistency-gate | human_cartography | gate | cross_modal_consistency_gate |
| 33 | auto-split-planner | operational_reality | planner | auto_split_planner |
| 34 | obsolete-knowledge-simulator | operational_reality | simulator | obsolete_knowledge_simulator |
| 35 | context-pack-regression-test | operational_reality | test | context_pack_regression_test |
| 36 | cartography-cognitive-load-meter | human_cartography | meter | cartography_cognitive_load_meter |
| 37 | documentation-entropy-monitor | operational_reality | monitor | documentation_entropy_monitor |
| 38 | canonical-question-router | truth_authority | router | canonical_question_router |
| 39 | evidence-sufficiency-gate | operational_reality | gate | evidence_sufficiency_gate |
| 40 | reality-diff-engine | operational_reality | engine | reality_diff_engine |
| 41 | orphaned-decision-finder | operational_reality | finder | orphaned_decision_finder |
| 42 | vocabulary-alignment-guard | truth_authority | guard | vocabulary_alignment_guard |
| 43 | privacy-redaction-gate | governance_lifecycle | gate | privacy_redaction_gate |
| 44 | access-policy-resolver | governance_lifecycle | resolver | access_policy_resolver |
| 45 | documentation-adoption-meter | feedback_learning | meter | documentation_adoption_meter |
| 46 | surface-coverage-matrix | human_cartography | matrix | surface_coverage_matrix |
| 47 | learning-to-doc-promotion-gate | feedback_learning | gate | learning_to_doc_promotion_gate |
| 48 | documentation-lifecycle-state-machine | governance_lifecycle | state_machine | documentation_lifecycle_state_machine |
| 49 | documentation-slo-alerting | governance_lifecycle | alerting | documentation_slo_alerting |
| 50 | owner-escalation-queue | governance_lifecycle | queue | owner_escalation_queue |
| 51 | synthetic-reader-tests | operational_reality | test | synthetic_reader_tests |
| 52 | canonical-example-corpus | operational_reality | corpus | canonical_example_corpus |

## Campos Comuns

Todos os blocos acima usam:

```yaml
status: active_l4_integrated
owner_doc: docs/engineering-knowledge-base/atlas-documentation-reality-system.md
source_path: docs/engineering-knowledge-base/atlas-documentation-reality-system.md
upgrade_source: docs/engineering-knowledge-base/atlas-documentation-reality-block-upgrade-map.md
acceptance_command: php artisan atlas:documentation-reality acceptance --strict --json
acceptance_test: tests/Feature/Engineering/AtlasDocumentationRealitySystemServiceTest.php
```

Quando o bloco pertence diretamente a ACRUI, AURC, Documentation OS ou Knowledge
Governance, o modal pode mostrar o doc filho como leitura prioritaria, mas a
fonte do catalogo dos 52 blocos continua sendo ADRS + este registro.

## Regras para IA

1. `block_id` e a chave visual. Nome humano vem do titulo da linha no ADRS.
2. `evaluation_ref` liga a peca ao runtime read-only.
3. Tap deve abrir o bloco dentro do fluxo ADRS; long press deve usar o contrato
   de 7 camadas do Documentation Creation Gate.
4. Se faltar evaluation ref ou acceptance, mostrar `lacuna documental`.
5. Nao inferir patamar por numero do bloco, plane ou ordem.

## Escopo de Implementacao

Este documento nao implementa runtime. Ele governa ids e metadados para os 52
blocos ja aceitos pelo runtime ADRS read-only.

## Dependencias

- `atlas-documentation-reality-system.md`
- `atlas-documentation-reality-block-upgrade-map.md`
- `AtlasDocumentationRealitySystemService`
- `atlas-universal-reality-cartography.md`

## Evidencias

```bash
php artisan atlas:documentation-reality acceptance --strict --json
php artisan atlas:documentation-reality evaluations --strict --json
php artisan atlas:engineering:knowledge docs-health --json
```

## Riscos

- Registro divergir da tabela ADRS.
- Cartografia exibir bloco sem source path ou evaluation ref.
- IA tratar bloco como runtime mutativo separado.

## Exemplos

- `aurc-visual-reality` abre AURC como doc filha e usa `aurc_visual_reality`.
- `documentation-authority-kernel` abre ADRS e usa `authority_kernel`.

## Proximas Acoes

1. Manter `test_block_registry_stays_in_sync_with_runtime_blocks_for_cartography`.
2. Fazer Cartografia consumir `block_id` como chave de drilldown.
