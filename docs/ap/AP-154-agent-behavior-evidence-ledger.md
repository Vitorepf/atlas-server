# AP-154 — Agent Behavior Evidence Ledger

Status: implemented-ledger-signal

## Problema

AP-150 criava findings `agent.*` e AP-151 levava esses findings para a review
action, mas o Evidence Ledger ainda nao recebia um evento canonico do gate
comportamental. Isso deixava Curator, replay e telemetria dependentes de metadata
local em `ai_quality_evaluations`.

## Contrato

Quando `AiQualityEvaluator` encontrar `agent_behavior_findings`, ele deve chamar
`AtlasEvidenceLedger::recordAgentBehaviorGateEvaluation()`.

O evento deve ser:

- `event_type = GATE_EVALUATED`;
- `emitter_stage = atlas.agent_behavior_quality_gate`;
- `payload.gate_id = atlas.agent_behavior`;
- `payload.schema_version = atlas.agent_behavior.gate_evaluation.v1`;
- `payload.agent_behavior_findings[]` com os findings estruturados;
- `payload.contract_id` e `payload.contract_hash` quando disponiveis;
- `trace_id`, `quality_evaluation_id`, score, flags e evidencia tecnica.

## Nao Objetivos

- Nao bloquear execucao automaticamente.
- Nao criar novo tipo de evento se `GATE_EVALUATED` ja representa a semantica.
- Nao duplicar a regra do gate fora de `AgentBehaviorQualityGate`.

## Verificacao

- `AiQualityEvaluatorTest::test_dev_task_type_requires_verification_signal`
- `KernelArchitectureStaticScanner::scanAgentBehaviorEvidenceLedger`
