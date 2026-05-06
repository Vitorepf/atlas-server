# AP-145 — Documentation Health Curator Review

## Problema

`architecture-validate` ja expunha docs acima do limite da Documentation OS,
mas esse sinal ficava passivo. Documentacao grande demais reduz leitura de IA,
cria falso negativo sobre o que ja existe e favorece duplicacao de fluxos.

## Contrato

`AtlasSelfImprovementRuntime::documentationHealthFindings` deve:

- consumir `documentation.oversized_docs` do `AtlasAiArchitectureValidationService`;
- ignorar docs `split_required_grandfathered` como finding primario;
- criar finding quando existir doc ativo com status `split_required`;
- usar schema `atlas.self_improvement.documentation_health_gap.v1`;
- recomendar `split_oversized_active_docs`;
- preservar path, line count, limit, status e recommended action em `source_refs`;
- rodar em `docs_drift_review`, `weekly_architecture_audit`,
  `domain_learning_review` e default nightly.

## Resultado

Oversize documental deixa de ser apenas informativo e vira backlog revisavel do
Curator. A governanca continua verde quando bootstrap/frontmatter estao certos,
mas a propria estrutura mae passa a lembrar que docs ativos grandes precisam
ser fatiados antes de receber novas responsabilidades.

## Testes

- `AtlasSelfImprovementRuntimeTest::test_self_improvement_detects_oversized_active_documentation_from_architecture_validation`
- `KernelArchitectureStaticScanner::scanDocumentationHealthCuratorReview`
