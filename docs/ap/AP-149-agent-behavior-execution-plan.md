# AP-149 — Agent Behavior Execution Plan

Status: implemented-initial-contract

## Problema

AP-148 injeta o contrato comportamental no `IdentityFragment` dos providers, mas
o plano de execucao ainda nao carregava esse contrato como dado estruturado. Uma
surface poderia usar um `AiExecutionPlan` sem enxergar explicitamente as regras
de suposicao, simplicidade, diff cirurgico e verificacao.

## Contrato

`AiExecutionPlan::fromTask` deve:

- obter `AgentBehaviorContract` a partir do Kernel;
- anexar `agent_behavior_contract` ao array do plano;
- preservar `contract_id`, `content_hash`, `principles` e `source`;
- renderizar `## Agent Behavior Contract` em `toPromptSection()`;
- nao duplicar texto local por surface.

Esse contrato e inicial: ele torna o comportamento visivel e hashavel no plano.
ABC-3 e ABC-4 ainda precisam transformar isso em findings comportamentais e UI
de review.

## Nao Escopo

AP-149 nao altera `Atlas Decide`, nao muda provider selection, nao bloqueia run
automaticamente e nao substitui Quality Gates. O objetivo e propagacao
estruturada do contrato comportamental para prompts/plans.

## Testes

- `AiHarnessContractsTest::test_execution_plan_adds_human_gate_for_high_risk_tasks`
- `AiHarnessContractsTest::test_execution_plan_prompt_renders_agent_behavior_contract`
- `KernelArchitectureStaticScanner::scanAgentBehaviorExecutionPlan`
