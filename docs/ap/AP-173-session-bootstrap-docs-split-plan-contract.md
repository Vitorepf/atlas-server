# AP-173 — Session Bootstrap Docs Split Plan Contract

## Problema

`session-bootstrap` ja orientava a sessao com docs obrigatorios, placement,
owners e comandos de validacao, mas nao entregava a divida documental focada.
Isso fazia agentes abrirem o plano global de split, parsearem documentos demais
ou ignorarem `split_required` por falta de proxima acao operacional.

## Contrato

- `AtlasSessionBootstrapService` deve injetar
  `AtlasDocumentationSplitPlanService`.
- O bootstrap deve calcular um owner provavel por `splitOwner()` usando a tarefa
  e o resultado de feature placement.
- O payload deve conter `docs_split_plan` com `owner`, `status`,
  `split_required_count`, `total_split_required_count`, `execution_order`,
  `first_doc` e `command`.
- O `command` canonico deve ser
  `php artisan atlas:ai:docs-split-plan --owner=<owner> --json`.
- CLI deve mostrar `Docs split owner` no output humano.
- API `/ai/session-bootstrap` e MCP `atlas_session_bootstrap` devem expor o
  mesmo contrato.
- O catalogo `architecture_operations` deve declarar
  `session_bootstrap.output_contract.docs_split_plan` para descoberta sem
  executar o comando.
- O scanner deve publicar
  `ap173_session_bootstrap_docs_split_plan_contract`.

## Evidencia

- `AtlasAiSessionBootstrapCommandTest::test_session_bootstrap_returns_owner_docs_and_feature_placement`
- `AtlasAiSessionBootstrapCommandTest::test_session_bootstrap_focuses_docs_split_plan_for_memory_tasks`
- `AtlasAiGovernanceApiTest::test_session_bootstrap_api_returns_canonical_package`
- `AtlasOpenBrainMcpServiceTest::test_governance_tools_expose_session_bootstrap_feature_placement_and_split_plan`
- `AtlasAiArchitectureValidateCommandTest`
- `AtlasAiArchitectureValidateApiTest`

## Resultado

Toda nova sessao recebe a divida documental mais provavel do escopo atual, com
ordem de execucao e comando filtrado. Isso reduz leitura global, impede que docs
grandes sejam esquecidos e transforma governanca documental em proxima acao
operacional, nao conselho solto.
