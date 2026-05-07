---
id: AP-158
title: Agent Behavior Direct Surfaces
status: implemented-direct-surfaces
area: atlas-ai
type: architecture-principle
created_at: 2026-05-06
updated_at: 2026-05-06
depends_on:
  - AP-155
  - AP-156
  - AP-157
related_paths:
  - app/Console/Commands/AtlasAiAgentBehaviorReportCommand.php
  - app/Http/Controllers/AtlasAiAgentBehaviorReportController.php
  - app/Services/Ai/Kernel/Architecture/AtlasArchitectureOperationsCatalog.php
  - routes/api.php
  - tests/Feature/Ai/AtlasAiAgentBehaviorReportCommandTest.php
  - tests/Feature/Ai/AtlasAiAgentBehaviorReportApiTest.php
---

# AP-158 Agent Behavior Direct Surfaces

## Decisao

O read model de comportamento dos agentes deve estar disponivel em superficies
diretas, alem do MCP e do Curator, para operador, App e novas sessoes de IA.

## Superficies

- CLI: `atlas:ai:agent-behavior-report`
- User-facing command catalog: `atlas ai agent-behavior-report --hours=24 --json`
- API: `GET /ai/agent-behavior/report`
- Operations catalog: `agent_behavior_report`

## Contrato

Todas as superficies chamam `AtlasLedgerReplayService::agentBehaviorReportForWindow`.
Nenhuma consulta tabela crua, log de provider ou trace paralelo.

Filtros aceitos:

- `status`
- `provider`
- `model`
- `agent_slug`
- `finding_code`
- `contract_id`

## Saida

O payload deve manter:

- `status`
- `hours`
- `filters`
- `agent_behavior`
- `agent_behavior.review_signal`

## Verificacao

- `AtlasAiAgentBehaviorReportCommandTest`
- `AtlasAiAgentBehaviorReportApiTest`
- `KernelArchitectureStaticScanner::scanAgentBehaviorDirectSurfaces`
- `php artisan atlas:ai:architecture-validate --json`
