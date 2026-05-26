---
id: atlas-multi-agent-unified-architecture
type: engineering_knowledge
title: Atlas Multi-Agent Unified Architecture
status: active
category: atlas-ai
priority: 102
summary: Doc canonico que consolida em uma unica autoridade as quatro camadas multi-agente do Atlas (Agentic Workcell Runtime para topologia organizacional, Multi-Provider Agent Orchestration para selecao de profile, Agent Control Plane para lifecycle e Forge Multi-Agent Scheduler para paralelismo), com boundary explicita entre cada camada e antipadrao contra criacao de quinta camada.
tags:
  - atlas-ai
  - multi-agent
  - unified-architecture
  - layered-runtime
  - aawr
  - agent-control-plane
  - forge-scheduler
  - provider-orchestration
capabilities:
  - multi_agent_unified_runtime
  - layered_agent_orchestration
  - agent_lifecycle_governance
  - parallel_agent_execution
  - cross_layer_decision_boundary
decisions:
  - O Atlas tem QUATRO camadas multi-agente declaradas; criar quinta camada exige decision receipt assinado e revisao de Architect.
  - Cada camada decide UMA coisa: AAWR decide topologia, Profiles decidem provider mix, ACP decide lifecycle execucao, Forge Scheduler decide paralelismo durable.
  - Sprawl no Self-Construction OS Agent Control Plane veio de ausencia desta doc; refatoracao em T3.4 e consequencia, nao fix isolado.
  - Nenhuma camada pode bypassar outra; ordem canonica AAWR -> Profiles -> ACP -> Scheduler.
maintenance:
  - Atualize este doc antes de criar nova camada multi-agente, alterar boundary entre camadas ou introduzir novo profile/topology.
  - Validar consistencia com T3.4 Self-Construction Compaction Plan apos qualquer mudanca em ACP.
related_paths:
  - docs/engineering-knowledge-base/atlas-agentic-workcell-runtime.md
  - docs/engineering-knowledge-base/self-construction/multi-provider-agent-orchestration-contract.md
  - docs/engineering-knowledge-base/self-construction/agent-control-plane-contract.md
  - docs/engineering-knowledge-base/atlas-forge-continuum-os.md
  - docs/engineering-knowledge-base/atlas-forge-operating-system.md
  - docs/engineering-knowledge-base/atlas-agentic-engineering-os-runbook.md
  - docs/engineering-knowledge-base/atlas-parallel-multi-agent-execution-spec.md
  - app/Services/Ai/AtlasAgenticWorkcell/
  - app/Services/Ai/AtlasDecide/
  - app/Services/Ai/AtlasForge/
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-multi-agent-unified-architecture
graph_title: Atlas Multi-Agent Unified Architecture
graph_world: atlas
graph_layer: system
graph_kind: system
graph_parent: atlas-agentic-engineering-os
graph_status: active
graph_source: repo
human_name: Atlas Multi-Agent Unified Architecture
canonical_name: Atlas Multi-Agent Unified Architecture
technical_name: atlas-multi-agent-unified-architecture
cartography_type: architecture
canonical_source: docs/engineering-knowledge-base/atlas-multi-agent-unified-architecture.md
owner: atlas-ai
product_name: Atlas Multi-Agent Unified Architecture
internal_product_name: AMUA
runtime_acronym: AMUA
technical_runtime: atlas.multi_agent.unified
repo_paths:
  - docs/engineering-knowledge-base/atlas-multi-agent-unified-architecture.md
allowed_changes:
  - Refinar boundary, adicionar topologia/profile/role, ajustar matriz de decisao.
forbidden_changes:
  - Criar quinta camada multi-agente sem decision receipt e Architect review.
  - Permitir bypass de camada (ex.: Scheduler sem AAWR).
depends_on:
  - atlas-agentic-engineering-os
  - atlas-agentic-workcell-runtime
  - atlas-forge-continuum-os
flows_to:
  - atlas-parallel-multi-agent-execution-spec
  - atlas-aaeos-cross-department-choreography
  - atlas-ai-self-construction-os-compaction-plan
unlocks:
  - multi-agent-canonical-boundary
  - sprawl-prevention-floor
governs:
  - atlas_ai.multi_agent.unified
evidence:
  - docs/engineering-knowledge-base/atlas-multi-agent-unified-architecture.md
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
requires_evidence: true
risk_level: high
visual_tags:
  - architecture
  - multi-agent
  - layered
ai_entrypoints:
  - Use a tabela de boundary antes de propor servico multi-agente novo. Servico que cruza boundary vira blocker.
ai_usage_notes:
  - Cada camada e UMA decisao isolada. Nao misture topologia com lifecycle ou paralelismo.
quality_gates:
  - four-layers-have-clear-boundary
  - decision-matrix-complete
  - antipattern-fifth-layer-declared
  - all-services-mapped-to-layer
failure_modes:
  - Servico multi-agente sem mapping para camada explicita.
  - Decisao tomada em camada errada (ex.: ACP escolhe provider).
  - Bypass de camada via atalho operacional.
  - Criacao de orquestrador paralelo nao governado.
observability_signals:
  - amua_layer_violation_count
  - amua_cross_layer_call_count
next_actions:
  - Mapear cada servico em `app/Services/Ai/AtlasAgenticWorkcell/`, `AtlasDecide/`, `AtlasForge/` e `self-construction/agent-control-plane*` para sua camada canonica.
  - Adicionar gate de arquitetura que bloqueia PR criando quinta camada.
---
# Atlas Multi-Agent Unified Architecture

## Resumo

Doc canonica que consolida em uma unica autoridade as quatro camadas multi-agente do Atlas: AAWR (topologia organizacional), Multi-Provider Agent Orchestration Profiles (selecao de mix), Agent Control Plane (lifecycle execucao) e Forge Multi-Agent Scheduler (paralelismo durable). Define boundary explicita, matriz de decisao e antipadrao contra quinta camada.

## Papel no Atlas

Hoje multi-agente vive em quatro docs separados sem doc-mae unificada. Consequencia: o Self-Construction OS Agent Control Plane explodiu em 292 services com nomes de 200+ chars (visto em `atlas-ai-self-construction-os.md`). **Esta doc e o substrato canonico** que evita repetir o sprawl em Forge ou em qualquer extensao futura.

## Onde Se Encaixa

```text
atlas-agentic-engineering-os
  +-- atlas-multi-agent-unified-architecture (este doc)
        +-- camada 1: AAWR
        +-- camada 2: Multi-Provider Profiles
        +-- camada 3: Agent Control Plane
        +-- camada 4: Forge Multi-Agent Scheduler
```

## Contratos

### As 4 camadas canonicas

| # | Camada | Decide | Doc owner | Servico canonico |
|---|--------|--------|-----------|------------------|
| 1 | AAWR (Agentic Workcell Runtime) | **topologia organizacional** entre agentes (single, pair, debate, swarm, etc.) | `atlas-agentic-workcell-runtime.md` | `AtlasAgenticWorkcellRuntime*` |
| 2 | Multi-Provider Profiles | **mix de providers** (scout, builder, reviewer, judge) por intent_class | `self-construction/multi-provider-agent-orchestration-contract.md` | `AtlasDecideOrchestrator + AtlasMultiProviderProfileService` |
| 3 | Agent Control Plane (ACP) | **lifecycle de execucao** (start, heartbeat, dispatch, claim, release, merge) | `self-construction/agent-control-plane-contract.md` | `AtlasAgentControlPlane*` |
| 4 | Forge Multi-Agent Scheduler | **paralelismo durable** (reservation ledger, worktree, collision guard, merge review) | `atlas-forge-continuum-os.md` | `AtlasForgeMultiAgentScheduler*` |

### Boundary canonica (o que NAO atravessa)

- **AAWR nao escolhe provider**. Topologia decide quantos agentes e em que padrao; quem sao os providers vem da camada 2.
- **Profiles nao executam**. Profile escolhe mix; execucao vem da camada 3.
- **ACP nao decide topologia nem provider**. Recebe decisoes das camadas 1+2 e governa lifecycle.
- **Scheduler nao decide topologia nem provider nem lifecycle individual**. Coordena paralelismo entre execucoes ja governadas pelas camadas 1+2+3.

### Schema canonico de decisao multi-agente (`atlas.multi_agent.decision.v1`)

```text
{
  "schema": "atlas.multi_agent.decision.v1",
  "intent_id": "<uuid>",
  "topology": {
    "kind": "single|pair|trio|debate|swarm|debate_with_judge|swarm_review|chain_of_specialists",
    "size": <int>,
    "rationale": "<string>"
  },
  "provider_profile": {
    "scout": "<provider_id_or_null>",
    "builder": "<provider_id>",
    "reviewer": "<provider_id_or_null>",
    "judge": "<provider_id_or_null>",
    "fallback_chain": ["<provider_id>"]
  },
  "lifecycle": {
    "agents": [{"agent_id": "<id>", "role": "<role>", "provider": "<id>", "scope_hash": "<sha256>"}],
    "heartbeat_interval_seconds": <int>,
    "dispatch_strategy": "sequential|parallel|conditional"
  },
  "scheduler": {
    "parallelism_mode": "single|parallel_durable",
    "max_parallel_agents": <int>,
    "reservation_strategy": "exclusive|sharded|read_only",
    "merge_strategy": "auto|review_required|architect_approval"
  },
  "evidence_hashes": ["sha256:..."]
}
```

### 8 topologias canonicas (camada 1)

1. `single` — 1 agente, sem paralelismo. Default para R1.
2. `pair` — builder + reviewer sequencial. R2.
3. `trio` — scout + builder + reviewer. R2-R3.
4. `debate` — 2-3 agentes argumentam, sem judge. Architect spec.
5. `debate_with_judge` — debate + judge desempata. Spec critico R4+.
6. `swarm` — N agentes paralelos sem coordenacao tight. Forge R3-R4.
7. `swarm_review` — swarm + review final cruzado. Forge R4-R5.
8. `chain_of_specialists` — sequencia de roles especializados (research -> spec -> impl -> review -> security). Enterprise.

### 5 provider profiles canonicos (camada 2)

1. `claude_solo` — Claude Code para tudo. Default tactico.
2. `codex_solo` — Codex para tudo. Default headless.
3. `claude_codex_pair` — Claude builder + Codex reviewer. R2-R3.
4. `gemini_scout_claude_builder_codex_reviewer` — trio cross-provider. R3-R4.
5. `cursor_local_anchor` — Cursor CLI ancora local + provider externo. Operator-led.

### Matriz de decisao (intent -> camadas)

| Intent class | Topologia | Profile | Scheduler |
|--------------|-----------|---------|-----------|
| R1 typo/fix | `single` | `claude_solo` | `single` |
| R2 bug medio | `pair` | `claude_codex_pair` | `single` |
| R3 feature | `trio` | `gemini_scout_claude_builder_codex_reviewer` | `single` ou `parallel_durable` se 2+ modulos |
| R4 obra week | `swarm_review` | `gemini_scout_claude_builder_codex_reviewer` | `parallel_durable` (max 4) |
| R5 enterprise | `chain_of_specialists` ou `debate_with_judge` para spec | profile custom + Cursor anchor | `parallel_durable` (max 6) |

## Fluxo

```mermaid
flowchart TD
  Intent[intent classification]
  L1[L1 AAWR escolhe topologia]
  L2[L2 Profile escolhe provider mix]
  L3[L3 ACP coordena lifecycle]
  L4[L4 Scheduler coordena paralelismo durable]
  Exec[execucao governada]

  Intent --> L1 --> L2 --> L3 --> L4 --> Exec

  Forbid1[Bypass: Scheduler sem AAWR]:::forbid
  Forbid2[Bypass: ACP escolhe provider]:::forbid
  Forbid3[Bypass: AAWR executa]:::forbid

  L4 -.X.-> Forbid1
  L3 -.X.-> Forbid2
  L1 -.X.-> Forbid3

  classDef forbid fill:#fdd,stroke:#900
```

## Antipadroes (o que esta arquitetura PROIBE)

1. **Quinta camada multi-agente** sem decision receipt assinado e revisao Architect.
2. **Servico que cruza boundary** (ex.: ACP que tambem escolhe provider) — refatorar em DOIS servicos antes de merge.
3. **Bypass de camada** (ex.: Forge Scheduler chamado direto sem AAWR ter rodado) — bloquear no scope guard.
4. **Sprawl de naming** (`agent-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-...`) — sintoma de UMA camada absorvendo responsabilidades de TRES. Refatoracao em T3.4.
5. **Topologia decidida em prompt em vez de schema** — toda decisao sai como `atlas.multi_agent.decision.v1`.

## Regras para IA

- Antes de criar servico em `app/Services/Ai/` que envolva mais de 1 agente, declarar a camada canonica (1, 2, 3 ou 4).
- Se o servico decide DUAS coisas, refatorar em DOIS servicos.
- Cada agente em execucao tem registro em `atlas.multi_agent.decision.v1.lifecycle.agents` com `scope_hash` distinto.
- Bypass de camada exige decision receipt + escalation para Architect.

## Escopo de Implementacao

Mudancas neste doc afetam: `AtlasAgenticWorkcell*`, `AtlasDecide*`, `AtlasAgentControlPlane*`, `AtlasForgeMultiAgentScheduler*`. Atualizar junto: T2.2 `atlas-parallel-multi-agent-execution-spec.md` (paralelismo durable) e T3.4 Self-Construction Compaction (refator do Agent Control Plane sprawl).

## Dependencias

Ver frontmatter. Resumo: depende de AAEOS e AAWR; flui para Parallel Multi-Agent Execution Spec, Choreography e Self-Construction Compaction.

## Evidencias

- Doc canonico
- Comando esperado: `php artisan atlas:multi-agent:layer-status --json` mapeia cada servico para sua camada e detecta cross-layer calls.

## Riscos

- **Sprawl repete em Forge**: Forge Scheduler hoje esta em 5-7 services; sem boundary clara repete o pattern do ACP. Mitigacao: gate de arquitetura T3.4.
- **Cross-layer calls**: dependencia circular entre camadas. Mitigacao: telemetria `amua_cross_layer_call_count`.
- **Decisao implicita**: agente decide mix sem emitir schema. Mitigacao: validador obrigatorio no entry point das camadas 3 e 4.

## O que este doc NAO e

- Nao e a doc-mae do AAWR (essa continua sendo `atlas-agentic-workcell-runtime.md`).
- Nao e o spec de paralelismo durable (T2.2).
- Nao e o spec de compaction do Self-Construction OS (T3.4).
- Nao executa codigo; e contrato declarativo de boundary.

## Exemplos

### Exemplo 1: Bug fix R1

```text
{
  "schema": "atlas.multi_agent.decision.v1",
  "intent_id": "abc123",
  "topology": {"kind": "single", "size": 1, "rationale": "R1 fix em 1 arquivo"},
  "provider_profile": {"builder": "claude_code", "fallback_chain": ["codex_cli"]},
  "lifecycle": {"agents": [{"agent_id": "a1", "role": "builder", "provider": "claude_code", "scope_hash": "sha256:..."}], "heartbeat_interval_seconds": 30, "dispatch_strategy": "sequential"},
  "scheduler": {"parallelism_mode": "single", "max_parallel_agents": 1, "reservation_strategy": "exclusive", "merge_strategy": "auto"}
}
```

### Exemplo 2: Obra de uma semana R4

```text
{
  "schema": "atlas.multi_agent.decision.v1",
  "intent_id": "obra-notif-001",
  "topology": {"kind": "swarm_review", "size": 3, "rationale": "R4 multi-modulo notif"},
  "provider_profile": {"scout": "gemini_cli", "builder": "claude_code", "reviewer": "codex_cli", "fallback_chain": ["claude_code"]},
  "lifecycle": {"agents": [
    {"agent_id": "a1", "role": "scout", "provider": "gemini_cli", "scope_hash": "..."},
    {"agent_id": "a2", "role": "builder", "provider": "claude_code", "scope_hash": "..."},
    {"agent_id": "a3", "role": "reviewer", "provider": "codex_cli", "scope_hash": "..."}
  ], "heartbeat_interval_seconds": 60, "dispatch_strategy": "parallel"},
  "scheduler": {"parallelism_mode": "parallel_durable", "max_parallel_agents": 4, "reservation_strategy": "sharded", "merge_strategy": "review_required"}
}
```

## Proximas Acoes

1. Implementar `AtlasMultiAgentLayerRegistryService` que carrega mapping servico->camada deste doc.
2. Implementar `php artisan atlas:multi-agent:layer-status --json` que detecta servicos sem mapping.
3. Adicionar gate `four-layers-have-clear-boundary` no docs-health v2.
4. Refatorar Self-Construction Agent Control Plane para respeitar boundary (governado por T3.4).
