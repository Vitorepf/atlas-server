# AP-150 — Agent Behavior Quality Gate

Status: implemented-initial-gate

## Problema

AP-148 e AP-149 tornam o contrato comportamental visivel para providers e planos,
mas ainda faltava uma primeira avaliacao operacional que transformasse violacoes
em findings estruturados.

## Contrato

`AgentBehaviorQualityGate` deve emitir findings `agent.*` governados pelo contrato
`atlas-ai.agent-behavior.v1`:

- `agent.verification_missing` quando uma tarefa tecnica nao declara teste, gate,
  comando executado ou motivo para nao validar;
- `agent.unsurgical_diff` quando arquivos alterados escapam dos `allowed_paths`
  declarados pela tarefa.

Todo finding precisa carregar:

- `metadata.schema_version = atlas.agent_behavior.finding.v1`;
- `metadata.contract_id`;
- `metadata.contract_hash`;
- `metadata.review_signal`;
- evidencia objetiva do principio violado.

## Integracao Inicial

`AiQualityEvaluator` consome o gate para popular o flag legado
`verification_missing` e grava os findings em `metadata.evidence`, preservando
compatibilidade com scorecards existentes enquanto o Review Mode e Quality Gates
ganham suporte direto aos findings `agent.*`.

## Nao Escopo

AP-150 nao bloqueia execucao sozinho, nao altera provider routing e nao aplica
patch automatico. Bloqueio formal em review/gate pertence aos proximos APs.

## Testes

- `AgentBehaviorQualityGateTest`
- `AiQualityEvaluatorTest::test_flags_internal_context_leaks_and_is_idempotent`
- `KernelArchitectureStaticScanner::scanAgentBehaviorQualityGate`
