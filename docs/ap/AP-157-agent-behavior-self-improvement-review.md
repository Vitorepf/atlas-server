---
id: AP-157
title: Agent Behavior Self-Improvement Review
status: implemented-self-improvement-review
area: atlas-ai
type: architecture-principle
created_at: 2026-05-06
updated_at: 2026-05-06
depends_on:
  - AP-154
  - AP-155
  - AP-156
related_paths:
  - app/Services/Ai/SelfImprovement/AtlasSelfImprovementRuntime.php
  - app/Services/Ai/Kernel/Evidence/AtlasLedgerReplayService.php
  - app/Services/Ai/Kernel/Architecture/KernelArchitectureStaticScanner.php
  - tests/Feature/Ai/AtlasSelfImprovementRuntimeTest.php
  - docs/engineering-knowledge-base/atlas-ai-agent-behavior-contract.md
---

# AP-157 Agent Behavior Self-Improvement Review

## Decisao

Self-Improvement / Curator deve consumir o read model de comportamento dos
agentes e abrir finding revisavel quando o Evidence Ledger indicar recorrencia
de falhas como `agent.verification_missing` ou `agent.unsurgical_diff`.

AP-159 promove essa capacidade para o flow dedicado
`self_improvement.agent_behavior_review`, invocado por
`atlas ai self-improve --flow=agent_behavior_review --hours=168 --json`.
Esse flow nao substitui `weekly_architecture_audit` nem
`provider_performance_review`; ele existe para auditorias focadas em qualidade
de agentes quando o operador quer investigar comportamento, nao custo/modelo.

## Regra

O Curator nao le traces crus, prompts soltos ou logs paralelos. Ele chama
`AtlasLedgerReplayService::agentBehaviorReportForWindow()` e preserva o
`review_signal` canonico. A saida usa schema
`atlas.self_improvement.agent_behavior_replay.v1` e action
`open_reviewable_agent_behavior_quality_proposal`.

## Fluxo

1. `AiQualityEvaluator` gera findings `agent.*`.
2. AP-154 emite `GATE_EVALUATED` no Evidence Ledger.
3. AP-155 resume recorrencia em `agentBehaviorReportForWindow`.
4. AP-156 expoe o mesmo relatorio no MCP.
5. AP-157 faz `agentBehaviorReplayFindings()` criar finding do Curator.
6. `self_improvement.agent_behavior_review` permite rodar apenas esse sensor.
7. A proposta segue para Inbox / review humano antes de mudar comportamento.

## Invariantes

- Self-Improvement nao autoaltera prompts, gates ou providers por conta propria.
- Toda proposta carrega `source_refs` de ledger events.
- Filtros aceitos: `status`, `provider`, `model`, `agent_slug`,
  `finding_code`, `contract_id`.
- O dedupe key usa `self-improvement:agent-behavior-replay`.
- A validação arquitetural deve falhar se o runtime parar de consumir
  `agentBehaviorReportForWindow`.
- O catalogo operacional deve expor
  `atlas ai self-improve --flow=agent_behavior_review --hours=168 --json`.

## Verificacao

- `tests/Feature/Ai/AtlasSelfImprovementRuntimeTest.php`
  - `test_self_improvement_detects_agent_behavior_replay_patterns`
- `KernelArchitectureStaticScanner::scanAgentBehaviorSelfImprovementReview`
- `php artisan atlas:ai:architecture-validate --json`
