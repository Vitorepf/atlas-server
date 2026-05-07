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
  - Rivals-Voice mede se Atlas Voice supera uso direto de voice mode de providers.
maintenance:
  - Manter abaixo de 260 linhas.
  - Atualizar quando mobile voice, LiveKit Agents SDK, STT/TTS providers, eclipse rules, latency SLO ou eventos VOICE_* mudarem.
  - Se passar do limite, mover detalhes para AP specs ou runbooks por fase.
related_paths:
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

O objetivo nao e "ter voz". O objetivo e fazer o Atlas virar uma interface
conversacional natural, multi-provider, auditavel, governada e superior ao uso
direto de ChatGPT Voice, Claude voice ou qualquer provider isolado.

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

1. capturar ou receber audio do app mobile;
2. abrir sessao push-to-talk ou realtime;
3. usar LiveKit para transporte, VAD, turn detection, interruption e TTS/STT plugins;
4. enviar transcripts e metadados de turno para o Kernel;
5. tocar audio gerado pelo TTS aprovado pelo receipt;
6. mostrar controles de mute, stop, push-to-talk, privacy e eclipse;
7. emitir eventos VOICE_* no Evidence Ledger.

Voice Surface nao pode:

1. decidir provider/modelo;
2. executar tool;
3. gravar memoria diretamente;
4. salvar audio raw como conhecimento duravel;
5. ignorar eclipse/privacy;
6. chamar provider direto;
7. alterar comportamento critico sem Proposal Inbox/review humano;
8. virar domain separado.

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

```text
atlas-native-mac-agent / atlas-voice-edge
  -> wake word local, mic/AirPods, screen/context opt-in
  -> LiveKit Room
  -> mesmo fluxo mobile-first
```

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

1. e a surface mais proxima do usuario durante o dia;
2. ja tem mic, speaker, push, permissao, auth e UI de conversa;
3. reduz o risco de sempre-ligado invasivo no Mac;
4. prova valor antes de criar daemon ambiental;
5. permite testar Rivals-Voice mais rapido.

Fase inicial deve ser push-to-talk. Always-on e wake word entram apenas depois
de privacy, eclipse, indicador visual e forgetting protocol estarem prontos.

## LiveKit Agents SDK

LiveKit Agents SDK e a escolha canonica para:

1. loop conversacional;
2. VAD e turn detection;
3. interruption/barge-in;
4. STT streaming;
5. TTS streaming;
6. backchanneling;
7. plugin matrix de providers;
8. metricas de latencia de audio.

O adapter LLM do Agents deve chamar o Atlas Kernel por webhook/HTTP interno.
Ele nao deve apontar para OpenAI/Claude/Gemini direto.

## Status E Contratos

Implementado agora: adapter `voice_realtime`, capability `atlas.input.voice_audio`,
endpoints Fase 0, eclipse, Envelope/Receipt por turno, callbacks, contrato e
bootstrap machine-readable, runtime Python scaffold, VOICE_* events e SLOs.

| Classe | Responsabilidade |
|---|---|
| `AtlasVoiceRealtimeSurfaceAdapter` | registra `voice_realtime` como surface |
| `AtlasVoiceRealtimeService` | scaffold Fase 0: sessao, turno, envelope, receipt, callbacks, ledger, SLO |
| `AtlasVoiceEclipseGuard` | bloqueia captura/resposta quando necessario |
| `KernelSloTargets` | declara `voice.wake_word_detect` e `voice.turn_to_first_audio` |
| `AtlasVoiceRivalsRunner` | compara Atlas Voice vs provider direto |

Endpoints:

| Metodo | Path | Uso |
|---|---|---|
| POST | `/ai/voice/session/start` | cria sessao scaffold e evento |
| POST | `/ai/voice/session/end` | encerra sessao |
| POST | `/ai/voice/turn` | recebe transcript/turno do Agents SDK |
| POST | `/ai/voice/turn/interrupted` | registra interrupcao |
| POST | `/ai/voice/turn/synthesized` | registra TTS sem texto/audio raw |
| POST | `/ai/voice/turn/played` | registra playback |
| POST | `/ai/voice/runtime/failed` | registra falha runtime |
| POST | `/ai/voice/provider/health-degraded` | registra degradacao STT/TTS |
| GET | `/ai/voice/runtime/contract` | contrato machine-readable |
| GET | `/ai/voice/health` | health agregado |
| GET | `/ai/voice/eclipse/active` | eclipse ativo |

Os mesmos paths existem em `/v1/mobile/ai/voice/*`; mobile e a primeira surface real.

Eventos minimos: session started/ended, wake word, audio received,
transcribed, decided, synthesized, played, interrupted, runtime failed,
provider degraded, eclipse triggered/lifted.

## Policy E Privacy

1. Audio raw e buffer temporario, nao conhecimento duravel.
2. Transcript recebe privacy class por domain/flow.
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
| 0 | Mobile push-to-talk + Kernel turn API + transcript/TTS basico | P0 | 5-8 dias uteis |
| 1 | LiveKit Agents SDK + rooms + STT/TTS streaming + interruption | P0 | 2-4 semanas |
| 2 | Swift Mac edge com wake word local e contexto opt-in | P1 | 3-6 semanas |
| 3 | Multi-device continuity: mobile, Mac, AirPods, Watch/Vision futuro | P2 | 6-12+ semanas |

Fase 0 sai antes de always-on; Fase 1 permite comparar contra voice modes.

## Definition Of Done

Voice v1 esta pronto quando:

1. app mobile inicia sessao push-to-talk;
2. LiveKit Agents SDK roda com plugin STT/TTS configuravel;
3. cada turno cria Operation Envelope e Decision Receipt;
4. provider/modelo e escolhido por Atlas Decide, salvo override manual auditado;
5. VOICE_* events aparecem no Evidence Ledger;
6. audio raw nao persiste;
7. eclipse manual bloqueia captura/resposta;
8. latency SLO e registrado;
9. Rivals-Voice gera scorecard;
10. docs, KB sync, code index e architecture validate passam.

## Anti-Patterns

1. Implementar Mac Swift antes do mobile voice.
2. Deixar LiveKit Agents chamar provider direto.
3. Persistir audio raw por comodidade.
4. Criar `voice` como domain.
5. Fazer voice sem Decision Receipt por turno.
6. Fazer wake word always-on antes de eclipse/privacy.
7. Duplicar STT/TTS policy no app mobile.
8. Medir "funciona" sem Rivals-Voice e SLO.
