---
id: AP-160
title: Agent Behavior Curator Filter Surface
status: implemented-filter-surface
area: atlas-ai
type: architecture-principle
created_at: 2026-05-06
updated_at: 2026-05-06
depends_on:
  - AP-159
related_paths:
  - app/Console/Commands/AtlasAiSelfImproveCommand.php
  - app/Services/Ai/SelfImprovement/AtlasSelfImprovementOrchestrator.php
  - app/Services/Ai/SelfImprovement/AtlasSelfImprovementRuntime.php
  - app/Services/Ai/Kernel/Architecture/KernelArchitectureStaticScanner.php
  - tests/Feature/Ai/AtlasSelfImprovementRuntimeTest.php
---

# AP-160 Agent Behavior Curator Filter Surface

## Decisao

`atlas:ai:self-improve --flow=agent_behavior_review` deve expor diretamente os
agent behavior filters suportados pelo read model e pelo runtime.

## Problema

AP-159 criou um flow dedicado para comportamento de agentes, mas sem filtros
diretos na CLI o operador precisava depender de chamadas internas ou filtros
genéricos. Isso enfraquecia o uso real por humanos e outras IAs.

## Contrato

O comando `atlas:ai:self-improve` deve aceitar:

- `--agent-status`
- `--agent-slug`
- `--finding-code`
- `--contract-id`

Essas opções devem ser traduzidas para os filtros canônicos:

- `status`
- `agent_slug`
- `finding_code`
- `contract_id`

O `AtlasSelfImprovementOrchestrator` deve preservar esses filtros no plano, e o
`AtlasSelfImprovementRuntime` deve aplicá-los via `normalizedAgentBehaviorFilters`.

## Exemplo

```bash
php artisan atlas:ai:self-improve --flow=agent_behavior_review \
  --provider=codex_cli \
  --agent-slug=programming_agent \
  --finding-code=agent.verification_missing \
  --contract-id=atlas-ai.agent-behavior.v1 \
  --agent-status=needs_review \
  --hours=24 \
  --json
```

## Invariantes

- Filtro não decide provider, não muda modelo e não altera policy.
- Filtro só reduz a janela de replay usada para gerar propostas revisáveis.
- O flow continua proposal-only.
- O scanner publica `ap160_agent_behavior_curator_filter_surface`.

## Verificacao

- `AtlasSelfImprovementRuntimeTest::test_command_filters_agent_behavior_review_by_behavior_dimensions`
- `KernelArchitectureStaticScanner::scanAgentBehaviorCuratorFilterSurface`
- `php artisan atlas:ai:architecture-validate --json`
