# Atlas Self-Construction OS Handoff

## Status

- Repo: `/Users/vitorepf/develop/Atlas/atlas-server`
- Data do handoff: 2026-05-14
- Conversa original no Codex: `Aprimorar constelação do inbox`
- Nome canônico da missão: `Atlas Self-Construction OS`
- Submódulo ativo: `Atlas Agent Control Plane`
- Estado deste arquivo: handoff canônico para nova sessão
- Regra principal: preservar todas as mudanças existentes; há arquivos modificados e arquivos não rastreados de múltiplas frentes.

## 1. Missão Principal Desta Sessão

### O que significa "Atlas Self-Construction OS"

`Atlas Self-Construction OS` é o programa de evolução que permite ao Atlas construir o próprio Atlas com governança, documentação, divisão de escopo, execução por múltiplas sessões/agentes, evidência, validação e continuidade operacional. O objetivo não é apenas "uma IA programar", mas criar o sistema que permite várias IAs/sessões trabalharem no mesmo macroprograma sem perder contexto, sem colisão de arquivos, sem duplicar decisões e sem poluir memória/governança.

Na prática, o Self-Construction OS transforma:

```text
pedido humano amplo
-> contrato-mãe
-> documentação canônica
-> readiness gates
-> task packets
-> dispatch/claim/lease
-> execução por sessão/agente
-> receipts/evidência
-> quality gates
-> repair/escalation
-> learning/propostas
```

O submódulo ativo nesta sequência é o `Atlas Agent Control Plane`, responsável por transformar esse sistema em operação concreta:

```text
workers/agentes
-> runs
-> tasks
-> workspaces
-> leases
-> liveness
-> provider adapters
-> dispatch release gates
-> execution receipts
-> evidence ledger
-> continuation summaries
```

### Problema de produto/arquitetura

O Atlas precisa sair de um fluxo dependente de uma conversa longa e virar um sistema em que uma nova sessão do Codex, ou outro agente no futuro, consiga entrar no repo, ler os contratos certos, receber uma task segura, implementar dentro de um escopo permitido, registrar evidência e devolver continuidade sem depender de memória humana.

Os problemas principais que esta missão resolve:

- Sessões longas perdem contexto e ficam caras.
- Múltiplos agentes podem colidir se não houver contrato, claim, lease e allowed files.
- Provider diferente precisa receber contexto operacional suficiente, sem consumir histórico inteiro.
- Execução precisa de gates, receipts, evidência e liveness para não virar "chat solto programando".
- O Atlas precisa preparar o caminho para `Atlas Self-Programming OS`, mas ainda sem pular governança.
- A documentação precisa ser o contrato-mãe para impedir regressão, bagunça e decisões esquecidas.

### Resultado final esperado

Resultado esperado para o `Atlas Self-Construction OS` nesta fase:

- Uma nova sessão consegue ler um handoff/packet canônico e continuar sem histórico.
- O Atlas consegue gerar/validar start packets para sessões Codex.
- Tasks podem ser divididas, claimadas e executadas com escopo explícito.
- Cada execução tem estado, liveness, receipt, evidência e gates.
- O sistema diferencia "pronto para execução", "bloqueado", "precisa de operador" e "falha recuperável".
- O `Atlas Agent Control Plane` absorve padrões robustos inspirados no Paperclip: runtime state, heartbeat runs, checkout locks, execution workspace, cost events, activity log, approvals, adapters, liveness e continuation summary.
- O caminho fica preparado para dois ou mais Codex trabalharem em paralelo com worktrees/escopos separados.
- O próximo patamar, `Atlas Self-Programming OS`, fica bloqueado até o Self-Construction OS ter controle operacional suficiente.

### Contexto secundário: Inbox/Constellation

A conversa original no Codex se chama `Aprimorar constelação do inbox`, e há contexto real de Inbox/Constellation registrado neste handoff porque foi a origem da thread. Porém, a missão ativa e o nome canônico deste handoff são `Atlas Self-Construction OS`. Inbox/Constellation deve ser tratado como contexto arquitetural preservado, não como próximo elo técnico salvo se o operador pedir explicitamente.

## 2. Estado Atual Do Repo

### Diretório correto

Trabalhar em:

```bash
/Users/vitorepf/develop/Atlas/atlas-server
```

O workspace raiz `/Users/vitorepf/develop/Atlas` existe, mas a implementação Laravel/backend e a KB usada nesta sessão estão em `atlas-server`.

### Repos/pacotes envolvidos

Confirmado nesta sessão:

- Backend Laravel: `/Users/vitorepf/develop/Atlas/atlas-server`
- Docs/KB canônica: `/Users/vitorepf/develop/Atlas/atlas-server/docs/engineering-knowledge-base`
- Runtime Python de voz aparece no git status, mas não faz parte da missão `Atlas Self-Construction OS` nem do `Atlas Agent Control Plane` atual.

Incerto:

- App mobile/frontend específico do Inbox não foi inspecionado nesta etapa. Verificar no workspace se houver pacote mobile separado.

### Arquivos principais relacionados a Inbox/Constellation

Backend/API:

- `routes/api.php`
- `app/Http/Controllers/InboxController.php`
- `app/Http/Controllers/Mobile/MobileInboxController.php`
- `app/Http/Controllers/AtlasAiInboxActionReportController.php`
- `app/Http/Resources/AiInboxItemResource.php`
- `app/Models/AiInboxItem.php`
- `app/Models/Capture.php`
- `app/Models/SemanticCurationProposal.php`
- `app/Models/AiMemoryDelta.php`
- `app/Models/AtlasMemoryEntry.php`
- `app/Models/AtlasOpenBrainAccessLog.php`

Services:

- `app/Services/Ai/Mobile/AtlasInboxService.php`
- `app/Services/Ai/Mobile/InboxActionRegistry.php`
- `app/Services/Ai/Mobile/ProposalInboxEmitter.php`
- `app/Services/Ai/Mobile/InsightInboxEmitter.php`
- `app/Services/Ai/Mobile/JobResultInboxEmitter.php`
- `app/Services/Ai/Mobile/AiCriticalInboxReviewReadModel.php`
- `app/Services/Ai/Capture/CaptureInboxPipelineReadModel.php`
- `app/Services/Ai/Capture/CaptureInboxPipelineContractBackfill.php`
- `app/Services/CaptureService.php`
- `app/Services/Semantic/CurationProposalService.php`
- `app/Services/Semantic/CaptureSemanticClarifier.php`
- `app/Services/Ai/AtlasOpenBrainService.php`
- `app/Services/Ai/AtlasOpenBrainContextInjectionService.php`
- `app/Services/Ai/AiMemoryDeltaProposer.php`
- `app/Services/Ai/AtlasMemoryDeltaPromotionService.php`

Commands:

- `app/Console/Commands/AtlasAiCaptureInboxPipelineReportCommand.php`
- `app/Console/Commands/AtlasAiCaptureInboxPipelineBackfillContractsCommand.php`
- `app/Console/Commands/AtlasAiInboxActionReportCommand.php`
- `app/Console/Commands/AtlasCliInboxCommand.php`
- `app/Console/Commands/AtlasInsightCommand.php`
- `app/Console/Commands/AtlasProposalCommand.php`
- `app/Console/Commands/AtlasProposalScanCommand.php`
- `app/Console/Commands/AtlasOpenBrainContextCommand.php`
- `app/Console/Commands/AtlasOpenBrainMcpCommand.php`

Migrations/tabelas:

- `database/migrations/2026_04_30_152000_create_ai_inbox_items_table.php`
- `database/migrations/2026_04_29_140000_create_domains_and_inbox_destination_tables.php`
- `database/migrations/2026_04_29_141500_harden_domain_and_inbox_health_constraints.php`
- `database/migrations/2026_05_01_124500_add_perf_indexes_for_chat_and_inbox.php`
- `database/migrations/2026_04_28_050000_create_semantic_memory_tables.php`
- `database/migrations/2026_04_30_122000_create_ai_memory_deltas_table.php`
- `database/migrations/2026_05_02_000000_create_atlas_memory_entries_table.php`
- `database/migrations/2026_05_03_130000_create_atlas_open_brain_access_logs_table.php`

Tests:

- `tests/Feature/Ai/AtlasAiCaptureInboxPipelineReportCommandTest.php`
- `tests/Feature/Ai/AtlasAiInboxActionReportCommandTest.php`
- `tests/Feature/Ai/AtlasAiInboxActionReportApiTest.php`
- `tests/Feature/Ai/InboxLedgerProjectionActionTest.php`
- `tests/Unit/Ai/ProposalInboxEmitterTest.php`
- `tests/Unit/Ai/AiInboxItemResourceTest.php`
- `tests/Unit/Ai/AtlasOpenBrainContextInjectionServiceTest.php`

### Arquivos principais relacionados à missão ativa real desta sessão

A implementação ativa nos últimos ciclos foi o **Atlas Self-Construction OS / Atlas Agent Control Plane**, não o Inbox/Constellation direto.

Arquivos modificados/tocados:

- `app/Services/Ai/SelfConstruction/AtlasSelfConstructionReadinessService.php`
- `app/Console/Commands/AtlasAiSelfConstructionCommand.php`
- `tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php`
- `docs/engineering-knowledge-base/self-construction/agent-control-plane-contract.md`

Arquivos novos não rastreados nesta frente:

- `app/Services/Ai/SelfConstruction/AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerManualStartExecutorReceiptInvoker.php`
- `app/Services/Ai/SelfConstruction/AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerOperatorStartHandoffInvoker.php`
- `app/Services/Ai/SelfConstruction/AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartReceiptContractInvoker.php`
- `app/Services/Ai/SelfConstruction/AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartEvidenceReceiptInvoker.php`
- `app/Services/Ai/SelfConstruction/AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartEvidenceAcceptanceBridgeInvoker.php`
- `app/Services/Ai/SelfConstruction/AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartLivenessMonitorInvoker.php`
- `app/Services/Ai/SelfConstruction/AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartDispatchReleaseGateInvoker.php`
- Tests correspondentes em `tests/Feature/Ai/AtlasAiSelfConstructionAgentAutomaticDispatchSchedulerOneShotTickCodexRealInvoker...Test.php`

### Arquivos que NÃO devem ser mexidos sem necessidade

Há mudanças não relacionadas que devem ser preservadas e não revertidas:

- `app/Console/Commands/AtlasEngineeringBenchmarkCommand.php`
- `app/Console/Commands/AtlasEngineeringBenchmarkFairCommand.php`
- `app/Services/Ai/AiGatewayService.php`
- `app/Services/Ai/AiWorker.php`
- `app/Services/Ai/ClaudeCliProvider.php`
- `app/Services/Ai/Concerns/RunsCliProcesses.php`
- `app/Services/Engineering/EngineeringBenchmarkService.php`
- `app/Services/Engineering/EngineeringHarnessRunnerService.php`
- `app/Services/Engineering/EngineeringTestMatrixService.php`
- `tests/Feature/EngineeringHarnessRunnerTest.php`
- `runtimes/python/voice_realtime/...`
- `docs/engineering-knowledge-base/adr/0002-voice-realtime-sdk-loop-kernel-response-path.md`
- `docs/engineering-knowledge-base/atlas-ai-voice-realtime-canon-de-fala.md`

Esses parecem pertencer a outras frentes: benchmark/engineering harness e Voice Realtime. Não tocar, não formatar, não reverter.

### Mudanças existentes que devem ser preservadas

Preservar todo o estado sujo atual. O `git status --short` no momento deste handoff mostra múltiplas modificações e arquivos novos. A próxima sessão deve começar com `git status --short`, ler os arquivos que vai editar e trabalhar só no escopo escolhido.

## 3. Decisões Arquiteturais Tomadas

### Nomes canônicos

- `Atlas Inbox Constellation`: conceito preservado da conversa original para a evolução do Inbox como grafo/superfície operacional. Não é o nome deste handoff.
- `Capture/Inbox Pipeline`: nome já presente no código via `CaptureInboxPipelineReadModel` e comando `atlas:ai:capture-inbox-pipeline-report`.
- `Atlas Self-Construction OS`: programa de evolução que governa a construção do próprio Atlas.
- `Atlas Agent Control Plane`: submódulo do Self-Construction OS responsável por runs, leases, dispatch, liveness, provider adapters, receipts, gates e evidência.
- `Obras Shared Workspace / Forge Workspace`: conceito discutido como escritório compartilhado para múltiplas IAs trabalharem com contexto comum. Ainda não deve substituir o Agent Control Plane; deve integrá-lo no futuro.
- `Atlas Self-Programming OS`: próximo patamar depois do Self-Construction OS, quando o Atlas puder se programar com autonomia muito maior. Ainda não é o estágio atual.

### Modelo de confiança para Inbox/Constellation

Regra central confirmada no código/docs:

```text
captura crua não é memória
captura crua não é contexto
captura crua não é evidência final
captura crua não entra no Open Brain
```

`CaptureService::withCognitiveQuarantine()` cria `metadata.cognitive_quarantine` com:

- `memory_eligible=false`
- `context_eligible=false`
- `constellation_eligible=false`
- `embedding_allowed=false`
- `provider_export_allowed=false`
- `open_brain_context_allowed=false`
- `raw_content_exposed=false`
- `review.required=true`

`docs/engineering-knowledge-base/memory-core-maturity-dod.md` também define que Constelação exige `constellation_eligible=true` e valor semântico.

### Contratos/gates relevantes

Inbox/Capture:

- `atlas.capture_inbox_pipeline_report.v1`
- `atlas.capture_inbox_pipeline.promotion_gate.v1`
- `atlas.inbox_item.safety.v1`
- `proactive_delivery_contract` dentro de `AiInboxItemResource::safetySummary()`

Self-Construction / Agent Control Plane:

- `Atlas Agent Control Plane`
- `Decision Receipt`
- `Evidence Ledger`
- `Agent Run`
- `Heartbeat Runs`
- `Checkout Lock`
- `Execution Workspace`
- `Cost Events`
- `Activity Log`
- `Approvals`
- `Adapter Abstraction`
- `Run Liveness`
- `Continuation Summary`

Post-start Codex real invoker chain já em andamento:

```text
manual start executor receipt
-> operator start handoff
-> post-start receipt contract
-> post-start evidence receipt
-> post-start evidence acceptance bridge
-> post-start liveness monitor
-> post-start dispatch release gate
-> signed dispatch authorization gate
```

O último item está parcialmente em implementação.

### Regras de governança

- Nunca promover captura para memória/Open Brain sem review/gate.
- Inbox pode pedir ação humana, mas não deve executar ações sensíveis automaticamente.
- Push deve ser pointer-only: sem payload bruto, sem corpo sensível.
- `InboxActionRegistry` deve registrar action requested/completed em audit/evidence.
- Ações sensíveis no mobile passam por `MobileGatewayRateLimiter`.
- `ProposalInboxEmitter` deduplica e cria `ContextBundle`.
- Open Brain compõe contexto provider-safe, não lê arquivos/capturas arbitrárias.
- Agent Control Plane status/projeções devem ser read-only quando marcadas como status/preflight/contract.
- Runtime não decide; kernel/receipt/policy decidem.
- Provider/adapter não pode burlar policy.
- Self-programming permanece proibido até gates explícitos.

### Limites entre módulos

- Inbox:
  - Superfície de revisão, ação e notificação.
  - Entidade principal: `AiInboxItem`.
  - Não deve ser fonte final de verdade de memória.

- Captura:
  - Entrada bruta e privada.
  - Entidade principal: `Capture`.
  - Nasce em quarentena.

- Curadoria:
  - Transforma captura em proposta.
  - Entidade principal: `SemanticCurationProposal`.
  - Requer revisão/ratificação.

- Memória:
  - Guarda conhecimento promovido.
  - Entidades: `AtlasMemoryEntry`, `AiMemoryDelta`, relações/usage.
  - Não deve aceitar raw/untrusted sem gate.

- Open Brain:
  - Compositor de contexto provider-safe.
  - Registra acesso em `atlas_open_brain_access_logs`.
  - Não é inbox, não é capture storage, não é memória crua.

- Worker:
  - Deve processar jobs, manutenção, relatórios e projeções.
  - Não deve decidir ações sensíveis sem contrato/receipt.

- Backend:
  - Fonte de verdade operacional.
  - Exponha APIs/commands com contratos testados.

- Mobile/UI:
  - Mostra itens, ações, contexto e discussão.
  - Não deve receber payload bruto via push.

## 4. Implementações Já Feitas

### Inbox/Constellation já existente no repo

Pronto:

- `AiInboxItem` com tipos, status, payload, actions, dedupe, response, context bundle e push policy.
- Migration `ai_inbox_items` com índice único parcial para dedupe ativo.
- `AtlasInboxService` para criar/listar/ler/dispensar/snoozar itens.
- `MobileInboxController` com endpoints:
  - `GET /mobile/inbox`
  - `GET /mobile/inbox/critical-review`
  - `GET /mobile/inbox/{inboxItem}`
  - `POST /mobile/inbox/{inboxItem}/read`
  - `POST /mobile/inbox/{inboxItem}/dismiss`
  - `POST /mobile/inbox/{inboxItem}/snooze`
  - `POST /mobile/inbox/{inboxItem}/respond`
  - `POST /mobile/inbox/{inboxItem}/discuss`
  - `POST /mobile/inbox/{inboxItem}/discussion-bootstrap/retry`
- `InboxController` para capture inbox/backend:
  - `GET /inbox`
  - `GET /inbox/health`
  - `POST /inbox/bulk`
- `InboxActionRegistry` com ações como:
  - `mark_read`
  - `dismiss`
  - `snooze`
  - `discuss`
  - `approve_once`
  - `approve_session`
  - `approve_workspace_1h`
  - `deny`
  - `view_trace`
  - `review_patch`
  - `create_proposal`
  - `run_ledger_projection`
  - `review_retrieval_regression`
  - `review_retrieval_shadow_scope`
  - `review_external_vector_rag_preflight`
  - `record_rivals_review`
  - `configure_provider_cost_rates`
  - `ignore_30d`
  - recommendation actions
- `ProposalInboxEmitter` cria propostas com `ContextBundle`, dedupe e payload sanitizado.
- `AiInboxItemResource` expõe `safety` e `presentation`.
- `CaptureInboxPipelineReadModel` gera relatório read-only do pipeline.
- `atlas:ai:capture-inbox-pipeline-report --json` existe.
- `atlas:ai:inbox-action-report --json` existe.

Parcial/pendente:

- Não foi implementada uma UI explícita de "constellation graph" neste turno.
- Não foi confirmado se há visualização Obsidian/graph para Inbox.
- Não foi implementado novo relacionamento persistente específico `inbox_constellation_edges`.
- Não foi implementado novo read model "Atlas Inbox Constellation Graph".

### Self-Construction / Agent Control Plane implementado nesta sequência

Concluído em ciclos anteriores da mesma conversa:

- Scheduler bridge para manual start executor receipt.
- Scheduler bridge para operator start handoff.
- Scheduler bridge para post-start receipt contract.
- Scheduler bridge para post-start evidence receipt.
- Scheduler bridge para post-start evidence acceptance bridge.
- Scheduler bridge para post-start liveness monitor.
- Testes focados para os bridges acima.
- Readiness/CLI/status JSON para esses estágios em `AtlasSelfConstructionReadinessService` e `AtlasAiSelfConstructionCommand`.
- Documentação em `docs/engineering-knowledge-base/self-construction/agent-control-plane-contract.md`.

Concluído nesta última etapa antes do handoff:

- `AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartLivenessMonitorInvoker`
- `AtlasAiSelfConstructionAgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartLivenessMonitorInvokerTest`
- CLI/readiness/tests de comando para liveness monitor.
- Docs do liveness monitor no Agent Control Plane contract.

Parcial/interrompido:

- Iniciado o próximo slice:
  - `AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartDispatchReleaseGateInvoker.php`
  - `AtlasAiSelfConstructionAgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartDispatchReleaseGateInvokerTest.php`
  - alterações em `AtlasSelfConstructionReadinessService.php`
  - alterações em `AtlasAiSelfConstructionCommand.php`
- Esse slice foi interrompido antes de adicionar render CLI completo, testes de comando e validação final.
- Antes de continuar implementação, validar sintaxe e completar wiring/testes.

Atalho/dívida técnica aceita:

- O Agent Control Plane ainda está sendo ativado em fatias muito granulares.
- O dispatch real continua bloqueado; isto é intencional.
- O fluxo ainda é Codex-first neste mês; multi-provider completo vem depois.

## 5. Validações Realizadas

### Validações de liveness monitor realizadas e passaram

Sintaxe:

```bash
php -l app/Services/Ai/SelfConstruction/AtlasSelfConstructionReadinessService.php
php -l app/Console/Commands/AtlasAiSelfConstructionCommand.php
php -l tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php
php -l app/Services/Ai/SelfConstruction/AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartLivenessMonitorInvoker.php
php -l tests/Feature/Ai/AtlasAiSelfConstructionAgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartLivenessMonitorInvokerTest.php
```

Testes:

```bash
php artisan test tests/Feature/Ai/AtlasAiSelfConstructionAgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartLivenessMonitorInvokerTest.php tests/Feature/Ai/AtlasAiSelfConstructionAgentCodexRealInvokerPostStartLivenessMonitorTest.php
```

Resultado: 13 testes passaram, 51 assertions.

```bash
php artisan test tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php --filter='post_start_liveness_monitor'
```

Resultado: 7 testes passaram, 75 assertions.

Formatação:

```bash
php -d memory_limit=512M ./vendor/bin/pint app/Services/Ai/SelfConstruction/AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartLivenessMonitorInvoker.php tests/Feature/Ai/AtlasAiSelfConstructionAgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartLivenessMonitorInvokerTest.php app/Services/Ai/SelfConstruction/AtlasSelfConstructionReadinessService.php app/Console/Commands/AtlasAiSelfConstructionCommand.php tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php
```

Resultado: passed.

Projeção:

```bash
php artisan atlas:ai:self-construction --agent-control-plane --json | rg 'next_required_slice|post_start_liveness|post_start_dispatch_release|not_yet_runtime_capable|agent_control_plane_ready|status'
```

Resultado na época: `agent_control_plane_ready`, apontando próximo slice para `activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_dispatch_release_gate_contract`.

Docs/arquitetura:

```bash
php artisan atlas:engineering:knowledge docs-health --json
php artisan atlas:ai:architecture-validate --json
git diff --check
```

Resultados:

- docs-health: `status=ok`
- architecture-validate: `status=ok`
- git diff --check: sem saída

Aviso conhecido:

- `architecture-validate` reportou drift em `ai_traces` dentro de `ledger_projections`, mas o status geral ficou `ok`. Isso apareceu como atenção operacional, não como regressão deste slice.

### Validações pendentes após interrupção

Como o slice de dispatch release gate foi iniciado e interrompido, rodar antes de prosseguir:

```bash
php -l app/Services/Ai/SelfConstruction/AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartDispatchReleaseGateInvoker.php
php -l tests/Feature/Ai/AtlasAiSelfConstructionAgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartDispatchReleaseGateInvokerTest.php
php -l app/Services/Ai/SelfConstruction/AtlasSelfConstructionReadinessService.php
php -l app/Console/Commands/AtlasAiSelfConstructionCommand.php
php artisan test tests/Feature/Ai/AtlasAiSelfConstructionAgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartDispatchReleaseGateInvokerTest.php tests/Feature/Ai/AtlasAiSelfConstructionAgentCodexRealInvokerPostStartDispatchReleaseGateTest.php
```

Depois de completar CLI/tests/docs:

```bash
php artisan test tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php --filter='post_start_dispatch_release_gate'
php -d memory_limit=512M ./vendor/bin/pint <arquivos tocados>
php artisan atlas:ai:self-construction --agent-control-plane --json | rg 'next_required_slice|post_start_dispatch_release|signed_dispatch_authorization|not_yet_runtime_capable|agent_control_plane_ready|status'
php artisan atlas:engineering:knowledge docs-health --json
php artisan atlas:ai:architecture-validate --json
git diff --check
```

## 6. Estado Do Inbox/Constellation

### Como funciona hoje

Fluxo atual confirmado no código:

```text
CaptureService cria Capture
-> metadata.content_intelligence
-> metadata.cognitive_quarantine
-> Semantic/CurationProposalService pode criar SemanticCurationProposal
-> AiMemoryDeltaProposer pode propor delta
-> Inbox recebe propostas/alertas/insights por emitters
-> MobileInboxController lista/aciona item
-> InboxActionRegistry executa ação governada
-> Audit/Evidence registra ação
-> Open Brain só compõe contexto a partir de material provider-safe
```

`CaptureInboxPipelineReadModel` já mede:

- capturas recentes;
- capturas com/sem quarentena;
- capturas com/sem content intelligence;
- capturas unsafe;
- propostas sem backlink correto;
- propostas pendentes/stale;
- memory deltas pendentes/promovidos;
- itens de Inbox ativos;
- promotion gate read-only.

### Como deve funcionar

Estado desejado:

```text
Inbox Item
-> source refs
-> context bundle
-> available actions
-> dedupe key
-> safety contract
-> action receipt
-> ledger event
-> memory/open brain effect only after gates
-> graph/constellation view
```

O Inbox deve ser uma porta de decisão humana e operacional, não um executor livre.

### Entidades/relacionamentos existentes

Existentes:

- `ai_inbox_items`
- `captures`
- `capture_links`
- `semantic_curation_proposals`
- `ai_memory_deltas`
- `atlas_memory_entries`
- `atlas_memory_entry_relations`
- `atlas_memory_entry_usages`
- `atlas_open_brain_access_logs`
- `atlas_ledger_events`
- `ai_context_bundles` mencionado por `ProposalInboxEmitter` e `AiInboxItemResource`; verificar migration/model antes de editar.

Relações atuais:

- `AiInboxItem` pertence a `AiContextBundle` por `context_bundle_id`.
- `AiInboxItem` tem `source_type/source_id`.
- `AiInboxItem` tem `available_actions`, `payload`, `response`, `dedupe_key`.
- `Capture` pode ter links via `capture_links`.
- `SemanticCurationProposal` aponta para capture via `source_refs.capture_id`.
- `Capture.metadata.semantic_curation.proposal_id` pode fazer backlink.
- `Open Brain` registra acesso em `atlas_open_brain_access_logs`.

Relação desejada ainda não implementada:

- Read model/grafo explícito de constelação para visualizar nós e edges entre Inbox, capture, proposal, memory, decision, evidence e action.

### Captura, classificação, deduplicação, enriquecimento, memória e Open Brain

Captura:

- `CaptureService::create()` usa `client_id` para dedupe/replay.
- Toda captura nova passa por `withCognitiveQuarantine()`.

Classificação/enriquecimento:

- `content_intelligence` e `cognitive_quarantine` ficam em `Capture.metadata`.
- `CaptureSemanticClarifier` ajuda a decidir se deve propor curadoria.

Deduplicação:

- Captura: por `client_id`.
- Inbox: por `user_id + dedupe_key` enquanto status não estiver `resolved`, `dismissed` ou `expired`.
- `AtlasInboxService::create()` expira itens antigos de mesma dedupe key e atualiza existente quando aplicável.

Memória:

- `AiMemoryDelta` representa proposta/delta.
- Promoção deve passar por revisão/receipt.
- Captura crua não entra direto.

Open Brain:

- Deve receber somente material seguro/provider-safe.
- `AtlasOpenBrainContextInjectionService` injeta contexto em flows de programação/review/debug.
- `open_brain_context_allowed=false` em captura crua.

### Fluxos UI/UX esperados

Mobile/API atual:

- Lista com status/tipo/severidade/limite/cursor.
- Show item com context bundle carregado.
- Ações: read/dismiss/snooze/respond/discuss/retry bootstrap.
- Critical review endpoint.

UX desejada para Constellation:

- Mostrar item como nó com origem e relações.
- Mostrar por que apareceu no Inbox.
- Mostrar ações seguras disponíveis.
- Mostrar contexto mínimo necessário.
- Mostrar se pode virar memória, proposta, task, decisão ou apenas descarte.
- Mostrar trilha: captura -> proposta -> inbox -> ação -> evidência -> memória/Open Brain.

## 7. Próximos Passos Recomendados

### Próximo elo técnico exato se continuar Agent Control Plane

Completar o slice interrompido:

```text
activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_dispatch_release_gate_contract
```

Arquivos já iniciados:

- `app/Services/Ai/SelfConstruction/AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartDispatchReleaseGateInvoker.php`
- `tests/Feature/Ai/AtlasAiSelfConstructionAgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartDispatchReleaseGateInvokerTest.php`
- `app/Services/Ai/SelfConstruction/AtlasSelfConstructionReadinessService.php`
- `app/Console/Commands/AtlasAiSelfConstructionCommand.php`

Ainda falta nesse slice:

- Verificar sintaxe dos novos arquivos.
- Completar render CLI humano para as quatro opções scheduler-specific:
  - contract
  - preflight
  - implementation-packet
  - status
- Adicionar testes JSON em `AtlasAiSelfConstructionCommandTest`.
- Atualizar `agent-control-plane-contract.md` com dispatch release gate scheduler bridge.
- Rodar testes e Pint.
- Confirmar projeção aponta para:
  - `activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_signed_dispatch_authorization_gate_contract`

### Próximo elo técnico se voltar para Inbox/Constellation

Criar primeiro read model canônico, sem alterar UI ainda:

```text
AtlasInboxConstellationReadModel
```

Escopo recomendado:

- Serviço read-only em `app/Services/Ai/Mobile` ou `app/Services/Ai/Inbox`.
- Command/API read-only para projetar nós/edges.
- Não criar tabela nova no primeiro passo.
- Usar dados existentes:
  - `ai_inbox_items`
  - `captures`
  - `capture_links`
  - `semantic_curation_proposals`
  - `ai_memory_deltas`
  - `atlas_memory_entries`
  - `atlas_ledger_events`
  - `ai_context_bundles`
- Output JSON:
  - `nodes`
  - `edges`
  - `health`
  - `gates`
  - `unsafe_counts`
  - `next_actions`

Critério do primeiro slice:

- Read-only.
- Sem promoção de memória.
- Sem Open Brain injection.
- Sem mudança mobile.
- Teste cobrindo que captura crua não vira nó provider-safe/memory-safe.

### Ordem de prioridade

1. Fechar o slice interrompido do Agent Control Plane ou reverter conscientemente somente se o operador pedir. Não deixar arquivo parcial sem validação.
2. Se o objetivo voltar ao Inbox/Constellation, implementar read model read-only antes de UI.
3. Depois criar endpoint/API e testes.
4. Depois pensar em UI/graph/Obsidian export.
5. Só depois considerar migrations novas para edges persistentes.

### O que NÃO fazer ainda

- Não liberar dispatch real do Codex.
- Não implementar Self-Programming OS ainda.
- Não promover captura crua para memória.
- Não fazer Open Brain consumir Inbox/captura sem gate.
- Não mexer nos arquivos de Voice Realtime.
- Não mexer nos arquivos de Engineering Benchmark/Harness sem pedido explícito.
- Não criar "constellation graph" persistente antes de provar read model.
- Não voltar a discutir nomes já definidos sem necessidade.

### Critérios para considerar Inbox/Constellation completo

Mínimo operacional:

- Read model de constelação com nós/edges.
- API/command JSON.
- Testes de segurança de captura crua.
- Integração com InboxActionRegistry/evidence sem ação automática.

Enterprise:

- Graph visual no app/mobile ou export Obsidian.
- Health score por nó.
- Dedupe e lineage auditáveis.
- Ações com receipts.
- Open Brain só por gates.
- Replay/read model por ledger.

Absurdo/estado da arte:

- Inbox mostra todo o ciclo de conhecimento/ação do Atlas.
- Cada nó tem fonte, decisão, evidência, qualidade, risco e próximo passo.
- O sistema recomenda revisão, promoção, descarte ou transformação em Obra/Task/Memory.
- IA consegue operar sem histórico de chat porque o grafo é autoexplicativo.

## 8. Prompt De Retomada

Copie e cole em uma nova sessão:

```text
Leia primeiro o handoff canônico:
docs/engineering-knowledge-base/atlas-self-construction-os-handoff.md

Depois rode:
pwd
git status --short

Trabalhe em /Users/vitorepf/develop/Atlas/atlas-server.

Preserve todas as mudanças existentes. Não reverta arquivos sujos que você não tocou. Não mexa em Voice Realtime nem Engineering Benchmark/Harness, a menos que o status mostre que seu escopo depende deles.

Contexto: a conversa se chama "Aprimorar constelação do inbox", mas a implementação ativa mais recente é o Atlas Self-Construction OS / Atlas Agent Control Plane. O último slice completo foi o scheduler-specific Codex real invoker post-start liveness monitor. O slice interrompido é o scheduler-specific post-start dispatch release gate.

Próximo elo técnico preferido:
1. Validar e completar o slice `activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_dispatch_release_gate_contract`.
2. Completar CLI/render/tests/docs para:
   - post-start dispatch release gate contract
   - preflight
   - implementation packet
   - status
3. Rodar sintaxe, testes focados, Pint, docs-health, architecture-validate e git diff --check.
4. Garantir que `atlas:ai:self-construction --agent-control-plane --json` aponte o próximo required slice para `activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_signed_dispatch_authorization_gate_contract`.

Se o operador pedir explicitamente para voltar ao Inbox/Constellation, não comece por UI. Comece por um read model read-only `AtlasInboxConstellationReadModel` que projete nós/edges a partir de `ai_inbox_items`, `captures`, `capture_links`, `semantic_curation_proposals`, `ai_memory_deltas`, `atlas_memory_entries`, `atlas_ledger_events` e `ai_context_bundles`.

Não volte a decisões antigas já resolvidas:
- captura crua não é memória;
- Inbox é superfície de ação/review, não fonte final;
- Open Brain só recebe provider-safe;
- Self-Programming OS ainda não está liberado;
- dispatch real do Codex continua bloqueado até signed authorization gates.

Continue do próximo elo técnico, com escopo estreito, testes e evidência.
```
