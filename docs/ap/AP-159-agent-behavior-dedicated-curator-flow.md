---
id: AP-159
title: Agent Behavior Dedicated Curator Flow
status: implemented-dedicated-curator-flow
area: atlas-ai
type: architecture-principle
created_at: 2026-05-06
updated_at: 2026-05-06
depends_on:
  - AP-157
  - AP-158
related_paths:
  - app/Services/Ai/SelfImprovement/AtlasSelfImprovementOrchestrator.php
  - app/Services/Ai/SelfImprovement/AtlasSelfImprovementRuntime.php
  - app/Services/Ai/AtlasDomainProfileRegistry.php
  - app/Services/Ai/Kernel/Architecture/AtlasArchitectureOperationsCatalog.php
  - app/Services/Ai/Kernel/Architecture/KernelArchitectureStaticScanner.php
  - tests/Feature/Ai/AtlasSelfImprovementRuntimeTest.php
  - tests/Unit/Ai/AtlasSelfImprovementOrchestratorTest.php
  - docs/engineering-knowledge-base/atlas-ai-agent-behavior-contract.md
---

# AP-159 Agent Behavior Dedicated Curator Flow

## Decisao

O Curator deve ter um flow dedicado para revisar comportamento de agentes:
`self_improvement.agent_behavior_review`.

O comando canonico e:

```bash
php artisan atlas:ai:self-improve --flow=agent_behavior_review --hours=168 --json
```

AP-160 adiciona filtros diretos para uso operacional:

```bash
php artisan atlas:ai:self-improve --flow=agent_behavior_review --agent-slug=programming_agent --finding-code=agent.verification_missing --contract-id=atlas-ai.agent-behavior.v1 --agent-status=needs_review --json
```

## Por que

AP-157 ja fazia Self-Improvement consumir replay comportamental, mas o sensor
ficava misturado em auditorias amplas como `weekly_architecture_audit` e
`provider_performance_review`. Isso era util, porem fraco para investigacoes
focadas em qualidade de agentes.

AP-159 separa esse caminho sem duplicar logica: o flow dedicado continua usando
`agentBehaviorReportForWindow()` e `agentBehaviorReplayFindings()`.

## Contrato

- `AtlasSelfImprovementOrchestrator::SUPPORTED_FLOWS` inclui
  `self_improvement.agent_behavior_review`.
- `AtlasDomainProfileRegistry` declara runtime
  `agent_behavior_review_runtime`.
- `AtlasSelfImprovementRuntime::findingsForFlow()` roteia esse flow para
  `agentBehaviorReplayFindings($hours, $filters)`.
- `AtlasArchitectureOperationsCatalog` publica `agent_behavior_curator_review`.
- CLI help, Observability e Open Brain MCP descobrem o comando pelo catalogo.
- O flow e proposal-only: nao altera prompts, providers, policies ou gates.
- O scanner arquitetural publica
  `ap159_agent_behavior_dedicated_curator_flow`.

## Filtros

O flow preserva os filtros canonicos do read model:

- `provider`
- `model`
- `agent_slug`
- `finding_code`
- `contract_id`
- `status`

Na CLI esses filtros aparecem como `--agent-slug`, `--finding-code`,
`--contract-id` e `--agent-status`.

## Verificacao

- `AtlasSelfImprovementOrchestratorTest::test_agent_behavior_review_plan_uses_dedicated_executor_contract`
- `AtlasSelfImprovementRuntimeTest::test_self_improvement_detects_agent_behavior_replay_patterns`
- `AtlasArchitectureOperationsCatalogTest`
- `AtlasOpenBrainMcpServiceTest::test_architecture_operations_tool_exposes_shared_operations_catalog`
- `AtlasCliHelpCommandTest`
- `KernelArchitectureStaticScanner::scanAgentBehaviorDedicatedCuratorFlow`
- `KernelArchitectureStaticScanner::scanAgentBehaviorCuratorFilterSurface`
- `php artisan atlas:ai:architecture-validate --json`
