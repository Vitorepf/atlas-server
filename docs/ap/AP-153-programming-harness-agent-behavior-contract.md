# AP-153 — Programming Harness Agent Behavior Contract

Status: implemented-harness-contract

## Problema

AP-152 colocou `agent_behavior_contract` no plano do Programming Domain, mas a
fronteira `ProgrammingExecutionRequest -> EngineeringHarnessExecutionService`
ainda nao tinha accessor/propagacao explicita. Isso permitia que o Forge/Harness
executasse programacao pesada sem carregar o mesmo contrato comportamental no
contrato da task, nas opcoes efetivas ou no metadata de repair.

## Contrato

`ProgrammingExecutionRequest` deve expor `agentBehaviorContract()`.

`EngineeringHarnessExecutionService` deve propagar esse contrato para:

- `harnessOptions().agent_behavior_contract`;
- `atlas_tasks.metadata.engineering_contract.agent_behavior_contract`;
- `atlas_tasks.metadata.programming_orchestrator.agent_behavior_contract`;
- `policy_contract_enforcement.effective_options.agent_behavior_contract`;
- metadata do Kernel Repair quando o harness bloquear/falhar.

O contrato permanece read-only: nao escolhe provider, nao altera policy, nao
executa tool e nao substitui quality gates.

## Testes

- `EngineeringHarnessRunnerTest::test_harness_execution_service_creates_task_for_programming_request_without_task_id`
- `EngineeringHarnessRunnerTest::test_harness_execution_service_blocks_provider_run_when_tool_contract_is_read_only`
- `KernelArchitectureStaticScanner::scanProgrammingHarnessAgentBehaviorContract`
