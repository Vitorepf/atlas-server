# AP-133 — Architecture Operations Filter Contract

## Problema

Depois que o catalogo operacional ganhou IDs e tipos, agentes ainda precisavam
buscar o catalogo inteiro e filtrar localmente para achar uma unica operacao.
Isso empurra parse/heuristica para cada cliente e recria divergencia nas
surfaces.

## Contrato

- `AtlasArchitectureOperationsCatalog::summary()` deve aceitar filtros `id`,
  `kind`, `section`, `surface` e `owner_layer`.
- `id/kind/section/surface/owner_layer` e o conjunto canonico de filtros para catalogo operacional; qualquer
  surface nova deve reutilizar estes nomes sem criar aliases locais.
- `surface` diz por onde a operacao roda; `owner_layer` diz qual camada ela governa
  (`runtime`, por exemplo), sem misturar transporte com responsabilidade.
- O summary deve preservar `filters`, `operation_ids`, `command_count` e
  `commands` filtrados.
- CLI deve expor `--id`, `--kind`, `--section`, `--surface` e `--owner-layer`.
- API deve aceitar query params `id`, `kind`, `section`, `surface` e `owner_layer`.
- MCP `atlas_architecture_operations` deve aceitar argumentos `id`, `kind`, `section`, `surface` e `owner_layer`.
- O scanner deve publicar `ap133_architecture_operations_filter_contract`.

## Evidencia

- `AtlasArchitectureOperationsCatalogTest::test_catalog_filters_architecture_operations_by_id_and_kind`
- `AtlasAiArchitectureOperationsCommandTest::test_command_filters_architecture_operations_by_id_and_kind`
- `AtlasAiArchitectureOperationsApiTest::test_api_filters_architecture_operations_catalog`
- `AtlasOpenBrainMcpServiceTest::test_architecture_operations_tool_filters_shared_operations_catalog`
- `AtlasAiArchitectureValidateCommandTest`
- `AtlasAiArchitectureValidateApiTest`

## Resultado

Operador, App, MCP e sessoes auxiliares conseguem consultar apenas uma operacao
ou uma classe de operacoes sem parsear strings de comando nem duplicar regras de
filtro.
