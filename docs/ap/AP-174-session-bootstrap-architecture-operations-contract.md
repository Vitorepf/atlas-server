# AP-174 — Session Bootstrap Architecture Operations Contract

## Problema

`session-bootstrap` ja entregava docs, placement, projection e split plan, mas
nao carregava as operacoes canonicas da arquitetura mae. Uma IA nova ainda
precisava consultar outro catalogo para descobrir os comandos obrigatorios de
governanca e validacao da propria sessao.

## Contrato

- `AtlasSessionBootstrapService` deve injetar
  `AtlasArchitectureOperationsCatalog`.
- O payload deve conter `architecture_operations` com schema
  `atlas.architecture_operations.v1`, `operation_ids`, `command_count` e
  `commands`.
- O payload deve conter `architecture_operations.owner_layer_operations.runtime`
  para descobrir `runtime_language_boundary` antes de qualquer Python/Go/Swift/RAG/ML.
- O bloco deve incluir pelo menos `architecture_readiness`,
  `session_bootstrap`, `feature_placement`, `documentation_split_plan`, `architecture_operations`,
  `architecture_validate`, `documentation_health`, `provider_projection_status`,
  `knowledge_sync`, `code_intelligence_index` e `ap_agent_workflow_registry`.
- Quando a tarefa mencionar AP, arquitetura ou governanca, `read_first` deve
  incluir `docs/ap/AP-204-ap-agent-workflow-registry.md` para carregar a cadeia
  AP-200..AP-234 antes de qualquer edicao.
- CLI, API `/ai/session-bootstrap` e MCP `atlas_session_bootstrap` devem expor o
  mesmo bloco.
- O payload deve conter tambem `architecture_readiness` resumido, derivado de
  `AtlasArchitectureReadinessService::snapshot()`, com `status`, `ready`,
  `checks`, `review_signal`, `coverage_boundary`, `safe_next_blocks` e comando
  focado por owner. O snapshot completo continua pertencendo a AP-176/AP-177;
  bootstrap carrega o sinal operacional necessario para a sessao.
- O payload deve expor `coverage_boundary` e `safe_next_blocks` tambem no nivel
  raiz. Esses campos sao read-model diagnostico da matriz
  `implemented-vs-scaffold`; nao viram backlog paralelo nem autorizam pular AP.
- O scanner deve publicar
  `ap174_session_bootstrap_architecture_operations_contract`.

## Evidencia

- `AtlasAiSessionBootstrapCommandTest::test_session_bootstrap_returns_owner_docs_and_feature_placement`
- `AtlasAiGovernanceApiTest::test_session_bootstrap_api_returns_canonical_package`
- `AtlasOpenBrainMcpServiceTest::test_governance_tools_expose_session_bootstrap_feature_placement_and_split_plan`
- `AtlasAiArchitectureValidateCommandTest`
- `AtlasAiArchitectureValidateApiTest`

## Resultado

Toda sessao nasce com os comandos essenciais de bootstrap, placement, split
plan, projection, workflow AP, validacao, limite de cobertura e os blocos
seguros para continuar. Isso reduz consultas secundarias, evita comandos
antigos em prompts externos e torna o bootstrap uma unidade operacional
completa.
