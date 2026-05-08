# AP-182 - Voice Realtime Callback Sequence Contract

Status: `foundation-contract-implemented`  
Owner: Atlas Kernel / Voice Realtime  
Last updated: 2026-05-08

## 1. Proposito

AP-181 valida o formato de cada callback. AP-182 valida a ordem dos callbacks dentro de uma sessao de voz.

Isto protege o Atlas contra um erro comum em realtime: payloads individualmente validos, mas semanticamente impossiveis quando chegam fora de ordem.

## 2. Escopo Implementado

- `AtlasVoiceCallbackSequenceContract`
- fixture offline `tests/Fixtures/Ai/voice-callback-sequences.json`
- testes unitarios de sequencia valida, sequencia invalida, propagacao de erro do contrato de payload e sequencia vazia

## 3. Autoridade

Schema: `atlas.voice_realtime.callback_sequence_contract.v1`  
Modo: `validation_only`  
Autoridade: `callback_sequence_contract_no_runtime_execution`

Este contrato nao executa daemon, nao chama provider, nao grava ledger e nao substitui Atlas Decide.

## 4. Regras de Sequencia

- a sequencia deve pertencer a uma unica `session_id`
- `participant_joined` deve vir antes de eventos de turno
- `participant_left` encerra a sequencia
- `tts_synthesized` exige `transcript_final` do mesmo `turn_id`
- `audio_played` exige `tts_synthesized` do mesmo `turn_id`
- `barge_in` exige `transcript_final` do mesmo `turn_id`
- `provider_health_degraded` exige turno conhecido
- `runtime_failed` exige turno conhecido
- cada evento precisa passar pelo contrato de payload do AP-181

## 5. Falhas Detectadas

- sequencia vazia
- callback antes do participante entrar
- evento depois de `participant_left`
- mudanca de `session_id` no meio da sequencia
- TTS antes da transcricao final
- playback antes de sintese
- erro de payload carregado para o erro de sequencia

## 6. Encaixe no Fluxo Atlas

1. Runtime/mobile captura evento bruto
2. adapter normaliza para callback canonico
3. AP-181 valida payload
4. AP-182 valida sequencia
5. Policy/Atlas Decide autoriza turno
6. Runtime executa provider/ferramenta apenas com receipt
7. Evidence Ledger grava evento aprovado

AP-182 cobre somente o passo 4.

## 7. Nao Escopo

- nao altera `AtlasVoiceRealtimeService`
- nao cria API
- nao cria comando
- nao altera Python runtime
- nao altera Architecture Operations
- nao cria persistencia
- nao executa LiveKit

## 8. Proximo Passo Seguro

Quando a area quente de Voice Realtime estiver livre, um adapter pode usar AP-181 e AP-182 antes de encaminhar eventos ao Kernel:

1. normalizar callback do runtime
2. validar payload
3. validar sequencia local da sessao
4. produzir Operation Envelope
5. exigir Decision Receipt v2 antes de provider/tool

## 9. Definition of Done

- Sequencia normal passa
- Sequencias fora de ordem falham fechado
- Erros de payload aparecem no resultado de sequencia
- Sequencia vazia falha fechado
- Nenhum runtime externo e necessario para testar
