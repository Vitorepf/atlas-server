---
id: atlas-ai-voice-realtime-surface
type: engineering_knowledge
title: Atlas AI Voice Realtime Surface
status: building
category: surface-architecture
priority: 94
implementation_state: certified_scaffold_runtime_boundary_not_promoted
blocker: livekit_agents_sdk_callback_loop_not_wired_to_livekit_callback_router
summary: Contrato canonico do Voice Realtime Surface do Atlas AI. Voz nasce mobile-first, usa LiveKit Agents SDK como runtime conversacional, preserva o Kernel Laravel como decisor soberano e deixa Swift/macOS como edge ambiental posterior.
tags:
  - atlas-ai
  - voice
  - realtime
  - mobile
  - livekit
  - privacy
capabilities:
  - voice_realtime_surface
  - mobile_voice_first
  - livekit_agents_sdk
  - voice_eclipse_governance
  - voice_evidence_ledger
decisions:
  - Voice Realtime e Surface do Atlas AI; nao e domain, provider, tool ou runtime soberano.
  - A primeira experiencia local/produto deve nascer no app mobile, com push-to-talk antes de always-on.
  - LiveKit Agents SDK e o runtime canonico para loop conversacional, STT/TTS, turn detection, interruption e plugins.
  - LiveKit Server/Cloud e transporte WebRTC; ele nao decide modelo, dominio, memoria, tool ou policy.
  - Laravel Kernel continua soberano: todo turno passa por Surface Adapter, Operation Envelope, Atlas Decide e Decision Receipt.
  - Swift Native Mac entra depois como edge ambiental local para Mac: wake word, mic/AirPods, FSEvents, Accessibility e contexto opt-in.
  - Audio raw nao persiste como memoria duravel; o Atlas grava hashes, transcripts governados, eventos e metricas.
  - Rivals-Voice mede se Atlas Voice supera uso direto de voice mode de providers somente depois de readiness e runtime certification.
maintenance:
  - Manter abaixo de 300 linhas.
  - Atualizar quando mobile voice, LiveKit Agents SDK, STT/TTS providers, eclipse rules, latency SLO ou eventos VOICE_* mudarem.
  - Se passar do limite, mover detalhes para AP specs ou runbooks por fase.
related_paths:
  - docs/ap/AP-686-voice-realtime-python-runtime-boundary.md
  - docs/ap/AP-687-voice-realtime-production-promotion-gate.md
  - docs/engineering-knowledge-base/atlas-ai-mobile-surface-gateway.md
  - docs/engineering-knowledge-base/atlas-ai-runtime-language-boundaries.md
  - docs/engineering-knowledge-base/atlas-native-mac-agent.md
  - docs/engineering-knowledge-base/atlas-ai-pipeline.md
  - docs/engineering-knowledge-base/atlas-ai-telemetry-evidence-performance.md
  - docs/engineering-knowledge-base/atlas-ai-thesis-multiplier-channel.md
  - docs/engineering-knowledge-base/atlas-ai-documentation-operating-system.md
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-voice-realtime-surface

graph_title: Atlas AI Voice Realtime Surface

graph_world: atlas

graph_layer: module

graph_kind: surface

graph_parent: atlas-ai-canonical-architecture-index

graph_status: building

graph_source: repo
human_name: Atlas AI Voice Realtime Surface
canonical_name: Atlas AI Voice Realtime Surface
technical_name: atlas-ai-voice-realtime-surface
cartography_type: surface
canonical_source: docs/engineering-knowledge-base/atlas-ai-voice-realtime-surface.md

owner: surface-architecture

gear_flow:
  - graph_id: atlas-ai-voice-realtime-surface:mobile
    target_graph_id: atlas-ai-voice-realtime-canon-de-fala
    name: Mobile Voice
    kind: input
    summary: push-to-talk/realtime captura audio com permissao e eclipse
  - graph_id: atlas-ai-voice-realtime-surface:livekit
    target_graph_id: adr-0002-voice-realtime-sdk-loop-kernel-response-path
    name: LiveKit Agents SDK
    kind: context
    summary: VAD, turn detection, STT/TTS e interruption sem decidir provider
  - graph_id: atlas-ai-voice-realtime-surface:envelope
    target_graph_id: operation-envelope
    name: Operation Envelope
    kind: context
    summary: transcript e metadados entram no caminho unico do Kernel
  - graph_id: atlas-ai-voice-realtime-surface:decide
    target_graph_id: atlas-decide
    name: Atlas Decide
    kind: decision
    summary: Laravel Kernel escolhe policy, provider e autorizacao
  - graph_id: atlas-ai-voice-realtime-surface:receipt
    target_graph_id: decision-receipt
    name: Decision Receipt
    kind: gate
    summary: resposta ou acao so sai com receipt, privacy e gates
  - graph_id: atlas-ai-voice-realtime-surface:tts
    target_graph_id: adr-0002-voice-realtime-sdk-loop-kernel-response-path
    name: Response / TTS
    kind: output
    summary: resposta volta ao mobile por audio aprovado
  - graph_id: atlas-ai-voice-realtime-surface:evidence
    target_graph_id: evidence-ledger
    name: Evidence + Learning
    kind: gate
    summary: eventos VOICE_*, metricas e propostas fecham o loop

repo_paths:
  - docs/engineering-knowledge-base/atlas-ai-voice-realtime-surface.md

allowed_changes:
  - Atualizar este doc quando codigo, arquitetura, fluxo, evidencia ou decisao canonica mudar.

forbidden_changes:
  - Declarar runtime, maturidade ou prontidao sem evidencia verificavel e gates verdes.

depends_on:
  - atlas-ai-documentation-operating-system

flows_to:
  - atlas-cartography
  - atlas-code

unlocks:
  - ai-safe-implementation-context

governs:
  - surface-architecture

evidence:
  - docs/engineering-knowledge-base/atlas-ai-voice-realtime-surface.md

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: medium

visual_tags:
  - module
  - surface
  - surface-architecture

ai_entrypoints:
  - Leia Resumo, Contratos, Regras para IA, Evidencias e Riscos antes de implementar.

ai_usage_notes:
  - Use repo_paths, allowed_changes, forbidden_changes e required_tests como limites operacionais.

quality_gates:
  - "php artisan atlas:engineering:knowledge docs-health --json"

failure_modes:
  - Contexto desatualizado entre doc, codigo, teste e evidencia.

observability_signals:
  - docs-health status ok

next_actions:
  - Manter este doc sincronizado com codigo, testes, evidencias e Cartografia.
---
# Atlas AI Voice Realtime Surface

Este documento define a forma final enterprise do Voice Realtime Surface: uma interface conversacional natural, multi-provider, auditavel, governada e superior ao uso direto de voice modes isolados.

## Autoridade

| Assunto | Doc dono |
|---|---|
| Pipeline universal | `atlas-ai-pipeline.md` |
| Surface mobile | `atlas-ai-mobile-surface-gateway.md` |
| Voice realtime | este documento |
| Fronteira Laravel/Python/Go/Swift | `atlas-ai-runtime-language-boundaries.md` |
| Swift/macOS edge | `atlas-native-mac-agent.md` |
| Evidencia e telemetria | `atlas-ai-telemetry-evidence-performance.md` |

Em conflito: Tese cardinal -> Kernel/Pipeline -> este doc -> docs de runtime especifico.

## Principio Central

Voice e uma surface de entrada/saida. Surface nao decide.

Toda fala vira turno governado:

```text
Mobile Voice -> Surface Adapter -> Operation Envelope -> Intent/Routing
-> Context -> Policy -> Atlas Decide -> Decision Receipt -> Runtime
-> Quality Gates -> Response/TTS -> Evidence -> Learning/Proposals
```

O LiveKit Agents SDK acelera a conversa, mas nao substitui o Kernel; Agents nunca chamam Claude, OpenAI, Gemini ou modelos locais diretamente sem receipt.

## Fronteira De Poder

Voice Surface pode:

capturar audio mobile, abrir push-to-talk/realtime, usar LiveKit para VAD/turn
detection/interruption/STT/TTS, enviar transcripts e metadados ao Kernel,
tocar audio aprovado por receipt, mostrar controles de privacy/eclipses e
emitir eventos VOICE_*.

Voice Surface nao pode:

decidir provider/modelo, executar tool, gravar memoria diretamente, salvar
audio raw, ignorar eclipse/privacy, chamar provider direto, alterar
comportamento critico sem Proposal Inbox/review humano ou virar domain.

## Arquitetura Final

```text
App Mobile (primeira surface real)
  -> LiveKit Room / WebRTC
  -> LiveKit Agents SDK (Python AI/Data Runtime)
  -> POST /ai/voice/turn
  -> Atlas Kernel (Laravel)
  -> Decision Receipt v2
  -> provider/tool/harness aprovado
  -> streaming response
  -> LiveKit Agents SDK
  -> TTS/audio para mobile
```

Mac/Swift entra depois:

`atlas-native-mac-agent / atlas-voice-edge` adiciona wake word local,
mic/AirPods e contexto opt-in ao mesmo fluxo mobile-first.

## Papeis Por Runtime

| Runtime | Papel correto | Proibido |
|---|---|---|
| Laravel Kernel | Policy, Decide, Receipt, Envelope, Gates, Ledger, API | Captura de audio baixo nivel |
| LiveKit Agents SDK / Python | Conversa realtime, STT/TTS plugins, turn detection, interruption | Decidir provider ou executar tools |
| LiveKit Server/Cloud / Go | Transporte WebRTC, rooms, baixa latencia | Conhecimento, memoria ou decisao |
| Mobile App | Primeira UX de voz, push-to-talk, controles, playback | Orquestrar IA fora do Kernel |
| Swift Native Mac | Edge ambiental Mac futuro, wake word local, contexto opt-in | Ser primeiro produto de voz ou backend paralelo |

## Mobile First

O primeiro local natural do Atlas Voice e o app mobile porque:

ele e a surface mais proxima do usuario, ja tem mic/speaker/push/permissao/auth e UI de conversa,
reduz risco de always-on invasivo no Mac, prova valor antes de daemon ambiental e acelera Rivals-Voice.

Fase inicial deve ser push-to-talk. Always-on e wake word entram apenas depois
de privacy, eclipse, indicador visual e forgetting protocol estarem prontos.

## LiveKit Agents SDK

LiveKit Agents SDK e a escolha canonica para:

loop conversacional, VAD, turn detection, interruption/barge-in, STT/TTS
streaming, backchanneling, plugin matrix e metricas de latencia de audio.

O adapter LLM do Agents deve chamar o Atlas Kernel por webhook/HTTP interno.
Ele nao deve apontar para OpenAI/Claude/Gemini direto.

## Status E Contratos

Implementado agora: adapter `voice_realtime`, capability `atlas.input.voice_audio`, endpoints Fase 0, session lease com token LiveKit opt-in, probe redigido do LiveKit Server, eclipse, Envelope/Receipt, preflight/activation governance ate `controlled_livekit_server_supervised_smoke_contract` (mobile-first, no direct-provider, no daemon/always-on), callback router/sequence smoke, maquina de estado por turno aceito, validacao recursiva de callbacks, contrato/bootstrap/start-check, Rivals-Voice, runtime Python e SLOs. Callback sem `VOICE_TURN_DECIDED`, inclusive `runtime_failed`, falha fechado. O runtime Python valida `runtime_invocation_contract.return_contract` e o worker retorna `decision_receipt_hash`, `evidence_refs` e `errors`.

O app mobile possui contrato cliente para `/v1/mobile/ai/voice/session/*` e Voice Mode com fallback local. Isso nao significa audio realtime pronto: LiveKit media streaming, STT/TTS e UX final continuam pendentes como `mobile_surface_status=pending_contract`, seguindo `atlas-ai-mobile-surface-gateway.md`.

| Classe | Responsabilidade |
|---|---|
| `AtlasVoiceRealtimeSurfaceAdapter` | registra `voice_realtime` como surface |
| `AtlasVoiceRealtimeService` | scaffold Fase 0: sessao, turno, envelope, receipt, callbacks fail-closed, payload contract, ledger, SLO |
| `AtlasVoiceEclipseGuard` | bloqueia captura/resposta quando necessario |
| `AtlasVoiceLiveKitTokenIssuer` | emite JWT LiveKit opt-in; token nunca entra no Ledger |
| `AtlasVoiceRuntimeCertificationService` | certifica preflight, callback sequence, production-loop smoke e start bloqueado; usado por CLI, API e mobile |
| `KernelSloTargets` | declara `voice.wake_word_detect` e `voice.turn_to_first_audio` |
| `AtlasVoiceRivalsRunner` | relatorio read-only Atlas Voice vs baseline direto; bloqueia maturidade se runtime certification falhar |

Endpoints: `/ai/voice/session/*`, `/wake-word`, `/turn`, `/turn/interrupted`, `/turn/synthesized`, `/turn/played`, `/runtime/failed`, `/provider/health-degraded`, `/runtime/{contract,bootstrap,dependencies,dependency-install-plan,token-issuer-plan,token-issuer-smoke,pre-start-health-checks-smoke,certification,product-loop-check,promotion-review-packet}`, `/runtime/events/{normalize,normalize-sequence}`, `/health`, `/readiness`, `/rivals`, `/eclipse/active`. Os mesmos paths existem em `/v1/mobile/ai/voice/*`; mobile e a primeira surface real.

Eventos minimos: session start/end, wake word, audio, transcript, decided, synthesized, played, interrupted (runtime exige turno aceito), failed, degraded, eclipse.

## Policy E Privacy

1. Audio raw e texto de resposta cru sao buffers temporarios, nao conhecimento duravel.
2. Runtime, surface, transport e privacy class usam allowlist, nao string livre.
3. Finance, health e empresa em producao exigem policy mais restritiva.
4. Eclipse manual deve estar disponivel no mobile.
5. Calendar/private context pode ativar eclipse automatico quando integrado.
6. Toda sessao exibe indicador claro de escuta.
7. Forgetting protocol deve remover transcripts e links derivados.

## SLO De Latencia

| Metrica | Target |
|---|---:|
| mic_to_transcript_partial_p95 | <= 350ms |
| transcript_to_first_token_p95 | <= 600ms |
| first_token_to_first_audio_p95 | <= 350ms |
| turn_to_first_audio_p95 | <= 1200ms |
| interruption_stop_audio_p95 | <= 250ms |

## Fases

| Fase | Escopo | Prioridade | Estimativa |
|---|---|---:|---:|
| 0 | Mobile push-to-talk + Kernel turn API + transcript/TTS basico | P0 | backend/client parcial; media real pendente |
| 1 | LiveKit Agents SDK + rooms + STT/TTS streaming + interruption | P0 | 2-4 semanas |
| 2 | Swift Mac edge com wake word local e contexto opt-in | P1 | 3-6 semanas |
| 3 | Multi-device continuity: mobile, Mac, AirPods, Watch/Vision futuro | P2 | 6-12+ semanas |

Fase 0 sai antes de always-on; Fase 1 permite comparar contra voice modes.

## Runtime Certification Gate

`/ai/voice/runtime/certification` e `php artisan atlas:ai:voice runtime-certify --json` agregam AP-185, preflight, callbacks, smoke com `bridge_contract_report` + `worker_return_contract`, worker-start, `product_loop_check` e `pre_start_health_checks_smoke`. O certificado e sanitizado: nao expõe token, API key, secret, audio raw, transcript cru ou payload de provider.

O gate `certification_artifacts_sanitized` e obrigatorio em CLI, API interna, mobile gateway e Rivals-Voice summary antes de qualquer maturidade acima de scaffold. AP-687 adiciona `production_promotion_gate`: scaffold certificado segue bloqueado para produto real ate `--require-sdk`, LiveKit Agents SDK, token issuer, smokes, Rivals baseline, bootstrap namespace-safe, `promotion_allowed=false`, auto-promotion proibida, receipt, rollback plan e review humano passarem.

`phase0_hardening`: readiness estrutural em `atlas:ai:voice readiness --json` com schema `atlas.voice_realtime.phase0_hardening_gate.v1`. A fase 0 exige mobile-first, Kernel Decision Receipt por turno, callback loop fail-closed, audio cru nao persistido, AP-201 runtime boundary verde e promocao travada por review humano. O readiness publica `product_loop_check` como referencia machine-readable para `php artisan atlas:ai:voice product-loop-check --json`, com `promotion_allowed=false`, `auto_promotion_allowed=false`, `daemon_started=false` e gates esperados incluindo `sdk_probe_import_safe`.

`product_loop_wiring_flags`: `--callback-loop-wired` e `--production-sdk-loop-wired` provam o caminho LiveKit Agents em `callback-loop-check`, `worker-plan`, `production-loop-plan`, `activation-contract`, `product-loop-check`, `worker-start-check`, `runtime-certify`, `promotion-review-packet`, Rivals, API interna e mobile; o cliente Python consulta status em `--readiness` e repassa essas flags ao Kernel em `--rivals`, `--kernel-product-loop-check` e `--promotion-review-packet`, todos com validadores fail-closed recursivos contra segredo, audio cru, texto bruto de provider e tool args. `production_loop_smoke` tambem valida `atlas.voice_realtime.production_loop_smoke.v1` antes de publicar resultado: reports validos, `daemon_started=false`, `sdk_imported=false`, zero sessoes abertas e sem transcript/audio cru. `worker-start-check` valida `atlas.voice_realtime.worker_start.v1`: `started=false`, review humano, daemon review, rollback, Decision Receipt e guardrails nao podem relaxar. `activation-contract` exige `production_sdk_loop_wired`; callback router sozinho nao autoriza worker.

`LiveKitSdkHandlerRegistry` e `atlas.voice_realtime.sdk_handler_registry.v1` sao parte do loop real: callback SDK -> handler governado -> `LiveKitSdkEventBridge.to_callback_event` -> `LiveKitCallbackRouter.route` -> `AtlasLiveKitWorker`, sem provider, tool, memory, policy, token ou audio cru dentro dos handlers; bridge, router e worker repetem validacao recursiva fail-closed para impedir bypass acidental.

`product-loop-check`: artefato `atlas.voice_realtime.product_loop_check.v1` disponivel por CLI (`php artisan atlas:ai:voice product-loop-check --json`), API interna `/ai/voice/runtime/product-loop-check`, mobile `/v1/mobile/ai/voice/runtime/product-loop-check` e bootstrap `kernel.runtime_product_loop_check_url`; todos aceitam wiring flags auditaveis. Ele combina callback loop, production-loop-plan e worker-start com wiring de produto habilitado, nunca inicia daemon e nunca substitui `production_promotion_gate`. Sem `callback_loop_wired=true` e `production_sdk_loop_wired=true`, o status valido e `blocked`, nunca `ready_for_human_review`. Se `sdk_probe_import_safe` falhar, `next_action=fix_sdk_probe_contract`; se `sdk_handler_blueprint_available` falhar, `next_action=fix_sdk_handler_blueprint_contract`. O cliente Python consome por `AtlasKernelClient.product_loop_check()` e valida com `validate_product_loop_check`: schema, surface, runtime, `kernel_only`, `mobile_first`, `daemon_started=false`, estados governados, gates criticos e guardrails fail-closed; provider/tool direto, audio raw, token log, segredo aninhado, auto-promocao ou daemon iniciado falham antes de virar evidencia local.

O gate tambem exige `sdk_kernel_normalizer_required=true`: o `sdk_wiring_contract` declara `KernelRuntimeEventNormalizerGuard`, `validate_every_sdk_event_through_kernel_normalizer`, `kernel_event_normalizer_required_for_real_loop=true` e cada handler blueprint passa por `KernelRuntimeEventNormalizerGuard.assert_event_valid` antes do router/worker.

`daemon-supervisor-check`: artefato `atlas.voice_realtime.daemon_supervisor_execution.v1`. Ele é a fronteira unica para futura execucao persistente, mas hoje roda em `supervised_contract_only`, sem processo: `process_launch_attempted=false`, `daemon_started=false`, `start_allowed=false` e `process_adapter_implemented=false`. Ele publica `process_adapter_blueprint` (`atlas.voice_realtime.daemon_process_adapter_blueprint.v1`) com argv sanitizado, env keys obrigatorias, segredos nao logaveis, health checks e rollback; `launch_allowed=false`.

`product-loop-check` publica `ready_for_human_review` quando machine gates passam sem receipt; so publica `ready_for_daemon_implementation_review` com `production_review_receipt_valid=true`, `boolean_approval_is_sufficient=false` e `daemon_started=false`; so publica `ready_for_supervised_start_implementation` quando `daemon_implementation_review_valid=true` tambem estiver presente.

Quando o review humano vier acompanhado do bundle atual, `product-loop-check` tambem deve publicar `production_review_bound_to_expected_bundle=true` e `production_review_expected_bundle_validated=true`. Esses gates existem para diferenciar compatibilidade de receipt antigo de promocao enterprise final: shape valido sem bundle ainda nao prova que o operador revisou o pacote atual de evidencias.

`runtime-certify` publica `artifacts.product_loop_check`, `artifacts.pre_start_health_checks_smoke`, `artifacts.livekit_token_issuer_smoke`, `artifacts.livekit_server_probe`, gates `product_loop_check_available` e `pre_start_health_checks_smoke_passed`, resumo Rivals e review packet: passa quando conserva `daemon_started=false`, prova `sdk_probe_import_safe=true`, `sdk_handler_blueprint_available=true`, `sdk_kernel_normalizer_required=true`, `daemon_supervisor_execution_available=true`, `daemon_supervisor_process_launch_disabled=true`, `daemon_process_adapter_blueprint_available=true`, `subprocess_start_contract_available=true`, `reviewed_subprocess_start_execution_available=true`, `real_start_adapter_disabled_available=true`, `real_start_enablement_gate_available=true`, `runtime_policy_enablement_review_available=true`, `real_start_adapter_review_contract_available=true`, `reviewed_real_start_execution_contract_available=true`, `final_start_executor_disabled_available=true`, `final_start_executor_enablement_gate_available=true`, `supervised_start_execution_review_available=true`, `real_start_execution_contract_available=true`, `guarded_start_executor_disabled_available=true`, `guarded_start_executor_enablement_gate_available=true`, `reviewed_guarded_start_execution_contract_available=true`, `guarded_start_dry_run_contract_available=true`, `guarded_start_simulation_contract_available=true`, `guarded_start_runtime_handoff_contract_available=true`, `guarded_start_policy_patch_review_contract_available=true`, `guarded_start_human_review_contract_available=true`, `guarded_start_final_enablement_gate_available=true`, `guarded_start_policy_enablement_contract_available=true`, `guarded_start_activation_contract_available=true`, `guarded_start_execution_attempt_contract_available=true`, `guarded_start_execution_rehearsal_contract_available=true`, `guarded_start_observability_contract_available=true`, `guarded_start_release_candidate_contract_available=true`, `guarded_start_operator_acceptance_contract_available=true`, `guarded_start_final_start_receipt_contract_available=true`, `guarded_start_launch_window_contract_available=true`, `guarded_start_pre_launch_guard_contract_available=true`, `guarded_start_executor_runtime_contract_available=true`, `guarded_start_process_spawn_contract_available=true`, `guarded_start_spawn_review_contract_available=true`, `guarded_start_subprocess_import_contract_available=true`, `guarded_start_launch_invocation_contract_available=true`, `guarded_start_final_process_start_contract_available=true`, `guarded_start_process_execution_review_available=true`, `guarded_start_process_execution_packet_available=true`, `guarded_start_process_executor_stub_available=true`, `guarded_start_process_executor_review_available=true`, `guarded_start_process_executor_contract_available=true`, `guarded_start_process_runtime_adapter_available=true`, `guarded_start_process_adapter_review_available=true`, `guarded_start_process_adapter_contract_available=true`, `guarded_start_process_runner_contract_available=true`, `guarded_start_process_runner_review_available=true`, `guarded_start_process_runner_packet_available=true`, `guarded_start_process_runner_execution_review_available=true`, `guarded_start_process_runner_execution_contract_available=true`, `guarded_start_process_runner_start_gate_available=true`, `guarded_start_process_runner_final_review_available=true`, `guarded_start_process_runner_promotion_packet_available=true`, `guarded_start_process_runner_operator_release_review_available=true`, `guarded_start_process_runner_release_finalization_available=true`, `guarded_start_process_runner_release_authorization_available=true`, `controlled_livekit_server_supervised_smoke_contract_available=true`, `production_promotion_blocked=true`, `pre_start_health_checks_smoke.status=passed_no_process_start`, `smoke_only=true`, `production_readiness=not_proven_by_smoke` e nenhum processo/provider/tool/audio foi acionado. `status=certified_scaffold` significa SDK/token issuer/runtime local verificados, mas a promocao real fica `blocked` ate `livekit_server_probe.status=reachable`, review/receipt final e smoke supervisionado posterior.

`livekit-sdk-version-gate`: `runtime-dependencies.json` exige Python `>=3.10` e `livekit-agents>=1.3.12,<2.0.0`. O check normal usa `importlib`/metadata sem importar SDK; se o Python local for 3.9, o gate bloqueia com `next_action=upgrade_python_runtime_for_livekit_agents_sdk`, porque `livekit-agents 1.3.12` importa `typing.TypeAlias`. A IA nao deve “resolver” isso com monkey patch, downgrade cego ou import direto: deve preparar runtime Python 3.10/3.11 governado, rerodar `sdk-check`, depois seguir token issuer/review.

`promotion-review-packet`: comando `php artisan atlas:ai:voice promotion-review-packet --json`, API interna `/ai/voice/runtime/promotion-review-packet` e mobile `/v1/mobile/ai/voice/runtime/promotion-review-packet` geram o mesmo `atlas.voice_realtime.production_promotion_review_bundle.v1` via `AtlasVoiceProductionPromotionReviewBundleService`. O bundle junta `runtime-certify --require-sdk`, `product-loop-check`, `pre-start-health-checks-smoke` e Rivals-Voice em resumos com hash canonico, publica `review_packet`, `failed_machine_gates`, `bundle_hash`, rollback e proibicoes; `bundle_hash` inclui os flags `callback_loop_wired` e `production_sdk_loop_wired`. Ele nunca aprova promocao, nunca inicia daemon e conserva `promotion_allowed=false`, `auto_promotion_allowed=false`, `daemon_started=false`; quando os gates de maquina passam, o proximo passo ainda e anexar Decision Receipt e review humano.

O runtime Python deve consumir esse bundle apenas por leitura governada: o bootstrap exige `kernel.runtime_promotion_review_packet_url`, `AtlasKernelClient.production_promotion_review_packet()` usa GET com `runtime=livekit_agents_sdk`, repassa `callback_loop_wired`/`production_sdk_loop_wired` quando declarados na CLI, e `--promotion-review-packet` imprime o bundle sem iniciar worker, provider, tool, audio ou daemon. Esse caminho existe para smoke/review do produto, nao para dar autoridade de promocao ao runtime. A resposta tambem passa por `validate_promotion_review_packet`: schema, surface, runtime, `kernel_only`, `mobile_first`, review humano, receipt, rollback, review packet, evidence summaries hashados, gates de maquina, `bundle_hash` e guardrails precisam continuar fail-closed; qualquer relaxamento como `promotion_allowed=true`, `daemon_started=true`, provider direto, tool direto, memory write, audio raw ou boolean approval suficiente falha o runtime.

`production-promotion-review`: o arquivo de review humano aceito pelo runtime Python usa `atlas.voice_realtime.production_promotion_review.v1` e nao pode ser generico. Alem de `decision_receipt_id`, operador, rollback e forbidden-action ack, ele deve apontar para `reviewed_bundle_schema_version=atlas.voice_realtime.production_promotion_review_bundle.v1`, `reviewed_bundle_hash` SHA-256, `reviewed_machine_gate_status`, `required_evidence_reviewed` contendo `runtime_certification`, `product_loop_check`, `pre_start_health_checks_smoke` e `rivals_voice_comparison`, e `failed_machine_gates_acknowledged` como lista explicita. O caminho enterprise final deve passar tambem o bundle revisado por `--promotion-review-bundle-file`; quando esse bundle e fornecido, o runtime valida `validate_promotion_review_packet`, exige match exato de `bundle_hash`, `schema_version` e `status`, e recusa receipt antigo/stale. Sem esse vinculo ao bundle, o review e invalido: uma aprovacao antiga ou booleana nao pode promover um pacote de evidencias diferente.

`token-issuer-plan`: comando `php artisan atlas:ai:voice token-issuer-plan --json`, API interna `/ai/voice/runtime/token-issuer-plan` e mobile `/v1/mobile/ai/voice/runtime/token-issuer-plan` geram `atlas.voice_realtime.livekit_token_issuer_config_plan.v1`. Ele e o passo canonico entre SDK instalado e promocao de produto: lista `ATLAS_VOICE_LIVEKIT_TOKEN_ISSUER_ENABLED`, `LIVEKIT_URL`, `LIVEKIT_API_KEY`, `LIVEKIT_API_SECRET` e `ATLAS_VOICE_LIVEKIT_TOKEN_TTL_SECONDS`, mostra quais estao configurados, publica template redigido e hash, mas nunca imprime segredo, nunca escreve `.env`, nunca emite token e nunca inicia daemon. Se pronto, o proximo gate e `runtime-certify --require-sdk`; se bloqueado, o unico proximo passo e configurar env localmente.

`token-issuer-smoke`: comando `php artisan atlas:ai:voice token-issuer-smoke --json`, API interna `/ai/voice/runtime/token-issuer-smoke` e mobile `/v1/mobile/ai/voice/runtime/token-issuer-smoke` geram `atlas.voice_realtime.livekit_token_issuer_smoke.v1`. O smoke em ambiente real so passa quando o token issuer esta configurado; quando bloqueado, nao emite token. A flag `--ephemeral-test-config` existe apenas para provar o caminho criptografico em memoria com valores nao-producao, restaura config antes de sair e marca `production_readiness=not_proven_by_ephemeral_smoke`. Nenhuma variante pode expor `access_token`, segredo, audio bruto, executar tool/provider, escrever `.env` ou iniciar daemon.

`livekit-server-probe`: comando `php artisan atlas:ai:voice livekit-server-probe --json`, API interna `/ai/voice/runtime/livekit-server-probe` e mobile `/v1/mobile/ai/voice/runtime/livekit-server-probe` geram `atlas.voice_realtime.livekit_server_probe.v1`. Esse contrato prova apenas alcance TCP do `LIVEKIT_URL` configurado e redige host quando nao-local; ele nao le `LIVEKIT_API_KEY`, nao le `LIVEKIT_API_SECRET`, nao emite token, nao importa LiveKit SDK, nao inicia daemon/subprocess, nao chama provider/tool e nao toca audio. `status=reachable` autoriza somente o proximo smoke supervisionado de handshake do worker; `status=blocked` manda iniciar/corrigir o LiveKit Server local fora do probe e repetir. URL configurada nao e tratada como segredo, mas query/path e credenciais nunca entram no output.

LiveKit Server local e operator-managed via `docker-compose.livekit.yml`: validar com `docker compose -f docker-compose.yml -f docker-compose.livekit.yml config --quiet`; smoke controlado com `docker compose -f docker-compose.yml -f docker-compose.livekit.yml up -d livekit`, `php artisan atlas:ai:voice livekit-server-probe --json` e `docker compose -f docker-compose.yml -f docker-compose.livekit.yml stop livekit`. O override usa `livekit/livekit-server` em `--dev` para ambiente local, expõe 7880/7881/7882 UDP configuráveis, injeta `LIVEKIT_URL=http://livekit:7880` apenas nos containers Laravel quando o override e usado e nunca inicia o worker Python do Atlas. No host, `LIVEKIT_URL` continua devendo apontar para `http://127.0.0.1:7880` ou equivalente local.

O runtime Python tambem deve conseguir ler os contratos de token issuer sem operar nada: o bootstrap exige `kernel.runtime_token_issuer_plan_url` e `kernel.runtime_token_issuer_smoke_url`, `AtlasKernelClient.token_issuer_plan()` e `token_issuer_smoke()` chamam o Kernel por GET, e `validate_token_issuer_plan` / `validate_token_issuer_smoke` rejeitam segredo exposto, template nao redigido, `.env` write, daemon start, provider/tool direto, audio raw, token cru, sala fora de `atlas-voice-` ou participante fora de `mobile:`. As flags Python `--token-issuer-plan` e `--token-issuer-smoke` sao somente leitura governada para review/smoke; nao configuram env e nao emitem autoridade.

`pre-start-health-checks-smoke`: comando `php artisan atlas:ai:voice pre-start-health-checks-smoke --json`, API interna `/ai/voice/runtime/pre-start-health-checks-smoke` e mobile `/v1/mobile/ai/voice/runtime/pre-start-health-checks-smoke` geram `atlas.voice_realtime.pre_start_health_checks_smoke.v1`. Esse smoke escreve somente um `.env` temporario de placeholders `atlas-voice-*.env` com permissao `0600`, redige o path como `<temporary-managed-env-file>`, executa checks pre-start sinteticos seguros, avalia a cadeia de start de `subprocess_start_contract` ate `guarded_start_final_process_start_contract` e remove o arquivo antes de retornar. Ele deve passar como `passed_no_process_start`, mas sempre marca `smoke_only=true` e `production_readiness=not_proven_by_smoke`; nao prova LiveKit real, nao importa SDK, nao chama provider/tool, nao toca audio e nao inicia daemon.

`worker-start` separa `blocked_pending_human_review`, `blocked_pending_daemon_implementation_review` e `blocked_unimplemented_start`: wiring completo sem review vira review humano; review humano sem review tecnico vira implementacao bloqueada; `--production-promotion-approved` e legado e nao aprova nada sem receipts validos. O receipt exige `decision_receipt_id`, rollback, forbidden-action ack, `review_receipt_valid=true`, `daemon_implementation_review_valid=true`, `boolean_approval_is_sufficient=false` e conserva `started=false` ate o loop LiveKit real.

`supervised_start_plan` (`atlas.voice_realtime.supervised_start_plan.v1`) e o handoff final antes de daemon: mesmo em `ready_for_supervised_start_implementation`, `start_allowed=false`, `execution_implemented=false` e supervisor real continuam obrigatorios. Ele passa por `validate_supervised_start_plan`, recusando start, daemon, restart automatico, segredo, transcript/audio cru ou guardrail relaxado. O plano inclui `supervisor_contract` (`atlas.voice_realtime.daemon_supervisor_contract.v1`) com lifecycle, health checks, rollback, restart policy fail-closed e eventos `VOICE_DAEMON_*`; tambem inclui `supervisor_health_snapshot` (`atlas.voice_realtime.daemon_supervisor_health_snapshot.v1`) e `supervisor_preflight` (`atlas.voice_realtime.daemon_supervisor_preflight.v1`), sempre sem launch nesta fase.

`daemon_supervisor_execution` (`atlas.voice_realtime.daemon_supervisor_execution.v1`) chega a `ready_for_process_adapter_implementation` quando os dois receipts e o preflight estao validos; ainda assim apenas orienta `implement_reviewed_process_adapter`, sem launch, provider direto ou audio cru. O output passa por `validate_daemon_supervisor_packet`: supervisor, blueprint, adapter, env contract, launch authorization, supervised launch e subprocess start precisam conservar `daemon_started=false`, `launch_allowed=false`, segredos redigidos e guardrails fail-closed. O blueprint de adapter dentro dele (`atlas.voice_realtime.daemon_process_adapter_blueprint.v1`) e a proxima unidade implementavel: deve virar subprocess supervisionado apenas depois de testes de launch/stop/rollback e review, conservando segredos fora do output e nunca chamando provider/tool diretamente.

`supervised_process_adapter` (`atlas.voice_realtime.supervised_process_adapter.v1`)
e o shell executavel fail-closed: implementa prepare/health/start/stop/rollback
como contrato revisavel, mas `start_worker_process` retorna
`blocked_launch_not_implemented`, `process_launch_attempted=false`,
`daemon_started=false`, `subprocess_module_imported=false` e
`livekit_sdk_imported=false`. A resposta direta tambem passa por `validate_supervised_process_adapter_packet`, recusando launch, env secret, provider/tool ou audio cru aninhado. Ele tambem publica
`managed_environment_contract` (`atlas.voice_realtime.managed_env_contract.v1`)
com refs de env/segredo, manifesto sanitizado e `env_file_write_attempted=false`;
nenhum `.env` e escrito nesta fase e nenhum valor secreto aparece no output.
Tambem publica `launch_authorization_contract`
(`atlas.voice_realtime.launch_authorization_contract.v1`) com
`launch_allowed=false`, `process_launch_attempted=false`, receipts exigidos e
pre-start checks obrigatorios; e autorizacao para implementar o proximo passo,
nao para iniciar daemon.

Tambem publica `managed_env_writer`
(`atlas.voice_realtime.managed_env_writer.v1`). Esse writer valida placeholders
e manifesto redigido, mas conserva `managed_env_writer_contract_available`,
`write_execution_implemented=false`, `env_file_write_attempted=false`,
`secret_values_present_in_output=false` e proibe escrever `.env`, logar segredo
ou iniciar processo apos render.

`execute_managed_env_write` e a fronteira de escrita revisada: exige
`atlas.voice_realtime.managed_env_write_authorization.v1`, aceita somente
arquivo `atlas-voice-*.env`, escreve placeholders com permissao `0600`, retorna
`atlas.voice_realtime.managed_env_write_execution.v1` com `content_sha256`, mantem `process_launch_attempted=false`, `daemon_started=false` e validadores
fail-closed contra segredo, conteudo do `.env`, audio cru e provider/tool direto.

`supervised_launch_execution` (`atlas.voice_realtime.supervised_launch_execution.v1`) e a fronteira pre-subprocess posterior ao writer: consome `managed_env_write_execution`, `launch_authorization_contract` e `atlas.voice_realtime.launch_execution_authorization.v1`, prova argv redigido, checks pre-start e receipts exigidos, mas conserva `subprocess_launch_implemented=false`, `process_launch_attempted=false`, `daemon_started=false`, `subprocess_module_imported=false` e `livekit_sdk_imported=false`. Ele tambem passa por `validate_supervised_launch_packet` e publica `pre_start_health_checks_execution_available=true`, mas `pre_start_health_checks_executed=false` no product loop.

`execute_pre_start_health_checks` (`atlas.voice_realtime.pre_start_health_checks_execution.v1`) e o executor local dos checks antes do subprocess: exige `atlas.voice_realtime.pre_start_health_checks_authorization.v1`, valida todos os checks obrigatorios como `passed`, passa por `validate_pre_start_health_checks_packet`, recusa resultado que inicia processo, chama provider/tool ou toca audio cru, emite `VOICE_DAEMON_PRE_START_HEALTH_CHECKS_EVALUATED` e ainda mantem `process_launch_attempted=false`. Health check verde prepara o contrato de start futuro; nao inicia daemon.

`subprocess_start_contract` (`atlas.voice_realtime.subprocess_start_contract.v1`) exige `supervised_launch_execution` pronto, `pre_start_health_checks_execution` verde e `atlas.voice_realtime.subprocess_start_authorization.v1`; depois, `reviewed_subprocess_start_execution` exige review proprio; `real_start_adapter_disabled` formaliza adapter desligado; `real_start_enablement_gate` exige policy patch, review humano, rollback e receipt fresco; `runtime_policy_enablement_review` exige bundle hash revisado; `real_start_adapter_review_contract` exige PID guard, timeout, health probe, stdout/stderr sanitizado e rollback; `reviewed_real_start_execution_contract` exige final receipt, post-start ready event, single start por receipt e rollback rehearsal; `final_start_executor_disabled` declara executor final desligado por padrao; `final_start_executor_enablement_gate` exige bundle revisado, final receipt, single-start, ready event e rollback; `supervised_start_execution_review` exige review tecnico do bundle atual antes do contrato real; `real_start_execution_contract` formaliza PID guard, startup timeout, stdout/stderr sanitizado e ready event antes do executor guardado; `guarded_start_executor_disabled` declara a casca do executor guardado desligada por padrao; `guarded_start_executor_enablement_gate` exige bundle revisado, receipt final, single-start, PID guard, timeout, stream sanitizado e rollback antes do review de execucao guardada; `reviewed_guarded_start_execution_contract` exige review tecnico, bundle atual, dry-run plan, receipt final e rollback antes de qualquer dry-run; `guarded_start_dry_run_contract` simula PID guard, timeout, stdout/stderr sanitizado e ready event; `guarded_start_simulation_contract` simula lifecycle, ready probe e exit code; `guarded_start_runtime_handoff_contract` exige contrato Kernel-runtime, evidence sink, rollback, policy patch review, review humano e runtime `python_ai_data`; `guarded_start_policy_patch_review_contract` revisa diff/dry-run/hash do patch mas conserva policy start desligada; `guarded_start_human_review_contract` exige operador, hash igual ao patch, rollback revisado, receipt e gate final; `guarded_start_final_enablement_gate` exige receipt final, single-start, ready event e rollback rehearsal; `guarded_start_policy_enablement_contract` e o unico elo que pode marcar `runtime_policy_start_enabled=true`; `guarded_start_activation_contract` anexa janela de ativacao, review do operador, observabilidade pos-start, rollback e revoke; `guarded_start_execution_attempt_contract` exige PID guard, timeout, stream sanitizado, ready event e dry-run rehearsal; `guarded_start_execution_rehearsal_contract` ensaia esses controles sem launch; `guarded_start_observability_contract` exige ready event, health snapshot, SLO, rollback telemetry e evidence sink; `guarded_start_release_candidate_contract` congela bundle hash, evidence manifest, rollback e receipt final; `guarded_start_operator_acceptance_contract` exige aceite explicito, manifest/rollback revisados e receipt; `guarded_start_final_start_receipt_contract` vincula receipt final fresco, single-start e ready event; `guarded_start_launch_window_contract` exige janela declarada, operador presente, receipts frescos, observabilidade armada, rollback armado e revoke; `guarded_start_pre_launch_guard_contract` exige kernel, token lease e callback router frescos; `guarded_start_executor_runtime_contract` vincula runtime family, evidence sink, sanitizers, PID guard, timeout, ready probe e rollback; `guarded_start_process_spawn_contract` declara cwd confinado, argv redigido, timeout, ready event e rollback sem importar subprocess; `guarded_start_spawn_review_contract` exige review tecnico/hash/cwd/argv/timeout/rollback antes do import; `guarded_start_subprocess_import_contract` declara boundary de import localizado, sem top-level import, import so no executor e evento de auditoria; `guarded_start_launch_invocation_contract` exige template de comando, argv/env redigidos, cwd confinado, PID guard, timeout, ready event e rollback antes do start final; `guarded_start_final_process_start_contract` exige receipt fresco, single-start por receipt, ready event, PID guard, timeout, stdout/stderr sanitizado e rollback antes de qualquer review de execucao real; `guarded_start_process_execution_review` exige review tecnico, receipt vinculado, PID/timeout/ready event/streams/rollback/observability revisados antes do pacote de execucao; `guarded_start_process_execution_packet` anexa receipt, argv/env/cwd redigidos, PID guard, timeout, ready event, sanitizers, rollback e observabilidade; `guarded_start_process_executor_stub` declara stub localizado; `guarded_start_process_executor_review` revisa stub, import localizado futuro, PID/timeout/ready event, streams, rollback e observabilidade; `guarded_start_process_executor_contract` formaliza o contrato do executor runtime antes do adapter; `guarded_start_process_runtime_adapter` declara o adapter de runtime; `guarded_start_process_adapter_review` revisa esse adapter; `guarded_start_process_adapter_contract` formaliza o contrato do adapter; `guarded_start_process_runner_contract` formaliza single-start receipt, PID, timeout, ready event, sanitizers, rollback e observabilidade; `guarded_start_process_runner_review` revisa esses controles; `guarded_start_process_runner_packet` anexa receipt, argv/env/cwd redigidos, PID, timeout, ready event, sanitizers, rollback e observabilidade; `guarded_start_process_runner_execution_review` revisa esse pacote; `guarded_start_process_runner_execution_contract` vincula receipt, argv/env/cwd, PID, timeout, ready event, sanitizers, rollback e observabilidade; `guarded_start_process_runner_start_gate` exige receipt final, single-start, PID, timeout, ready event, sanitizers, rollback e observabilidade antes do final review; `guarded_start_process_runner_final_review` revisa receipt final, single-start, PID, timeout, ready event, sanitizers, rollback e observabilidade antes do promotion packet; `guarded_start_process_runner_promotion_packet` anexa bundle hash, evidence manifest, rollback, receipt e exige release review do operador; `guarded_start_process_runner_operator_release_review` confirma operador, owner, bundle hash, evidence manifest e rollback; `guarded_start_process_runner_release_finalization` vincula receipt final, single-start, janela, revoke e review posterior; `guarded_start_process_runner_release_authorization` exige finalizacao anexada, release final do operador, janela/revoke validados, smoke controlado do LiveKit Server e supervisao de worker antes de qualquer daemon/start; `controlled_livekit_server_supervised_smoke_contract` prepara health probe, sala efemera, token issuer smoke, sanitizers, cleanup e worker supervisionado, mas ainda nao inicia processo. Mesmo pronto, validadores conservam `guarded_start_executor_enabled=false`, `guarded_start_executor_implemented=false`, `final_start_executor_enabled=false`, `real_start_adapter_enabled=false`, `real_subprocess_start_implemented=false`, `process_launch_attempted=false`, `daemon_started=false`, `subprocess_module_imported=false`, `livekit_sdk_imported=false`, provider/tool proibidos e audio cru intocado.

`dependency-install-plan` (`atlas.voice_realtime.dependency_install_plan.v1`) torna a instalacao do LiveKit Agents reproduzivel via CLI Laravel autoritativa, API interna `/ai/voice/runtime/dependency-install-plan`, mobile `/v1/mobile/ai/voice/runtime/dependency-install-plan` e bootstrap `kernel.runtime_dependency_install_plan_url`: aponta `requirements-livekit.txt`, comando `pip install -r`, comando de verificacao e hash do arquivo, mas conserva `pip_execution_attempted=false`, `sdk_imported=false` e `daemon_started=false`. A instalacao e operator-managed; Atlas nunca roda pip, importa SDK ou muda policy automaticamente nesse plano. O runtime Python valida esse mesmo contrato com `validate_dependency_install_plan`, falhando por excecao em segredo/audio/tool/provider aninhado, pip executado, daemon iniciado ou gates criticos relaxados. `--kernel-dependency-install-plan` e smoke de cliente, nao autoridade nova.

`runtime-dependencies` tambem publica `python_runtime` (`atlas.voice_realtime.python_runtime_plan.v1`): mostra `configured_binary`, versao detectada, minimo `3.10`, recomendado `3.11`, candidatos locais, `auto_install_allowed=false`, exemplo `ATLAS_VOICE_PYTHON_BIN` e `next_action`. Esse e o contrato canonico para resolver Python 3.10+ sem monkey patch, downgrade cego, pip automatico ou caminho hardcoded.

`sdk-check`: probe de compatibilidade, nao import de runtime. Retorna `sdk_imported=false`, `import_probe_only=true`, `package_checks`, `missing_imports`, versao instalada quando existir e policy do `runtime-dependencies.json`.

Callbacks runtime sao validados dentro de `AtlasVoiceRealtimeService`, nao so no controller HTTP. Campo obrigatorio ausente/proibido gera `callback_rejected_payload_contract`, bloqueia o evento solicitado e grava `VOICE_RUNTIME_FAILED` com `runtime_callback_payload_contract_violation`.

Rivals-Voice deve consumir apenas o resumo do certificado. Se o runtime nao estiver `certified_scaffold`, fica `not_ready` e recomenda `fix_voice_runtime_certification_before_rivals_voice`. `self_improvement.voice_realtime_review` abre proposta quando houver atividade VOICE_* sem readiness/certification/baseline fechados.

## Definition Of Done
Voice v1 esta pronto quando mobile push-to-talk inicia sessao; LiveKit Agents
roda STT/TTS configuravel; cada turno tem Envelope, Decision Receipt, VOICE_*
Ledger e SLO; Atlas Decide escolhe provider/modelo salvo override auditado;
audio raw nao persiste; eclipse bloqueia captura/resposta; runtime certification
passa antes de Rivals-Voice; docs, KB, code index e architecture validate passam.

## Anti-Patterns
Scanner AP-179 exige literal: Implementar Mac Swift antes do mobile voice.

Proibido: Mac Swift antes do mobile voice; LiveKit Agents chamar provider direto; persistir audio raw; criar `voice` como domain; turno sem Decision Receipt; wake word always-on antes de eclipse/privacy; duplicar STT/TTS policy no mobile; declarar sucesso sem Rivals-Voice e SLO.

## Resumo

Contrato canonico do Voice Realtime Surface do Atlas AI. Voz nasce mobile-first, usa LiveKit Agents SDK como runtime conversacional, preserva o Kernel Laravel como decisor soberano e deixa Swift/macOS como edge ambiental posterior.

## Papel no Atlas

Define a responsabilidade desta peca dentro da arquitetura Atlas.

## Onde Se Encaixa

Relaciona esta peca com seu sistema, camada, fluxo ou modulo pai.

## Contratos

Declara invariantes, entradas, saidas, limites e obrigacoes relevantes.

## Fluxo

Descreve o caminho operacional ou a sequencia de uso quando aplicavel.

## Regras para IA

Agentes devem respeitar escopo, evidencias, testes e proibicoes antes de alterar codigo.

## Escopo de Implementacao

Mudancas devem permanecer nos caminhos e limites declarados no frontmatter.

## Dependencias

Dependencias canonicas vivem em frontmatter e no corpo deste documento.

## Evidencias

Evidencias aceitas incluem docs, comandos, testes, receipts, reports e paths verificaveis.

## Riscos

Riscos principais devem ser tratados antes de promover status, runtime ou claims de prontidao.

## Exemplos

Exemplos concretos devem ser adicionados quando reduzirem ambiguidade para humanos ou IAs.

## Proximas Acoes

Proximas acoes devem ser concretas, verificaveis e ligadas a gates de qualidade.
