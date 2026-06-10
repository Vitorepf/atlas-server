---
id: operator-intelligence-storage-and-data-model
type: engineering_knowledge
title: Operator Intelligence Storage And Data Model
status: active
category: learning-governance
priority: 98
summary: Storage e data model implementados para salvar sinais, candidatos, profile items, regras, feedback e snapshots do Operator Intelligence Layer.
tags:
  - atlas-ai
  - operator-intelligence
  - storage
  - data-model
  - postgres
capabilities:
  - operator_learning_storage
  - operator_profile_registry
  - operator_learning_data_model
decisions:
  - Postgres e a fonte operacional de verdade do Operator Intelligence Layer.
  - Repo Markdown guarda contratos e schemas, nao dados privados reais do operador.
  - Markdown privado gerado e projection humana, nao runtime primario.
  - Memory Core recebe somente aprendizados estaveis e promovidos.
maintenance:
  - Atualize este doc antes de alterar migrations ou fields do Operator Intelligence Layer.
  - Rode docs-health, sync e index-code depois de alterar este contrato.
related_paths:
  - docs/engineering-knowledge-base/atlas-operator-intelligence-layer.md
  - docs/engineering-knowledge-base/atlas-learning-taxonomy-170.md
  - config/atlas_operator_intelligence.php
  - app/Models/OperatorLearningSignal.php
  - app/Models/OperatorLearningCandidate.php
  - app/Models/OperatorProfileItem.php
  - app/Models/OperatorProfilePolicyRule.php
  - app/Models/OperatorProfileFeedbackEvent.php
  - app/Models/OperatorProfileSnapshot.php
  - app/Models/AiMemoryDelta.php
  - app/Models/AtlasMemoryEntry.php
doc_schema: atlas_canonical_module_doc.v1
graph_id: operator-intelligence-storage-and-data-model
graph_title: Operator Intelligence Storage And Data Model
graph_world: atlas
graph_layer: module
graph_kind: contract
graph_parent: atlas-operator-intelligence-layer
graph_status: active
graph_source: repo
human_name: Operator Intelligence Storage And Data Model
canonical_name: Operator Intelligence Storage And Data Model
technical_name: OperatorIntelligenceStorageDataModel
cartography_type: data_model
canonical_source: docs/engineering-knowledge-base/operator-intelligence/storage-and-data-model.md
owner: learning-governance
repo_paths:
  - docs/engineering-knowledge-base/operator-intelligence/storage-and-data-model.md
allowed_changes:
  - Evoluir tabelas, colunas e invariantes com migrations e testes.
forbidden_changes:
  - Tratar Markdown privado ou AtlasVault como fonte operacional primaria.
  - Salvar raw text sensivel desnecessario em tabelas ou docs versionados.
depends_on:
  - atlas-operator-intelligence-layer
  - atlas-learning-taxonomy-170
flows_to:
  - operator-profile-registry
unlocks:
  - operator-learning-migrations
governs:
  - operator-learning-storage
evidence:
  - docs/engineering-knowledge-base/operator-intelligence/storage-and-data-model.md
  - database/migrations/2026_06_08_130000_create_operator_learning_signals_table.php
  - database/migrations/2026_06_08_130100_create_operator_learning_candidates_table.php
  - database/migrations/2026_06_08_130200_create_operator_profile_items_table.php
  - database/migrations/2026_06_08_130300_create_operator_profile_policy_rules_table.php
  - database/migrations/2026_06_08_130400_create_operator_profile_feedback_events_table.php
  - database/migrations/2026_06_08_130500_create_operator_profile_snapshots_table.php
  - app/Models/OperatorLearningSignal.php
  - app/Models/OperatorLearningCandidate.php
  - app/Models/OperatorProfileItem.php
  - app/Models/OperatorProfilePolicyRule.php
  - app/Models/OperatorProfileFeedbackEvent.php
  - app/Models/OperatorProfileSnapshot.php
implementation_state: implemented_initial_runtime
required_tests:
  - "git diff --check"
  - "php artisan atlas:engineering:knowledge docs-health --json"
requires_evidence: true
risk_level: high
visual_tags:
  - storage
  - data-model
  - operator
ai_entrypoints:
  - Leia este doc antes de criar migrations do Operator Intelligence Layer.
ai_usage_notes:
  - Este doc define o modelo implementado; confira evidence/migrations antes de alterar.
quality_gates:
  - "php artisan atlas:engineering:knowledge docs-health --json"
failure_modes:
  - Sinal bruto vira memoria aplicada sem candidato/review.
  - Dados privados entram em docs versionados.
observability_signals:
  - Cada profile item possui taxonomia, source, confidence, privacy_class e status.
next_actions:
  - Expandir constraints FK depois de estabilizar rollout.
---
# Operator Intelligence Storage And Data Model

## Resumo

Este doc define onde o Operator Intelligence Layer salva cada tipo de dado:
Postgres para estado operacional, Markdown versionado para contrato, Markdown
privado para projection humana e Memory Core para memoria longa promovida.

## Papel no Atlas

O storage precisa permitir aprendizado automatico sem perder auditabilidade. O
Atlas deve saber diferenciar sinal bruto, candidato, profile item aprovado,
regra compilada, feedback de uso e memoria longa.

## Onde Se Encaixa

Este doc e filho de `atlas-operator-intelligence-layer.md` e especializado em
storage/data model. Ele nao substitui Memory Core.

## Contratos

- `operator_learning_signals` e append-only.
- `operator_learning_candidates` e fila de curadoria.
- `operator_profile_items` e registry ativo.
- `operator_profile_policy_rules` e compilacao operacional.
- `operator_profile_feedback_events` mede se o profile ajudou.
- `operator_profile_snapshots` e cache/projection compacto.
- `AtlasMemoryEntry` recebe apenas aprendizado estavel promovido.

## Fluxo

1. Capture cria `operator_learning_signals`.
2. Classifier cria ou atualiza `operator_learning_candidates`.
3. Review aprova/rejeita/supersedes candidates.
4. Promotion cria `operator_profile_items`.
5. Compiler cria `operator_profile_policy_rules`.
6. Runtime registra `operator_profile_feedback_events`.
7. Digest cria `operator_profile_snapshots`.

## Regras para IA

1. Nao salve dados pessoais reais em repo docs.
2. Nao pule candidate/review para escrever profile item sensivel.
3. Nao crie `memory_type` novo sem atualizar `AtlasMemoryEntry` e testes.
4. Use `raw_excerpt_hash` quando raw text nao for necessario.

## Escopo de Implementacao

Migrations implementadas:

- `create_operator_learning_signals_table`
- `create_operator_learning_candidates_table`
- `create_operator_profile_items_table`
- `create_operator_profile_policy_rules_table`
- `create_operator_profile_feedback_events_table`
- `create_operator_profile_snapshots_table`

## Dependencias

- `atlas-operator-intelligence-layer.md`
- `atlas-learning-taxonomy-170.md`
- `AiMemoryDelta`
- `AtlasMemoryEntry`

## Evidencias

O runtime inicial existe via migrations e models listados no frontmatter. FKs
fortes podem ser adicionadas depois de estabilizar rollout e dados existentes.

## Riscos

- Sem signal log, nao ha auditabilidade.
- Sem candidate review, preferencias falsas viram comportamento.
- Sem profile registry, cada provider reinventa contexto.
- Sem feedback events, o Atlas nao sabe se aprendeu certo.

## Exemplos

Um sinal sobre estilo de resposta entra em `operator_learning_signals`, vira
candidato em `operator_learning_candidates`, e so depois de aprovacao vira
`operator_profile_items` com effect `response_style`.

## Proximas Acoes

- Expandir constraints FK.
- Adicionar testes de migration em banco real alem de `migrate --pretend`.
- Monitorar crescimento de signals e snapshots.

## Tabelas Planejadas

### operator_learning_signals

Campos:

- `id`
- `operator_id`
- `taxonomy_item_id`
- `signal_kind`
- `source_type`
- `source_ref_type`
- `source_ref_id`
- `trace_id`
- `session_id`
- `raw_excerpt_hash`
- `normalized_claim`
- `evidence_refs`
- `privacy_class`
- `risk_level`
- `confidence`
- `scope_type`
- `scope_id`
- `valid_from`
- `valid_until`
- `metadata`
- timestamps

### operator_learning_candidates

Campos:

- `id`
- `signal_id`
- `operator_id`
- `taxonomy_item_id`
- `claim`
- `value`
- `status`
- `confidence`
- `conflict_group`
- `supersedes_id`
- `requires_confirmation`
- `auto_apply_eligible`
- `gate_receipt`
- `decided_by`
- `decided_at`
- timestamps

Statuses: `candidate`, `needs_review`, `approved`, `rejected`, `superseded`,
`archived`.

### operator_profile_items

Campos:

- `id`
- `operator_id`
- `taxonomy_item_id`
- `profile_key`
- `value`
- `summary`
- `scope_type`
- `scope_id`
- `validity_kind`
- `valid_from`
- `valid_until`
- `confidence`
- `privacy_class`
- `automation_level`
- `status`
- `source_candidate_id`
- `source_memory_entry_id`
- `last_applied_at`
- timestamps

`validity_kind`: `permanent`, `project`, `session`, `temporary`.

`automation_level`: `observe`, `suggest`, `auto_apply_reversible`,
`auto_apply_after_report`, `autonomous_gated`.

### operator_profile_policy_rules

Campos:

- `id`
- `operator_profile_item_id`
- `rule_key`
- `rule`
- `applies_to_flow`
- `priority`
- `effect`
- `reverse_handle`
- timestamps

Effects: `context_hint`, `response_style`, `approval_gate`,
`autonomy_limit`, `do_not_do`, `workflow_preference`, `tool_preference`,
`handoff_preference`.

### operator_profile_feedback_events

Campos: `id`, `operator_profile_item_id`, `trace_id`, `session_id`,
`feedback_action`, `feedback_score`, `outcome`, `created_at`.

### operator_profile_snapshots

Campos: `id`, `operator_id`, `snapshot_kind`, `summary`, `profile_hash`,
`included_item_ids`, `metadata`, timestamps.

## Markdown E Memory Bridge

Repo docs canonicos:

- `atlas-operator-intelligence-layer.md`
- `operator-intelligence/storage-and-data-model.md`
- `operator-intelligence/implementation-file-map.md`
- `operator-intelligence/automation-context-and-safety.md`

Projection privada implementada por `OperatorProfileProjectionService`:

- `storage/app/atlas/operator-intelligence/{operator_hash}/profile.md`
- `storage/app/atlas/operator-intelligence/{operator_hash}/review-queue.md`
- `storage/app/atlas/operator-intelligence/{operator_hash}/weekly-digest.md`

Memory Core:

- use tipos existentes `preference`, `feedback`, `decision`,
  `strategic_insight` e `anti_memory`;
- `AiMemoryDelta` pode carregar proposta;
- `AtlasMemoryEntry` so recebe aprendizado aprovado e estavel.
