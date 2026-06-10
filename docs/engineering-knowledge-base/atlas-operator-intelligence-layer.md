---
id: atlas-operator-intelligence-layer
type: engineering_knowledge
title: Atlas Operator Intelligence Layer
status: active
category: learning-governance
priority: 99
summary: Blueprint canonico da camada que captura, valida, salva, aplica e aprimora aprendizado do Atlas sobre o operador.
human_summary: Define onde salvar, como revisar, como aplicar e como automatizar aprendizado sobre o operador sem criar Memory Core paralelo.
human_what: Arquitetura-mae de operator profile, storage, review queue, policy compilation, context injection e automacao segura.
human_purpose: Fazer o Atlas aprender o operador com evidencia, privacidade, reversibilidade e automacao segura.
human_input: Sinais de conversa, decisoes, correcoes, aprovacoes, rejeicoes, preferencias, feedback e itens `OP-*`/`COL-*` da taxonomia.
human_output: Operator profile aplicavel, fila de revisao, regras compiladas, contexto provider-safe, projections legiveis e feedback loop.
human_change_when: Atualize antes de criar migrations, services, comandos, UI ou automacao relacionada a aprendizado do operador.
human_block_when: Bloqueie quando uma IA tentar salvar dados privados no repo, criar memoria paralela ou aplicar aprendizado sensivel sem gate.
tags:
  - atlas-ai
  - operator-intelligence
  - operator-profile
  - learning
  - memory
  - autonomous-learning
  - atlas-learning-taxonomy-170
capabilities:
  - operator_intelligence_layer
  - operator_profile_registry
  - operator_learning_storage
  - operator_learning_review_queue
  - operator_context_composition
  - operator_policy_compilation
  - operator_learning_automation
decisions:
  - O Operator Intelligence Layer e uma camada core/general do Atlas, nao dominio separado e nao copia do Hermes.
  - Postgres e a fonte operacional de verdade para sinais, candidatos, profile items, regras compiladas, feedback e snapshots.
  - Markdown versionado no repo guarda arquitetura, schema e runbooks, nunca preferencias privadas reais do operador.
  - Markdown privado gerado pode existir em `storage/app/atlas/operator-intelligence/{operator_hash}/` ou AtlasVault, mas e projecao auditavel.
  - `AtlasMemoryEntry` e `AiMemoryDelta` continuam sendo o bridge canonico para memoria de longo prazo.
  - Aprendizado automatico so aplica itens reversiveis, de baixo risco, com escopo claro e evidencia suficiente.
  - Todo item aprendido deve referenciar a taxonomia `SYS-*`, `OP-*` ou `COL-*`.
maintenance:
  - Leia este doc antes de implementar qualquer arquivo de Operator Intelligence.
  - Leia `atlas-learning-taxonomy-170.md` para mapear cada aprendizado ao ID canonico.
  - Rode docs-health, sync e index-code depois de alterar este contrato.
  - Nao coloque dados pessoais reais do operador dentro de docs versionados.
related_paths:
  - docs/engineering-knowledge-base/atlas-learning-taxonomy-170.md
  - docs/engineering-knowledge-base/operator-intelligence/storage-and-data-model.md
  - docs/engineering-knowledge-base/operator-intelligence/implementation-file-map.md
  - docs/engineering-knowledge-base/operator-intelligence/automation-context-and-safety.md
  - docs/engineering-knowledge-base/atlas-ai-knowledge-governance-system.md
  - docs/engineering-knowledge-base/memory-core-runbook.md
  - docs/engineering-knowledge-base/memory-core-security-privacy.md
  - docs/engineering-knowledge-base/atlas-ai-memory-context-core-open-brain.md
  - docs/engineering-knowledge-base/atlas-learning-mutation-runtime.md
  - docs/engineering-knowledge-base/atlas-local-agent-memory-ingestion.md
  - docs/engineering-knowledge-base/atlas-self-improvement-governance-ladder.md
  - docs/engineering-knowledge-base/atlas-ai-operator-review-approval-gates.md
  - docs/engineering-knowledge-base/open-brain-context-injection.md
  - config/atlas_operator_intelligence.php
  - app/Models/AiMemoryDelta.php
  - app/Models/AtlasMemoryEntry.php
  - app/Models/OperatorLearningSignal.php
  - app/Models/OperatorLearningCandidate.php
  - app/Models/OperatorProfileItem.php
  - app/Models/OperatorProfilePolicyRule.php
  - app/Models/OperatorProfileFeedbackEvent.php
  - app/Models/OperatorProfileSnapshot.php
  - app/Services/Ai/OperatorIntelligence
  - app/Services/Ai/AtlasOpenBrainContextInjectionService.php
  - app/Services/Ai/AiGatewayService.php
  - app/Console/Commands/AtlasOperatorLearningCommand.php
  - app/Console/Commands/AtlasOperatorProfileContextCommand.php
  - app/Http/Controllers/AtlasOperatorIntelligenceController.php
  - app/Services/Ai/AtlasMemoryLearningPromotionService.php
  - app/Services/Ai/AtlasMemoryUsageService.php
  - app/Services/Ai/OperatorApproval/OperatorApprovalGateService.php
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-operator-intelligence-layer
graph_title: Atlas Operator Intelligence Layer
graph_world: atlas
graph_layer: system
graph_kind: contract
graph_parent: atlas-learning-taxonomy-170
graph_status: active
graph_source: repo
human_name: Atlas Operator Intelligence Layer
canonical_name: Atlas Operator Intelligence Layer
technical_name: OperatorIntelligenceLayer
cartography_type: system_contract
canonical_source: docs/engineering-knowledge-base/atlas-operator-intelligence-layer.md
owner: learning-governance
repo_paths:
  - docs/engineering-knowledge-base/atlas-operator-intelligence-layer.md
  - docs/engineering-knowledge-base/operator-intelligence/storage-and-data-model.md
  - docs/engineering-knowledge-base/operator-intelligence/implementation-file-map.md
  - docs/engineering-knowledge-base/operator-intelligence/automation-context-and-safety.md
  - config/atlas_operator_intelligence.php
  - database/migrations/2026_06_08_130000_create_operator_learning_signals_table.php
  - database/migrations/2026_06_08_130100_create_operator_learning_candidates_table.php
  - database/migrations/2026_06_08_130200_create_operator_profile_items_table.php
  - database/migrations/2026_06_08_130300_create_operator_profile_policy_rules_table.php
  - database/migrations/2026_06_08_130400_create_operator_profile_feedback_events_table.php
  - database/migrations/2026_06_08_130500_create_operator_profile_snapshots_table.php
  - app/Services/Ai/OperatorIntelligence
allowed_changes:
  - Adicionar fases, services, comandos e gates quando a implementacao real evoluir.
  - Promover partes deste blueprint para APs quando a fase de codigo comecar.
forbidden_changes:
  - Salvar preferencias privadas reais do operador em docs versionados no repo.
  - Criar um segundo Memory Core paralelo a `AtlasMemoryEntry` e `AiMemoryDelta`.
  - Aplicar aprendizado automatico sensivel sem review, approval gate, evidencia e reversibilidade.
  - Declarar runtime implementado sem migrations, services, comandos, testes e evidence verificaveis.
depends_on:
  - atlas-learning-taxonomy-170
  - atlas-ai-knowledge-governance-system
  - memory-core-runbook
  - atlas-ai-memory-context-core-open-brain
flows_to:
  - atlas-memory-review
  - atlas-context-injection
  - atlas-operator-profile
  - atlas-autonomous-learning
unlocks:
  - operator-aware-context-pack
  - automatic-operator-learning
  - operator-policy-profile
governs:
  - operator-learning-storage
  - operator-profile-registry
  - operator-learning-automation
  - operator-context-composition
evidence:
  - docs/engineering-knowledge-base/atlas-operator-intelligence-layer.md
  - docs/engineering-knowledge-base/atlas-learning-taxonomy-170.md
  - config/atlas_operator_intelligence.php
  - app/Services/Ai/OperatorIntelligence
  - app/Console/Commands/AtlasOperatorLearningCommand.php
  - app/Console/Commands/AtlasOperatorProfileContextCommand.php
  - app/Http/Controllers/AtlasOperatorIntelligenceController.php
  - tests/Feature/Ai/OperatorIntelligence/OperatorLearningReviewCommandTest.php
  - tests/Feature/Ai/OperatorIntelligence/OperatorLearningGatewayCaptureTest.php
  - tests/Unit/Ai/AtlasOpenBrainContextInjectionServiceTest.php
  - tests/Unit/Ai/OperatorIntelligence/OperatorLearningClassifierTest.php
  - tests/Unit/Ai/OperatorIntelligence/OperatorLearningGateTest.php
  - tests/Unit/Ai/OperatorIntelligence/OperatorLearningSignalDetectorTest.php
implementation_state: implemented_initial_runtime
required_tests:
  - "git diff --check"
  - "php artisan test tests/Unit/Ai/OperatorIntelligence/OperatorLearningClassifierTest.php tests/Unit/Ai/OperatorIntelligence/OperatorLearningGateTest.php tests/Feature/Ai/OperatorIntelligence/OperatorLearningReviewCommandTest.php"
  - "php artisan route:list --path=atlas/operator-intelligence"
  - "atlas engineering knowledge docs-health --json"
  - "atlas engineering knowledge sync --prune"
  - "atlas engineering knowledge index-code --workspace=/Users/vitorepf/develop/Atlas/atlas-server --prune --summary-only --json"
requires_evidence: true
risk_level: high
visual_tags:
  - learning
  - memory
  - operator
  - profile
  - automation
ai_entrypoints:
  - Leia este doc quando a pergunta for onde salvar aprendizado do operador, como implementar Operator Intelligence Layer ou como deixar aprendizado do Atlas automatico.
  - Leia primeiro `atlas-learning-taxonomy-170.md` quando precisar saber o que o Atlas deve aprender.
ai_usage_notes:
  - Use este blueprint como contrato e mapa do runtime inicial ja implementado.
  - Todo codigo novo deve passar por feature placement e AP quando tocar migrations, runtime, scheduler, UI ou automacao.
quality_gates:
  - "php artisan atlas:ai:session-bootstrap --task=\"Operator Intelligence Layer\" --json"
  - "php artisan atlas:ai:place-feature \"Operator Intelligence Layer\" --json"
  - "php artisan atlas:engineering:knowledge docs-health --json"
  - "atlas engineering knowledge sync --prune"
  - "atlas engineering knowledge index-code --prune"
failure_modes:
  - IA grava gosto pessoal do operador em doc publico/versionado.
  - IA aprende de uma frase isolada como preferencia permanente sem confirmacao.
  - IA mistura sinal bruto, candidato, memoria aprovada e regra aplicada.
  - IA injeta contexto privado cru em provider externo sem redacao.
observability_signals:
  - Cada profile item tem fonte, taxonomia, confidence, escopo, privacy_class, status e historico.
  - Review queue mostra candidatos pendentes, conflitos, rejeicoes e promocoes.
  - Context Composer reporta quais profile items foram injetados e se ajudaram.
  - Digest periodico mostra o que foi aprendido, ignorado, arquivado e aplicado.
next_actions:
  - Harden com UI humana para review/pausar/editar profile items.
  - Expandir surface humana para revisar capturas automaticas vindas do gateway.
  - Expandir automacao gated com evidence de uso real alem do auto-apply reversivel.
---
# Atlas Operator Intelligence Layer

Este e o contrato-mae para implementar o **Operator Intelligence Layer** do
Atlas. Ele existe para responder onde salvar o que o Atlas aprende sobre o
operador, como revisar, como aplicar e como deixar automatico depois.

## Resumo

O Operator Intelligence Layer transforma os itens `OP-*` e `COL-*` da
taxonomia dos 170 aprendizados em runtime real. Ele observa sinais, normaliza
candidatos, pede revisao quando necessario, promove itens para um perfil
operacional, compila regras aplicaveis e injeta somente contexto seguro nos
fluxos do Atlas.

Resposta curta sobre storage:

- **Postgres**: fonte operacional de verdade.
- **Repo Markdown**: contratos, schema e runbooks, sem dados privados reais.
- **Markdown privado/AtlasVault**: projection humana, nao runtime primario.
- **Memory Core**: memoria longa promovida via `AiMemoryDelta` e
  `AtlasMemoryEntry`.

## Papel no Atlas

Esta camada faz o Atlas aprender o operador como parte central da inteligencia:
gosto, estilo, limites, ritmo, preferencias de decisao, tolerancia a risco,
autonomia permitida, modo de comunicacao e formas de trabalho que reduzem
atrito.

Sem ela, o Atlas lembra sistema, mas continua generico sobre quem opera. Com
ela, o Atlas usa aprendizado sobre o operador para planejar, responder,
aprovar, priorizar, compor contexto e se aprimorar.

## Onde Se Encaixa

Hierarquia correta:

1. `atlas-learning-taxonomy-170.md` define **o que** pode ser aprendido.
2. Este doc define **a arquitetura-mae** do Operator Intelligence Layer.
3. `operator-intelligence/storage-and-data-model.md` define **onde salva**.
4. `operator-intelligence/implementation-file-map.md` define **quais arquivos
   implementar**.
5. `operator-intelligence/automation-context-and-safety.md` define **como
   aplicar, injetar contexto e automatizar**.

O Operator Intelligence Layer e `core_or_general`: serve todos os dominios e
nao pertence a Programming, Finance, Personal Development ou Hermes.

## Contratos

- Todo aprendizado aponta para `SYS-*`, `OP-*` ou `COL-*`.
- Todo sinal bruto e append-only.
- Todo candidato tem status e pode ser rejeitado, superseded ou arquivado.
- Todo profile item ativo tem escopo, confidence, privacy_class e validade.
- Todo auto-apply precisa ser reversivel, auditable e limitado por risco.
- Todo contexto enviado a provider externo precisa ser redigido e provider-safe.
- Dados privados reais do operador nao entram em docs versionados.
- Markdown e projecao; Postgres e fonte operacional; Memory Core e memoria de
  longo prazo.

## Fluxo

1. **Capture**: conversa, decisao, correcao, aprovacao, rejeicao ou feedback
   gera sinal.
2. **Classify**: sinal recebe taxonomia, source, risco, privacidade, escopo e
   confidence.
3. **Normalize**: sinal vira claim curta e estruturada.
4. **Quarantine**: sinais incertos, sensiveis ou contraditorios viram
   candidatos pendentes.
5. **Review**: operador ou gate decide aprovar, rejeitar, pausar ou pedir mais
   evidencia.
6. **Promote**: candidato aprovado vira item ativo no profile registry.
7. **Compile**: profile items viram regras consumiveis por runtime.
8. **Compose**: contexto relevante e provider-safe entra no prompt/context pack.
9. **Observe**: feedback ajusta confidence, prioridade e validade.
10. **Digest**: Atlas mostra o que aprendeu, ignorou e aplicou.

## Regras para IA

1. Nao implemente migrations ou runtime sem AP/placement quando sair de docs.
2. Nao use Hermes como fonte de verdade do Atlas; use a arquitetura do Atlas.
3. Nao salve dados pessoais reais do operador no repo.
4. Nao trate frase isolada, irritacao ou preferencia momentanea como regra
   permanente.
5. Nao injete raw excerpts sensiveis em provider externo.
6. Nao confunda signals, candidates, profile items, policy rules e Memory Core.
7. Nao adicione `memory_type` novo em `AtlasMemoryEntry` sem migracao,
   compatibilidade e testes.

## Escopo de Implementacao

O runtime inicial implementa:

- schema e services com shadow mode default;
- review queue e comandos;
- profile registry e policy compiler;
- context injection provider-safe via API/comando e Open Brain;
- captura automatica de sinais explicitos no `AiGatewayService` para fontes
  humanas permitidas (`manual`, `app`, `voice_realtime`);
- auto-apply reversivel quando `auto_apply_enabled=true`, `shadow_mode=false`
  e todos os gates passam;
- projections privadas e digest;
- API minima para capture, review, profile, context, digest e projection.

Automacao real ja existe para aprendizados reversiveis de baixo risco, mas
continua default-off e bloqueada por shadow mode ate o operador ligar
explicitamente. Context injection em fluxos reais ja entra pelo Open Brain; a
ampliacao de automacao comportamental alem de perfil reversivel ainda deve ser
progressiva depois de evidence de uso real.

## Dependencias

- `atlas-learning-taxonomy-170.md`
- `operator-intelligence/storage-and-data-model.md`
- `operator-intelligence/implementation-file-map.md`
- `operator-intelligence/automation-context-and-safety.md`
- `memory-core-runbook.md`
- `memory-core-security-privacy.md`
- `atlas-ai-memory-context-core-open-brain.md`
- `atlas-learning-mutation-runtime.md`
- `atlas-local-agent-memory-ingestion.md`
- `atlas-self-improvement-governance-ladder.md`
- `atlas-ai-operator-review-approval-gates.md`
- `AiMemoryDelta`
- `AtlasMemoryEntry`
- `OperatorApprovalGateService`

## Evidencias

Estado atual confirmado por codigo/docs:

- Existe `AiMemoryDelta` para propostas de memoria.
- Existe `AtlasMemoryEntry` para memoria promovida.
- Existe `OperatorApprovalGateService` para gates de aprovacao.
- Existem docs canonicos de Memory Core, Open Brain e Learning Mutation.
- A taxonomia dos 170 itens ja separa sistema, operador e convivencia
  operacional.

Este doc agora prova runtime inicial implementado por migrations, models,
services, commands, API, captura automatica no gateway, Open Brain injection,
auto-apply reversivel gated e testes focados. Ele nao declara UI humana final
nem auto-apply global ligado em producao.

## Riscos

- Aprender demais sem curadoria pode virar perfil invasivo.
- Aprender de menos mantem o Atlas generico e sem inteligencia relacional.
- Salvar em Markdown versionado pode vazar preferencias privadas.
- Salvar so em memoria longa perde auditabilidade operacional.
- Aplicar automaticamente sem escopo pode criar comportamento irritante.
- Injetar tudo no contexto pode encarecer, vazar dados e piorar resposta.
- Misturar Hermes e Atlas pode gerar feature duplicada ou governanca paralela.

## Exemplos

- "para de me entregar so proposta, implementa quando eu pedir" pode gerar
  candidato `COL-156`/`COL-157`, mas precisa padrao ou confirmacao para virar
  regra permanente.
- "nao mexa em backups de /Applications" deve virar profile item ou memoria de
  seguranca com alta prioridade e enforcement por policy rule.
- Rejeicoes repetidas de respostas longas podem virar candidato de estilo,
  scoped por tipo de tarefa.
- Aprovacao de auto-sync de KB apos docs pode virar regra automatica
  reversivel para docs, com evidencia e logs.

## Proximas Acoes

1. Criar UI humana para revisar, pausar e editar profile items.
2. Expandir pause/archive/conflict UI e bridge Memory Core.
3. Medir utilidade das capturas automaticas antes de ampliar source types ou
   ligar auto-apply global.
4. Manter auto-apply global desligado por default ate haver evidence de uso real.
