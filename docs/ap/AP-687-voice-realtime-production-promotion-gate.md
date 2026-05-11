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
  `Voice Realtime phase 0 hardening` antes de qualquer produto LiveKit real e
  aponta `product_loop_check` como runtime contract obrigatorio antes de daemon;
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
  wiring de produto em checks governados, `product-loop-check`, `runtime-certify`,
  `promotion-review-packet`, Rivals, API interna e mobile; mesmo com ambos
  verdadeiros, o daemon nao inicia e `auto_promotion_allowed=false` permanece obrigatorio.
- `activation-contract` trata `production_sdk_loop_wired` como gate separado:
  callback router sozinho nao habilita worker start.
- `LiveKitSdkHandlerRegistry` formaliza o loop real do SDK: cada handler deve
  extrair somente primitivos, chamar `LiveKitSdkEventBridge.to_callback_event`,
  rotear por `LiveKitCallbackRouter.route` e retornar apenas
  `LiveKitWorkerResult` seguro. Handler nao pode chamar provider, tool, memory,
  policy, token, segredo ou audio cru.
- `product-loop-check` agrega callback loop, production-loop-plan e worker-start
  em `atlas.voice_realtime.product_loop_check.v1`, para a proxima IA saber o
  motivo exato do bloqueio sem inventar um fluxo paralelo. Ele inclui
  `sdk_probe_import_safe` e `sdk_handler_blueprint_available` como gates de maquina;
  se um deles falha, o next action manda corrigir probe ou blueprint antes do daemon.
  Esse gate exige `handler_registry_contract`, `complete_handler_registry=true`
  e blueprint por callback; documentar handlers sem registry nao basta.
  O mesmo artefato e publicado por CLI, API interna
  `/ai/voice/runtime/product-loop-check`, mobile
  `/v1/mobile/ai/voice/runtime/product-loop-check` e bootstrap
  `kernel.runtime_product_loop_check_url`, para o app mobile e runtimes Python
  consumirem o contrato sem criar caminho paralelo; os mesmos flags de wiring
  devem atravessar CLI, API e mobile.
- `daemon-supervisor-check` expõe `atlas.voice_realtime.daemon_supervisor_execution.v1`
  como fronteira unica de execucao futura. Hoje é `supervised_contract_only`:
  avalia receipts, preflight e health, mas conserva `process_launch_attempted=false`,
  `daemon_started=false`, `start_allowed=false` e `process_adapter_implemented=false`.
- `runtime-certify` executa e publica `product_loop_check` como artefato
  sanitizado. O gate `product_loop_check_available` passa quando o contrato
  agregado existe, preserva `daemon_started=false`, prova
  `sdk_probe_import_safe=true`, `sdk_handler_blueprint_available=true`,
  `daemon_supervisor_execution_available=true`,
  `daemon_supervisor_process_launch_disabled=true`, snapshot observacional do
  supervisor e promocao de producao bloqueada. Rivals-Voice expoe isso.
  O artefato pode ter `status=blocked` quando falta SDK/token issuer; isso nao
  e falha do scaffold, e sim a sinalizacao correta do proximo passo.
- `sdk-check` agora e contrato de compatibilidade: probe por package/import,
  `sdk_imported=false`, `import_probe_only=true`, `package_checks` e
  `missing_imports`, sem carregar SDK real durante readiness.
- `supervised_start_plan` (`atlas.voice_realtime.supervised_start_plan.v1`)
  documenta o ultimo handoff fail-closed: mesmo com reviews validos,
  `start_allowed=false`, `execution_implemented=false` e
  `worker_process_launch_disabled=true` ate existir supervisor real. Ele tambem
  publica `supervisor_contract`
  (`atlas.voice_realtime.daemon_supervisor_contract.v1`) com lifecycle,
  health checks, rollback, restart policy sem loop automatico e eventos
  `VOICE_DAEMON_*`, para orientar a implementacao futura sem permitir launch.
  Tambem publica `supervisor_health_snapshot`
  (`atlas.voice_realtime.daemon_supervisor_health_snapshot.v1`) observacional:
  `daemon_started=false`, checks planejados, blocked reasons e lifecycle sem
  iniciar processo; e `supervisor_preflight`
  (`atlas.voice_realtime.daemon_supervisor_preflight.v1`) como contrato de
  execucao futura, ainda com `process_launch_attempted=false`.
- `daemon_supervisor_execution`
  (`atlas.voice_realtime.daemon_supervisor_execution.v1`) é a primeira fronteira
  executavel do supervisor. Ela chega a `ready_for_process_adapter_implementation`
  quando os dois receipts e o preflight estao validos, mas ainda nao possui
  process adapter; o unico next action permitido é `implement_reviewed_process_adapter`.
  Ela tambem publica `process_adapter_blueprint`
  (`atlas.voice_realtime.daemon_process_adapter_blueprint.v1`) com `argv_template`,
  env keys obrigatorias, politicas de segredo, health checks, rollback e
  `launch_allowed=false`; isso e blueprint implementavel, nao daemon iniciado.
  O shell `supervised_process_adapter`
  (`atlas.voice_realtime.supervised_process_adapter.v1`) implementa os metodos
  de lifecycle/health/rollback como contrato revisavel, mas `start_worker_process`
  retorna `blocked_launch_not_implemented` e nao importa `subprocess` nem SDK.
  Ele tambem publica `managed_environment_contract`
  (`atlas.voice_realtime.managed_env_contract.v1`) com refs de env/segredo,
  manifesto sanitizado, `env_file_write_attempted=false` e nenhum valor secreto
  no output.
  O `launch_authorization_contract`
  (`atlas.voice_realtime.launch_authorization_contract.v1`) declara receipts,
  checks e proibicoes do proximo passo, mas conserva `launch_allowed=false` e
  `process_launch_attempted=false`. O gate separa
  `launch_authorization_contract_available` de
  `launch_authorization_contract_ready`: o contrato existe sempre que o adapter
  e inspecionado, mas so fica ready quando receipts e preflight permitem a
  implementacao do proximo passo.
  O `managed_env_writer` (`atlas.voice_realtime.managed_env_writer.v1`) valida
  placeholders e publica manifesto redigido, mas preserva
  `write_execution_implemented=false`, `env_file_write_attempted=false`,
  `secret_values_present_in_output=false` e nunca escreve `.env` nesta fase.
  A funcao `execute_managed_env_write` implementa a execucao futura de escrita
  sob `atlas.voice_realtime.managed_env_write_authorization.v1`: quando
  autorizada, escreve apenas placeholders em arquivo `atlas-voice-*.env`,
  aplica permissao `0600`, retorna `content_sha256`, nunca retorna conteudo,
  nunca inicia processo e emite
  `atlas.voice_realtime.managed_env_write_execution.v1`.
  O `subprocess_start_contract`
  (`atlas.voice_realtime.subprocess_start_contract.v1`) e a fronteira seguinte:
  exige pre-start checks executados sob autorizacao propria, mas ainda conserva
  `subprocess_launch_implemented=false`, `process_launch_attempted=false`,
  `daemon_started=false`, `subprocess_module_imported=false`,
  `livekit_sdk_imported=false`, provider/tool proibidos e audio cru intocado.
- `runtime/events/normalize` e `runtime/events/normalize-sequence` expõem o
  normalizador do Kernel para API interna e mobile. Eles validam eventos SDK,
  removem campos fora do contrato e falham fechado em segredo/audio cru, mas
  nunca executam runtime, provider, tool, memory ou policy.
- `atlas:ai:voice normalize-event|normalize-sequence --event-file=<json>`
  oferece a mesma validacao pelo CLI, para automacoes e IAs checarem payloads
  antes de chamar qualquer endpoint de runtime.
- runtime Python exige `kernel.runtime_event_normalizer_url` e
  `kernel.runtime_event_sequence_normalizer_url` no bootstrap manifest, e o
  `AtlasKernelClient` expoe `normalize_runtime_event*` como caminho oficial de
  preflight. O client ainda rejeita audio cru/segredos localmente antes de
  chamar o Kernel, preservando privacidade enquanto evita contrato paralelo.
- `production-loop-smoke` chama o normalizador de sequencia do Kernel antes de
  rotear handlers SDK e cada callback SDK passa novamente pelo normalizador de
  evento no `LiveKitSdkHandlerRegistry` antes de chegar no callback router. O
  smoke so avanca se
  `kernel_normalizer_contract_report.status=valid`, evitando que Python vire
  fonte independente de canonicalizacao.
- `KernelRuntimeEventNormalizerGuard` e o componente reutilizavel do runtime
  Python para esse preflight. Ele chama `AtlasKernelClient.normalize_runtime_event*`,
  publica contrato `atlas.voice_realtime.kernel_normalizer_guard.v1` e falha
  fechado dentro do handler registry se o Kernel reportar schema/guardrail
  invalido.
- `sdk_wiring_contract` declara `KernelRuntimeEventNormalizerGuard` como
  componente obrigatorio, exige o invariante
  `validate_every_sdk_event_through_kernel_normalizer` e marca
  `kernel_event_normalizer_required_for_real_loop=true`; remover esse gate
  quebra o contrato antes de qualquer daemon.
- `runtime-certify` e `production_promotion_gate` tratam
  `kernel_normalizer_contract_report.status=valid` como parte obrigatoria de
  `production_loop_smoke_passed`; bridge/handler/worker verdes sem normalizer
  do Kernel nao certificam o runtime.
- `runtime-certify` tambem coleta `pre-start-health-checks-smoke` como
  artefato oficial e machine gate. Esse smoke fecha a cadeia writer ->
  pre-start checks -> subprocess start contract sem iniciar processo; ele e
  evidencia obrigatoria do review packet, mas nunca conta como prova de
  LiveKit real ou promocao de producao.

## Machine Gates

| Gate | Regra |
|---|---|
| `scaffold_certified` | certificacao runtime passou |
| `sdk_certification_required` | comando foi rodado com `--require-sdk` |
| `livekit_agents_sdk_ready` | preflight ve `sdk_status=ready` |
| `livekit_token_issuer_ready` | token issuer habilitado e configurado sem expor segredo |
| `callback_sequence_passed` | callback sequence smoke fecha sessoes |
| `production_loop_smoke_passed` | SDK-shaped smoke passa sem daemon/import SDK e com `kernel_normalizer_contract_report.status=valid`, `bridge_contract_report.status=valid`, `handler_registry_contract_report.status=valid` e `worker_return_contract.status=valid` |
| `worker_start_still_blocked_until_real_loop` | worker nao inicia antes do loop real |
| `product_loop_wiring_flags_visible` | CLI/Python aceitam flags de wiring, mas preservam `started=false` |
| `product_loop_check_available` | artefato existe, preserva `daemon_started=false`, prova `sdk_probe_import_safe`, `sdk_handler_blueprint_available`, `sdk_kernel_normalizer_required`, `daemon_supervisor_health_snapshot_available`, `daemon_supervisor_preflight_available`, `daemon_supervisor_execution_available`, `daemon_supervisor_process_launch_disabled`, `daemon_process_adapter_blueprint_available`, `supervised_process_adapter_available` e promocao bloqueada |
| `managed_env_contract_available` | adapter supervisionado declara manifesto sanitizado de env sem escrever arquivo e sem vazar segredo |
| `launch_authorization_contract_available` | adapter declara autorizacao de implementacao do launch sem permitir iniciar processo |
| `managed_env_writer_contract_available` | writer governado existe, valida placeholders e continua sem escrever `.env` |
| `managed_env_write_execution_available` | funcao de escrita existe, exige receipt/autorizacao e escreve apenas placeholder `atlas-voice-*.env` com `0600` |
| `supervised_launch_execution_contract_available` | contrato pre-subprocess existe, consome env-write execution futura, exige launch execution authorization, expoe `pre_start_health_checks_execution_available=true`, mas conserva `subprocess_launch_implemented=false`, `process_launch_attempted=false` e `daemon_started=false` |
| `pre_start_health_checks_execution_available` | executor dos checks pre-start existe, exige authorization propria, recusa provider/tool/audio/processo e emite evidence sem iniciar daemon |
| `pre_start_health_checks_smoke_passed` | certification executa o smoke seguro, recebe `passed_no_process_start`, confirma `smoke_only=true`, `production_readiness=not_proven_by_smoke`, `.env` temporario removido, nenhum processo/provider/tool/audio acionado e `subprocess_start_contract.status=ready_for_reviewed_subprocess_start_implementation` |
| `subprocess_start_contract_available` | contrato de start existe, exige checks pre-start antes de ficar ready, mas conserva `subprocess_launch_implemented=false`, `process_launch_attempted=false`, `daemon_started=false`, `subprocess_module_imported=false` e `livekit_sdk_imported=false` |
| `dependency_install_plan_available` | plano operator-managed existe com `requirements-livekit.txt`, comando install/verify e `pip_execution_attempted=false`, sem importar SDK ou iniciar daemon |
| `sdk_probe_import_safe` | SDK readiness usa probe/metadata e nao importa LiveKit runtime |
| `sdk_handler_registry_complete` | todos os event kinds do SDK tem handler governado por `LiveKitSdkHandlerRegistry` |
| `production_review_receipt_valid` | `--production-promotion-approved` nao basta; `--production-promotion-review-file` deve validar `decision_receipt_id`, rollback plan, forbidden-action ack, vinculo ao `production_promotion_review_bundle` (`reviewed_bundle_hash`, status de gate, evidence reviewed), `review_receipt_valid=true` e `boolean_approval_is_sufficient=false` |
| `production_review_bound_to_expected_bundle` | quando `--promotion-review-bundle-file` e fornecido, o receipt humano deve bater exatamente com `bundle_hash`, schema e status do bundle atual; sem isso, review valido por shape nao conta como caminho enterprise final |
| `production_review_expected_bundle_validated` | torna visivel para CLI/API/IA que o bundle real foi validado, nao apenas um hash textual declarado pelo operador |
| `daemon_implementation_review_valid` | daemon real so pode virar implementacao supervisionada com review receipt tecnico separado do review humano de promocao |

`product-loop-check` separa `ready_for_human_review`,
`ready_for_daemon_implementation_review` e
`ready_for_supervised_start_implementation`: machine gates prontos sem receipt
pedem review humano; receipt humano valido pede review tecnico do daemon; apenas
os dois receipts validos permitem implementar start supervisionado, mantendo
`daemon_started=false`.
| `sdk_kernel_normalizer_required` | product loop/wiring exige `KernelRuntimeEventNormalizerGuard` em cada callback SDK antes de router/worker |
| `runtime_event_normalizer_available` | API interna/mobile conseguem validar evento ou sequencia sem execucao |

## Status Semantics

| Status | Significado |
|---|---|
| `blocked` | falta SDK, token issuer, require-sdk ou outro gate de maquina |
| `blocked_pending_human_review` | worker/product loop passou gates de maquina, mas falta review humano, Decision Receipt e rollback plan |
| `blocked_pending_daemon_implementation_review` | review humano passou, mas falta review tecnico da implementacao supervisionada do daemon |
| `blocked_unimplemented_start` | review foi marcado como aprovado, mas daemon real ainda nao foi commitado |
| `review_required` | todos gates de maquina passaram; humano ainda precisa aprovar |

Promocao automatica continua proibida: `auto_promotion_allowed=false`.
Promocao direta tambem continua proibida: `promotion_allowed=false`; mesmo
`review_required` significa apenas abrir review humano com Decision Receipt e
rollback plan obrigatorios.

`review_packet` deve acompanhar certification, Rivals-Voice e review_signal:
ele declara evidencias, rollback e proibicoes para qualquer humano ou IA saber
que promocao real nunca e automatica.
O review humano que fecha esse pacote deve ser um receipt especifico para o
bundle: `reviewed_bundle_schema_version` precisa ser
`atlas.voice_realtime.production_promotion_review_bundle.v1`,
`reviewed_bundle_hash` precisa ser SHA-256 canonico, `reviewed_machine_gate_status`
precisa indicar que a maquina chegou ao estado de review, e
`required_evidence_reviewed` precisa conter `runtime_certification`,
`product_loop_check`, `pre_start_health_checks_smoke` e
`rivals_voice_comparison`. `failed_machine_gates_acknowledged` deve existir como
lista explicita, mesmo vazia, para impedir que uma aprovacao antiga ou booleana
seja reaproveitada contra outro pacote de evidencias.
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
`token-issuer-plan` e o contrato de configuracao segura em CLI, API interna e
mobile gateway: lista env/config keys, indica faltantes, inclui template
redigido e hash, mas `secrets_exposed=false`, `writes_env_file=false`,
`issues_token_during_plan=false` e `starts_daemon=false`.
`token-issuer-smoke` prova a emissao redigida quando existe config real. Com
`--ephemeral-test-config`, ele usa apenas config nao-producao em memoria,
restaura a config antes de sair e marca `production_readiness` como
`not_proven_by_ephemeral_smoke`; portanto nao satisfaz promocao de producao.
O SDK gate tambem valida runtime Python: `livekit-agents>=1.3.12,<2.0.0`
exige Python `>=3.10`; Python 3.9 deve bloquear com
`upgrade_python_runtime_for_livekit_agents_sdk`, mesmo que `find_spec` encontre
o pacote. Isso evita falso positivo onde o import real quebra por
`typing.TypeAlias`.
O runtime Python consome `token-issuer-plan` e `token-issuer-smoke` apenas como
leitura governada do Kernel: `kernel.runtime_token_issuer_plan_url` e
`kernel.runtime_token_issuer_smoke_url` sao obrigatorios no bootstrap,
`AtlasKernelClient.token_issuer_plan()` e `token_issuer_smoke()` validam os
schemas e falham se houver segredo, token cru, template sem redacao, escrita de
`.env`, daemon start, provider/tool direto, audio raw, sala fora de
`atlas-voice-` ou participante fora de `mobile:`. Esse caminho existe para
certificacao/review e nao autoriza configurar LiveKit.
`pre-start-health-checks-smoke` prova a cadeia posterior ao writer sem iniciar
processo: cria apenas `.env` temporario de placeholders `atlas-voice-*.env`,
redige o path, remove o arquivo antes de retornar, executa checks pre-start
sinteticos seguros e deixa `subprocess_start_contract` pronto para review. O
resultado esperado e `passed_no_process_start`, com `smoke_only=true` e
`production_readiness=not_proven_by_smoke`; isso prova contrato, nao LiveKit
real.

## Non Goals

- nao instala LiveKit Agents SDK;
- nao inicia worker daemon;
- nao grava audio cru;
- nao autoriza provider direto;
- nao substitui Rivals-Voice ou review humano.

## Validation

```bash
php artisan test tests/Unit/Ai/Voice/AtlasVoiceRuntimeCertificationServiceTest.php
php artisan test tests/Unit/Ai/Voice/AtlasVoiceRuntimeEventNormalizerTest.php
php artisan test tests/Feature/Ai/AtlasAiVoiceRealtimeCommandTest.php tests/Feature/Ai/AtlasAiVoiceRealtimeApiTest.php
php artisan atlas:ai:voice token-issuer-plan --json
php artisan atlas:ai:voice token-issuer-smoke --json
php artisan atlas:ai:voice token-issuer-smoke --ephemeral-test-config --json
php artisan atlas:ai:voice pre-start-health-checks-smoke --json
curl -H "X-Atlas-Token: $ATLAS_TOKEN" "http://localhost/ai/voice/runtime/token-issuer-plan?runtime=livekit_agents_sdk"
curl -H "X-Atlas-Token: $ATLAS_TOKEN" "http://localhost/ai/voice/runtime/token-issuer-smoke?runtime=livekit_agents_sdk"
curl -H "X-Atlas-Token: $ATLAS_TOKEN" "http://localhost/ai/voice/runtime/pre-start-health-checks-smoke?runtime=livekit_agents_sdk"
php artisan atlas:ai:voice runtime-certify --json
php artisan atlas:ai:voice promotion-review-packet --json
curl -H "X-Atlas-Token: $ATLAS_TOKEN" "http://localhost/ai/voice/runtime/promotion-review-packet?runtime=livekit_agents_sdk&hours=24"
PYTHONPATH=runtimes/python/voice_realtime python3 -m atlas_voice_agent.main --bootstrap /path/to/bootstrap.json --mock-kernel --promotion-review-packet --hours 24
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
- `token-issuer-plan` gera
  `atlas.voice_realtime.livekit_token_issuer_config_plan.v1` com env vars
  necessarias, missing env, template redigido, `secrets_exposed=false`,
  `writes_env_file=false`, `issues_token_during_plan=false` e
  `starts_daemon=false`;
- `token-issuer-smoke` gera
  `atlas.voice_realtime.livekit_token_issuer_smoke.v1`, remove `access_token`,
  publica apenas hash do token quando emitido e nunca trata smoke efemero como
  prontidao de producao;
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
- runtime Python rejeita bootstrap sem URLs do runtime event normalizer, porque
  eventos SDK devem ter caminho canônico de normalizacao governado pelo Kernel;
- `promotion-review-packet` gera, via CLI/API interna/mobile a partir de
  `AtlasVoiceProductionPromotionReviewBundleService`,
  `atlas.voice_realtime.production_promotion_review_bundle.v1`: bundle
  hashavel com certification `--require-sdk`, `product-loop-check`,
  `pre-start-health-checks-smoke`, Rivals-Voice, `review_packet`, failed gates,
  evidence hashes, guardrails e flags de wiring `callback_loop_wired` /
  `production_sdk_loop_wired`.
  Ele prepara review humano, mas preserva `promotion_allowed=false`,
  `auto_promotion_allowed=false`, `daemon_started=false` e
  `start_daemon_allowed=false`.
- runtime Python exige `kernel.runtime_promotion_review_packet_url` no bootstrap
  e consegue buscar o mesmo bundle com `--promotion-review-packet`, apenas como
  leitura governada do Kernel; isso nao autoriza promocao, provider, tool,
  audio raw, daemon ou bypass de Decision Receipt.
- runtime Python exige `kernel.runtime_token_issuer_plan_url` e
  `kernel.runtime_token_issuer_smoke_url` no bootstrap e consegue buscar ambos
  com `--token-issuer-plan` / `--token-issuer-smoke`, sempre como leitura
  governada; validadores Python bloqueiam secret/token leak, `.env` write,
  daemon start, provider/tool direto e namespace de sala/participante invalido.
- runtime Python valida o bundle com `validate_promotion_review_packet`: schema,
  surface, runtime, `kernel_only`, `mobile_first`, review humano, receipt,
  rollback, review packet, evidence summaries hashados, machine gates,
  `bundle_hash` e guardrails devem permanecer fail-closed; qualquer relaxamento
  de promocao, daemon, provider direto, tool direto, memory write, audio raw ou
  boolean approval suficiente deve falhar antes de qualquer output confiavel.
- runtime Python valida o receipt humano com
  `validate_production_promotion_review`: o receipt deve referenciar o bundle
  revisado por `reviewed_bundle_hash`, declarar evidence reviewed, ack explicito
  dos failed gates e continuar com `auto_promotion_allowed=false`; receipt
  generico, sem hash, sem evidence ou com aprovacao booleana deve falhar.
- `product-loop-check`, `daemon-supervisor-check` e `worker-start-check`
  aceitam `--promotion-review-bundle-file` como bundle real do Kernel revisado.
  Quando fornecido, o runtime precisa validar o bundle e exigir match exato de
  `reviewed_bundle_hash`, `reviewed_bundle_schema_version` e
  `reviewed_machine_gate_status`; receipt humano antigo, stale ou apontando para
  outro pacote de evidencias deve manter `production_review_receipt_valid=false`.
- worker start nao permite promocao implicita: o payload expõe
  `worker_start_without_production_promotion_allowed=false`;
- worker start distingue `blocked_pending_human_review` de
  `blocked_pending_daemon_implementation_review` e `blocked_unimplemented_start`,
  para nao confundir maquina pronta, produto aprovado e implementacao real;
- `daemon_implementation_review_valid` precisa estar verdadeiro antes de
  `ready_for_supervised_start_implementation`;
- CLI/API/mobile mostram o status;
- `runtime-certify.next_action` espelha o promotion gate quando producao esta
  bloqueada;
- `product_loop_check_available` exige snapshot de supervisor com
  `schema_version=atlas.voice_realtime.daemon_supervisor_health_snapshot.v1` e
  `daemon_started=false`;
- tambem exige preflight de supervisor
  `atlas.voice_realtime.daemon_supervisor_preflight.v1` sem launch;
- tambem exige execucao de supervisor
  `atlas.voice_realtime.daemon_supervisor_execution.v1` com
  `process_launch_attempted=false`, `daemon_started=false` e `start_allowed=false`;
- tambem exige blueprint de adapter de processo
  `atlas.voice_realtime.daemon_process_adapter_blueprint.v1` com
  `launch_allowed=false`, segredos nao logaveis e rollback documentado;
- tambem exige shell de adapter supervisionado
  `atlas.voice_realtime.supervised_process_adapter.v1`, com health/rollback
  testaveis e `process_launch_attempted=false`;
- tambem exige `managed_environment_contract`
  `atlas.voice_realtime.managed_env_contract.v1`, com `env_file_write_attempted=false`
  e `secret_values_present_in_output=false`;
- tambem exige `launch_authorization_contract`
  `atlas.voice_realtime.launch_authorization_contract.v1`, com `launch_allowed=false`
  e `process_launch_attempted=false`;
- tambem exige `managed_env_writer`
  `atlas.voice_realtime.managed_env_writer.v1`, com
  `write_execution_implemented=false` e `env_file_write_attempted=false`;
- `execute_managed_env_write` ja existe como fronteira de execucao governada,
  mas so escreve arquivo placeholder com autorizacao
  `atlas.voice_realtime.managed_env_write_authorization.v1` e nunca inicia
  daemon;
- tambem exige `subprocess_start_contract`
  `atlas.voice_realtime.subprocess_start_contract.v1`, com
  `process_launch_attempted=false`, `daemon_started=false`,
  `subprocess_module_imported=false` e `livekit_sdk_imported=false`;
- `dependency-install-plan` publica
  `atlas.voice_realtime.dependency_install_plan.v1`, aponta
  `runtimes/python/voice_realtime/requirements-livekit.txt` e conserva
  `pip_execution_attempted=false`, `sdk_imported=false` e
  `daemon_started=false`;
- promotion para produto real exige review humano.
