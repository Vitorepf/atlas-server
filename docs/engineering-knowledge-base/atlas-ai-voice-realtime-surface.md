---
id: atlas-ai-voice-realtime-surface
type: engineering_knowledge
title: Atlas AI Voice Realtime Surface
status: scaffold
category: surface-architecture
priority: 94
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
  - Manter abaixo de 260 linhas.
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
---

# Atlas AI Voice Realtime Surface

Este documento define a forma final enterprise do Voice Realtime Surface.

O objetivo nao e "ter voz"; e fazer o Atlas virar uma interface conversacional
natural, multi-provider, auditavel, governada e superior ao uso direto de voice modes isolados.

## Autoridade

| Assunto | Doc dono |
|---|---|
| Pipeline universal | `atlas-ai-pipeline.md` |
| Surface mobile | `atlas-ai-mobile-surface-gateway.md` |
| Voice realtime | este documento |
| Fronteira Laravel/Python/Go/Swift | `atlas-ai-runtime-language-boundaries.md` |
| Swift/macOS edge | `atlas-native-mac-agent.md` |
| Evidencia e telemetria | `atlas-ai-telemetry-evidence-performance.md` |

Em conflito, a ordem e: Tese cardinal -> Kernel/Pipeline -> este doc -> docs
de runtime especifico.

## Principio Central

Voice e uma surface de entrada/saida. Surface nao decide.

Toda fala vira turno governado:

```text
Mobile Voice -> Surface Adapter -> Operation Envelope -> Intent/Routing
-> Context -> Policy -> Atlas Decide -> Decision Receipt -> Runtime
-> Quality Gates -> Response/TTS -> Evidence -> Learning/Proposals
```

O LiveKit Agents SDK acelera a conversa, mas nao substitui o Kernel. Agents
nunca chamam Claude, OpenAI, Gemini ou modelos locais diretamente sem receipt.

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

Implementado agora: adapter `voice_realtime`, capability `atlas.input.voice_audio`, endpoints Fase 0, session lease com token LiveKit opt-in, eclipse, Envelope/Receipt, preflight/activation governance (mobile-first, no direct-provider, no daemon/always-on), callback router/sequence smoke, maquina de estado por turno aceito, validacao recursiva de callbacks, contrato/bootstrap/start-check, Rivals-Voice, runtime Python e SLOs. Callback sem `VOICE_TURN_DECIDED`, inclusive `runtime_failed`, falha fechado. O runtime Python valida `runtime_invocation_contract.return_contract` e o worker retorna `decision_receipt_hash`, `evidence_refs` e `errors`.

O app mobile possui contrato cliente para `/v1/mobile/ai/voice/session/*` e Voice Mode com fallback local. Isso nao significa audio realtime pronto: LiveKit media streaming, STT/TTS e UX final continuam pendentes.

| Classe | Responsabilidade |
|---|---|
| `AtlasVoiceRealtimeSurfaceAdapter` | registra `voice_realtime` como surface |
| `AtlasVoiceRealtimeService` | scaffold Fase 0: sessao, turno, envelope, receipt, callbacks fail-closed, payload contract, ledger, SLO |
| `AtlasVoiceEclipseGuard` | bloqueia captura/resposta quando necessario |
| `AtlasVoiceLiveKitTokenIssuer` | emite JWT LiveKit opt-in; token nunca entra no Ledger |
| `AtlasVoiceRuntimeCertificationService` | certifica preflight, callback sequence, production-loop smoke e start bloqueado; usado por CLI, API e mobile |
| `KernelSloTargets` | declara `voice.wake_word_detect` e `voice.turn_to_first_audio` |
| `AtlasVoiceRivalsRunner` | relatorio read-only Atlas Voice vs baseline direto; bloqueia maturidade se runtime certification falhar |

Endpoints: `/ai/voice/session/*`, `/wake-word`, `/turn`, `/turn/interrupted`, `/turn/synthesized`, `/turn/played`, `/runtime/failed`, `/provider/health-degraded`, `/runtime/{contract,bootstrap,dependencies,certification}`, `/health`, `/readiness`, `/rivals`, `/eclipse/active`. Os mesmos paths existem em `/v1/mobile/ai/voice/*`; mobile e a primeira surface real.

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

`/ai/voice/runtime/certification` e `php artisan atlas:ai:voice runtime-certify --json` agregam AP-185, preflight, callbacks, smoke com `bridge_contract_report` + `worker_return_contract`, worker-start e `product_loop_check`. O certificado e sanitizado: nao expõe token, API key, secret, audio raw, transcript cru ou payload de provider.

O gate `certification_artifacts_sanitized` e obrigatorio em CLI, API interna, mobile gateway e Rivals-Voice summary antes de qualquer maturidade acima de scaffold. AP-687 adiciona `production_promotion_gate`: scaffold certificado segue bloqueado para produto real ate `--require-sdk`, LiveKit Agents SDK, token issuer, smokes, Rivals baseline, bootstrap namespace-safe, `promotion_allowed=false`, auto-promotion proibida, receipt, rollback plan e review humano passarem.

`phase0_hardening`: readiness estrutural em `atlas:ai:voice readiness --json` com schema `atlas.voice_realtime.phase0_hardening_gate.v1`. A fase 0 exige mobile-first, Kernel Decision Receipt por turno, callback loop fail-closed, audio cru nao persistido, AP-201 runtime boundary verde e promocao travada por review humano. O readiness publica `product_loop_check` como referencia machine-readable para `php artisan atlas:ai:voice product-loop-check --json`, com `promotion_allowed=false`, `auto_promotion_allowed=false`, `daemon_started=false` e gates esperados incluindo `sdk_probe_import_safe`.

`product_loop_wiring_flags`: `--callback-loop-wired` e `--production-sdk-loop-wired` provam o caminho LiveKit Agents em `callback-loop-check`, `worker-plan`, `production-loop-plan`, `activation-contract` e `worker-start-check`, mas `started=false`, auto-promotion proibida e provider/tool direto seguem imutaveis. `activation-contract` exige `production_sdk_loop_wired`; callback router sozinho nao autoriza worker.

`LiveKitSdkHandlerRegistry` e `atlas.voice_realtime.sdk_handler_registry.v1` sao parte do loop real: callback SDK -> handler governado -> `LiveKitSdkEventBridge.to_callback_event` -> `LiveKitCallbackRouter.route` -> `AtlasLiveKitWorker`, sem provider, tool, memory, policy, token ou audio cru dentro dos handlers.

`product-loop-check`: artefato `atlas.voice_realtime.product_loop_check.v1` que combina callback loop, production-loop-plan e worker-start com wiring de produto habilitado. Ele nunca inicia daemon e nunca substitui `production_promotion_gate`. Se `sdk_probe_import_safe` falhar, `next_action=fix_sdk_probe_contract`; se `sdk_handler_blueprint_available` falhar, `next_action=fix_sdk_handler_blueprint_contract`.

O gate tambem exige `sdk_kernel_normalizer_required=true`: o `sdk_wiring_contract` declara `KernelRuntimeEventNormalizerGuard`, `validate_every_sdk_event_through_kernel_normalizer`, `kernel_event_normalizer_required_for_real_loop=true` e cada handler blueprint passa por `KernelRuntimeEventNormalizerGuard.assert_event_valid` antes do router/worker.

`product-loop-check` publica `ready_for_human_review` quando machine gates passam sem receipt; so publica `ready_for_daemon_implementation_review` com `production_review_receipt_valid=true`, `boolean_approval_is_sufficient=false` e `daemon_started=false`.

`runtime-certify` publica `artifacts.product_loop_check`, gate `product_loop_check_available`, resumo Rivals e review packet: passa quando conserva `daemon_started=false`, prova `sdk_probe_import_safe=true`, `sdk_handler_blueprint_available=true`, `sdk_kernel_normalizer_required=true` e `production_promotion_blocked=true`. `status=blocked` por falta de SDK/token issuer e scaffold seguro, nao autorizacao para daemon.

`worker-start` separa `blocked_pending_human_review` de `blocked_unimplemented_start`: wiring completo sem review vira review humano obrigatorio; `--production-promotion-approved` e apenas declaracao legada e nao aprova nada sem `--production-promotion-review-file` valido. O receipt exige `decision_receipt_id`, rollback plan, forbidden-action ack, `review_receipt_valid=true`, `boolean_approval_is_sufficient=false` e ainda conserva `started=false` enquanto o loop LiveKit real nao estiver commitado.

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
1. Implementar Mac Swift antes do mobile voice.
2. Deixar LiveKit Agents chamar provider direto.
3. Persistir audio raw por comodidade.
4. Criar `voice` como domain.
5. Fazer voice sem Decision Receipt por turno.
6. Fazer wake word always-on antes de eclipse/privacy.
7. Duplicar STT/TTS policy no app mobile.
8. Medir "funciona" sem Rivals-Voice e SLO.
