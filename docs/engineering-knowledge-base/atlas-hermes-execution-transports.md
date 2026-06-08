---
id: atlas-hermes-execution-transports
type: engineering_knowledge
title: Atlas Hermes Execution Transports (ACP + CLI)
status: active
category: architecture
priority: 93
summary: Camada de transporte de execucao do Hermes runtime — uma so entrada governada no HermesCliProvider seleciona o transporte (acp persistente via JSON-RPC, ou cli por chamada como fallback), monta a ExecutiveMission uma vez e mapeia o result_packet uma vez pelo HermesResultPacketFactory canonico, de modo que os gates de memory/schedule/procedure rodam identicamente em qualquer transporte; ACP e o caminho robusto (quente, estruturado, sem parse de stdout), com fallback automatico para CLI.
tags:
  - atlas-ai
  - hermes
  - execution-transport
  - acp
  - executive-runtime
  - antifragile
capabilities:
  - hermes_execution_transport_selection
  - hermes_acp_persistent_runtime
  - hermes_cli_fallback_transport
  - hermes_transport_agnostic_result_packet
  - hermes_acp_permission_governance
decisions:
  - O transporte de execucao e selecionavel por config (execution_transport=acp|cli), default cli/opt-in; ACP e o caminho robusto preferido quando ligado.
  - Os dois transportes produzem um AiProviderResult e passam pelo MESMO HermesResultPacketFactory + gates — governanca identica independente do transporte (sem builder divergente).
  - ACP roda o agente Hermes como processo persistente via JSON-RPC (initialize -> session/new -> session/prompt); o texto vem por notificacoes session/update; nada de parse de stdout humano.
  - Qualquer falha do ACP devolve fallback_required e o provider cai para CLI automaticamente — nada trava nem se perde.
  - Pedidos de permissao do agente durante a run sao governados pelo HermesAcpPermissionGate (default-deny fora de escopo) — o human-in-the-loop que o CLI nao tinha.
maintenance:
  - Manter abaixo de 520 linhas; detalhes por componente vivem no codigo e nos testes.
  - Atualizar quando um novo transporte (ex.: batch nativo) ou uma nova etapa do protocolo ACP for adicionada.
related_paths:
  - docs/engineering-knowledge-base/atlas-hermes-executive-runtime.md
  - docs/engineering-knowledge-base/atlas-hermes-capability-registry.md
  - docs/engineering-knowledge-base/atlas-hermes-executive-mesh.md
  - app/Services/Ai/HermesCliProvider.php
  - app/Services/Ai/Hermes/Acp/AtlasHermesAcpRuntime.php
  - app/Services/Ai/Hermes/Acp/HermesAcpProtocol.php
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-hermes-execution-transports
graph_title: Atlas Hermes Execution Transports
graph_world: atlas
graph_layer: module
graph_kind: module
graph_parent: atlas-hermes-executive-runtime
graph_status: active
graph_source: repo
macro_layer: false
human_name: Transportes de Execucao do Hermes (ACP + CLI)
canonical_name: Atlas Hermes Execution Transports
technical_name: atlas-hermes-execution-transports
cartography_type: runtime
canonical_source: docs/engineering-knowledge-base/atlas-hermes-execution-transports.md
owner: architecture
repo_paths:
  - docs/engineering-knowledge-base/atlas-hermes-execution-transports.md
  - app/Services/Ai/HermesCliProvider.php
  - app/Services/Ai/Hermes/Acp/AtlasHermesAcpRuntime.php
  - app/Services/Ai/Hermes/Acp/HermesAcpProtocol.php
  - app/Services/Ai/Hermes/Acp/HermesAcpTransport.php
  - app/Services/Ai/Hermes/Acp/HermesAcpChannel.php
  - app/Services/Ai/Hermes/Acp/HermesAcpPermissionGate.php
  - app/Services/Ai/Hermes/Acp/HermesAcpResultMapper.php
  - app/Services/Ai/Hermes/ManagedHermesHome.php
  - config/atlas.php
allowed_changes:
  - Adicionar novos transportes ou etapas de protocolo ACP mantendo o AiProviderResult + result_packet unico e o fallback default-safe.
  - Evoluir a governanca de permissao ACP e os timeouts sob o mesmo contrato selado.
forbidden_changes:
  - Criar um segundo builder de result_packet por transporte (a divergencia de governanca e proibida).
  - Deixar um transporte pular os gates de memory/schedule/procedure.
  - Permitir que o ACP selecione/aprove uma permissao fora do escopo da missao sem o gate.
  - Tornar o ACP autoridade de decisao (hermes_acp_can_decide e sempre false).
depends_on:
  - atlas-hermes-executive-runtime
  - atlas-hermes-capability-registry
flows_to:
  - atlas-runtime-router
unlocks:
  - hermes-robust-persistent-execution
governs:
  - hermes-execution-transport-selection-and-fallback
evidence:
  - docs/engineering-knowledge-base/atlas-hermes-execution-transports.md
  - app/Services/Ai/HermesCliProvider.php
  - app/Services/Ai/Hermes/Acp/AtlasHermesAcpRuntime.php
  - app/Services/Ai/Hermes/Acp/HermesAcpProtocol.php
  - app/Services/Ai/Hermes/Acp/HermesAcpTransport.php
  - app/Services/Ai/Hermes/Acp/HermesAcpPermissionGate.php
  - app/Services/Ai/Hermes/Acp/HermesAcpResultMapper.php
  - app/Services/Ai/Hermes/ManagedHermesHome.php
required_tests:
  - php artisan test tests/Unit/Ai/Hermes/Acp/
  - php artisan test --filter=AtlasHermesAcpRuntimeTest
  - php artisan atlas:engineering:knowledge docs-health --json
  - php artisan atlas:ai:architecture-validate --json
requires_evidence: true
risk_level: high
visual_tags:
  - runtime
  - sovereignty
  - transport
  - antifragile
ai_entrypoints:
  - Leia Resumo, Fluxo e Contratos antes de mexer em qualquer caminho de execucao do Hermes.
ai_usage_notes:
  - Os dois transportes DEVEM produzir AiProviderResult e passar pelo HermesResultPacketFactory unico; nunca crie um builder de packet paralelo.
  - Para usar o ACP, ligue execution_transport=acp; ele cai para CLI sozinho em qualquer falha. Default cli ate provar em producao.
quality_gates:
  - docs-health status ok
  - architecture-validate status ok
failure_modes:
  - Um transporte que pula os gates (governanca perdida).
  - ACP que trava sem devolver fallback_required (deveria sempre cair para CLI).
  - Permissao ACP aprovada fora de escopo (deve ser default-deny).
observability_signals:
  - O AiProviderResult carrega metadata.hermes_transport=acp|cli e, no ACP, acp_usage + acp_permission_decisions.
  - Todo run sela atlas.hermes.result_packet.v1 via HermesResultPacketFactory, identico por transporte.
  - Falha de ACP sela atlas.hermes.acp_runtime_fallback.v1 e o provider usa CLI.
implementation_state: phase_6_acp_transport_wired_proven_live
next_actions:
  - Decidir quando flipar o default execution_transport de cli para acp (ACP ja provado live + fallback automatico).
  - Unificar o result_packet do mesh (HermesMeshProcessHandle) pelo mesmo factory canonico.
  - Manter um unico HERMES_HOME gerenciado por run (MCP + delegation ja convergem; ACP reusa o mesmo builder).
---
# Atlas Hermes Execution Transports (ACP + CLI)

## Resumo

O Hermes runtime tem UMA entrada de execucao governada: `HermesCliProvider`.
Ela monta a `ExecutiveMission` selada uma vez, escolhe o **transporte** e, no
fim, mapeia o resultado pelo `HermesResultPacketFactory` canonico uma vez — de
modo que os gates de memory/schedule/procedure rodam identicamente nao importa
o transporte. Dois transportes existem:

- **ACP** (`hermes acp`): processo PERSISTENTE dirigido por JSON-RPC 2.0
  (newline-delimited) sobre stdio. Quente, estruturado, sem parse de stdout
  humano, sem o hang de `--checkpoints` em workdir grande. E o caminho robusto.
- **CLI** (`hermes chat`): subprocesso por chamada. Simples, e o **fallback**.

`execution_transport` (config) seleciona; default `cli` (opt-in `acp`). Qualquer
falha do ACP devolve `fallback_required` e o provider usa CLI automaticamente.

## Decisao Executiva

O elo de execucao era o ponto mais fragil do runtime (cold-boot 5-9s por
chamada, parse de stdout, hang de checkpoints). ACP resolve isso sem tocar a
governanca: o ATLS continua soberano (monta missao, decide policy, roda os
gates, sela evidencia); o Hermes so executa, agora por um protocolo estruturado
e quente. A unificacao do result_packet fecha o buraco em que transportes
nao-CLI pulavam os gates.

## Papel no Atlas

Modulo consumidor do Executive Runtime. Acionado por Atlas Decide / Forge quando
uma missao Hermes precisa rodar. A selecao de transporte e governada por config
e nunca muda quem decide (ATLS) nem o contrato (ExecutiveMission/ResultPacket).

## Onde Se Encaixa

- **Acima:** Atlas Decide escolhe o provider hermes_cli; o provider escolhe o transporte.
- **Dentro:** `maybeRunViaAcp()` roda o `AtlasHermesAcpRuntime` quando acp; senao `runProcessStreaming` (CLI). Ambos devolvem `AiProviderResult`.
- **Depois:** `HermesResultPacketFactory` + adapters (memory/schedule/procedure) rodam IGUAL para os dois.
- **Lateral:** `ManagedHermesHome` provê o HERMES_HOME (MCP servers + delegation caps + learning + memory) que o ACP e o CLI reusam.

## Fluxo

1. `HermesCliProvider::runStreaming` monta a `ExecutiveMission`, resolve
   capability/mcp/delegation, registra o hook bridge.
2. `executionTransport()` le `execution_transport` (payload > provider > config,
   default `cli`).
3. **ACP:** `maybeRunViaAcp()` cria um `HermesAcpTransport` (cwd=escopo,
   HERMES_HOME gerenciado) e roda o `AtlasHermesAcpRuntime`:
   `initialize` -> `session/new` -> `session/prompt`, coletando o texto via
   notificacoes `session/update` (agent_message_chunk) e governando
   `session/request_permission` pelo `HermesAcpPermissionGate`. Devolve um
   `AiProviderResult` (ou null em `fallback_required`).
4. **CLI / fallback:** se ACP devolveu null, roda `hermes chat` via
   `runProcessStreaming`.
5. O `AiProviderResult` (de qualquer transporte) alimenta o
   `HermesResultPacketFactory` + os gates. Um result_packet selado, identico.

## Contratos

- `HermesCliProvider::maybeRunViaAcp(...): ?AiProviderResult` — null quando
  transport != acp OU em fallback_required (→ CLI).
- `AtlasHermesAcpRuntime::run(mission, prompt, invocation, HermesAcpChannel, options): array`
  — result_packet.v1 com `fallback_required`, `permission_decisions`, ou um
  `atlas.hermes.acp_runtime_fallback.v1` selado.
- `HermesAcpProtocol` — codec puro JSON-RPC/ACP (request builders, classify,
  agent_message_chunk, permissionResponse). Sem I/O.
- `HermesAcpTransport implements HermesAcpChannel` — I/O proc_open (start /
  writeLine / readLine / stop). O codec e testavel com um canal fake.
- `HermesAcpPermissionGate::decide(req, scope, mode): array` — allow|deny|escalate;
  default-deny em read, escalate fora de escopo; `hermes_acp_can_decide`=false.
- `HermesAcpResultMapper` — fallback/legado para mapear um run cru; o caminho
  vivo passa o texto pelo `HermesResultPacketFactory` canonico.

## Regras para IA

- Nunca crie um segundo builder de result_packet; alimente o canonico.
- Todo transporte passa pelos mesmos gates — nao bypasse.
- ACP sempre cai para CLI em falha; nunca deixe travar silencioso.
- Permissao ACP e default-deny fora de escopo; nunca auto-aprove.

## Escopo de Implementacao

Feito + provado live (phase 6): `execution_transport` no config; `maybeRunViaAcp`
no provider; runtime ACP completo (protocol/transport/gate/mapper); result_packet
unificado (ACP roda os gates — provado: mem/sched/proc adapters RAN num run ACP);
`ManagedHermesHome` unico (MCP + delegation + ACP reusam). Default cli/opt-in.

## Dependencias

- `atlas-hermes-executive-runtime` (missao/result/provider).
- `atlas-hermes-capability-registry` (manifest de capacidades).
- `ManagedHermesHome` (HERMES_HOME gerenciado).
- Binario `hermes` v0.15.1+ com modo `acp`.

## Riscos

- **Processo persistente** = mais superficie de ops (lifecycle/restart);
  mitigado por start/stop por run + fallback CLI.
- **Latencia warm** ainda inclui inferencia; o ganho e nao pagar cold-boot.
- **Permissao mid-run** mal-governada = acao fora de escopo; mitigado pelo
  default-deny do gate.

## Exemplos

- Chat/missao com `execution_transport=acp` → run ACP quente → result_packet
  selado com gates rodando; em falha, CLI transparente.
- `ATLAS_AI_HERMES_EXECUTION_TRANSPORT=acp` liga o ACP globalmente.

## Evidencias

- `app/Services/Ai/Hermes/Acp/` (6 arquivos) + `HermesCliProvider::maybeRunViaAcp`.
- Testes em `tests/Unit/Ai/Hermes/Acp/` (protocol/gate/mapper/runtime).
- Prova live: run ACP pelo provider real devolveu output estruturado + gates rodaram.

## Proximas Acoes

- Decidir quando flipar o default para `acp`.
- Unificar o result_packet do mesh pelo mesmo factory.
- Manter um unico HERMES_HOME gerenciado por run.
