---
id: adr-0002-voice-realtime-sdk-loop-kernel-response-path
type: engineering_adr
title: ADR 0002 - Voice Realtime SDK Loop E Kernel Response Path
status: building
category: architecture_decision
priority: 95
implementation_state: proposed_decision_not_accepted
blocker: operator_must_choose_response_text_path_before_sdk_loop
summary: Resolve a unica fronteira arquitetural ainda aberta antes de Phase 1 promotion do Voice Realtime: como o runtime LiveKit Agents obtem response_text do Kernel para TTS, sem violar privacy_audio_not_persisted nem o contrato runtime_execution_enabled=false.
tags:
  - adr
  - voice
  - realtime
  - livekit
  - kernel-boundary
  - tts
capabilities:
  - voice_kernel_response_handoff_decision
  - voice_runtime_tts_authority_decision
  - voice_phase1_unblock
decisions:
  - Antes de codificar o real LiveKit Agents SDK loop, escolher e travar uma das tres opcoes de response_text path.
  - Decisao tem consequencia direta em onde TTS roda, quem segura provider key, e qual SLO end-to-end e atingivel.
  - Sem decisao gravada aqui, qualquer codigo do SDK loop e suposicao e bloqueia promotion de Phase 1.
maintenance:
  - Atualizar quando uma das tres opcoes for travada em codigo.
  - Marcar como `accepted` quando opcao final estiver implementada e gates verdes.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-voice-realtime-surface.md
  - docs/engineering-knowledge-base/atlas-ai-voice-realtime-canon-de-fala.md
  - docs/engineering-knowledge-base/atlas-ai-pipeline.md
  - docs/engineering-knowledge-base/atlas-ai-runtime-language-boundaries.md
  - app/Services/Ai/Voice/AtlasVoiceRealtimeService.php
  - runtimes/python/voice_realtime/atlas_voice_agent/livekit_sdk_adapter.py
  - runtimes/python/voice_realtime/atlas_voice_agent/livekit_worker.py
doc_schema: atlas_canonical_module_doc.v1

graph_id: adr-0002-voice-realtime-sdk-loop-kernel-response-path

graph_title: ADR 0002 - Voice Realtime SDK Loop E Kernel Response Path

graph_world: atlas

graph_layer: system

graph_kind: adr

graph_parent: atlas-ai-voice-realtime-surface

graph_status: building

graph_source: repo
human_name: ADR 0002 - Voice Realtime SDK Loop E Kernel Response Path
canonical_name: ADR 0002 - Voice Realtime SDK Loop E Kernel Response Path
technical_name: adr-0002-voice-realtime-sdk-loop-kernel-response-path
cartography_type: adr
canonical_source: docs/engineering-knowledge-base/adr/0002-voice-realtime-sdk-loop-kernel-response-path.md

owner: surface-architecture

repo_paths:
  - docs/engineering-knowledge-base/adr/0002-voice-realtime-sdk-loop-kernel-response-path.md

allowed_changes:
  - Atualizar este doc quando opcao for travada, codigo for implementado, evidence for coletada.

forbidden_changes:
  - Marcar como accepted sem opcao implementada e gates verdes.
  - Implementar SDK loop sem este ADR aceito.

depends_on:
  - atlas-ai-voice-realtime-surface

flows_to:
  - atlas-cartography
  - atlas-code

unlocks:
  - voice-realtime-phase1-promotion

governs:
  - voice-runtime-architecture

evidence:
  - docs/engineering-knowledge-base/adr/0002-voice-realtime-sdk-loop-kernel-response-path.md

evidence_refs:
  - symbol: AtlasVoiceRealtimeService
  - command: atlas:ai:voice
  - test: AtlasVoiceRealtimeServiceTest
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: high

visual_tags:
  - system
  - adr
  - voice

ai_entrypoints:
  - Leia Contexto, Opcoes, Comparacao e Status antes de escrever qualquer codigo do SDK loop real.

ai_usage_notes:
  - Este ADR e bloqueador de implementacao: codigo do SDK loop nao deve ser escrito ate uma das opcoes ser aceita.

quality_gates:
  - "php artisan atlas:engineering:knowledge docs-health --json"

failure_modes:
  - Implementacao do SDK loop antes da decisao causa retrabalho de semanas.
  - Vazamento de response_text em log/evidence se opcao errada for escolhida sem guardrail.

observability_signals:
  - docs-health status ok

next_actions:
  - Operador escolhe entre Opcao A, B ou C abaixo.
  - Atualizar este ADR para `accepted` com receipt do operador.
  - Implementar SDK loop conforme opcao aceita.
---
# ADR 0002 - Voice Realtime SDK Loop E Kernel Response Path

## Contexto

Em 2026-05-13, o Voice Realtime do Atlas esta em `certified_scaffold` com todos os 6 gates de Phase 0 verdes (`atlas-ai-voice-realtime-surface.md`). O unico bloqueador codigo restante para Phase 1 e o que `worker_start_blocked_safely` reporta literalmente: *"LiveKit Agents SDK callback loop is not wired to LiveKitCallbackRouter yet"*.

Ao tentar especificar a implementacao desse loop, surge uma fronteira arquitetural nao decidida:

**`AtlasVoiceRealtimeService::handleTurn()` (linhas 240-358) retorna Decision Receipt + envelope + ai_interaction info, mas NAO retorna `response_text`.** O contrato de callback `tts_synthesized` proibe `response_text` no payload. O LLM call propriamente dito acontece via `maybeEnqueueTranscriptInteraction` (background queue / AiWorker), com `runtime_execution_enabled=false`.

Pergunta nao respondida: **como o runtime LiveKit Agents obtem o texto da resposta para alimentar o TTS plugin?**

Sem responder isso, qualquer codigo do SDK loop e suposicao. Este ADR pinpoint as tres opcoes coerentes com o resto da arquitetura e exige escolha antes de codificar.

## Estado Atual Verificado

Comandos executados em 2026-05-13:

| Gate | Status |
|---|---|
| `atlas:ai:voice readiness --json` -> phase0_hardening | 6/6 passed |
| `atlas:ai:voice runtime-certify --json` | `certified_scaffold` |
| `atlas:ai:voice product-loop-check --json` | `ready_for_human_review` |
| `worker_start_blocked_safely` | `blocked_unwired_sdk_callbacks` |

A camada de traducao (handler registry, event bridge, callback router, worker, adapter, kernel client) esta **completa**. Falta o real LiveKit Agents SDK invocando `LiveKitSdkAdapter`.

## Opcoes

### Opcao A - Server-side TTS, runtime so observa

Atlas Kernel sintetiza audio server-side (PHP chama TTS provider direto, ou enfileira em queue) e publica audio track na LiveKit Room. Runtime Python:

- escuta room
- transcreve audio do usuario (STT plugin)
- POST `/voice/turn` com transcript (recebe Decision Receipt, sem texto)
- subscribe a track de audio publicada pelo Atlas server
- emite callback `tts_synthesized` quando track chegar (sabendo o `audio_hash` via header/metadata)
- emite `audio_played` quando playback completa
- emite `barge_in` se usuario voltar a falar

**Quem segura provider key:** Atlas server (Laravel).
**Quem decide TTS provider:** Decision Receipt + Kernel policy.
**Latencia esperada:** ~1.5-2.5s (queue + LLM + TTS server-side + room publish), pior que SLO atual de 1.2s, mas dentro do gate humano.

Pros:
- Runtime segue verdadeiramente cego ao texto. `response_text` nunca sai do Kernel.
- Provider keys ficam no servidor, nunca no runtime nem no cliente.
- Atlas Decide controla TTS provider igual controla LLM.
- Compativel 100% com o contrato existente `tts_synthesized.prohibited: response_text`.

Contras:
- LiveKit Agents SDK perde uso natural do TTS plugin (vira bridge passivo).
- Atlas server precisa publicar audio em LiveKit Room (nova boundary, nao existe ainda).
- Latencia pior que opcao B/C; barge-in continua tratavel mas TTS ja saiu do server.
- Precisa de nova classe Laravel `AtlasVoiceServerSideTtsPublisher` + LiveKit server SDK em PHP (nao existe).

### Opcao B - Runtime recebe response_text por canal governado, TTS local

Atlas Kernel retorna `response_text` em `handleTurn` mas APENAS em campo transient `transient.tts_input_text` que **nao** entra no `evidence_ledger`, **nao** entra em log, **e** o runtime e contratualmente obrigado a descartar apos enviar para TTS. Runtime Python:

- escuta room
- STT (plugin) -> transcript
- POST `/voice/turn` com transcript -> recebe Decision Receipt + `transient.tts_input_text`
- imediatamente passa texto para TTS plugin (Cartesia/openai/elevenlabs)
- TTS plugin gera audio stream
- runtime publica audio na LiveKit Room
- emite `tts_synthesized` com `response_text_hash` (sha256 do texto que ja foi descartado)

**Quem segura provider key:** runtime (LiveKit Agents container) tem TTS provider key em env.
**Quem decide TTS provider:** Decision Receipt define `tts_provider` allowed; runtime usa o que esta configurado.
**Latencia esperada:** ~800ms-1.2s, dentro do SLO `turn_to_first_audio_p95 ≤ 1200ms`.

Pros:
- Latencia melhor que opcao A.
- Usa LiveKit Agents TTS plugin idiomaticamente.
- `response_text` continua proibido em logs/evidence; texto vive ~200ms na memoria do runtime.

Contras:
- Quebra a invariante atual de que `handleTurn` so retorna metadata.
- Requer audit estatica para garantir que `transient.tts_input_text` nunca vaze para log/evidence/disco.
- Provider key no runtime aumenta surface de exposure.
- Mudanca em `AtlasVoiceRealtimeService::handleTurn()` e em `AtlasKernelClient.submit_turn()` retorno.

### Opcao C - Realtime speech-to-speech (OpenAI Realtime ou similar) governado pelo Kernel

Atlas Kernel autoriza por Decision Receipt o uso de speech-to-speech provider (OpenAI Realtime, Gemini Live). LiveKit Agents instancia o provider **com credenciais issued por turn pelo Kernel**. Provider faz STT+LLM+TTS internamente. Runtime so:

- POST `/voice/turn` com `transcript_partial="" transport_kind="speech_to_speech"` -> Kernel issues Decision Receipt + ephemeral provider lease (token escopado por turn, TTL <30s)
- runtime ata stream de audio do usuario direto ao provider via lease
- runtime ata stream de audio do provider direto a LiveKit Room
- emite `tts_synthesized` quando provider terminou primeiro chunk
- emite `audio_played` quando playback completa

**Quem segura provider key:** Kernel issues ephemeral lease; runtime nunca ve key permanente.
**Quem decide LLM/TTS:** Provider escolhido pelo Kernel via Decision Receipt.
**Latencia esperada:** ~400-800ms (sub-1s consistente, classe Jarvis real).

Pros:
- Latencia best-in-class (espelha Jarvis fictional).
- Pros´odia natural nativa do provider (gpt-realtime tem voice presets).
- Atlas Kernel mantem soberania via Decision Receipt + ephemeral lease.
- Sem `response_text` trafegando pelo Kernel ou Ledger.

Contras:
- Quebra parcialmente o canon "LLM = Atlas Kernel via Claude/Codex/Gemini per Decide". O LLM real do turno e o speech-to-speech provider, nao Atlas-orchestrated provider.
- Atlas Decide perde granularidade de provider/model em voice (so escolhe entre presets de speech-to-speech).
- Requer Kernel emitindo ephemeral lease scoped to turn (nova capability).
- Custo por minuto significativamente maior.
- Conflita com existing requirements (`livekit-plugins-openai>=1.5,<2.0`) que aponta para gpt-realtime mas o canon do voice-realtime-surface diz "LLM adapter chama Kernel, nao OpenAI direto".

## Comparacao

| Eixo | A: Server-side TTS | B: Runtime TTS com transient | C: Speech-to-speech |
|---|---|---|---|
| Latencia turn_to_first_audio | 1.5-2.5s | 0.8-1.2s | 0.4-0.8s |
| Privacy: `response_text` nunca sai do server | ✓ | parcial (transient) | ✓ |
| Compativel com contrato atual `tts_synthesized.prohibited` | ✓ | requires new transient field | ✓ |
| Codigo novo Laravel | alto (TTS publisher + LiveKit SDK PHP) | medio (transient field) | medio (ephemeral lease issuer) |
| Codigo novo Python | baixo (so observa) | medio (TTS plugin orquestrado) | alto (speech-to-speech bridge) |
| Provider key surface | servidor apenas | runtime + servidor | ephemeral lease so |
| Atlas Decide LLM choice granularity | total | total | reduzida (so presets s2s) |
| Custo operacional | medio | medio | alto |
| Coerencia com canon "LLM = Atlas Kernel" | total | total | parcial |
| Coerencia com SLO atual `≤1200ms` | falha | atinge | supera |

## Decisao

**Pendente.** Operador deve escolher entre A, B ou C antes de codificar o SDK loop real.

Recomendacao tecnica deste ADR (nao decisiva): **B com guardrails extremos**. Razoes:
1. Atinge SLO sem quebrar canon "LLM = Atlas Kernel".
2. Mudanca server-side menor (campo transient com expiry obrigatorio).
3. LiveKit Agents TTS plugin usado idiomaticamente.
4. Caminho upgrade para C depois (se latencia <800ms se tornar requisito).

Riscos de B mitigaveis:
- Static scanner que rejeita `tts_input_text` em qualquer log/evidence/disk write.
- Test que prova descarte do texto apos send para TTS.
- Schema versionado `atlas.voice_realtime.turn_response_with_transient_tts.v1` separado do return scaffold.

## Consequencias

Da decisao gravada aqui sairao:
- shape final do `handleTurn` return.
- presenca/ausencia de classe `AtlasVoiceServerSideTtsPublisher` (Laravel).
- escolha de plugin em `requirements-livekit.txt`.
- shape do real SDK loop em `runtimes/python/voice_realtime/atlas_voice_agent/livekit_real_sdk_loop.py` (a criar).
- atualizacao do canon de fala para refletir prosodia atingivel pelo provider escolhido.
- novo gate em `runtime-certify`: `kernel_response_path_decision_receipt` provando que ADR foi aceito antes de wire.

## Status

Proposed. Aguardando decisao do operador.

## Resumo

Sem este ADR aceito, o codigo do real LiveKit Agents SDK loop e suposicao. As tres opcoes diferem em onde TTS roda, qual a latencia atingivel e quem segura provider key. Recomendacao: opcao B, mas a decisao e do operador.

## Papel no Atlas

Bloqueia implementacao do real SDK loop ate que a fronteira arquitetural Kernel<->Runtime para audio de saida seja decidida.

## Onde Se Encaixa

ADR filho de `atlas-ai-voice-realtime-surface.md`, irmao de `atlas-ai-voice-realtime-canon-de-fala.md`. Consumido por qualquer codigo de Phase 1 voice.

## Contratos

Nenhuma das tres opcoes pode violar: `privacy_audio_not_persisted`, `kernel_decision_receipt_required`, `direct_provider_call_allowed=false` no runtime, `access_token_log_allowed=false`.

## Fluxo

Operador le o ADR -> escolhe opcao -> emite Decision Receipt registrando a escolha -> codigo do SDK loop sera escrito conforme opcao -> `runtime-certify --require-sdk` valida o caminho.

## Regras para IA

Nao escrever codigo do real SDK loop ate uma opcao ser aceita por receipt. Nao chutar a opcao com base em "default razoavel". Pedir decisao explicita ao operador.

## Escopo de Implementacao

Mudancas em handleTurn return shape, atlasVoiceRuntime, livekit_sdk_loop e plugin requirements ficam bloqueadas ate este ADR sair de proposed.

## Dependencias

`atlas-ai-voice-realtime-surface.md`, `atlas-ai-voice-realtime-canon-de-fala.md`, `atlas-ai-pipeline.md`, `atlas-ai-runtime-language-boundaries.md`.

## Evidencias

Estado atual verificado por `atlas:ai:voice readiness/runtime-certify/product-loop-check` em 2026-05-13. Fonte da fronteira: `AtlasVoiceRealtimeService.php:240-358` + contrato `tts_synthesized.prohibited: response_text` em `livekit_sdk_event_bridge.py:73-86`.

## Riscos

Implementar SDK loop sem este ADR causa retrabalho de semanas e risco de vazamento de `response_text` em ledger/log.

## Exemplos

Decisao boa: "Aceito opcao B. Receipt id atlas-rec-2026-05-15-voice-tts-path-b. Prazo de implementacao: 2 semanas."
Decisao ruim: "Vamos com a que for mais facil." (nao gera receipt, nao trava ADR).

## Proximas Acoes

Operador escolhe A, B ou C; emite Decision Receipt; este ADR vira `accepted`; codigo do real SDK loop sera escrito conforme opcao; `runtime-certify --require-sdk --callback-loop-wired --production-sdk-loop-wired` valida.
