# AP-132 — Architecture Operations Metadata Contract

## Problema

O catalogo operacional da arquitetura mae ja era compartilhado entre CLI, API,
Observability, MCP e Self-Improvement, mas cada item ainda era basicamente
`command + description`. Isso era bom para humanos, porem fraco para automacoes:
agentes precisavam inferir por string quais operacoes eram validacao, reports de
evidencia ou catalogo.

## Contrato

- `AtlasArchitectureOperationsCatalog::summary()` deve publicar
  `schema_version=atlas.architecture_operations.v1`.
- O summary deve incluir `operation_ids`.
- Cada comando canonico deve ter `id`, `command`, `description`, `surface`,
  `kind` e `output`.
- CLI, API, Observability e MCP devem preservar o mesmo shape.
- O scanner deve publicar
  `ap132_architecture_operations_metadata_contract`.

## Evidencia

- `AtlasArchitectureOperationsCatalogTest`
- `AtlasAiArchitectureOperationsCommandTest`
- `AtlasAiArchitectureOperationsApiTest`
- `AiObservabilityKernelSloTest`
- `AtlasOpenBrainMcpServiceTest`
- `AtlasAiArchitectureValidateCommandTest`
- `AtlasAiArchitectureValidateApiTest`

## Resultado

O control plane operacional continua legivel para operador, mas agora tambem e
estavel para App, Curator, MCP e sessoes auxiliares: elas podem consumir IDs e
tipos de operacao sem parsear comandos de terminal.
