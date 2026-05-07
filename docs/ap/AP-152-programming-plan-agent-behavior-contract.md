# AP-152 — Programming Plan Agent Behavior Contract

Status: implemented-programming-plan-contract

## Problema

AP-148 e AP-149 propagavam o contrato comportamental para provider identity e
`AiExecutionPlan`, mas o plano especifico do `AtlasProgrammingOrchestrator`
ainda nao declarava esse contrato. Isso criava risco de `atlas dev`, `atlas
forge`, `atlas fix` e dispatch/harness enxergarem policy/gates sem a camada
comportamental versionada.

## Contrato

`AtlasProgrammingOrchestrator::sessionPlan()` deve anexar
`agent_behavior_contract` ao plano retornado, usando `AgentBehaviorContract` do
Kernel. O plano de Programming deve carregar:

- `contract_id = atlas-ai.agent-behavior.v1`;
- `content_hash`;
- `principles`;
- `source`.

Esse contrato nao escolhe provider, nao altera repair, nao executa tool e nao
substitui `policy_contracts`. Ele apenas torna a disciplina comportamental parte
do plano canonico de programacao.

## Testes

- `AtlasProgrammingOrchestratorTest::test_session_plan_uses_policy_executor_for_normal_complete_and_forge_profiles`
- `KernelArchitectureStaticScanner::scanProgrammingPlanAgentBehaviorContract`
