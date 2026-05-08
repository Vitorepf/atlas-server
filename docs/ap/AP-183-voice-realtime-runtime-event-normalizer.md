# AP-183 - Voice Realtime Runtime Event Normalizer

Status: `foundation-contract-implemented`  
Owner: Atlas Kernel / Voice Realtime  
Last updated: 2026-05-08

## 1. Proposito

AP-183 cria a ponte segura entre nomes de evento do runtime/mobile/LiveKit e os callbacks canonicos do Atlas.

O runtime pode falar `room_connected`, `transcribed_turn`, `synthesized` ou `room_disconnected`. O Kernel deve receber `participant_joined`, `transcript_final`, `tts_synthesized` e `participant_left`.

## 2. Escopo Implementado

- `AtlasVoiceRuntimeEventNormalizer`
- fixture offline `tests/Fixtures/Ai/voice-runtime-events.json`
- teste unitario para contrato, evento wrapped, sequencia valida, eventos invalidos e erro de sequencia apos normalizacao

## 3. Autoridade

Schema: `atlas.voice_realtime.runtime_event_normalizer.v1`  
Modo: `normalization_and_validation_only`  
Autoridade: `runtime_event_normalizer_no_runtime_execution`

O normalizer nao executa LiveKit, nao chama provider, nao grava ledger e nao abre API.

## 4. Mapa Canonico

- `room_connected`, `start_session` -> `participant_joined`
- `transcribed_turn` -> `transcript_final`
- `synthesized` -> `tts_synthesized`
- `played` -> `audio_played`
- `turn_interrupted` -> `barge_in`
- `room_disconnected`, `session_ended` -> `participant_left`

Eventos ja canonicos tambem sao aceitos.

## 5. Higiene de Payload

O normalizer:

- rejeita evento desconhecido
- rejeita segredo, audio cru, texto cru de resposta e tool call antes de filtrar campos
- remove campos que nao pertencem ao payload canonico
- valida cada evento com AP-181
- valida sequencias com AP-182 quando recebe varios eventos

## 6. Encaixe no Fluxo Atlas

1. LiveKit/mobile emite evento bruto
2. AP-183 normaliza para callback canonico
3. AP-181 valida payload
4. AP-182 valida sequencia
5. Policy / Atlas Decide avalia o turno
6. Runtime executa somente com Decision Receipt
7. Evidence Ledger recebe apenas evento aprovado

## 7. Nao Escopo

- nao altera `AtlasVoiceRealtimeService`
- nao altera Python runtime
- nao cria comando
- nao cria endpoint
- nao altera Architecture Operations
- nao grava Evidence Ledger

## 8. Proximo Passo

Quando a area quente de Voice Realtime estiver livre, conectar o adapter real nesta ordem:

1. runtime event -> AP-183
2. callback payload -> AP-181
3. session sequence -> AP-182
4. Operation Envelope
5. Policy / Decide
6. Decision Receipt v2
7. callback handler existente

## 9. Definition of Done

- aliases principais do Python/LiveKit mapeados
- evento bruto valido normaliza
- payload wrapped normaliza
- evento com dado proibido falha fechado
- sequencia normalizada passa pelos contratos AP-181/AP-182
