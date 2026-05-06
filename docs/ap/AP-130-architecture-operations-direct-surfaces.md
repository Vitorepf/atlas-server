# AP-130 — Architecture Operations Direct Surfaces

## Problema

O catalogo operacional da arquitetura mae ja estava em fonte unica e aparecia em
CLI help, Observability e Open Brain MCP. Ainda assim, operador e automacoes nao
tinham uma surface direta para consultar apenas esse catalogo sem carregar um
payload maior.

## Contrato

- `atlas:ai:architecture-operations --json` deve retornar `status=ok` e
  `architecture_operations`.
- `GET /ai/architecture/operations` deve retornar o mesmo shape via API
  autenticada.
- CLI e API devem consumir `AtlasArchitectureOperationsCatalog::summary()`.
- O proprio catalogo deve listar `atlas ai architecture-operations --json`.
- O scanner deve publicar
  `ap130_architecture_operations_direct_surfaces`.

## Evidencia

- `AtlasAiArchitectureOperationsCommandTest`
- `AtlasAiArchitectureOperationsApiTest`
- `AtlasArchitectureOperationsCatalogTest`
- `AtlasAiArchitectureValidateCommandTest`
- `AtlasAiArchitectureValidateApiTest`

## Resultado

O control plane operacional da arquitetura mae ficou consultavel por uma surface
dedicada e compacta, preservando a fonte unica compartilhada entre CLI help,
Observability, MCP e API.
