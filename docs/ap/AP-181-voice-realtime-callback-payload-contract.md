# AP-181 - Voice Realtime Callback Payload Contract

Status: `foundation-contract-implemented`  
Owner: Atlas Kernel / Voice Realtime  
Last updated: 2026-05-08

## 1. Proposito

Este AP fecha uma fundacao segura para a superficie `voice_realtime`: validar payloads de callbacks antes de qualquer persistencia, Evidence Ledger, provider, ferramenta ou runtime real.

O objetivo e simples: quando LiveKit Agents SDK, app mobile ou worker Python enviarem eventos de voz, o Atlas precisa rejeitar cedo qualquer payload com audio cru, texto cru de resposta, segredo, chamada direta de ferramenta ou campo fora do contrato.

## 2. Escopo Implementado

- `AtlasVoiceCallbackPayloadContract`
- fixture offline `tests/Fixtures/Ai/voice-callback-payloads.json`
- testes unitarios de payload valido, payload invalido, callback desconhecido, campo desconhecido e deteccao recursiva de dados proibidos

## 3. Autoridade

Schema: `atlas.voice_realtime.callback_payload_contract.v1`  
Modo: `validation_only`  
Autoridade: `callback_payload_contract_no_runtime_execution`

Este contrato nao executa runtime, nao chama provider, nao aplica policy, nao grava Evidence Ledger e nao decide turno. Ele apenas define e valida o envelope seguro que uma camada futura pode consumir.

## 4. Callbacks Cobertos

- `participant_joined`
- `transcript_final`
- `wake_word_detected`
- `tts_synthesized`
- `audio_played`
- `barge_in`
- `runtime_failed`
- `provider_health_degraded`
- `participant_left`

## 5. Regra de Privacidade

Campos proibidos sao bloqueados em qualquer profundidade do payload:

- tokens e chaves: `access_token`, `livekit_token`, `api_key`, `api_secret`, `provider_api_key`, `token`
- audio cru: `audio`, `audio_bytes`, `audio_raw`, `raw_audio`, `raw_audio_bytes`, `pcm`, `wav`
- texto cru de resposta: `response_text`, `raw_response_text`, `tts_text`
- execucao direta: `tool_call`, `tool_args`

O contrato permite hashes, duracao, latencia, provider/model e metadados canonicos aprovados pelo schema.

## 6. Fail Closed

O validador falha quando:

- callback nao esta na allowlist
- campo obrigatorio esta ausente
- campo top-level nao pertence ao schema do callback
- campo proibido aparece no topo ou aninhado

Isto impede o caminho perigoso de "aceitar tudo agora e limpar depois".

## 7. Ligacao com a Estrutura Mae

Este AP encaixa no fluxo:

1. Surface Plane: app mobile ou runtime de voz
2. Surface Adapter: normaliza o evento
3. Atlas Input / Operation Envelope: payload validado
4. Policy / Profile: privacidade e permissao
5. Atlas Decide: decisao por turno
6. Decision Receipt v2: recibo auditavel
7. Runtime / Executor: LiveKit/Python so executa depois da decisao
8. Evidence Ledger: persiste apenas eventos aprovados, sem audio cru ou segredo

AP-181 cobre somente o passo 2 para 3.

## 8. Nao Escopo

- Nao cria rota API
- Nao cria comando Artisan
- Nao altera `AtlasVoiceRealtimeService`
- Nao inicia daemon LiveKit
- Nao grava Evidence Ledger
- Nao conecta provider
- Nao altera Architecture Operations

## 9. Proximo Passo Seguro

Quando o Codex principal liberar a area quente de Voice Realtime, este contrato pode ser conectado por uma camada adaptadora:

1. normalizar evento bruto do runtime
2. validar com `AtlasVoiceCallbackPayloadContract`
3. rejeitar com erro auditavel se falhar
4. converter payload valido em Operation Envelope
5. passar por Policy / Decide antes de provider ou ferramenta

## 10. Definition of Done

- Todos os callbacks principais possuem required/optional/prohibited fields
- Fixtures validas passam
- Fixtures invalidas falham com erros esperados
- Segredos e audio cru aninhados sao detectados
- Campo desconhecido falha fechado
- Teste unitario dedicado passa sem runtime externo
