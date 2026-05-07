# AP-161 - Agent Behavior Recurring Schedule

Status: implemented

## Problema

AP-159 criou o flow dedicado `self_improvement.agent_behavior_review` e AP-160 adicionou filtros ergonomicos para investigar agentes, codigos de finding e contratos especificos. O risco restante era operacional: o flow podia existir, mas ficar fora da agenda recorrente padrao do Self-Improvement. Nesse estado, o Atlas so revisaria comportamento de agentes quando um operador lembrasse de chamar o comando manualmente.

Para a arquitetura mae, isso e uma brecha. Comportamento de agente e contrato de qualidade do proprio Atlas, entao precisa entrar no ciclo automatico de melhoria continua.

## Decisao

O default recorrente de Self-Improvement passa a incluir:

```text
nightly_review
weekly_architecture_audit
repair_loop_review
kernel_pipeline_review
agent_behavior_review
```

O plano padrao registra 5 comandos:

```text
atlas:ai:self-improve --flow=nightly_review --hours=24 --limit=5 --json
atlas:ai:self-improve --flow=weekly_architecture_audit --hours=24 --limit=5 --json
atlas:ai:self-improve --flow=repair_loop_review --hours=24 --limit=5 --json
atlas:ai:self-improve --flow=kernel_pipeline_review --hours=24 --limit=5 --json
atlas:ai:self-improve --flow=agent_behavior_review --hours=24 --limit=5 --json
```

Cadencia canonica:

```text
daily: 4
weekly: 1
```

`weekly_architecture_audit` permanece semanal. `agent_behavior_review` e diario porque agentes degradam por drift de prompt, provider, ferramenta, pressa operacional e mudanca de contexto.

## Contrato

- `config/atlas_ai.php` deve manter `agent_behavior_review` no default de `ATLAS_AI_SELF_IMPROVEMENT_FLOWS`.
- `AtlasSelfImprovementScheduleService::defaultFlows()` deve incluir `agent_behavior_review`.
- `GET /ai/self-improvement/schedule`, MCP, observabilidade e CLI devem reportar 5 comandos recorrentes quando o scheduler estiver ativo.
- O comando gerado para o flow deve ser exatamente `atlas:ai:self-improve --flow=agent_behavior_review --hours=24 --limit=5 --json`.
- `architecture-validate` deve expor e validar `ap161_agent_behavior_recurring_schedule`.

## Anti-regressao

O scanner `ap161_agent_behavior_recurring_schedule` falha se:

- o default de config remover `agent_behavior_review`;
- o schedule service remover o flow da agenda padrao;
- os testes deixarem de travar `registered_command_count`, `cadence_counts` ou o comando do flow;
- a documentacao canonica deixar de citar AP-161 e o default recorrente.

## Resultado esperado

O Atlas passa a auditar comportamento de agentes no ciclo noturno de Self-Improvement, gerando propostas e findings governados por policy. Nada critico e autoaplicado: findings comportamentais entram como evidencia, sinais de memoria e propostas revisaveis.
