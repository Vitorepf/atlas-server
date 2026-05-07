---
id: atlas-ai-voice-realtime-surface
type: engineering_knowledge
title: Atlas AI Voice Realtime Surface
status: scaffold
category: surface-architecture
priority: 86
summary: Contrato canonico do Voice Realtime Surface do Atlas AI cobrindo Surface Adapter no Kernel, LiveKit Server como Go Edge, LiveKit Agents como Python AI/Data Runtime e atlas-voice-edge como Swift Local macOS Runtime, com privacy/eclipse, evidence, latency SLO e Rivals-Voice.
tags:
  - atlas-ai
  - voice
  - realtime
  - surface
  - livekit
  - swift
  - runtime-boundaries
capabilities:
  - voice_realtime_surface
  - swift_native_mac_runtime
  - livekit_voice_pipeline
  - voice_eclipse_governance
  - voice_evidence_ledger
decisions:
  - Voice Realtime e Surface do Atlas AI; nao e domain, provider ou runtime soberano paralelo.
  - LiveKit Server (Go) atua como Edge Runtime de transporte WebRTC self-hosted no Mac.
  - LiveKit Agents (Python) atua como AI/Data Runtime de orquestracao STT/TTS/turn detection, sempre subordinado a DecisionReceipt do Kernel.
  - atlas-voice-edge (Swift) e o terceiro runtime canonico: swift_native_mac, responsavel por wake word local, VAD, echo cancel e captura nativa.
  - Provider de IA nunca e chamado direto pelo Agents; toda decisao de turno passa pelo Atlas Kernel via webhook assinado.
  - Audio raw nunca persiste; somente transcript apos VAD, sob privacy class definida pelo domain.
  - Wake word e detectado localmente; audio ambiente nao e streamado antes da deteccao.
  - Eclipse modes sao constitutional class-3: janelas onde o Atlas e surdo por contrato, nao por feature flag.
  - Rivals-Voice e o instrumento empirico que mede multiplicador da voz vs uso direto de provider voice mode.
maintenance:
  - Atualizar quando STT/TTS providers, LiveKit major version, plugin matrix, eclipse rules, latency SLO ou contratos atlas.voice.* mudarem.
  - Rodar docs-health e architecture-validate apos alterar.
  - Manter abaixo de 700 linhas; detalhes operacionais vivem em runbooks externos.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-runtime-language-boundaries.md
  - docs/engineering-knowledge-base/atlas-ai-canonical-architecture-index.md
  - docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md
  - docs/engineering-knowledge-base/atlas-ai-mobile-surface-gateway.md
  - docs/engineering-knowledge-base/atlas-local-agent-surface.md
  - docs/engineering-knowledge-base/atlas-ai-operating-system.md
  - docs/engineering-knowledge-base/atlas-ai-pipeline.md
  - docs/engineering-knowledge-base/atlas-ai-thesis-multiplier-channel.md
  - docs/engineering-knowledge-base/atlas-ai-qualitative-levels-roadmap.md
  - docs/engineering-knowledge-base/atlas-ai-telemetry-evidence-performance.md
  - docs/atlas-outro-patamar-roadmap.md
---

# Atlas AI Voice Realtime Surface

Este documento define o Voice Realtime Surface do Atlas AI: a porta de entrada e
saida de interacao por voz em tempo real, com qualidade conversacional natural,
latencia <600ms p95 e governanca constitutional. Voice e Surface, nao novo
cerebro.

A meta operacional e: voz ambiente que Vitor prefere a digitar em casos
adequados, governada pelo mesmo Pipeline universal, plugavel a qualquer
provider de STT/TTS/LLM e auditavel pelo Evidence Ledger.

## Autoridade

| Assunto | Autoridade |
|---|---|
| Voice Realtime Surface contract | Este documento |
| Layer 1.5 runtime boundary including swift_native_mac | `atlas-ai-runtime-language-boundaries.md` |
| Pipeline universal | `atlas-ai-pipeline.md` |
| Operation Envelope, Decision Receipt, Evidence Ledger | `atlas-ai-kernel-architecture.md` |
| Mobile surface contract | `atlas-ai-mobile-surface-gateway.md` |
| Local Mac agent (power/wake) | `atlas-local-agent-surface.md` |
| Tese cardinal e Rivals | `atlas-ai-thesis-multiplier-channel.md` |
| Qualitative Levels (Z-axis ambient) | `atlas-ai-qualitative-levels-roadmap.md` |

Quando houver conflito, Layer -1 (Tese) vence sempre.

## Fronteira

Voice Surface pode:

- capturar audio do mic do Mac, AirPods ou outro device de captura suportado;
- detectar wake word localmente e iniciar sessao via LiveKit;
- manter conexao WebRTC com LiveKit Server self-hosted;
- delegar STT/TTS/turn detection ao LiveKit Agents;
- abrir Operation Envelope por turno via Surface Adapter `voice_realtime`;
- emitir eventos no Evidence Ledger para cada estagio do turno;
- aplicar eclipse modes desligando captura por contrato (calendar, focus, manual);
- expor controles de start/stop/mute/eclipse via CLI, app e mobile;
- sintetizar resposta usando TTS plugavel selecionado pela Policy.

Voice Surface nao pode:

- chamar provider de IA direto, sem passar pelo Kernel;
- gravar memoria canonica, deltas ou learning;
- decidir domain, flow, provider ou autonomia;
- elevar permissao por estar local;
- persistir audio raw em qualquer armazenamento, mesmo temporario, sem policy
  explicita;
- streamar audio ambiente antes de wake word detectado;
- ignorar eclipse window;
- transmitir conteudo sensivel sem privacy class do domain ativo;
- substituir CLI/app/mobile como surface unica.

## Principio Constitucional

Voice Realtime Surface e auditada contra a Tese cardinal:

1. **Multiplica?** Sim. Provider de TTS/STT melhor entra como plugin sem
   refator. LLM continua chamado via Kernel, recebendo o multiplicador
   completo (memory canonical, context pack, constitutional filter).
2. **Canal unico?** Sim. Vitor nao usa voice mode direto de provider; toda
   conversa de voz passa por Atlas. Sem isso, Evidence Ledger inanecio.
3. **Antifragil?** Sim. Provider lanca voice mode novo e melhor: vira plugin,
   nao ameaca. Federation A2A futura: Atlas continua orquestrando.

Pergunta-norte aplicada: voice multiplica (provider recebe contexto pessoal +
constitutional filter), nao compete (nao reinventa LLM).

## Mapeamento Em Layers

```text
Layer -1   Tese (multiplicador, canal unico, antifragil)
Layer 0    Constitution + glossary + privacy class definitions
Layer 1    Kernel: OperationEnvelope, DecisionReceipt, EvidenceLedger
Layer 1.5  Runtime boundaries:
             Laravel Kernel        (decide e governa)
             Python AI/Data        (LiveKit Agents)
             Go Edge               (LiveKit Server)
             swift_native_mac     (atlas-voice-edge)   <- novo
Layer 3    Operating topology: pipeline universal
Layer 4    Domain Specs: programming.voice, finance.voice, etc.
```

A adicao constitucional em Layer 1.5 e: Swift como terceiro runtime
especializado, com identidade `swift_native_mac`. Detalhes em
`atlas-ai-runtime-language-boundaries.md` (atualizacao concomitante).

## Arquitetura

```text
Mac mic / AirPods
        │
        ▼
┌──────────────────────────┐
│ atlas-voice-edge (Swift) │  swift_native_mac runtime
│ wake word + VAD + AEC    │  binario CLI long-running
│ AVAudioEngine native     │
└────────────┬─────────────┘
             │ WebRTC publish (post wake)
             ▼
┌──────────────────────────┐
│ LiveKit Server (Go)      │  go_edge runtime
│ self-hosted on Mac       │  WebRTC SFU, sala "atlas-voice-{user}"
└────────────┬─────────────┘
             │ track subscribed
             ▼
┌──────────────────────────┐
│ LiveKit Agents (Python)  │  python_ai_data runtime
│ STT plugin → text        │
│ Atlas Kernel webhook  ───┼──→ HTTPS POST /ai/voice/turn
│ TTS plugin → audio       │ ←── streaming text response
└────────────┬─────────────┘
             │ WebRTC publish synth audio
             ▼
   atlas-voice-edge (Swift) → speakers / AirPods
                            └→ emite eventos local de played

(em paralelo, todo o ciclo:)
        │
        ▼
┌──────────────────────────┐
│ Atlas Kernel (Laravel)   │  soberano
│ Surface Adapter:         │
│   voice_realtime         │
│ Pipeline universal       │
│ Decision Receipt         │
│ Evidence Ledger          │
│ Eclipse Guard            │
│ Privacy Class            │
└──────────────────────────┘
```

## Componentes Canonicos

### Atlas Kernel — Voice Surface Adapter (Laravel)

Diretorio: `app/Services/Ai/Voice/`

Classes obrigatorias:

| Classe | Responsabilidade |
|---|---|
| `AtlasVoiceSurfaceAdapter` | Implementa `SurfaceAdapter` para `voice_realtime`; abre Envelope por turno. |
| `AtlasVoiceTurnService` | Orquestra ciclo de turno: envelope → decide → executor → ledger. |
| `AtlasVoiceTurnRequest` | DTO tipado de input vindo do LiveKit Agents. |
| `AtlasVoiceTurnResponse` | DTO tipado de output streamado de volta. |
| `AtlasVoiceEclipseGuard` | Avalia se o turno deve ser bloqueado por eclipse window. |
| `AtlasVoicePolicyResolver` | Resolve TTS voice id, STT provider, LLM provider, privacy class por domain/flow. |
| `AtlasVoiceSessionRegistry` | Registry append-only de sessoes de voz ativas. |
| `AtlasVoiceRivalsRunner` | Executa profile `voice` do Atlas Rivals. |

Endpoints HTTP (controllers em `app/Http/Controllers/Ai/Voice/`):

| Metodo | Path | Funcao |
|---|---|---|
| `POST` | `/ai/voice/session/start` | LiveKit Agents inicia sessao apos wake word. Retorna session token + initial policy. |
| `POST` | `/ai/voice/session/end` | Encerra sessao. Emite `VOICE_SESSION_ENDED`. |
| `POST` | `/ai/voice/turn` | Webhook por turno: agents envia transcript final, kernel devolve resposta streaming. |
| `POST` | `/ai/voice/turn/interrupted` | Agents notifica interrupcao do usuario. |
| `GET` | `/ai/voice/health` | Status agregado: agents up, server up, edge up, eclipse active, latency p95. |
| `GET` | `/ai/voice/eclipse/active` | Lista eclipse windows ativas para a hora atual. |
| `POST` | `/ai/voice/eclipse/manual` | Aciona eclipse manual com TTL. |

Comandos artisan (`app/Console/Commands/Ai/Voice/`):

| Comando | Funcao |
|---|---|
| `atlas:ai:voice:health` | Health check completo do stack. |
| `atlas:ai:voice:turn-replay --turn=<id>` | Replay de turno via Evidence Ledger. |
| `atlas:ai:voice:eclipse-list` | Lista eclipse windows configuradas. |
| `atlas:ai:voice:rivals --profile=voice` | Roda Rivals-Voice e grava comparativo. |
| `atlas:ai:voice:plugin-list` | Lista STT/TTS plugins ativos com health. |

Migrations (em `database/migrations/`):

```text
2026_05_xx_create_atlas_voice_sessions_table.php
2026_05_xx_create_atlas_voice_turns_table.php
2026_05_xx_create_atlas_voice_eclipse_windows_table.php
2026_05_xx_create_atlas_voice_provider_health_table.php
```

Schema `atlas_voice_sessions`:

| Coluna | Tipo | Notas |
|---|---|---|
| `id` | uuid PK | |
| `user_id` | uuid FK | |
| `surface_origin` | string | `cli`, `app`, `mobile`, `mac_edge` |
| `started_at` | timestamp | |
| `ended_at` | timestamp nullable | |
| `livekit_room` | string | `atlas-voice-<user_short>` |
| `livekit_participant` | string | sid retornado pelo server |
| `wake_source` | enum | `wake_word`, `push_to_talk`, `manual_start` |
| `policy_snapshot_hash` | sha256 | snapshot da policy aplicada |
| `eclipse_state` | enum | `clear`, `eclipsed`, `eclipsed_recovered` |
| `metadata_json` | jsonb | device, app version, edge version |

Schema `atlas_voice_turns`:

| Coluna | Tipo | Notas |
|---|---|---|
| `id` | uuid PK | |
| `session_id` | uuid FK | |
| `envelope_id` | uuid FK | OperationEnvelope |
| `decision_receipt_id` | uuid FK | DecisionReceipt |
| `transcript_text` | text | apos VAD final |
| `transcript_confidence` | float | 0-1 |
| `domain_id` | string | resolvido pela Intent |
| `flow_id` | string | `<domain>.voice` |
| `stt_provider` | string | plugin id |
| `tts_provider` | string | plugin id |
| `llm_provider` | string | provider driver id |
| `latency_audio_received_at` | timestamp(6) | |
| `latency_transcript_final_at` | timestamp(6) | |
| `latency_decision_at` | timestamp(6) | |
| `latency_first_synth_audio_at` | timestamp(6) | |
| `latency_played_at` | timestamp(6) | |
| `total_latency_ms` | int | computado |
| `interrupted` | boolean | |
| `privacy_class` | enum | `public`, `personal`, `sensitive`, `secret` |
| `audio_hash` | sha256 nullable | hash do audio raw, jamais audio |

Schema `atlas_voice_eclipse_windows`:

| Coluna | Tipo | Notas |
|---|---|---|
| `id` | uuid PK | |
| `user_id` | uuid FK | |
| `source` | enum | `calendar_private`, `focus_mode`, `manual`, `domain_rule` |
| `starts_at` | timestamp | |
| `ends_at` | timestamp | |
| `reason` | string | livre, auditavel |
| `active` | boolean | |

LedgerEventType (em `app/Domain/AtlasAi/Ledger/LedgerEventType.php`):

```text
VOICE_SESSION_STARTED
VOICE_WAKE_WORD_DETECTED
VOICE_TURN_AUDIO_RECEIVED
VOICE_TURN_TRANSCRIBED
VOICE_TURN_DECIDED
VOICE_TURN_SYNTHESIZED
VOICE_TURN_PLAYED
VOICE_TURN_INTERRUPTED
VOICE_RUNTIME_FAILED
VOICE_ECLIPSE_TRIGGERED
VOICE_ECLIPSE_LIFTED
VOICE_SESSION_ENDED
VOICE_PROVIDER_HEALTH_DEGRADED
```

Tests obrigatorios (em `tests/Unit/` e `tests/Feature/`):

| Teste | O que prova |
|---|---|
| `VoiceSurfaceAdapterContractTest` | Surface adapter implementa contrato e emite Envelope. |
| `VoiceTurnServiceTest` | Pipeline universal e percorrido por turno. |
| `VoiceEclipseGuardTest` | Eclipse bloqueia turno e emite evento. |
| `VoicePolicyResolverTest` | Resolve TTS/STT/LLM por domain/flow. |
| `VoiceTurnEvidenceComplianceTest` | Todos os 5 latency timestamps sao registrados. |
| `VoiceRivalsRunnerTest` | Profile voice gera scorecard valido. |
| `VoicePrivacyClassPropagationTest` | Audio nao persiste; transcript respeita privacy class. |

### LiveKit Server — Go Edge Runtime

Identidade no Layer 1.5: `go_edge`.

Deployment: self-hosted no Mac via LaunchAgent, NAO LiveKit Cloud.

Arquivos:

| Arquivo | Funcao |
|---|---|
| `infra/livekit/livekit.yaml` | Config oficial do server. |
| `infra/livekit/com.atlas.livekit-server.plist` | LaunchAgent macOS. |
| `scripts/install-livekit-server-launch-agent.sh` | Instala/recarrega LaunchAgent. |
| `scripts/uninstall-livekit-server-launch-agent.sh` | Remove LaunchAgent. |

Config minimo `livekit.yaml`:

```yaml
port: 7880
rtc:
  tcp_port: 7881
  use_external_ip: false
keys:
  ATLAS_VOICE_KEY: ${LIVEKIT_API_SECRET}
log_level: info
```

Variaveis de ambiente em `.env`:

```text
LIVEKIT_URL=ws://127.0.0.1:7880
LIVEKIT_API_KEY=ATLAS_VOICE_KEY
LIVEKIT_API_SECRET=<secret>
ATLAS_VOICE_ROOM_PREFIX=atlas-voice
```

LiveKit Server nao decide nada sobre IA. Apenas roteia audio.

### LiveKit Agents — Python AI/Data Runtime

Identidade no Layer 1.5: `python_ai_data`.

Diretorio: `services/voice-agents/` (subprojeto Python no monorepo).

Estrutura:

```text
services/voice-agents/
├── pyproject.toml
├── atlas_voice_agents/
│   ├── __init__.py
│   ├── agent.py              # entrypoint LiveKit agent
│   ├── kernel_client.py      # cliente HTTPS para Atlas Kernel
│   ├── plugins/
│   │   ├── stt_deepgram.py
│   │   ├── stt_whisper_local.py
│   │   ├── tts_elevenlabs.py
│   │   ├── tts_cartesia.py
│   │   ├── tts_kokoro_local.py
│   ├── policy.py             # aplica policy vinda do Kernel
│   ├── eclipse.py            # checa eclipse antes de processar
│   └── evidence.py           # POST de eventos para Kernel
├── tests/
│   ├── test_kernel_client.py
│   ├── test_plugin_matrix.py
│   └── test_eclipse_guard.py
└── Dockerfile
```

Entrypoint registrado como runtime em Atlas:

```text
runtime_id: python_ai_data
runtime_subtype: voice_agents
health_endpoint: http://127.0.0.1:7881/health
```

Contratos do Agents:

1. **Recebe** `atlas.voice.session.start.v1` do Kernel via webhook outbound.
2. **Envia** `atlas.voice.turn.v1` para `/ai/voice/turn` com transcript final.
3. **Recebe** `atlas.voice.turn.response.v1` em streaming SSE/chunked do Kernel.
4. **Emite** evidence eventos via `POST /ai/observability/voice-event`.

Constitutional dura no Agents:

- nao chama provider de LLM direto;
- nao mantem memoria de turnos;
- nao implementa logica de domain;
- recebe lista permitida de plugins via Policy do Kernel;
- failover entre plugins: ordem definida pela Policy, nao pelo Agents.

### atlas-voice-edge — Swift Local macOS Runtime

Identidade no Layer 1.5: `swift_native_mac` (novo).

Diretorio: `services/voice-edge/` (Swift package, build pra binario CLI).

Estrutura:

```text
services/voice-edge/
├── Package.swift
├── Sources/
│   └── AtlasVoiceEdge/
│       ├── main.swift
│       ├── WakeWordEngine.swift       # Apple Speech / OpenWakeWord
│       ├── VADEngine.swift             # Silero local ou WebRTC VAD
│       ├── AudioCapture.swift          # AVAudioEngine + AirPods routing
│       ├── EchoCancel.swift            # AVAudioSession AEC
│       ├── LiveKitClient.swift         # SDK Swift oficial do LiveKit
│       ├── KernelHandshake.swift       # autentica e recebe session token
│       ├── EclipseListener.swift       # ouve push do Kernel sobre eclipse
│       └── Telemetry.swift             # emite eventos local pro Kernel
├── Tests/
│   └── AtlasVoiceEdgeTests/
│       ├── WakeWordEngineTests.swift
│       ├── VADEngineTests.swift
│       └── EclipseListenerTests.swift
└── Resources/
    └── wake-word-model.bin
```

Build: `swift build -c release` produz binario em
`.build/release/atlas-voice-edge`.

Deployment: LaunchAgent.

| Arquivo | Funcao |
|---|---|
| `infra/voice-edge/com.atlas.voice-edge.plist` | LaunchAgent. |
| `scripts/install-voice-edge-launch-agent.sh` | Instala. |
| `scripts/uninstall-voice-edge-launch-agent.sh` | Remove. |

Entitlements obrigatorios:

- `com.apple.security.device.audio-input`
- `NSMicrophoneUsageDescription` claro e auditavel

CLI flags:

```text
atlas-voice-edge \
  --kernel-url http://127.0.0.1:8000 \
  --kernel-token $ATLAS_VOICE_EDGE_TOKEN \
  --livekit-url ws://127.0.0.1:7880 \
  --wake-word "Hey Atlas" \
  --vad silero \
  --aec native
```

Constitutional dura no edge:

- audio nunca sai da maquina antes de wake word detectado;
- audio raw nunca grava em disco;
- eclipse vindo do Kernel desliga captura imediatamente;
- LED virtual (NSStatusItem) sinaliza captura ativa em tempo real;
- shutdown gracioso ao receber SIGTERM, encerrando sessao no Kernel.

## Pipeline Integration

Todo turno de voz percorre o Pipeline universal:

```text
Input            atlas-voice-edge captura audio apos wake word
Intent           Kernel classifica via transcript final
Domain           Resolvido pela Intent (programming, finance, ...)
Domain Profile   `<domain>.voice`
Flow Profile     ex: `programming.voice`, `finance.voice`, `general.voice`
Context          Context Pack via Open Brain MCP, com voice-aware budget
Policy           AtlasVoicePolicyResolver: STT/TTS/LLM/privacy/budget/eclipse
Decide           DecisionReceipt v2 com `surface=voice_realtime`
Executor         Provider Driver chamado pelo Kernel; resposta streamada
Gate             Quality Gates aplicaveis (constitutional filter, length, etc.)
Repair           se falha: degrada plugin, reroteia, ou escala pra texto
Evidence         eventos VOICE_* + DECISION_ISSUED + ENVELOPE_CREATED
Learning         turno marcado para Curator se sinalizado relevante
Output           texto streamado ao Agents → TTS → audio ao edge → speaker
```

Invariantes especificos de voz:

1. `Input.audio` nunca atravessa o Kernel; somente `Input.transcript`.
2. `Decide` deve registrar `voice_profile` (TTS voice id, prosody hints).
3. `Executor` para voice e sempre streaming (nao batch).
4. `Gate` constitutional filter aplica antes do TTS comecar a sintetizar.
5. `Evidence` deve ter os 5 latency timestamps obrigatorios.

## Contratos De Comunicacao

### atlas.voice.session.start.v1

Kernel → Agents (outbound webhook quando edge envia start):

```json
{
  "schema_version": "atlas.voice.session.start.v1",
  "session_id": "uuid",
  "user_id": "uuid",
  "livekit_room": "atlas-voice-vitor",
  "livekit_token": "jwt",
  "policy": {
    "stt_provider_chain": ["deepgram", "whisper_local"],
    "tts_provider_chain": ["elevenlabs", "kokoro_local"],
    "tts_voice_id": "atlas-default",
    "interruption_enabled": true,
    "max_turn_duration_s": 120,
    "privacy_class_default": "personal"
  },
  "eclipse_active": false
}
```

### atlas.voice.turn.v1

Agents → Kernel (POST /ai/voice/turn):

```json
{
  "schema_version": "atlas.voice.turn.v1",
  "session_id": "uuid",
  "turn_id": "uuid",
  "transcript_text": "...",
  "transcript_confidence": 0.93,
  "stt_provider": "deepgram",
  "audio_received_at": "2026-05-06T14:00:00.123456Z",
  "transcript_final_at": "2026-05-06T14:00:00.456789Z",
  "language": "pt-BR",
  "metadata": {
    "edge_version": "0.1.0",
    "agents_version": "0.1.0",
    "wake_source": "wake_word"
  }
}
```

### atlas.voice.turn.response.v1

Kernel → Agents (response streaming chunked):

```json
{
  "schema_version": "atlas.voice.turn.response.v1",
  "turn_id": "uuid",
  "decision_receipt_id": "uuid",
  "tts_voice_id": "atlas-default",
  "tts_provider": "elevenlabs",
  "prosody_hints": {
    "emotion": "neutral",
    "pace": "normal"
  },
  "stream": [
    {"type": "text_chunk", "text": "Ola Vitor, posso..."},
    {"type": "text_chunk", "text": "ajudar com..."},
    {"type": "end", "total_tokens": 42}
  ]
}
```

### atlas.runtime.invoke.v2 (extension swift_native_mac)

Reusa o contrato canonico (`atlas-ai-runtime-language-boundaries.md`),
adicionando `runtime: "swift_native_mac"` como valor permitido.

## Privacy E Eclipse

Privacy class por domain (configurada em domain manifest):

| Domain | Privacy class default | Audio hash registrado? |
|---|---|---|
| `general` | personal | sim |
| `programming` | personal | sim |
| `finance` | sensitive | sim, com retencao reduzida |
| `personal_development` | sensitive | sim |
| `health` (futuro) | secret | nao registra hash |

Eclipse modes obrigatorios:

| Source | Trigger | Comportamento |
|---|---|---|
| `calendar_private` | Evento marcado como `private` no calendar | edge desliga captura ate fim do evento |
| `focus_mode` | macOS Focus em modo declarado privado | edge desliga captura |
| `manual` | Usuario aciona via CLI/app | edge desliga ate TTL ou release |
| `domain_rule` | Domain declara rule (ex: `health` exige eclipse fora horario clinico) | Kernel bloqueia turn; emite VOICE_ECLIPSE_TRIGGERED |

Constitutional class-3 (imutavel):

1. Audio raw nunca persiste em disco, mesmo temporariamente.
2. Wake word detectado localmente; nao streama audio antes.
3. Eclipse window ativa = edge nao captura, Kernel nao processa.
4. LED virtual sinaliza captura em tempo real.
5. Forgetting Protocol aplica em transcripts segundo privacy class.

## Provider Plugin Matrix

| Plugin | Tipo | Modo | Quando default |
|---|---|---|---|
| `deepgram` | STT | cloud streaming | qualidade + idioma pt-BR |
| `whisper_local` | STT | local (Whisper.cpp via Swift FFI) | offline, fallback |
| `elevenlabs_conversational_v2` | TTS | cloud streaming | conversa natural |
| `cartesia_sonic` | TTS | cloud streaming | low latency |
| `openai_tts_4o_mini` | TTS | cloud | fallback |
| `kokoro_local` | TTS | local (Core ML) | offline, fallback, eclipse-friendly |
| `silero_vad` | VAD | local | sempre |
| `apple_speech_wake_word` | wake word | local | default no Mac |

Politica de chain: Policy do domain define `stt_provider_chain` e
`tts_provider_chain`. Agents tenta na ordem; falha de qualquer plugin gera
`VOICE_PROVIDER_HEALTH_DEGRADED` e usa o proximo.

## Latency SLOs

Targets por percentil:

| Metrica | p50 | p95 | p99 |
|---|---|---|---|
| `audio_received → transcript_final` | 250ms | 450ms | 800ms |
| `transcript_final → first_synth_audio` | 350ms | 700ms | 1200ms |
| `total_turn_latency` | 600ms | 1100ms | 1800ms |
| `wake_word_detection` | 200ms | 400ms | 600ms |

SLOs vivem em `app/Domain/AtlasAi/Slo/VoiceLatencySlo.php` e sao validados
pelo Self-Improvement (`atlas:ai:self-improve --flow=voice_latency_review`).

Quando p95 quebra, evento `SLO_OBSERVED` com severity `degraded` e proposal
para Curator via Inbox.

## Failure Modes

| Falha | Detector | Repair |
|---|---|---|
| LiveKit Server down | health check | edge fallback push-to-talk text via CLI |
| LiveKit Agents down | health check | Surface bloqueia voice; emit alerta inbox |
| STT provider down | timeout / 5xx | failover proximo plugin do chain |
| TTS provider down | timeout / 5xx | failover; ultimo fallback texto na tela |
| LLM provider down | DecisionReceipt repair | Provider Driver fallback do Kernel |
| Eclipse race (window comeca durante turno) | guard | turno e abortado, evento `VOICE_TURN_INTERRUPTED` reason=eclipse |
| Wake word falso positivo | confidence threshold + post-hoc check | sessao abortada; nenhum evento de turno emitido |
| Edge crash | LaunchAgent KeepAlive | reinicia binario, registra `VOICE_RUNTIME_FAILED` |
| Mic permission revogado | Swift NSMicrophone check | edge alerta Kernel; surface fica unavailable |

## Rivals-Voice

Profile do Atlas Rivals especifico para voz:

- comando: `atlas:ai:voice:rivals --scenario=<id>`
- baseline: ChatGPT Voice Mode com mesmo prompt;
- atlas-side: Atlas Voice via stack inteira;
- metricas:
  - latency total p50/p95;
  - perceived quality 1-10 (avaliacao manual);
  - context fidelity (Atlas usa memory canonical, baseline nao);
  - constitutional adherence (output passa em filter);
  - regret rate apos 7 dias (foi util?);
- output: scorecard em `atlas_engineering_runs` com `profile=voice`;
- multiplicador esperado: positivo apos Fase 2 madura.

## Phases E Definition Of Done

### Fase 0 — Pilot Push-To-Talk Local (sem LiveKit)

DoD:

- Swift binario `atlas-voice-pilot` (separado, nao o edge final) capturando audio com hold-to-talk.
- Whisper.cpp local + Kokoro local.
- CLI command `atlas voice pilot` operacional.
- Latency total medida; relatorio em `docs/atlas-voice-pilot-report.md`.
- Decisao documentada: prosseguir ou nao para Fase 1.

### Fase 1 — LiveKit Local Push-To-Talk

DoD:

- LiveKit Server self-hosted instalado e validado.
- LiveKit Agents Python rodando com Deepgram + ElevenLabs.
- Surface Adapter `voice_realtime` registrado em `AtlasCapabilityRegistry`.
- Endpoints `/ai/voice/*` ativos.
- Migrations aplicadas; tabelas auditaveis.
- Evidence Ledger emitindo todos os eventos VOICE_*.
- 7 testes obrigatorios verdes.
- `atlas:ai:voice:health` mostrando todos os componentes up.
- Documentacao operacional em `docs/atlas-voice-runbook.md`.

### Fase 2 — atlas-voice-edge Swift + Wake Word Local

DoD:

- Swift package `atlas-voice-edge` buildando.
- Wake word "Hey Atlas" detectado localmente, latency p95 <400ms.
- VAD local ativo; AEC native operacional.
- Eclipse Listener funcional (calendar, focus, manual).
- LED status visivel.
- LaunchAgent instalado e estavel.
- Layer 1.5 atualizado no doc canonico com `swift_native_mac`.
- Rivals-Voice com scorecard inicial gravado.

### Fase 3 — Producao Continua

DoD:

- Voice como surface de uso diario do Vitor.
- Multiplicador positivo confirmado em Rivals-Voice.
- SLO p95 dentro do target.
- Privacy/eclipse violacoes = 0 em 30 dias.
- Self-Improvement gerando proposals de melhoria automaticas.

## Updates Em Outros Docs

Codex deve atualizar concomitantemente:

| Doc | Mudanca |
|---|---|
| `atlas-ai-runtime-language-boundaries.md` | Adicionar `swift_native_mac` como terceiro runtime, com secao "Papel Do Swift" e linhas na matriz de decisao. |
| `atlas-ai-canonical-architecture-index.md` | Adicionar este doc em `related_paths` e na tabela "Autoridade Por Assunto" (Voice Realtime Surface → este doc). |
| `atlas-ai-master-architecture.md` | Listar `voice_realtime` como surface oficial; adicionar `<domain>.voice` como flow profile padrao. |
| `atlas-ai-mobile-surface-gateway.md` | Mencionar voice como complemento mobile; declarar que mobile pode iniciar/encerrar sessao de voz remota. |
| `atlas-ai-pipeline.md` | Listar `voice_realtime` em surfaces que entram no pipeline. |
| `atlas-ai-telemetry-evidence-performance.md` | Listar eventos VOICE_* e SLO de voz. |
| `atlas-ai-qualitative-levels-roadmap.md` | Marcar Z-P1 (voz ambiente) como plano executavel desta spec. |
| `docs/atlas-outro-patamar-roadmap.md` | Linkar este doc na secao "Movimento 3 — Hardware sovereignty". |
| `README.md` da KB | Adicionar entrada para Voice Realtime Surface. |

## Anti-Padroes

- LiveKit Agents chamando OpenAI/Claude/Gemini direto (canal unico violado).
- Audio raw persistido em disco para "debugging".
- Wake word detectado em cloud (audio ambiente streamado).
- Bypass de eclipse via flag em runtime.
- Surface mobile/CLI duplicando logica de turno.
- Plugin novo de TTS sem Policy controlando.
- Latency timestamp ausente no evento de turno.
- Memory canonical sendo escrita pelo Agents.

## Source Material

- `docs/atlas-outro-patamar-roadmap.md` (Movimento 3, Eixo Z-P1)
- `docs/engineering-knowledge-base/atlas-ai-thesis-multiplier-channel.md` (Invariante 8 — Atlas Voice obrigatorio)
- LiveKit docs: <https://docs.livekit.io>
- LiveKit Agents framework: <https://github.com/livekit/agents>
- LiveKit Swift SDK: <https://github.com/livekit/client-sdk-swift>
