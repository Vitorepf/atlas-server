# AP-129 — Architecture Operations MCP Tool

## Problema

Depois do AP-128, o catalogo operacional da arquitetura mae passou a ter uma
fonte unica (`AtlasArchitectureOperationsCatalog`), mas agentes MCP/Open Brain
ainda precisavam descobrir esses comandos por CLI help, Observability HTTP ou
documentacao. Isso criava uma surface read-only mais fraca justamente para
sessoes auxiliares de implementacao e revisao.

## Contrato

- `AtlasOpenBrainMcpService` deve expor a tool read-only
  `atlas_architecture_operations`.
- A tool deve consumir `AtlasArchitectureOperationsCatalog::summary()` sem
  duplicar comandos.
- O payload deve retornar `ok=true`, `tool=atlas_architecture_operations`,
  `architecture_operations` e `writes=false`.
- `atlas_capabilities` deve listar a tool para capability negotiation.
- O scanner arquitetural deve publicar
  `ap129_architecture_operations_mcp_tool`.

## Evidencia

- `AtlasOpenBrainMcpServiceTest::test_capabilities_returns_full_tool_inventory`
- `AtlasOpenBrainMcpServiceTest::test_architecture_operations_tool_exposes_shared_operations_catalog`
- `AtlasAiArchitectureValidateCommandTest`
- `AtlasAiArchitectureValidateApiTest`

## Resultado

Outras sessoes Codex, Claude, Curator e clientes MCP conseguem descobrir o
control plane operacional da arquitetura mae diretamente pelo Open Brain, sem
parsear terminal ou docs, mantendo a mesma fonte canonica usada por CLI e App.
