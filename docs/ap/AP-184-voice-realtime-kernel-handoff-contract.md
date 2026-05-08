# AP-184 - Voice Realtime Kernel Handoff Contract

Status: `foundation-contract-implemented`  
Owner: Atlas Kernel / Voice Realtime  
Last updated: 2026-05-08

## 1. Proposito

AP-181 valida payload. AP-182 valida sequencia. AP-183 normaliza evento bruto.

AP-184 define o ultimo passo antes do Kernel real: preparar um handoff declarativo dizendo qual metodo do Kernel deve receber o evento e quais garantias ainda sao obrigatorias.

## 2. Escopo Implementado

- `AtlasVoiceKernelHandoffContract`
- fixture offline `tests/Fixtures/Ai/voice-kernel-handoff-events.json`
- testes unitarios para contrato, turn decision request, runtime callback report, sequencia e evento invalido

## 3. Autoridade

Schema: `atlas.voice_realtime.kernel_handoff_contract.v1`  
Modo: `handoff_planning_only`  
Autoridade: `kernel_handoff_contract_no_kernel_execution`

Este contrato nao chama `AtlasVoiceRealtimeService`, nao cria Operation Envelope, nao emite Decision Receipt, nao grava Evidence Ledger e nao executa provider.

## 4. Mapeamento

- `participant_joined` -> `session_start`
- `wake_word_detected` -> `wake_word_observation`
- `transcript_final` -> `turn_decision_request`
- `tts_synthesized`, `audio_played`, `barge_in`, `runtime_failed`, `provider_health_degraded` -> `runtime_callback_report`
- `participant_left` -> `session_end`

## 5. Regras de Handoff

- `turn_decision_request` exige Operation Envelope e Decision Receipt.
- `runtime_callback_report` exige Decision Receipt ja existente.
- `session_start`, `wake_word_observation` e `session_end` nao criam decisao por este contrato.
- todo handoff carrega `canonical_event_hash` e `dedupe_key` estavel.
- todo payload ja passou por AP-181, AP-182 e AP-183.

## 6. Encaixe no Fluxo Atlas

1. evento bruto chega do runtime/mobile
2. AP-183 normaliza
3. AP-181 valida payload
4. AP-182 valida sequencia
5. AP-184 prepara handoff
6. `AtlasVoiceRealtimeService` ou adapter futuro executa Kernel real
7. Kernel cria envelope/receipt quando aplicavel
8. Evidence Ledger registra o evento aprovado

## 7. Nao Escopo

- nao cria endpoint
- nao cria comando Artisan
- nao altera runtime Python
- nao altera `AtlasVoiceRealtimeService`
- nao grava ledger
- nao cria receipt
- nao chama provider

## 8. Proximo Passo

Quando a area quente estiver livre, um adapter real pode consumir AP-184:

1. receber handoff valido
2. chamar metodo do Kernel indicado
3. anexar Operation Envelope / Decision Receipt quando o Kernel gerar
4. gravar Evidence Ledger apenas por servico autorizado

## 9. Definition of Done

- turn transcrito prepara `turn_decision_request`
- callback de runtime prepara `runtime_callback_report`
- sequencia prepara handoffs ordenados
- evento invalido nao gera handoff
- hashes/dedupe keys estaveis existem antes de qualquer persistencia
