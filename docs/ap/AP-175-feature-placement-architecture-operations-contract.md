# AP-175 — Feature Placement Architecture Operations Contract

## Problema

`place-feature` e o primeiro gate antes de implementar, mas ele ainda podia
devolver somente placement, docs donos e validacoes soltas. Agentes que pulavam
`session-bootstrap` ficavam sem o catalogo operacional minimo da arquitetura mae
para descobrir comandos canonicos.

## Contrato

- `AtlasFeaturePlacementService` deve injetar
  `AtlasArchitectureOperationsCatalog`.
- O payload deve conter `architecture_operations` com schema
  `atlas.architecture_operations.v1`, `operation_ids`, `command_count` e
  `commands`.
- O payload deve conter `architecture_operations.owner_layer_operations.runtime`
  para expor `runtime_language_boundary` quando a IA entrar direto pelo gate.
- Quando `placement.runtime` existir, `implementation_contract` deve carregar
  `runtime_invocation_contract` com schema
  `atlas.runtime_invocation_contract.v1`, `kernel_first=true`,
  `selected_runtime_family`, `decision_receipt_hash`, `evidence_sink`,
  `forbidden_runtime_authority` e `return_contract`.
- Aliases de placement podem existir para UX, como `go_edge_concurrency`, mas o
  contrato de invocacao deve normalizar a familia canonica, como `go_edge`.
- O bloco deve incluir pelo menos `architecture_readiness`,
  `feature_placement`, `session_bootstrap`, `documentation_split_plan`,
  `architecture_validate`, `documentation_health`, `knowledge_sync` e
  `code_intelligence_index`.
- CLI, API `/ai/feature-placement` e MCP `atlas_feature_placement` devem expor o
  mesmo bloco.
- O scanner deve publicar
  `ap175_feature_placement_architecture_operations_contract`.

## Evidencia

- `AtlasAiSessionBootstrapCommandTest::test_place_feature_reports_duplicate_candidates_before_implementation`
- `AtlasAiGovernanceApiTest::test_feature_placement_api_requires_feature_and_places_provider_release`
- `AtlasOpenBrainMcpServiceTest::test_governance_tools_expose_session_bootstrap_feature_placement_and_split_plan`
- `AtlasAiArchitectureValidateCommandTest`
- `AtlasAiArchitectureValidateApiTest`

## Resultado

Mesmo quando uma IA entra direto pelo gate de feature, ela recebe os comandos
canonicos de bootstrap, split plan e validacao. Isso reduz bifurcacao entre
sessao completa e atalho de placement.
