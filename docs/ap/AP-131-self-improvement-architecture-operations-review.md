# AP-131 — Self-Improvement Architecture Operations Review

## Problema

O catalogo operacional da arquitetura mae ja era fonte unica e aparecia em CLI,
API, Observability e MCP. Ainda faltava o Curator observar esse proprio
catalogo: se um comando critico sumisse, o Atlas poderia continuar saudavel no
runtime, mas piorar sua descobribilidade operacional.

## Contrato

- `AtlasSelfImprovementRuntime` deve receber
  `AtlasArchitectureOperationsCatalog`.
- `weekly_architecture_audit` e o fluxo default devem chamar
  `architectureOperationsFindings`.
- A auditoria deve detectar secao incorreta, `command_count` divergente ou
  comandos criticos ausentes.
- Os comandos criticos incluem as operacoes de governanca de cost-rate usadas
  por AP-99/AP-146/AP-147:
  - `atlas ai dynamic-compute-market --provider=<provider> --domain=<domain> --flow=<flow> --json`
  - `atlas ai self-improve --flow=provider_performance_review --hours=168 --json`
  - `atlas ai self-improve --flow=agent_behavior_review --hours=168 --json`
  - `atlas ai telemetry cost-rates --missing --hours=168 --json`
  - `atlas ai telemetry cost-rates --provider=<provider> --model=<model> --input-microusd=<input> --output-microusd=<output> --json`
- Findings devem usar schema
  `atlas.self_improvement.architecture_operations.v1`.
- O recommended action canonico deve ser
  `restore_architecture_operations_catalog`.
- O scanner deve publicar
  `ap131_self_improvement_architecture_operations_review`.

## Evidencia

- `AtlasSelfImprovementRuntimeTest::test_self_improvement_detects_architecture_operations_catalog_drift`
- `AtlasAiArchitectureValidateCommandTest`
- `AtlasAiArchitectureValidateApiTest`

## Resultado

O Self-Improvement passa a revisar a propria camada de descoberta operacional da
arquitetura mae. Comando implementado mas invisivel vira finding revisavel, em
vez de virar conhecimento tribal ou fluxo solto.
