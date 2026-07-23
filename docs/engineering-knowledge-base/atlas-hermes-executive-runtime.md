---
id: atlas-hermes-executive-runtime
type: engineering_knowledge
title: Atlas Hermes Executive Runtime
status: active
category: architecture
priority: 94
summary: Produto e contrato operacional para o ATLS usar Hermes como runtime executivo plugavel, mantendo Atlas soberano sobre intencao, contexto, memoria, policy, verificacao e verdade.
tags:
  - atlas-ai
  - hermes
  - executive-runtime
  - agent-runtime
  - memory-governance
capabilities:
  - hermes_executive_runtime
  - executive_mission_contract
  - runtime_adapter_governance
  - memory_gate
  - gateway_orchestration
decisions:
  - ATLS e o sistema soberano; Hermes e runtime executivo plugavel.
  - Usuario conversa com ATLS; Hermes executa missoes quando for o melhor executor.
  - Hermes pode oferecer gateway, skills, profiles, cron, webhooks, MCP, tools, sessions e plugins, mas nao governa memoria canonica.
  - Toda execucao Hermes deve nascer de uma Executive Mission com escopo, contexto, policy, criterios de sucesso e validacao.
  - Memoria ATLS e fonte de verdade; memoria Hermes deve iniciar desligada, limitada ou conectada por adapter governado.
  - Pesquisa concorrencial `Atlas_Concorrente_Hermes_Agent.md` e insumo humano, nao autoridade operacional.
maintenance:
  - Atualizar quando ATLS adotar Hermes, outro runtime externo ou memory adapter real.
  - Manter abaixo do limite canonico de 520 linhas; expandir detalhes para docs filhos se virar implementacao.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-canonical-architecture-index.md
  - docs/engineering-knowledge-base/atlas-ai-core-vs-domain.md
  - docs/engineering-knowledge-base/atlas-ai-documentation-operating-system.md
  - docs/engineering-knowledge-base/atlas-ai-knowledge-governance-system.md
  - docs/engineering-knowledge-base/atlas-ai-session-bootstrap.md
  - docs/engineering-knowledge-base/atlas-ai-runtime-language-boundaries.md
  - docs/engineering-knowledge-base/atlas-ai-local-performance-memory-strategy.md
  - docs/engineering-knowledge-base/atlas-ai-conversation-surface-and-atlas-dev-v1.md
  - docs/engineering-knowledge-base/atlas-programming-governance-system.md
  - docs/engineering-knowledge-base/programming-power-tools-catalog.md
  - docs/engineering-knowledge-base/atlas-hermes-executive-runtime-product-spec.md
  - resolver-o-que-vale-a-pena/root-md/Atlas_Concorrente_Hermes_Agent.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-hermes-executive-runtime
graph_title: Atlas Hermes Executive Runtime
graph_world: atlas
graph_layer: module
graph_kind: module
graph_parent: atlas-ai-canonical-architecture-index
graph_status: active
graph_source: repo
macro_layer: false
human_name: Hermes como Runtime Executivo do ATLS
canonical_name: Atlas Hermes Executive Runtime
technical_name: atlas-hermes-executive-runtime
cartography_type: runtime
canonical_source: docs/engineering-knowledge-base/atlas-hermes-executive-runtime.md
owner: architecture
repo_paths:
  - docs/engineering-knowledge-base/atlas-hermes-executive-runtime.md
  - docs/engineering-knowledge-base/atlas-hermes-executive-runtime-product-spec.md
  - app/Services/Ai/HermesCliProvider.php
  - app/Services/Ai/Hermes/HermesExecutiveMissionFactory.php
  - app/Services/Ai/Hermes/HermesMemoryAdapter.php
  - app/Services/Ai/Hermes/HermesResultPacketFactory.php
  - app/Services/Ai/Hermes/HermesScheduleAdapter.php
  - app/Services/Ai/Hermes/HermesAdapterReceipt.php
  - app/Services/Ai/Hermes/HermesProcedureAdapter.php
  - app/Services/Ai/Hermes/HermesScheduleActivationGate.php
  - app/Services/Ai/Hermes/HermesMemoryReviewGate.php
  - app/Services/Ai/Hermes/HermesRuntimeRouter.php
  - app/Models/HermesProcedureCandidate.php
  - app/Services/Ai/Provider/Drivers/HermesCliProviderDriver.php
  - app/Services/Ai/Provider/Drivers/ProviderDriverRegistry.php
  - app/Services/Ai/AiProviderManager.php
  - app/Services/Ai/AtlasDecideService.php
  - app/Services/Ai/Policy/AtlasAiPolicyService.php
  - app/Services/Ai/Policy/AtlasEffectivePolicyComposer.php
  - app/Services/Ai/Cli/AtlasCliModelCatalogService.php
  - app/Services/Ai/Cli/AtlasCliProviderStrategyService.php
  - config/atlas.php
allowed_changes:
  - Refinar contratos de missao, adapters e fases mantendo ATLS como soberano.
  - Evoluir o provider `hermes_cli` por fases, com testes e DecisionReceipt.
  - Criar docs filhos para Gateway, Skills, Cron/Webhooks e Memory Adapter.
forbidden_changes:
  - Tratar Hermes como memoria canonica, identidade do Atlas ou autoridade de decisao.
  - Chamar Hermes diretamente a partir de surface sem ATLS Intent, Policy, Context Pack e Evidence boundary.
  - Misturar memoria Hermes e memoria ATLS sem Memory Gate.
depends_on:
  - atlas-ai-canonical-architecture-index
  - atlas-ai-knowledge-governance-system
  - atlas-ai-core-vs-domain
flows_to:
  - atlas-executive-mesh
  - atlas-runtime-router
unlocks:
  - hermes-execution-transports
  - hermes-skill-adapter
  - hermes-memory-adapter
  - hermes-capability-registry
governs:
  - external-agent-runtime-integration
evidence:
  - docs/engineering-knowledge-base/atlas-hermes-executive-runtime.md
  - docs/engineering-knowledge-base/atlas-hermes-executive-runtime-product-spec.md
  - app/Services/Ai/HermesCliProvider.php
  - app/Services/Ai/Hermes/HermesExecutiveMissionFactory.php
  - app/Services/Ai/Hermes/HermesMemoryAdapter.php
  - app/Services/Ai/Hermes/HermesResultPacketFactory.php
  - app/Services/Ai/Hermes/HermesScheduleAdapter.php
  - app/Services/Ai/Provider/Drivers/HermesCliProviderDriver.php
  - app/Services/Ai/Kernel/Evidence/ProviderUsagePayload.php
  - tests/Unit/AiCliProviderRuntimeArgsTest.php
  - tests/Unit/Ai/HermesExecutiveRuntimePacketEvidenceTest.php
  - tests/Unit/Ai/Skills/Hermes/HermesProcedureAdapterTest.php
  - tests/Feature/Ai/Hermes/HermesScheduleActivationGateTest.php
  - tests/Feature/Ai/Hermes/HermesMemoryReviewGateTest.php
  - tests/Unit/Ai/HermesRuntimeRouterTest.php
  - tests/Unit/Ai/AtlasDecideHermesRuntimeRouterTest.php
  - tests/Unit/Ai/Provider/ProviderDriverWrappersTest.php
  - tests/Unit/Ai/AiProviderManagerTest.php
  - resolver-o-que-vale-a-pena/root-md/Atlas_Concorrente_Hermes_Agent.md
required_tests:
  - php artisan test tests/Unit/Ai/Provider/ProviderDriverWrappersTest.php tests/Unit/Ai/AiProviderManagerTest.php tests/Unit/AiCliProviderRuntimeArgsTest.php tests/Unit/Ai/HermesExecutiveRuntimePacketEvidenceTest.php
  - php artisan atlas:engineering:knowledge docs-health --json
  - php artisan atlas:ai:architecture-validate --json
requires_evidence: true
risk_level: high
visual_tags:
  - runtime
  - sovereignty
  - hermes
ai_entrypoints:
  - Leia Resumo, Decisao Executiva, Contratos e Fases antes de propor implementacao Hermes/ATLS.
ai_usage_notes:
  - Use este doc como contrato de produto e boundary operacional do Hermes no ATLS.
  - Use `atlas-hermes-executive-runtime-product-spec.md` como blueprint de produto final e DoD.
  - `hermes_cli` ja envolve cada chamada em ExecutiveMission, HermesResultPacket, candidate gates, Memory/Schedule adapters, Gateway adapter governado, Procedure adapter revisavel e Runtime Router default-safe; promocao/ativacao/delivery seguem gates ATLS dedicados.
quality_gates:
  - docs-health status ok
  - architecture-validate status ok
failure_modes:
  - Hermes virar cerebro paralelo.
  - Duas memorias competirem e criarem drift.
  - Gateway responder sem ATLS supervisionar contexto e permissao.
  - Skills Hermes virarem prompts soltos sem evaluacao Atlas.
observability_signals:
  - Toda chamada Hermes gera ExecutiveMission, DecisionReceipt, HermesResultPacket, tool summary e candidates Hermes em quarentena.
  - Provider `hermes_cli` registra `cli_invocation` com comando redigido, hash do prompt e politicas de memoria/schedule.
  - Provider usage ledger recebe referencia de mission/result packet e recibos dos adapters quando Hermes retorna.
  - Health check valida `hermes chat --help` sem chamar modelo.
implementation_state: phase_6_refactored_acp_wired_unified_result_packet
next_actions:
  - Decidir quando flipar o default execution_transport de cli para acp (ACP provado live + fallback CLI automatico); ver atlas-hermes-execution-transports.
  - Unificar o result_packet do mesh (HermesMeshProcessHandle) pelo HermesResultPacketFactory canonico (ultima duplicacao residual, caminho nao-vivo).
  - Construir o fluxo de review/promocao de skill candidates (hoje o provisioner+gate ja estao ligados; falta o servico que aprova candidatos installaveis).
  - Pool warm de sessao ACP por worker (hoje e acp-por-chamada: evita checkpoints/parse mas ainda paga o init do processo).
---
# Atlas Hermes Executive Runtime

## Resumo

Hermes deve entrar no ATLS como runtime executivo plugavel. ATLS continua
soberano sobre intencao, contexto, policy, memoria, verificacao e verdade.
Hermes fornece canais, tools, skills, profiles, cron, webhooks, MCP, plugins e
sessoes, mas sempre acionado por contrato.

```text
ATLS transforma intencao em missao.
Hermes transforma missao em acao.
ATLS transforma acao em evidencia, memoria e proximo contexto.
```

## Decisao Executiva

O uso comum e `Humano -> Hermes`. O desenho superior e `Humano -> ATLS ->
Hermes`. O salto de qualidade nao vem de misturar duas memorias; vem de ATLS
compilar contexto canonico, escolher o executor certo, usar Hermes para agir e
filtrar o retorno para Evidence Ledger, Memory Gate e proximas missoes.

```text
Hermes pode fazer quase tudo.
Somente ATLS decide o que aquilo significa.
```

## Papel no Atlas

Hermes vive na camada `Atlas.Executor`/Executive Runtime Layer. Ele e motor
substituivel, como Codex, Claude Code, local agents e futuros executores.

```text
ATLS Sovereign Core
├── Memory canonica
├── Knowledge governance
├── Intent Kernel
├── Context Compiler
├── Policy Engine
├── Runtime Router
├── Evidence Ledger
├── Memory Gate
└── Executive Runtime Layer
    ├── Hermes Runtime
    ├── Codex Runtime
    ├── Claude Code Runtime
    ├── Local Model Runtime
    └── Human Approval Runtime
```

## Onde Se Encaixa

ATLS deve usufruir de tudo do Hermes por adapters, nao por acoplamento cru.

| Capacidade Hermes | Uso pelo ATLS | Soberania Atlas |
|---|---|---|
| Gateway | Telegram, Discord, WhatsApp, email, API server | ATLS decide resposta e permissao |
| Skills | Procedimentos executaveis | ATLS indexa, avalia e escolhe |
| Profiles | Workers especializados | ATLS cria e roteia por missao |
| Cron | Rotinas recorrentes | ATLS cria contrato e revisa output |
| Webhooks | Eventos externos | ATLS normaliza e autoriza |
| MCP | Ferramentas externas e interop | ATLS mantem capability registry |
| Plugins | Extensao operacional | ATLS exige trust gate |
| Sessions | Transcritos de execucao | ATLS importa evidencias, nao verdade crua |
| Tools | Terminal, arquivos, browser, web, automacao | ATLS limita escopo e valida resultado |
| Dashboard/TUI | Diagnostico e fallback humano | ATLS UI continua a superficie principal |

## Contratos

ATLS nunca envia prompt solto ao Hermes. Toda chamada vira `ExecutiveMission`.

```yaml
ExecutiveMission:
  objective: objetivo humano normalizado
  operator_intent: intencao classificada pelo ATLS
  scope:
    allowed_paths: []
    forbidden_paths: []
    allowed_tools: []
    network_policy: none|limited|open
  context_pack:
    canonical_docs: []
    memory_refs: []
    project_state: []
    recent_decisions: []
  runtime:
    adapter: hermes
    profile: atlas-hermes-coder|atlas-hermes-researcher|atlas-hermes-gateway
    model_policy: decided_by_atls
  success_criteria: []
  validation:
    required_commands: []
    evidence_required: true
  memory_policy:
    hermes_memory: off|operational_only|atlas_adapter
    memory_delta_must_return: true
  approval_policy:
    human_required_for: []
  response_contract:
    format: final_report|patch_packet|research_brief|gateway_reply
```

## Fluxo

```text
Mensagem humana
-> ATLS Intent Kernel
-> Context Compiler
-> Policy Engine
-> Runtime Router
-> Executive Mission
-> Hermes Capsule
-> Tool execution / Gateway / Skills / Cron / MCP
-> Hermes Result Packet
-> ATLS Verifier
-> Evidence Ledger
-> Memory Gate
-> Resposta final do ATLS
```

Mesmo quando a entrada vem pelo Telegram:

```text
Telegram -> Hermes Gateway -> ATLS -> Hermes Worker -> ATLS -> Hermes Gateway
```

Hermes recebe e entrega mensagens; ATLS governa significado, contexto e acao.

## Regras para IA

- Nao propor Hermes como cerebro, memoria canonica ou surface soberana.
- Nao bypassar ATLS Intent Kernel, Policy Engine, Context Pack ou Evidence.
- Nao habilitar memoria Hermes sem Memory Gate.
- Nao transformar skills Hermes em prompts soltos sem avaliacao Atlas.
- Antes de codigo real, criar AP de discovery e boundary contract.

## Memoria

Memoria e o ponto de maior risco. ATLS Memory e canonica; Hermes Memory e cache
operacional ou adapter governado.

| Modelo | Quando usar | Regra |
|---|---|---|
| Hermes memory off | Fase 1 | Mais seguro; ATLS injeta todo contexto |
| Hermes operational only | Fase 2 | Hermes lembra workarounds de si mesmo |
| Hermes memory adapter -> ATLS | Fase 3 | Melhor modelo maduro |

Hermes nao deve salvar sozinho preferencias do operador, decisoes de arquitetura,
segredos, contexto sensivel ou fatos do projeto. Ele deve retornar candidatos:

```yaml
MemoryDeltaCandidate:
  claim:
  evidence:
  confidence:
  class: fact|preference|procedure|workaround|noise
  source: hermes_session
  suggested_action: promote|quarantine|discard
```

ATLS aprova, deduplica, versiona, rejeita ou transforma isso em skill.

## Flywheel De Melhoria

O sistema melhora sem treinar modelo porque ATLS aprende a operar Hermes:

```text
Uso do ATLS
-> contexto Atlas melhora
-> Executive Missions melhoram
-> Hermes recebe profiles/skills/contexto mais precisos
-> execucao melhora
-> ATLS captura evidencias e aprendizados
-> memoria, skills e rotas melhoram
-> proxima execucao melhora
```

## Profiles Hermes Recomendados

| Profile | Missao |
|---|---|
| `atlas-hermes-gateway` | Mensageria, Telegram, Discord, webhooks |
| `atlas-hermes-coder` | Edicao, testes, refactor curto |
| `atlas-hermes-reviewer` | Review, riscos, regressao, QA |
| `atlas-hermes-researcher` | Pesquisa web, coleta, briefing |
| `atlas-hermes-ops` | Sistema, logs, instalacao, diagnostico |
| `atlas-hermes-cron` | Rotinas recorrentes e automacoes |
| `atlas-hermes-skill-curator` | Descobrir skills/procedimentos candidatos |

## Produto Final

O produto final e especificado em
`docs/engineering-knowledge-base/atlas-hermes-executive-runtime-product-spec.md`.
Resumo: o operador conversa com ATLS por Desktop, Mobile, Voice ou Gateway; ATLS
decide se Hermes e o melhor executor; Hermes executa somente missoes; ATLS
verifica resultado, registra evidencia, governa memoria e responde ao operador.

Resultado esperado: o operador nao escolhe runtime, profile, canal, toolset,
skill, cron ou memoria manualmente. Ele usa uma superficie soberana unica, e
ATLS transforma cada execucao util em evidencia, aprendizado revisavel, skill
candidata, regra ou proxima missao.

## Implementacao Atual

Fase 2d esta implementada como provider governado `hermes_cli` com missao,
pacote de resultado, candidate gates reais, Memory Adapter persistente e
Schedule Adapter revisavel persistente.

O que existe no codigo:

| Peca | Caminho | Estado |
|---|---|---|
| Runtime provider | `app/Services/Ai/HermesCliProvider.php` | Executa `hermes chat --quiet --query` |
| Executive Mission | `app/Services/Ai/Hermes/HermesExecutiveMissionFactory.php` | Separa objetivo, scope, contexto, runtime, memoria e aprovacao |
| Result Packet | `app/Services/Ai/Hermes/HermesResultPacketFactory.php` | Resume output, evidence, memory, procedure e schedule gates (transport-agnostico: CLI e ACP alimentam o mesmo builder) |
| OpenAI response adapter | `app/Services/Ai/Hermes/HermesOpenAiResponseAdapter.php` | Traduz chamadas de unidades Rivals preregistradas para o mesmo `HermesCliProvider`; nunca executa provider por conta propria |
| Memory Adapter | `app/Services/Ai/Hermes/HermesMemoryAdapter.php` | Persiste MemoryDeltaCandidate como AiMemoryDelta pending quando policy permite |
| Schedule Adapter | `app/Services/Ai/Hermes/HermesScheduleAdapter.php` | Persiste ScheduleCandidate como AiScheduledTask candidate desligado quando policy permite |
| Procedure Adapter | `app/Services/Ai/Hermes/HermesProcedureAdapter.php` | Persiste ProcedureCandidate como skill candidato revisavel; promocao so via SkillPackPromotionGate |
| Schedule Activation Gate | `app/Services/Ai/Hermes/HermesScheduleActivationGate.php` | Converte candidate em schedule ativo so com aprovacao, stop conditions e cadence valida |
| Memory Review Gate | `app/Services/Ai/Hermes/HermesMemoryReviewGate.php` | Revisa/promove/rejeita/deduplica AiMemoryDelta via AtlasMemoryDeltaPromotionService |
| Runtime Router | `app/Services/Ai/Hermes/HermesRuntimeRouter.php` | Auto-routing governado default-safe com DecisionReceipt executive_runtime |
| Receipt trait | `app/Services/Ai/Hermes/HermesAdapterReceipt.php` | Sela todo recibo `atlas.hermes.*_receipt.v1` com receipt_hash |
| Driver auditavel | `app/Services/Ai/Provider/Drivers/HermesCliProviderDriver.php` | Injeta identidade e contrato Hermes |
| Registry | `app/Services/Ai/Provider/Drivers/ProviderDriverRegistry.php` | Expoe `hermes_cli` no manifest |
| Provider manager | `app/Services/Ai/AiProviderManager.php` | Resolve `hermes_cli` como `AiProvider` |
| Policy/Decide | `AtlasAiPolicyService`, `AtlasDecideService`, `AtlasEffectivePolicyComposer` | Permite Hermes como executor manual |
| Gateway/API/CLI | requests, controllers e comandos de chat/dev/decide | Aceitam `hermes_cli` como provider |
| Evidence | `ProviderUsagePayload` | Registra hashes de mission/result packet no ProviderReturned |
| Config | `config/atlas.php` | `allow_manual=true`, `allow_auto=false` por padrao |
| Testes | provider wrappers, manager e runtime args | Cobrem contrato, redacao e flags |

Configuracao padrao:

```yaml
providers.hermes_cli:
  binary: hermes
  model: hermes_cli_default
  allow_auto: false
  allow_manual: true
  source: tool
  max_turns: 90
  memory_policy: off
  schedule_policy: off
```

Contrato operacional implementado:

- ATLS passa por `AtlasDecide` e `AiGatewayService`.
- Hermes e provider executor; nao vira identidade nem memoria canonica.
- `HermesCliProvider` envolve o prompt em `atlas.hermes.executive_mission.v1`.
- Toda resposta gera `atlas.hermes.result_packet.v1` em metadata e ledger.
- `ProcedureCandidate` e `ScheduleCandidate` sao parseados, capados e
  quarentenados; nao instalam skill nem ativam schedule.
- `MemoryDeltaCandidate` e persistido em `ai_memory_deltas` apenas com
  `memory_policy=atlas_adapter`; status nasce `pending`, sem promocao.
- `ScheduleCandidate` e persistido em `ai_scheduled_tasks` apenas com
  `schedule_policy=atlas_adapter`; nasce `kind=candidate`, `enabled=false`,
  `next_run_at=null` e `schedule=candidate:*` para bloquear resume direto.
- Prompt em linha de comando e redigido em traces/metadata.
- `--yolo` so aparece quando a permissao Atlas do job e `danger`.
- `--accept-hooks` e `--checkpoints` so entram em `write` ou `danger`.
- `--provider`, `--toolsets`, `--skills`, `--source`, `--max-turns`,
  `--resume`, `--continue` e `--image` sao governados pelo payload/config ATLS.
- Args inseguros ou stale vindos de config sao removidos antes da chamada.
- Adapters response-only podem pedir `hermes.safe_mode=true`: o provider
  canonico adiciona `--safe-mode`, desliga regras/plugins/MCP/config e fallback
  do usuario, mantendo provider/model fixos no contrato da missao. E default-off.
- Memory policy default e `off`; candidatos retornados ficam em quarentena ate
  Atlas Memory Gate aprovar.
- Schedule policy default e `off`; candidatos retornados ficam revisaveis ate
  Atlas Schedule Gate aprovar e converter para schedule ativo.

## Escopo de Implementacao

| Fase | Entrega | Regra de seguranca |
|---|---|---|
| 0. Discovery | Mapear comandos Hermes, perfis, config e riscos | Concluido para rota `hermes chat` |
| 1. Subprocess | ATLS chama `hermes chat --quiet --query` com Context Pack | Implementado; Hermes memory off |
| 2a. Mission/Result | ExecutiveMission + HermesResultPacket + ledger refs | Implementado; sem promocao automatica |
| 2b. Candidate gates | MemoryDelta + Procedure + Schedule candidates | Implementado; quarentena |
| 2c. Memory Adapter | MemoryDeltaCandidate -> AiMemoryDelta pending | Implementado; promocao exige review |
| 2d. Schedule Adapter | ScheduleCandidate -> AiScheduledTask candidate | Implementado; ativacao exige review |
| 2. Gateway | Telegram/Discord entram por Hermes e roteiam ao ATLS | Gateway adapter governado implementado; transporte de canal pendente |
| 3. Profiles | Workers Hermes especializados | Mission contract obrigatorio |
| 4. Skills | Indexar skills Hermes como procedimentos Atlas | Procedure adapter + promocao via SkillPackPromotionGate implementados |
| 5. Cron/Webhooks | Rotinas Hermes gerenciadas pelo ATLS | Persistencia + Schedule activation gate implementados |
| 6. Memory Adapter | Hermes grava via ATLS Memory Gate | Persistencia + Memory review/promotion gate implementados |
| 7. Executive Mesh | Hermes ao lado de Codex, Claude Code e local agents | Runtime Router auto-routing governado implementado |

Permitido agora: usar `hermes_cli` manual ou auto-roteado quando a policy habilita
allow_auto, com DecisionReceipt, ExecutiveMission, ResultPacket e adapters/gates de
gateway, memoria, schedule e procedure. Proibido: promover memoria, ativar schedule,
promover skill ou entregar gateway sem o gate ATLS dedicado; auto-routing fora da policy.

## Dependencias

- `atlas-ai-canonical-architecture-index`
- `atlas-ai-knowledge-governance-system`
- `atlas-ai-core-vs-domain`
- `atlas-ai-runtime-language-boundaries`
- `atlas-ai-local-performance-memory-strategy`
- `programming-power-tools-catalog`

## Evidencias

- Este documento canonico.
- `atlas-hermes-executive-runtime-product-spec.md` blueprint de produto final.
- `app/Services/Ai/HermesCliProvider.php`.
- `app/Services/Ai/Hermes/HermesExecutiveMissionFactory.php`.
- `app/Services/Ai/Hermes/HermesMemoryAdapter.php`.
- `app/Services/Ai/Hermes/HermesResultPacketFactory.php`.
- `app/Services/Ai/Hermes/HermesOpenAiResponseAdapter.php`.
- `app/Services/Ai/Hermes/HermesScheduleAdapter.php`.
- `app/Services/Ai/Provider/Drivers/HermesCliProviderDriver.php`.
- `app/Services/Ai/Kernel/Evidence/ProviderUsagePayload.php`.
- `config/atlas.php` provider `hermes_cli`.
- `tests/Unit/AiCliProviderRuntimeArgsTest.php` cobre comando, redacao,
  permissao, model/provider/toolsets/skills, mission, result packet, candidate gates, Memory Adapter e Schedule Adapter.
- `tests/Unit/Ai/HermesExecutiveRuntimePacketEvidenceTest.php` cobre referencia
  mission/result packet e adapter receipts em `ProviderUsagePayload`.
- `tests/Unit/Ai/Hermes/HermesOpenAiResponseAdapterTest.php` cobre binding exato
  de unidade, uso real, streams persistidos, falha nomeada e prova agregada.
- `tests/Unit/Ai/Provider/ProviderDriverWrappersTest.php` cobre manifest e
  identity fragment.
- `tests/Unit/Ai/AiProviderManagerTest.php` cobre resolucao do provider.
- Pesquisa humana preservada em
  `resolver-o-que-vale-a-pena/root-md/Atlas_Concorrente_Hermes_Agent.md`.
- Gates exigidos em `required_tests` e `quality_gates`.

## Riscos

- Hermes responder direto pelo gateway e bypassar ATLS.
- Memoria Hermes criar drift contra memoria canonica.
- Skills Hermes entrarem como prompts soltos sem contrato de qualidade.
- Plugins/MCP ampliarem autoridade sem capability registry.
- Sessions Hermes virarem "verdade" sem verificacao.
- ATLS ficar dependente de detalhes internos instaveis do Hermes.

Mitigacao: adapters estreitos, Executive Mission, DecisionReceipt, Evidence
Ledger, Memory Gate, profiles isolados e reversibilidade por fase.

## Exemplos

- Pedido humano: "configure meu Telegram".
- ATLS monta `ExecutiveMission` com policy, contexto, paths e criterio de teste.
- Hermes executa gateway/setup dentro do contrato.
- ATLS valida, registra evidencia e promove apenas MemoryDelta aprovado.

## Proximas Acoes

1. Expor surfaces de operador (comando/controller) sobre os gates ja implementados.
2. Conectar transporte real de canal ao Gateway adapter (`Hermes Gateway -> ATLS
   -> Hermes Worker opcional -> ATLS -> Hermes Gateway`).
3. Promover `ProcedureCandidate` para skill via fluxo guiado sobre o
   `SkillPackPromotionGate` (owner doc + baseline/test + semver).
4. Habilitar auto-routing Hermes por policy explicita por dominio, mantendo o
   default-safe atual.
