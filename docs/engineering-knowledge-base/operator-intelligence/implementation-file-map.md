---
id: operator-intelligence-implementation-file-map
type: engineering_knowledge
title: Operator Intelligence Implementation File Map
status: active
category: learning-governance
priority: 98
summary: File map canonico para implementar o Operator Intelligence Layer com migrations, models, services, commands, API e testes.
tags:
  - atlas-ai
  - operator-intelligence
  - file-map
  - implementation
capabilities:
  - operator_intelligence_implementation
  - operator_learning_file_map
decisions:
  - A implementacao deve ser incremental e com AP antes de runtime/migrations.
  - Services vivem em `app/Services/Ai/OperatorIntelligence`.
  - Commands devem expor capture, review, approve, reject, profile, digest, project e simulate.
  - `OperatorLearningCandidateService` tambem governa auto-apply reversivel apos capture quando a config permite.
maintenance:
  - Atualize este doc quando nomes de arquivos ou fases mudarem.
  - Rode docs-health, sync e index-code apos alteracoes.
related_paths:
  - docs/engineering-knowledge-base/atlas-operator-intelligence-layer.md
  - docs/engineering-knowledge-base/operator-intelligence/storage-and-data-model.md
  - docs/engineering-knowledge-base/operator-intelligence/automation-context-and-safety.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: operator-intelligence-implementation-file-map
graph_title: Operator Intelligence Implementation File Map
graph_world: atlas
graph_layer: module
graph_kind: runbook
graph_parent: atlas-operator-intelligence-layer
graph_status: active
graph_source: repo
human_name: Operator Intelligence Implementation File Map
canonical_name: Operator Intelligence Implementation File Map
technical_name: OperatorIntelligenceImplementationFileMap
cartography_type: runbook
canonical_source: docs/engineering-knowledge-base/operator-intelligence/implementation-file-map.md
owner: learning-governance
repo_paths:
  - docs/engineering-knowledge-base/operator-intelligence/implementation-file-map.md
allowed_changes:
  - Ajustar nomes de classes quando AP aprovado exigir alinhamento com codigo existente.
forbidden_changes:
  - Implementar tudo em uma classe unica.
  - Misturar capture, review, registry, context injection e projection sem boundaries.
depends_on:
  - atlas-operator-intelligence-layer
flows_to:
  - operator-learning-runtime-implementation
unlocks:
  - operator-intelligence-ap
governs:
  - operator-intelligence-files
evidence:
  - docs/engineering-knowledge-base/operator-intelligence/implementation-file-map.md
  - config/atlas_operator_intelligence.php
  - app/Models/OperatorLearningSignal.php
  - app/Models/OperatorLearningCandidate.php
  - app/Models/OperatorProfileItem.php
  - app/Services/Ai/OperatorIntelligence/OperatorSignalCaptureService.php
  - app/Services/Ai/OperatorIntelligence/OperatorLearningClassifier.php
  - app/Services/Ai/OperatorIntelligence/OperatorLearningSignalDetector.php
  - app/Services/Ai/OperatorIntelligence/OperatorLearningRuntimeCaptureService.php
  - app/Services/Ai/OperatorIntelligence/OperatorLearningGate.php
  - app/Services/Ai/OperatorIntelligence/OperatorLearningCandidateService.php
  - app/Services/Ai/OperatorIntelligence/OperatorProfileRegistry.php
  - app/Services/Ai/OperatorIntelligence/OperatorProfilePolicyCompiler.php
  - app/Services/Ai/OperatorIntelligence/OperatorContextComposer.php
  - app/Services/Ai/OperatorIntelligence/OperatorProfileDigestService.php
  - app/Services/Ai/OperatorIntelligence/OperatorProfileProjectionService.php
  - app/Services/Ai/AtlasOpenBrainContextInjectionService.php
  - app/Services/Ai/AiGatewayService.php
  - app/Console/Commands/AtlasOperatorLearningCommand.php
  - app/Console/Commands/AtlasOperatorProfileContextCommand.php
  - app/Http/Controllers/AtlasOperatorIntelligenceController.php
implementation_state: implemented_initial_runtime
required_tests:
  - "git diff --check"
  - "php artisan atlas:engineering:knowledge docs-health --json"
requires_evidence: true
risk_level: high
visual_tags:
  - implementation
  - files
  - services
ai_entrypoints:
  - Leia este doc para saber quais arquivos criar no Operator Intelligence Layer.
ai_usage_notes:
  - File map implementado nao autoriza novas mudancas sem AP/placement quando tocar runtime.
quality_gates:
  - "php artisan atlas:ai:place-feature \"Operator Intelligence Layer\" --json"
failure_modes:
  - Classe gigante sem testabilidade.
  - Runtime criado sem commands de review e digest.
observability_signals:
  - Cada service tem teste unitario ou feature associado.
next_actions:
  - Adicionar UI humana em Atlas Code/Desktop.
---
# Operator Intelligence Implementation File Map

## Resumo

Este doc lista os arquivos criados para implementar o runtime inicial do
Operator Intelligence Layer e os pontos que ainda podem ser expandidos em fases
seguras.

## Papel no Atlas

Ele evita que uma IA implemente a camada de forma improvisada. Cada parte tem
arquivo dono: schema, model, capture, classifier, gate, registry, compiler,
context, feedback, projection e commands.

## Onde Se Encaixa

Filho de `atlas-operator-intelligence-layer.md`. Use junto com
`storage-and-data-model.md` e `automation-context-and-safety.md`.

## Contratos

- Um service nao deve acumular todos os papeis.
- Commands precisam permitir auditoria e review humano.
- Tests acompanham services e comandos.
- UI fica futura, depois de runtime e commands.

## Fluxo

1. Migrations e models.
2. Capture/classify/gate em shadow.
3. Review commands.
4. Profile registry.
5. Policy compiler.
6. Context composer.
7. Projection/digest.
8. Automacao reversivel.

## Regras para IA

1. Nao implemente sem AP quando tocar codigo runtime.
2. Use namespace `App\Services\Ai\OperatorIntelligence`.
3. Nao crie runtime paralelo de memoria.
4. Nao exponha dados privados em command output por padrao.

## Escopo de Implementacao

### Config

- `config/atlas_operator_intelligence.php`

Config minima:

- enabled;
- shadow mode;
- max injected profile items;
- privacy defaults;
- auto-apply disabled by default;
- automatic chat capture enabled for explicit operator-learning signals;
- provider-safe redaction;
- projection path;
- digest cadence.

### Migrations

- `create_operator_learning_signals_table`
- `create_operator_learning_candidates_table`
- `create_operator_profile_items_table`
- `create_operator_profile_policy_rules_table`
- `create_operator_profile_feedback_events_table`
- `create_operator_profile_snapshots_table`

### Models

- `app/Models/OperatorLearningSignal.php`
- `app/Models/OperatorLearningCandidate.php`
- `app/Models/OperatorProfileItem.php`
- `app/Models/OperatorProfilePolicyRule.php`
- `app/Models/OperatorProfileFeedbackEvent.php`
- `app/Models/OperatorProfileSnapshot.php`

### Services

- `OperatorSignalCaptureService`
- `OperatorLearningClassifier`
- `OperatorLearningSignalDetector`
- `OperatorLearningRuntimeCaptureService`
- `OperatorLearningGate`
- `OperatorLearningCandidateService`
- `OperatorProfileRegistry`
- `OperatorProfileConflictResolver`
- `OperatorProfilePolicyCompiler`
- `OperatorContextComposer`
- `OperatorProfileFeedbackService`
- `OperatorProfileDigestService`
- `OperatorProfileProjectionService`
- `AtlasOpenBrainContextInjectionService` consome o contexto provider-safe do
  Operator Intelligence dentro do Open Brain.
- `AiGatewayService` chama `OperatorLearningRuntimeCaptureService` apos criar
  trace para capturar sinais explicitos vindos de fontes humanas permitidas.

### Commands

- `app/Console/Commands/AtlasOperatorLearningCommand.php`
- `app/Console/Commands/AtlasOperatorProfileContextCommand.php`

Subcommands previstos:

- `atlas:operator-learning capture`
- `atlas:operator-learning review`
- `atlas:operator-learning approve`
- `atlas:operator-learning reject`
- `atlas:operator-learning profile`
- `atlas:operator-learning digest`
- `atlas:operator-learning project`
- `atlas:operator-learning simulate`
- `atlas:operator-profile context`

### HTTP e UI futura

- `app/Http/Controllers/AtlasOperatorIntelligenceController.php`
- routes para capture, review queue, candidate review, profile, digest,
  projection e context preview;
- Open Brain recebe `operator_profile_item` refs quando ha profile ativo
  provider-safe;
- surface futura no Atlas Code/Atlas Desktop para pausar, editar, aprovar e
  rejeitar aprendizados.

### Tests

- `tests/Unit/Ai/OperatorIntelligence/OperatorLearningClassifierTest.php`
- `tests/Unit/Ai/OperatorIntelligence/OperatorLearningSignalDetectorTest.php`
- `tests/Unit/Ai/OperatorIntelligence/OperatorLearningGateTest.php`
- `tests/Unit/Ai/OperatorIntelligence/OperatorProfileConflictResolverTest.php`
- `tests/Unit/Ai/OperatorIntelligence/OperatorProfilePolicyCompilerTest.php`
- `tests/Unit/Ai/OperatorIntelligence/OperatorContextComposerTest.php`
- `tests/Feature/Ai/OperatorIntelligence/OperatorLearningReviewCommandTest.php`
- `tests/Feature/Ai/OperatorIntelligence/OperatorLearningGatewayCaptureTest.php`
- `tests/Feature/Ai/OperatorIntelligence/OperatorProfileContextInjectionTest.php`
- API protegida, auto-apply reversivel e shadow mode ficam cobertos por
  `OperatorLearningReviewCommandTest`.

## Dependencias

- `storage-and-data-model.md`
- `automation-context-and-safety.md`
- `OperatorApprovalGateService`
- `AtlasMemoryLearningPromotionService`
- `AtlasMemoryUsageService`

## Evidencias

Este file map tem runtime inicial implementado, incluindo API protegida,
captura automatica no gateway, auto-apply reversivel gated, shadow mode e
injecao Open Brain. UI humana ainda e fase futura.

## Riscos

- Criar tudo em uma classe grande dificulta automacao futura.
- Pular commands deixa o operador sem revisao.
- Pular projection deixa aprendizado invisivel.
- Pular tests transforma profile em comportamento instavel.

## Exemplos

`OperatorLearningClassifier` nunca deve escrever profile item diretamente. Ele
classifica sinal e passa para `OperatorLearningCandidateService`.

## Proximas Acoes

- Criar UI humana.
- Expandir certificacao com conflitos, pause/archive e bridge Memory Core.

## Definition Of Done

- Tabelas existem.
- Models tem casts e relations.
- Capture gera signal com taxonomy ID.
- Gateway captura sinal explicito de operador em conversa humana e ignora fonte
  interna/sistema.
- Candidate entra em review.
- Approve promove profile item.
- Auto-apply promove candidato elegivel somente com config permissiva e shadow
  desligado.
- Compiler cria regra.
- Context composer injeta contexto seguro.
- Open Brain injeta profile items provider-safe no prompt auditavel.
- Feedback registra utilidade.
- Projection privada nao entra no repo.
- Memory Core bridge nao cria memoria paralela.
