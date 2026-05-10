---
id: AP-687-voice-realtime-production-promotion-gate
type: architecture_plan
title: AP-687 Voice Realtime Production Promotion Gate
status: implemented_ready
owner: Atlas Kernel / Voice Realtime
priority: 87
line_limit: 220
summary: Separa certificacao segura de scaffold de promocao real de produto para Voice Realtime, impedindo que LiveKit/mobile/audio sejam vendidos como prontos antes dos gates de producao.
tags:
  - atlas-ai
  - voice-realtime
  - production-gate
  - livekit
  - mobile-first
related_paths:
  - app/Services/Ai/Voice/AtlasVoiceRuntimeCertificationService.php
  - app/Services/Ai/Voice/AtlasVoiceLiveKitTokenIssuer.php
  - runtimes/python/voice_realtime/atlas_voice_agent/contract.py
  - runtimes/python/voice_realtime/atlas_voice_agent/livekit_runtime_entrypoint.py
  - runtimes/python/voice_realtime/atlas_voice_agent/mock_kernel.py
  - runtimes/python/voice_realtime/atlas_voice_agent/session_lease.py
  - runtimes/python/voice_realtime/atlas_voice_agent/settings.py
  - tests/Unit/Ai/Voice/AtlasVoiceRuntimeCertificationServiceTest.php
  - tests/Feature/Ai/AtlasAiVoiceRealtimeCommandTest.php
  - tests/Feature/Ai/AtlasAiVoiceRealtimeApiTest.php
  - runtimes/python/voice_realtime/tests/test_contract.py
  - runtimes/python/voice_realtime/tests/test_livekit_runtime_entrypoint.py
  - runtimes/python/voice_realtime/tests/test_mock_kernel.py
  - runtimes/python/voice_realtime/tests/test_session_lease.py
  - runtimes/python/voice_realtime/tests/test_settings.py
---

# AP-687 - Voice Realtime Production Promotion Gate

## Purpose

AP-687 garante que `runtime-certify` nao seja confundido com lancamento de
produto real. O runtime pode estar `certified_scaffold` e ainda assim ter
`production_promotion_gate.status=blocked`.

## Implemented Scope

- `AtlasVoiceLiveKitTokenIssuer::readiness()`;
- `production_promotion_gate` dentro de
  `AtlasVoiceRuntimeCertificationService::certify()`;
- `phase0_hardening` dentro de `atlas:ai:voice readiness --json`, com schema
  `atlas.voice_realtime.phase0_hardening_gate.v1`, explicita o bloco seguro
  `Voice Realtime phase 0 hardening` antes de qualquer produto LiveKit real;
- CLI human output exibe status de promocao e review humano;
- API interna e mobile gateway retornam o gate;
- testes provam que scaffold certificado continua bloqueado para producao.
- Rivals-Voice e Curator consomem o gate antes de maturidade.
- `base_url` de bootstrap/certificacao e validado antes de publicar manifest ou
  gerar env files temporarios: apenas `http/https`, host obrigatorio e sem
  caracteres de controle.
- comandos Python de certificacao tem timeout explicito e falham fechado com
  `voice_runtime_command_timeout`.
- runtime Python valida `ATLAS_BASE_URL`, `LIVEKIT_URL` e
  `ATLAS_VOICE_ROOM_PREFIX`; URLs com caracteres de controle ou prefixo fora
  de `atlas-voice-` falham antes de carregar contrato.
- runtime Python tambem rejeita manifest bootstrap cujo
  `session_lease.room_prefix` saia do namespace `atlas-voice-`, mesmo quando o
  env esta correto.
- runtime Python valida URLs publicadas pelo manifest bootstrap com parser HTTP
  real: sem caracteres de controle, scheme `http/https` e host obrigatorio.
- runtime Python valida session leases recebidas do Kernel: `room_name` deve
  permanecer em `atlas-voice-`, `participant_identity` deve vir de surface
  permitida e `livekit_url` deve ser HTTP(S) seguro.
- mock Kernel usado em smoke tests normaliza sala e participante do mesmo modo,
  para que testes nao ensinem um contrato diferente do produto.
- worker start do runtime Python declara explicitamente que production promotion,
  review humano, Decision Receipt e rollback plan continuam obrigatorios mesmo
  quando os gates tecnicos ficarem prontos.
- `--callback-loop-wired` e `--production-sdk-loop-wired` expõem o caminho de
  wiring de produto em checks governados; mesmo com ambos verdadeiros, o daemon
  nao inicia e `auto_promotion_allowed=false` permanece obrigatorio.
- `product-loop-check` agrega callback loop, production-loop-plan e worker-start
  em `atlas.voice_realtime.product_loop_check.v1`, para a proxima IA saber o
  motivo exato do bloqueio sem inventar um fluxo paralelo.
- `sdk-check` agora e contrato de compatibilidade: probe por package/import,
  `sdk_imported=false`, `import_probe_only=true`, `package_checks` e
  `missing_imports`, sem carregar SDK real durante readiness.

## Machine Gates

| Gate | Regra |
|---|---|
| `scaffold_certified` | certificacao runtime passou |
| `sdk_certification_required` | comando foi rodado com `--require-sdk` |
| `livekit_agents_sdk_ready` | preflight ve `sdk_status=ready` |
| `livekit_token_issuer_ready` | token issuer habilitado e configurado sem expor segredo |
| `callback_sequence_passed` | callback sequence smoke fecha sessoes |
| `production_loop_smoke_passed` | SDK-shaped smoke passa sem daemon e sem import SDK |
| `worker_start_still_blocked_until_real_loop` | worker nao inicia antes do loop real |
| `product_loop_wiring_flags_visible` | CLI/Python aceitam flags de wiring, mas preservam `started=false` |
| `product_loop_check_available` | artefato agregado existe e preserva `daemon_started=false` |
| `sdk_probe_import_safe` | SDK readiness usa probe/metadata e nao importa LiveKit runtime |

## Status Semantics

| Status | Significado |
|---|---|
| `blocked` | falta SDK, token issuer, require-sdk ou outro gate de maquina |
| `review_required` | todos gates de maquina passaram; humano ainda precisa aprovar |

Promocao automatica continua proibida: `auto_promotion_allowed=false`.
Promocao direta tambem continua proibida: `promotion_allowed=false`; mesmo
`review_required` significa apenas abrir review humano com Decision Receipt e
rollback plan obrigatorios.

`review_packet` deve acompanhar certification, Rivals-Voice e review_signal:
ele declara evidencias, rollback e proibicoes para qualquer humano ou IA saber
que promocao real nunca e automatica.
O Curator/Self-Improvement deve projetar esse mesmo `review_packet` no finding
de voz para que proposal inbox, review humano e rollback tenham a mesma fonte.
Session lease deve escopar toda sala LiveKit no namespace `atlas-voice-`, mesmo
quando o cliente pede `room_name`, para evitar sala arbitraria fora do Atlas.
`participant_identity` tambem deve ser normalizado pelo `client_surface` para
que tokens LiveKit nao carreguem identidade arbitraria definida pelo cliente.
Token LiveKit so pode ser emitido quando issuer estiver habilitado, URL, key e
secret estiverem configurados; caso contrario o lease fica sem `access_token`.
O proprio token issuer deve rejeitar lease com sala fora de `atlas-voice-` ou
participante fora do namespace do `client_surface`, mesmo que chamado direto.

## Non Goals

- nao instala LiveKit Agents SDK;
- nao inicia worker daemon;
- nao grava audio cru;
- nao autoriza provider direto;
- nao substitui Rivals-Voice ou review humano.

## Validation

```bash
php artisan test tests/Unit/Ai/Voice/AtlasVoiceRuntimeCertificationServiceTest.php
php artisan test tests/Feature/Ai/AtlasAiVoiceRealtimeCommandTest.php tests/Feature/Ai/AtlasAiVoiceRealtimeApiTest.php
php artisan atlas:ai:voice runtime-certify --json
```

## Definition Of Done

- `production_promotion_gate.schema_version` existe;
- `phase0_hardening.schema_version` existe no readiness de voz;
- gate fica `blocked` quando `--require-sdk` nao foi usado;
- `human_review_required=true` e `auto_promotion_allowed=false`;
- `promotion_allowed=false`, `decision_receipt_required=true` e
  `rollback_plan_required=true`;
- gate fica `blocked` quando LiveKit Agents SDK esta ausente;
- gate fica `blocked` quando token issuer nao esta configurado;
- `review_packet` declara decisao humana, evidencias, rollback e proibicoes;
- artifacts de token issuer nao expõem secrets;
- `base_url` de bootstrap/runtime-certify nao permite injecao por newline, path
  local ou scheme nao HTTP;
- runner Python de certificacao nao pode travar indefinidamente;
- runtime Python espelha o namespace `atlas-voice-` do Kernel no env e no
  contrato bootstrap;
- runtime Python rejeita URLs inseguras no contrato bootstrap antes de iniciar
  qualquer loop LiveKit;
- runtime Python rejeita session lease com sala, participante ou LiveKit URL
  fora do contrato antes de iniciar qualquer room;
- worker start nao permite promocao implicita: o payload expõe
  `worker_start_without_production_promotion_allowed=false`;
- CLI/API/mobile mostram o status;
- `runtime-certify.next_action` espelha o promotion gate quando producao esta
  bloqueada;
- promotion para produto real exige review humano.
