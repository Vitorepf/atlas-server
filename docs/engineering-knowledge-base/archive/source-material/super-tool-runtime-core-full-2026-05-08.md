---
id: atlas-super-tool-runtime-core
type: engineering_knowledge
title: Atlas Super Tool Runtime Core
status: source_material
category: architecture
priority: 98
summary: Fundacao transversal para registrar, governar, executar, normalizar e persistir evidencias de ferramentas locais ou project-local usadas pelo Atlas.
tags:
  - atlas
  - tools
  - harness
  - evidence
capabilities:
  - tool_registry
  - tool_policy_engine
  - tool_executor
  - result_normalizer
  - evidence_store
  - tool_gates
  - finding_waivers
decisions:
  - Ferramentas entram pelo registry canonico antes de virarem automacao recorrente.
  - Ferramentas ausentes geram estado auditavel em vez de silencio.
  - Quality Scan registra evidencias tambem no runtime generico sem remover os artifacts existentes do Engineering Harness.
  - Quality Scan gerenciado pelo Harness vincula evidencias ao `engineering_run` e o gate generico vira controle auditavel do run.
  - Visual Smoke gerenciado pelo Harness tambem vincula evidencias ao `engineering_run` e registra gate visual pelo runtime generico.
  - Code Intelligence aceita contexto explicito de runtime para index/audit usados por automacoes.
  - Sensores internos do Atlas tambem sao tools registradas quando produzem evidencia operacional.
  - Waivers de findings sao auditaveis e so removem bloqueio de gate enquanto estiverem validos.
maintenance:
  - Rode atlas tools doctor --workspace=<repo> depois de adicionar uma ferramenta ao catalogo.
  - Rode atlas tools authority --json depois de alterar tiers ou papeis de autoridade.
  - Rode atlas tools run <tool> --dry-run antes de habilitar execucao nova em fluxo automatico.
  - Rode atlas tools gate --workspace=<repo> --require-evidence antes de usar evidencia como release gate.
  - Rode atlas engineering knowledge sync --prune e atlas engineering knowledge index-code --prune depois de alterar esta camada.
related_paths:
  - app/Services/Tools/AtlasToolRegistryService.php
  - app/Services/Tools/AtlasToolPolicyEngine.php
  - app/Services/Tools/AtlasToolExecutor.php
  - app/Services/Tools/AtlasToolApprovalService.php
  - app/Services/Tools/AtlasToolFindingWaiverService.php
  - app/Services/Tools/AtlasToolEvidenceStore.php
  - app/Services/Tools/AtlasToolResultNormalizer.php
  - app/Services/Tools/AtlasToolGateService.php
  - app/Services/Tools/AtlasToolAuthorityMatrixService.php
  - app/Services/Tools/AtlasToolReleaseGateService.php
  - app/Services/Engineering/EngineeringQualityScanService.php
  - app/Services/Engineering/EngineeringApiContractService.php
  - app/Services/Engineering/EngineeringTestMatrixService.php
  - app/Services/Engineering/EngineeringHarnessRunnerService.php
  - app/Services/Engineering/EngineeringCodeIntelligenceService.php
  - app/Console/Commands/AtlasToolsCommand.php
  - app/Console/Commands/AtlasEngineeringApiContractCommand.php
  - app/Console/Commands/AtlasEngineeringVisualSmokeCommand.php
  - app/Http/Controllers/AtlasToolRuntimeController.php
  - database/migrations/2026_05_02_011000_create_atlas_tool_runtime_tables.php
  - tests/Feature/AtlasToolRuntimeCoreTest.php
---

# Atlas Super Tool Runtime Core

Esta camada e a base generica abaixo do Engineering Harness para ferramentas
locais, gratuitas, project-local ou Atlas-managed. Ela evita integrar cada
ferramenta como fluxo isolado.

## Fase 0 Implementada

O bloco inicial entrega:

- `atlas_tool_definitions` como catalogo persistente de ferramentas;
- `atlas_tool_installations` como estado detectado por workspace e camada;
- `atlas_tool_policies` para overrides por escopo;
- `atlas_tool_runs`, `atlas_tool_artifacts` e `atlas_tool_findings` como Evidence Store transversal;
- catalogo inicial para Git, ripgrep, Composer, Pint, PHPStan, Psalm, TypeScript, Biome, ESLint, ShellCheck, Hadolint, Gitleaks, Semgrep, Playwright, Cypress, Docker, Trivy, Syft, Grype, OSV-Scanner e sensores internos `atlas_code_intelligence`, `atlas_visual_smoke` e `atlas_api_contract`;
- `AtlasToolPolicyEngine` com decisoes auditaveis `allowed`, `denied`, `requires_approval` e `skipped`;
- Policy Engine considera risco, rede, custo, tier, sandbox, privacidade, task type e provider-safe; ferramentas que podem escrever no workspace exigem `worktree`/`docker` ou approval, e outputs inseguros para provider podem ser bloqueados quando o consumidor exige `requires_provider_safe`;
- `AtlasToolExecutor` com cwd controlado, argv array, timeout, dry-run, env seguro por allowlist de chamada, limite auditavel de stdout/stderr, output redigido e rejeicao de argumentos inseguros;
- `AtlasToolResultNormalizer` com contrato comum de status, findings, metrics, artifacts, recommendations e blocking failures;
- normalizacao estruturada compartilhada para outputs JSON de Gitleaks, Semgrep, ESLint, PHPStan, Psalm, ShellCheck, Trivy, OSV-Scanner e Grype;
- `AtlasToolEvidenceStore` para persistir runs, artifacts, hashes e findings normalizados;
- `AtlasToolEvidenceQueryService` para consultar evidencias recentes com filtros por workspace, tool, surface, status, policy decision, required e contexto, alem de carregar/exportar uma run por ID;
- `AtlasToolGateService` para avaliar evidencias normalizadas e produzir gate `passed`, `warning` ou `blocked`, incluindo freshness opcional por idade maxima de evidencia;
- `AtlasToolAuthorityPolicyService` para publicar o contrato auditavel de severidade por grupo de autoridade usado pelo gate;
- `AtlasToolReleaseGateService` para aplicar um release gate Security/SBOM sobre evidencias persistidas, exigindo secret scan, static security scan, dependency vulnerability scan, SBOM normalizado e freshness de release;
- `AtlasToolFindingWaiverService` para conceder/revogar waivers auditaveis de findings bloqueantes, com motivo, operador, origem, TTL opcional e historico;
- CLI `atlas tools doctor|list|authority|authority-policies|set-authority-policy|revoke-authority-policy|status|commands|run|run-recipe|evidence|evidence-show|evidence-export|gate|release-gate|approve|revoke|waive-finding|revoke-finding-waiver|policies`;
- CLI operacional do Engineering Harness tambem expoe `atlas engineering security-scan` e `atlas engineering sbom`, ambos reutilizando o Quality Scan e o Evidence Store genericos com filtro de tools;
- API `GET /tools`, `GET /tools/doctor`, `GET /tools/authority`, `GET /tools/authority/policies`, `PUT /tools/authority/policies/{authorityGroup}`, `DELETE /tools/authority/policies/{authorityGroup}`, `GET /tools/evidence`, `GET /tools/evidence/{run}`, `GET /tools/evidence/{run}/export`, `GET /tools/gate`, `GET /tools/release-gate`, `GET /tools/policies`, `GET /tools/{tool}`, `GET /tools/{tool}/commands`, `POST /tools/{tool}/commands/{recipe}/run`, `POST /tools/{tool}/run`, `POST /tools/{tool}/approval`, `DELETE /tools/{tool}/approval`, `POST /tools/findings/{finding}/waiver` e `DELETE /tools/findings/{finding}/waiver`;
- API operacional do Engineering Harness tambem expoe `POST /engineering/security-scan` e `POST /engineering/sbom`, protegidos por `atlas.token`, com o mesmo contrato filtrado dos comandos CLI;
- `AtlasToolApprovalService` para aprovacoes auditaveis por workspace/global, TTL, motivo, operador, permissao de rede e revogacao sem apagar historico;
- aprovacoes auditaveis tambem persistem guardrails de policy (`max_execution_tier`, `sandbox_mode`, `privacy_level`, `task_type`, `requires_provider_safe`), evitando que approval vire permissao irrestrita;
- integracao do `EngineeringQualityScanService` gravando evidencias no runtime generico, incluindo `run_context_type`/`run_context_id` quando chamado por automacao;
- Quality Scan em perfil `standard/release/deep` aciona scanners locais de seguranca e supply chain pelo mesmo contrato: OSV-Scanner quando ha lockfile/manifesto de dependencia, e Trivy, Syft e Grype nos perfis `release/deep`;
- outputs JSON de OSV-Scanner, Trivy, Syft e Grype tambem produzem metricas normalizadas; Syft publica resumo de SBOM em `artifacts` normalizados sem expor stdout bruto;
- integracao do `EngineeringTestMatrixService` espelhando o resultado capturado de quality scan para o Evidence Store quando a evidencia do subprocesso ainda nao esta visivel, evitando gaps em bancos de teste em memoria e em execucoes isoladas;
- integracao do `EngineeringHarnessRunnerService` gravando o controle `atlas_tool_runtime_gate` por run; o controle usa `AtlasToolGateService` filtrado por `workspace`, `surface=engineering_quality_scan`, `run_context_type=engineering_run` e `run_context_id`;
- integracao do `AtlasEngineeringVisualSmokeCommand` como sensor interno `atlas_visual_smoke`, com manifest, route artifacts, contexto de `engineering_run` quando chamado por automacao e findings para falha de rota/baseline/screenshot;
- integracao do Visual Smoke gerenciado com o controle `atlas_tool_runtime_visual_gate`, avaliando evidencias `engineering_visual_smoke` do run pelo `AtlasToolGateService`;
- integracao do `EngineeringCodeIntelligenceService` como analyzer interno `atlas_code_intelligence`, registrando index/audit, metricas de modulos/simbolos/doc links, contexto opcional de runtime e findings de drift.
- integracao inicial do API Contract Harness como sensor interno `atlas_api_contract`, detectando OpenAPI JSON/YAML, validando estrutura minima, comparando paths/metodos contra rotas Laravel, exigindo `responses` por operation, gerando findings por endpoint e persistindo evidencia em `engineering_api_contract`.
- politica de produto T0-T3 implementada no registry: `atlas_tool_definitions` agora guarda `execution_tier`, `expected_cost`, `default_trigger`, `authority_role` e `authority_group`; `AtlasToolPolicyEngine` inclui esses campos na decisao auditavel e respeita `max_execution_tier` em CLI/API para impedir que ferramentas T2/T3 rodem em fluxos T0/T1.
- roadmap de Programming Power Tools semeado no Tool Registry como ferramentas opcionais: Serena, Tree-sitter, ast-grep, ctags, CodeQL, Infer, Checkov, Terrascan, kube-linter, kube-score, Dockle, ScanCode, ORT, licensee, Rector, PHPMD, PHPCPD, Composer Require Checker, Composer Unused, Knip, ts-prune, Schemathesis, Pact, Prism, WireMock, Bruno, Infection, Stryker, fast-check, axe-core, Pa11y, Lighthouse CI, dependency-cruiser, Madge, Deptrac, Aider, Continue e OpenHands. Ferramentas ausentes continuam `missing`/`skipped` e nao viram dependencia obrigatoria.
- `AtlasToolAuthorityMatrixService` expoe a matriz operacional por CLI/API com resumo por tier, grupos de autoridade, papeis `primary|primary_or_complementary|complementary|fallback|executor`, ferramentas high-risk/release-heavy e recomendacoes de governanca.
- `AtlasToolAuthorityPolicyService`, `atlas tools authority-policies --json` e `GET /tools/authority/policies` expoem as regras de gate por authority group: severidades bloqueantes, severidades de warning, origem (`default`, `workspace`, `global`) e razoes auditaveis usadas em `blocking_failures`/`warnings`.
- Overrides operacionais de authority gate usam `atlas_tool_policies` com `tool_slug=authority:<group>` e `thresholds_json.schema=atlas.tool_authority_policy_override.v1`; podem ser aplicados por `atlas tools set-authority-policy <group>` ou `PUT /tools/authority/policies/{authorityGroup}` e revogados por CLI/API sem apagar historico.
- Tool Registry agora publica `safe_commands` por ferramenta e `atlas tools commands <tool>`/`GET /tools/{tool}/commands`, expondo command recipes auditaveis com argv, dry-run default, categoria, surface recomendada, se cria evidencia, se pode bloquear, tier, sandbox, privacidade, task type, rede e provider-safe.
- O primeiro pacote de recipes P0 reais foi promovido no registry: `gitleaks detect-redacted`, `semgrep scan-json`, `osv_scanner recursive-json`, `syft sbom-json`, `trivy fs-json`, `phpstan analyse-json`, `typescript no-emit`, `eslint lint-json`, `laravel_pint format-test`, `biome ci-json`, `hadolint dockerfile-json` e `checkov directory-sarif`.
- recipes podem ser executadas diretamente por `atlas tools run-recipe <tool> --recipe=<name>` e `POST /tools/{tool}/commands/{recipe}/run`, herdando argv e guardrails do registry e registrando `metadata_json.recipe` na evidencia.
- painel Engineering do app consome `GET /tools/authority` junto com doctor/evidence/gate, mostrando distribuicao T0-T3, recomendacoes, lacunas de primaria, coautoridade e grupos relevantes diretamente no card Super Tool Runtime.
- painel Engineering do app tambem consome `GET /tools/authority/policies`, mostrando contratos bloqueantes/warning e melhorando a explicabilidade de cada gate issue com authority group, policy e severidade.
- painel Engineering agora tambem opera overrides workspace de authority policy: `Medium bloqueia` endurece um grupo para bloquear critical/high/medium e avisar low; `Revogar` remove o override workspace e volta para a policy default/global efetiva.
- client do app expoe `runAtlasTool(tool, input)` com o contrato completo de execucao (`command`, `dry_run`, `approved`, `network_allowed`, `max_execution_tier`, `sandbox_mode`, `privacy_level`, `task_type`, `requires_provider_safe`, `env`, `output_limit`) e as linhas de evidencia exibem tier/sandbox/privacy/task/provider-safe a partir de `policy_decision_json`.
- client do app tambem expoe `listAtlasToolPolicies`, `approveAtlasTool` e `revokeAtlasToolApproval` com guardrails de approval (`max_execution_tier`, `sandbox_mode`, `privacy_level`, `task_type`, `requires_provider_safe`) e helper para renderizar policy auditavel.
- painel Engineering do app exibe `Approval policies` no card Super Tool Runtime, consumindo `GET /tools/policies` com o workspace ativo e mostrando status, escopo, TTL e guardrails persistidos para auditoria operacional.
- painel Engineering tambem opera actions seguras por ferramenta: `Dry-run` registra evidencia auditavel sem executar comando real, `Aprovar 2h` cria approval de workspace com TTL curto, sem rede e guardrails conservadores, e `Revogar` encerra a approval sem apagar historico.
- action `Dry-run` do app executa a command recipe declarada pelo registry via endpoint de recipe, em vez de montar ou enviar argv ad hoc na UI.
- evidencias geradas por recipe registram `metadata_json.recipe_category`, `recipe_recommended_surface`, `recipe_creates_evidence` e `recipe_blocking_capable`, permitindo gates e UI diferenciarem diagnostico, scan, refactor e release.
- execucoes por recipe gravam a `surface` operacional recomendada pela propria recipe (`manual_diagnostic`, `engineering_quality_scan` ou `release_gate`) e preservam a origem de chamada em `metadata_json.execution_origin` (`cli_recipe`, `api_recipe` ou outra origem auditavel). Assim uma recipe de release executada diretamente por CLI/API entra no gate correto sem surface manual.
- `AtlasToolResultNormalizer` agora tambem parseia diagnosticos textuais do TypeScript (`TSxxxx`), JSON do Laravel Pint, JSON do Biome, JSON do Hadolint e SARIF generico para CodeQL/Checkov, convertendo arquivo, linha, rule id, mensagem, severidade e bloqueio para findings normalizados.
- `AtlasToolGateService` respeita `metadata_json.recipe_blocking_capable=false`: falha de status em recipe diagnostica vira warning (`non_blocking_recipe_failed`) em vez de bloqueio, enquanto policy denial, `requires_approval` e findings bloqueantes continuam bloqueando normalmente.
- Evidence Store e gates aceitam filtros por recipe: `recipe`, `recipe_category`, `recipe_recommended_surface` e `recipe_blocking_capable`, disponiveis em CLI/API para separar diagnosticos de scans bloqueantes sem heuristica. Filtros internos tambem aceitam listas de `surface`, permitindo o `release-gate` consumir `engineering_quality_scan` e `release_gate` por padrao.
- `AtlasToolGateService` aplica thresholds por `authority_group`: secret scan bloqueia qualquer segredo confirmado; SAST, dependency vulnerability, vulnerability scan, IaC, container lint, type/static analysis, formatter, API contract, visual, accessibility e architecture bloqueiam critical/high e promovem medium para warning; SBOM bloqueia critical e deixa severidades menores como warning no gate generico.
- Quando existe override workspace/global valido, o gate usa a policy efetiva do `AtlasToolAuthorityPolicyService`; defaults continuam no codigo como baseline seguro e todo override preserva fonte, motivo e schema auditavel.
- `AtlasToolGateService` aceita `max_age_minutes` e `stale_blocks`; evidencia stale vira warning no gate generico quando `stale_blocks=false` e bloqueio quando `stale_blocks=true`. O payload publica `freshness`, `summary.stale_evidence_count` e `runs[].evidence_age_minutes`.
- `AtlasToolGateService` aceita `latest_per_tool`; quando ativo, o gate avalia apenas a evidencia mais nova por `tool_slug`, publica `selection.latest_per_tool` e preserva `summary.input_run_count` para auditoria.
- `AtlasToolReleaseGateService` aplica freshness por padrao e sempre usa `latest_per_tool`: release evidence com mais de 1440 minutos bloqueia, mas evidencias antigas da mesma ferramenta nao bloqueiam se uma evidencia mais nova e valida existir.
- `atlas help` e `bin/atlas-completion.bash` incluem `atlas tools authority`, `--tool-env`, `--output-limit`, `--max-execution-tier`, `--sandbox-mode`, `--privacy-level`, `--task-type` e `--requires-provider-safe`, reduzindo dependência de memória/documentação externa para operar o runtime.
- matriz de autoridade anti-duplicacao iniciada em `AtlasToolFindingCorrelationService`: o gate correlaciona findings bloqueantes entre ferramentas do mesmo `authority_group` por localizacao/titulo ou fingerprint, escolhe o achado autoritativo por `authority_role`/severidade e suprime duplicatas apenas na contagem de bloqueio. A evidencia original permanece intacta no Evidence Store.

## Como Registrar Nova Ferramenta

1. Adicione a definition em `AtlasToolDefinitionCatalog`.
2. Declare tipo, categoria, capacidades, camadas, riscos, timeout e failure policy.
3. Garanta que `atlas tools doctor --workspace=<repo> --json` mostre `ready`, `missing` ou `skipped` com motivo claro.
4. Se a ferramenta executa comando novo, comece por `atlas tools run <slug> --command=<argv> --dry-run --json`.
5. Adicione normalizacao especifica apenas quando o output estruturado justificar; ate la, use o contrato generico.
6. Escreva teste com binary fake em PATH ou no workspace.

## Roadmap De Ferramentas Para Programacao Pesada

O Atlas deve ser o control-plane de engenharia: ferramentas entram como
capabilities registradas, governadas por policy, executadas com sandbox,
normalizadas e avaliadas por gates. Nenhuma ferramenta abaixo deve virar
dependencia obrigatoria paga. Ferramentas ausentes geram `skipped` auditavel.

### P0 - Camada Semantica De Codigo

Esta e a maior lacuna para transformar o Atlas em uma ferramenta pesada de
programacao. O Code Intelligence interno ja indexa modulos, simbolos, rotas,
comandos, migrations, testes e doc links; a proxima camada deve adicionar
navegacao/edicao semantica de IDE.

| Ferramenta | Categoria Atlas | Papel | Postura |
|---|---|---|---|
| Serena | `semantic_code_intelligence` | LSP/MCP para simbolos, referencias e edicao semantica | opcional, open-source |
| Tree-sitter graph interno | `code_graph` | grafo canonico local, AST, impacto e comunidades | core futuro |
| ast-grep | `structural_search` | query/refactor AST multi-linguagem | opcional |
| ctags/universal-ctags | `symbol_index` | indice simbolico leve | opcional |
| Semantiq/Vera/Codebase-Memory-like search | `semantic_search` | busca semantica local/token-efficient | opcional |
| JetBrains MCP | `ide_bridge` | capacidades IDE quando o operador usa JetBrains | opcional |
| VS Code MCP | `ide_bridge` | capacidades IDE quando o operador usa VS Code | opcional |

Contrato esperado:

- separar capabilities de leitura (`symbol_lookup`, `find_references`,
  `impact_analysis`, `semantic_search`) e escrita (`rename_symbol`,
  `edit_symbol`, `apply_refactor`);
- leitura sem rede deve ser `medium` ou menor; escrita deve ser `high` e exigir
  approval quando automatizada;
- evidence deve registrar query, simbolos retornados, arquivos afetados, hashes
  e recomendacoes, nunca segredos ou artefatos privados brutos;
- Serena/MCP acelera o agente, mas a autoridade final continua sendo o indice,
  diff, testes, gates e memoria canonica do Atlas.

### P0 - Qualidade, Tipos E Analise Estatica

| Ferramenta | Categoria Atlas | Papel |
|---|---|---|
| PHPStan/Psalm/Psalm taint | `static_analysis` | tipos, contratos e fluxo de dados perigoso |
| Laravel Pint/Biome/Prettier | `formatter` | estabilizar diffs |
| ESLint/TypeScript | `lint_typecheck` | qualidade e tipos TS/JS |
| Rector | `mechanical_refactor` | upgrades/refactors PHP com dry-run |
| PHP Mess Detector | `maintainability` | smells e complexidade |
| PHPCPD | `duplication` | copia de codigo |
| Composer Require Checker | `dependency_hygiene` | deps usadas sem declarar |
| Composer Unused | `dependency_hygiene` | deps declaradas sem uso |
| Knip/ts-prune | `dead_code` | exports, arquivos e deps nao usados em TS/JS |

### P0 - Seguranca, Supply Chain E Compliance

| Ferramenta | Categoria Atlas | Papel |
|---|---|---|
| Gitleaks | `secret_scan` | bloquear vazamento de segredo |
| Semgrep | `sast` | bug patterns e security rules |
| CodeQL CLI | `semantic_sast` | queries semanticas profundas e SARIF |
| OSV-Scanner | `dependency_vulnerability_scan` | vulnerabilidades por lockfile |
| Trivy/Grype | `vulnerability_scan` | filesystem/container/deps |
| Syft | `sbom` | inventario de componentes |
| Checkov/Terrascan | `iac_security` | Terraform/Kubernetes/CloudFormation |
| kube-linter/kube-score | `kubernetes_policy` | manifests e operacao Kubernetes |
| Dockle | `container_hardening` | lint/hardening de imagens Docker |
| ScanCode Toolkit/ORT/licensee | `license_compliance` | licencas e compliance OSS |

### P1 - Contratos, APIs E Mocking

| Ferramenta | Categoria Atlas | Papel |
|---|---|---|
| Schemathesis | `api_contract_fuzzing` | property-based testing de OpenAPI/GraphQL |
| Pact | `consumer_provider_contract` | compatibilidade cliente/provedor |
| Prism | `openapi_validation_mock` | mock e validacao OpenAPI |
| WireMock | `external_api_mock` | simulacao de terceiros |
| Bruno | `api_collection` | colecoes API locais em arquivo |

O sensor interno `atlas_api_contract` permanece como base gratuita/local. Essas
ferramentas entram depois como executores externos normalizados, nao como fluxo
paralelo.

### P1 - Testes Profundos

| Ferramenta | Categoria Atlas | Papel |
|---|---|---|
| Infection | `mutation_testing` | mede se testes PHP realmente detectam bugs |
| Stryker | `mutation_testing` | mutation testing JS/TS |
| fast-check/Hypothesis | `property_testing` | casos gerados para parsers/policies/normalizers |
| PHPUnit/Pest/Vitest/Jest coverage | `coverage` | cobertura por arquivo tocado |
| Playwright trace analyzer | `e2e_diagnostics` | diagnostico reproduzivel de UI |

Mutation testing deve rodar em `deep`/release de alto risco, nao em todo ciclo
rapido. Mutants sobreviventes viram findings e artifacts.

### P1 - Frontend, Acessibilidade E Produto

| Ferramenta | Categoria Atlas | Papel |
|---|---|---|
| axe-core | `accessibility` | falhas a11y critical/serious |
| Pa11y | `accessibility_smoke` | a11y por rota |
| Lighthouse CI | `frontend_performance` | performance, best practices e budgets |
| bundle analyzer/source-map-explorer | `bundle_analysis` | tamanho e composicao de bundle |
| pixelmatch | `visual_diff` | regressao visual local sem SaaS |

### P1 - Arquitetura E Boundaries

| Ferramenta | Categoria Atlas | Papel |
|---|---|---|
| Deptrac | `architecture_boundary` | camadas e dependencias PHP |
| dependency-cruiser/Madge | `architecture_boundary` | ciclos e imports JS/TS |
| OpenRewrite/comby/jscodeshift/ts-morph | `mechanical_refactor` | transformacoes seguras por lote |
| custom Atlas boundary rules | `atlas_architecture_policy` | regras especificas do Harness/Memory/App |

### P2 - Agentes De Codigo Opcionais

| Ferramenta | Categoria Atlas | Papel |
|---|---|---|
| Aider | `external_coding_agent` | repo-map, edicao multi-arquivo e benchmark comparativo |
| Continue CLI/IDE | `external_coding_agent` | assistente open-source IDE/CLI |
| OpenHands | `external_coding_agent` | agente autonomo open-source para comparacao |

Esses agentes nao devem substituir o Atlas. Eles entram como providers de
capacidade, com policy forte: worktree por padrao, dry-run quando possivel,
sem rede/segredos sem approval, diff auditavel, testes obrigatorios e Evidence
Store como contrato comum.

## Politica De Execucao Por Tier

A lista de ferramentas so e produto quando existe custo de execucao explicito.
O Atlas nao deve rodar ferramentas lentas no mesmo gate de ferramentas
interativas. Cada tool definition deve declarar `execution_tier`, `expected_cost`
e `default_trigger`.

| Tier | Quando roda | Budget alvo | Exemplos | Pode bloquear? |
|---|---|---:|---|---|
| T0 interactive | durante planejamento, leitura e edicao incremental | <1s por query ou resposta incremental | LSP/Serena leitura, ripgrep, ast-grep query, symbol lookup, repo-map cache | nao por si so; orienta contexto |
| T1 local fast | antes de concluir patch local ou replay curto | <30s por workspace pequeno/escopo tocado | format check, lint escopado, typecheck incremental, Deptrac pequeno, coverage por arquivo tocado, secret scan em diff | sim, para erros diretos e escopados |
| T2 PR/review | antes de merge, benchmark ou run de risco medio/alto | <10min | CodeQL, Semgrep full, Schemathesis, axe por rotas tocadas, dependency-cruiser, full typecheck, Docker smoke | sim, com policy por severidade |
| T3 nightly/release | release, nightly, auditoria ou mudanca critica | minutos a horas | Infection/Stryker, Lighthouse full, license audit, ScanCode/ORT, Trivy filesystem/container full, performance load, mutation suite | sim, normalmente com waiver/approval |

Regras:

- T0 nunca deve executar escrita sem approval explicito.
- T1 deve preferir changed-files scope e falhar rapido.
- T2 pode usar cache, worktree e artifacts persistidos; deve ser assinalado como
  `required` apenas quando a task/risk profile justificar.
- T3 nao deve bloquear fluxo interativo; bloqueia release/nightly ou gera debt
  priorizado.
- O `AtlasToolPolicyEngine` deve poder elevar ou rebaixar tier por workspace,
  task type, risco, superficie, historico de flakiness e disponibilidade de
  cache.
- O app/CLI deve mostrar quando um gate ficou lento por decisao de policy, nao
  por surpresa operacional.

Implementado inicialmente:

```bash
atlas tools list --json
atlas tools authority --json
atlas tools commands ripgrep --workspace=<repo> --json
atlas tools run-recipe ripgrep --recipe=version --workspace=<repo> --json
atlas tools evidence ripgrep --recipe=version --recipe-category=diagnostic --recipe-blocking-capable=false --workspace=<repo> --json
atlas tools doctor --workspace=<repo> --json
atlas tools run codeql --workspace=<repo> --command=codeql --command=--version --approved --network-allowed --max-execution-tier=T1 --json
atlas tools run ripgrep --workspace=<repo> --command=rg --command=--version --tool-env=ATLAS_TOOL_MODE=fixture --output-limit=12000 --json
atlas tools run ast_grep --workspace=<repo> --command=ast-grep --command=--version --sandbox-mode=worktree --task-type=refactor --privacy-level=standard --json
```

O ultimo exemplo registra uma run `skipped` com motivo
`execution_tier_above_policy_budget`, porque `codeql` e T2 e o operador limitou
o budget a T1. A mesma politica esta disponivel em `POST /tools/{tool}/run` via
payload `max_execution_tier` e `network_allowed`.

Execucoes podem receber env explicito apenas por contrato seguro. No CLI use
`--tool-env=KEY=VALUE`, porque `--env` e reservado pelo Artisan. Na API use
`env` como objeto ou lista de `KEY=VALUE`. Chaves sensiveis como token, secret,
password, cookie, credential, auth, bearer, private key e API key sao rejeitadas
antes da execucao. `output_limit` controla quanto de stdout/stderr redigido e
persistido por stream; metadados `env_keys`, `output_limit`,
`stdout_truncated` e `stderr_truncated` ficam no Evidence Store.

Dimensoes de policy disponiveis no CLI/API:

- `sandbox_mode`: `workspace`, `worktree`, `docker`, `host` ou `none`.
- `privacy_level`: `standard`, `sensitive` ou `restricted`.
- `task_type`: identificador curto como `manual`, `quality_scan`,
  `security_scan`, `refactor`, `release_gate` ou `agent_execution`.
- `requires_provider_safe`: quando true, outputs marcados como sensiveis para
  provider/modelo geram `skipped` ou `requires_approval`.

Esses campos aparecem em `policy_decision_json` para auditoria. A regra pratica
e: leitura local barata deve fluir; rede, escrita e material sensivel precisam
de sandbox, approval ou waiver explicito.

Approvals nao substituem policy. `atlas tools approve <tool>` pode gravar TTL,
rede e guardrails:

```bash
atlas tools approve codeql --workspace=<repo> --network-allowed --max-execution-tier=T2 --ttl-hours=24 --json
atlas tools approve ast_grep --workspace=<repo> --sandbox-mode=worktree --task-type=refactor --ttl-hours=2 --json
atlas tools approve gitleaks --workspace=<repo> --requires-provider-safe --ttl-hours=1 --json
```

Runs posteriores herdam esses metadados quando o operador nao passa override no
run. Isso e intencional: uma policy pode aprovar CodeQL para PR/release sem
permitir que ele rode em budget T0/T1, ou aprovar ast-grep apenas quando a
execucao efetiva acontece em worktree.

`atlas tools authority --json` e `GET /tools/authority` retornam o diagnostico
operacional da politica de produto: `summary`, `tiers`, `authority_groups` e
`recommendations`. O payload mostra primarias, complementares, fallbacks,
executores, ferramentas high-risk/release-heavy e lacunas como grupos sem
primaria ou agentes externos que precisam permanecer atras do boundary do Atlas.

## Matriz De Autoridade Anti-Duplicacao

Ferramentas sobrepostas sao uteis quando o Atlas declara quem manda. Cada
categoria deve ter uma ferramenta primaria, ferramentas complementares e
fallbacks. Findings duplicados devem ser correlacionados por fingerprint, arquivo,
linha, regra e categoria.

| Categoria | Autoridade primaria | Complementares | Fallback | Regra de bloqueio |
|---|---|---|---|---|
| PHP static analysis | PHPStan ou Psalm, conforme repo | Psalm taint, PHP Mess Detector | `composer test`/PHPUnit failures | primaria bloqueia; complementares bloqueiam apenas high/critical ou policy explicita |
| TS/JS type/lint | `tsc` + ESLint/Biome conforme repo | Knip, ts-prune | npm scripts detectados | type/lint bloqueia em T1/T2; dead-code vira warning salvo release policy |
| Architecture PHP | Deptrac | custom Atlas boundary rules | Code Intelligence drift | boundary nova bloqueia; debt antigo requer baseline/waiver |
| Architecture TS/JS | dependency-cruiser | Madge | custom import scan | ciclos novos bloqueiam em core; ciclos existentes viram debt |
| Semantic SAST | CodeQL | Semgrep, Psalm taint | pattern scan Atlas | critical/high bloqueia em T2/T3; duplicatas nao contam duas vezes |
| Secret scan | Gitleaks | Trivy secret scan | Atlas redaction scanner | qualquer segredo real bloqueia T1+ |
| Dependency vulnerability | OSV-Scanner | Trivy, Grype | package manager audit quando local | critical/high bloqueia release sem waiver |
| SBOM | Syft | Trivy SBOM quando disponivel | package lock inventory | release exige artifact SBOM quando policy `release` |
| API contract | `atlas_api_contract` para diff estrutural | Schemathesis, Pact, Prism, WireMock, Bruno | feature tests | operation quebrada bloqueia; fuzz/compat entra por tier |
| Acessibilidade | axe-core | Pa11y | Playwright DOM checks | critical/serious em rota tocada bloqueia T2 |
| Performance frontend | Lighthouse CI | bundle analyzer, source-map-explorer | bundle size script | bloqueia por budget, nao por score absoluto generico |
| Visual regression | Playwright screenshots + pixelmatch | trace analyzer | DOM snapshot | diff strict bloqueia; observe vira warning |
| Mutation testing | Infection/Stryker | coverage reports | targeted tests | T3 bloqueia release apenas quando policy exige mutation score |
| License compliance | ScanCode/ORT | licensee | package metadata | copyleft/prohibited license bloqueia release por policy |

Regras:

- Um achado correlacionado nao deve aparecer como tres bloqueios independentes.
- A ferramenta primaria define severidade default; complementares podem elevar
  severidade quando trazem evidencia mais precisa.
- Quando duas ferramentas discordam, o Evidence Store preserva ambas, mas o gate
  usa a `authority_matrix` para decidir bloqueio.
- Baselines sao permitidas para debt legado; novas violacoes devem ser
  separadas de legado.
- Waiver deve apontar para finding/fingerprint e nao para "desligar ferramenta".

Implementado inicialmente:

- `AtlasToolFindingCorrelationService` agrupa findings bloqueantes nao-waived por
  `authority_group`.
- `AtlasToolGateService` retorna `finding_correlations` e os contadores
  `correlated_finding_group_count` e `suppressed_duplicate_finding_count`.
- Duplicatas sao suprimidas somente do gate; `atlas_tool_findings` preserva
  todos os achados e artifacts.

## Contrato Para Agentes Externos

Aider, Continue, OpenHands, Serena/MCP com escrita e agentes semelhantes so
entram como executores governados. O produto final desejado e: Atlas indexa,
governa, valida e aprende; agentes externos podem editar dentro desse envelope.

Contrato minimo:

- `Atlas` fornece contexto: Code Intelligence, semantic graph, task contract,
  recent evidence refs, policy, selected files e constraints.
- `Agent` executa em worktree/sandbox, produz patch, plano, arquivos tocados,
  comandos rodados e rationale resumido.
- `Atlas` valida: diff scope, tests, quality scan, security scan, API/visual
  contract, architecture boundaries e release gate conforme tier.
- `Atlas` persiste: tool run, artifacts, findings, patch metadata, stdout/stderr
  redigidos, custo, duracao e decisao de gate.
- `Agent` nunca recebe secrets, artifacts privados brutos ou rede sem approval.
- `Agent` nunca marca run como resolvido; so o Harness/Gate/Operator decidem.
- Quando MCP/IDE bridge estiver disponivel, agentes externos devem consumir o
  indice do Atlas; quando nao estiver, ainda podem rodar, mas perdem autoridade e
  ficam sujeitos a validacao mais conservadora.

Essa regra impede que o Atlas vire apenas "mais um launcher de agentes". O Atlas
continua sendo o substrato: indice, memoria, policies, evidencias, gates e
aprendizado.

## Aprovacoes Auditaveis

Ferramentas `high`/`critical`, ferramentas nao gratuitas ou ferramentas que podem
usar rede nao devem ser liberadas por flag solta em automacao recorrente. O fluxo
operacional e:

```bash
atlas tools approve gitleaks --workspace=<repo> --reason="release scan" --ttl-hours=24 --json
atlas tools policies --workspace=<repo> --json
atlas tools run gitleaks --workspace=<repo> --command=gitleaks --command=detect --json
atlas tools revoke gitleaks --workspace=<repo> --json
```

A policy fica em `atlas_tool_policies.metadata` com `approved_at`,
`approved_until`, `approved_by`, `approval_reason`, `network_allowed` e
`workspace_hash`. A decisao emitida em `atlas_tool_runs.policy_decision_json`
inclui `approval_status`, preservando auditoria mesmo quando a aprovacao expira
ou e revogada.

## Waivers De Findings

Waiver e excecao sobre um finding persistido, nao aprovacao para executar uma
ferramenta. Ele existe para falsos positivos, risco aceito por tempo limitado ou
mitigacao compensatoria. O finding permanece no Evidence Store com
`status=waived`, `waiver_id` e `metadata_json.waiver`; o historico anterior fica
em `metadata_json.waiver_history`.

```bash
atlas tools waive-finding --finding-id=<finding-id> --reason="false positive in generated file" --ttl-hours=24 --json
atlas tools revoke-finding-waiver --finding-id=<finding-id> --reason="risk accepted no longer valid" --json
```

A API equivalente e:

```http
POST /tools/findings/{finding}/waiver
DELETE /tools/findings/{finding}/waiver
```

Payload aceito no `POST`: `reason` e `ttl_hours`. Waiver sem TTL fica valido ate
revogacao explicita; waiver com TTL expirado deixa de suprimir bloqueio. O export
`atlas.tool_evidence.v1` inclui `waiver_id`, `status` e o resumo sanitizado do
waiver para auditoria externa.

## Consulta De Evidencias

O Evidence Store deve ser consultavel por automacoes, app e CLI sem varrer runs
globais. Use filtros estreitos quando estiver analisando um workspace ou run:

```bash
atlas tools evidence --workspace=<repo> --status=failed --json
atlas tools evidence semgrep --workspace=<repo> --surface=engineering_quality_scan --json
atlas tools evidence --workspace=<repo> --context-type=engineering_run --context-id=<run-id> --json
atlas tools evidence-show --run-id=<tool-run-id> --workspace=<repo> --json
atlas tools evidence-export --run-id=<tool-run-id> --workspace=<repo> --json
```

A API equivalente aceita `workspace`, `tool_slug`, `surface`, `status`,
`policy_decision`, `run_context_type`, `run_context_id`, `required` e `limit` em
`GET /tools/evidence`. O app Engineering usa o mesmo filtro de workspace do
doctor para evitar misturar evidencias de repositorios diferentes.

Para auditoria pontual, `GET /tools/evidence/{run}` retorna a run com artifacts
e findings carregados. `GET /tools/evidence/{run}/export` retorna um envelope
`atlas.tool_evidence.v1` com integridade: hash do comando, hash do workspace,
hashes dos artifacts e fingerprints de findings. O export nao devolve conteudo
bruto de artifact, paths absolutos nem previews por padrao; ele publica
metadados sanitizados e hashes. No CLI, `evidence-show`/`evidence-export`
podem buscar diretamente por run id sem `--workspace`; quando `--workspace` e
informado, a consulta fica explicitamente limitada ao hash daquele workspace.

A tela Engineering tambem mostra, em modo somente leitura, o resumo de Evidence
Gate para o mesmo workspace: status, decisao liberado/bloqueado, runs avaliadas,
tools, bloqueios, avisos e o primeiro detalhe acionavel. A formatacao vive em
helpers testaveis no app para manter o contrato da API separado da apresentacao.
O painel possui dois modos: `observacao`, que usa `require_evidence=false` e
trata ausencia de evidencia como warning, e `release`, que usa
`require_evidence=true` e transforma ausencia de evidencia em bloqueio.

O Engineering Context Pack tambem consome o Evidence Store em modo somente
leitura. Cada pack recebe `tool_evidence_refs` recentes do workspace, com tool,
surface, status, policy decision, contadores de artifacts/findings e um resumo
limitado dos achados bloqueantes. O pack nao carrega stdout/stderr bruto nem
artefatos completos; ele usa esses sinais para orientar a IA sobre evidencias
operacionais recentes e para adicionar arquivos afetados a `selected_files`.

## Gates De Evidencia

Gates nao executam ferramentas; eles avaliam evidencias persistidas. Isso permite
que o Harness rode sensores em momentos diferentes e depois aplique uma decisao
auditavel sobre o conjunto filtrado.

```bash
atlas tools gate --workspace=<repo> --required-tool=semgrep --require-evidence --json
atlas tools gate semgrep --workspace=<repo> --surface=engineering_quality_scan --json
atlas tools gate --workspace=<repo> --max-age-minutes=240 --stale-blocks --json
atlas tools gate --workspace=<repo> --latest-per-tool --json
```

O gate bloqueia por padrao quando uma run filtrada tem status `failed`,
`timeout`, `requires_approval` ou `denied`, quando a policy decision foi
`requires_approval`/`denied`, quando existe finding com `blocks_resolved=true`
sem waiver valido ou quando o resultado normalizado declara
`blocking_failures`. A API equivalente e `GET /tools/gate`.

Freshness e opcional no gate generico. Use `max_age_minutes` para exigir
evidencia recente e `stale_blocks=true` quando stale deve bloquear em vez de
avisar. O gate retorna `freshness.max_age_minutes`,
`freshness.stale_blocks`, `summary.stale_evidence_count` e
`runs[].evidence_age_minutes`.

Use `latest_per_tool=true`/`--latest-per-tool` quando a decisao deve considerar
somente a ultima evidencia por ferramenta. Isso evita que uma falha antiga
continue bloqueando depois que a mesma ferramenta produziu uma evidencia mais
nova. O gate retorna `selection.latest_per_tool`, `summary.input_run_count` e
`summary.run_count` para deixar claro quantas runs foram descartadas da decisao.

O gate deve deduplicar failures normalizados que ja foram persistidos como
`atlas_tool_findings`, para evitar contagem dupla do mesmo achado. Isso tambem
impede que um finding corretamente waived volte a bloquear pelo espelho em
`normalized_result_json.blocking_failures`. Failures normalizados independentes
continuam entrando como `normalized_blocking_failure`.

No Engineering Harness, `quality_scan=required` ou perfil `release/deep` torna o
controle `atlas_tool_runtime_gate` requerido. O controle falha somente quando o
gate retorna `blocked`; `warning` permanece auditavel em metadata, mas nao
derruba o run quando `gate.allowed=true`, porque ferramentas opcionais ausentes
podem produzir warnings sem violar o contrato normalizado do quality scan.

Para visual, `visual_e2e=required` ou uma matriz visual requerida gera o controle
`atlas_tool_runtime_visual_gate`. Ele filtra `surface=engineering_visual_smoke`
e o `run_context_id` do run. Assim, o gate visual/manual existente continua
valendo, mas a evidencia do sensor Atlas-managed tambem entra no contrato
transversal de ferramentas.

## Release Gate Security/SBOM

O release gate do Super Tool Runtime e uma especializacao auditavel sobre o
Evidence Store. Ele nao executa ferramentas; ele exige que evidencias recentes ja
tenham sido registradas por `quality-scan`, `security-scan`, `sbom` ou uma
automacao equivalente.

```bash
atlas tools release-gate --workspace=<repo> --json
```

A API equivalente e:

```http
GET /tools/release-gate
```

Quando `surface` nao e informado, o release gate avalia evidencias recentes de
`engineering_quality_scan` e `release_gate`. Use `--surface=<surface>` ou
`surface=<surface>` na API apenas para restringir a auditoria a uma surface
especifica.

Por padrao, o release gate bloqueia evidencias stale acima de 24h
(`max_age_minutes=1440`, `stale_blocks=true`). Operadores podem passar
`--max-age-minutes=<n>`/`max_age_minutes=<n>` para ajustar o budget de
freshness por release.

O release gate sempre avalia a ultima evidencia por ferramenta. Historico antigo
continua preservado no Evidence Store, mas nao bloqueia release quando uma run
mais nova da mesma ferramenta satisfaz o contrato.

O perfil atual `security_sbom_release` exige quatro requisitos:

- `secret_scan`: evidencia nao skipped de `gitleaks`;
- `static_security_scan`: evidencia nao skipped de `semgrep`;
- `dependency_vulnerability_scan`: pelo menos uma evidencia nao skipped de `osv_scanner`, `trivy` ou `grype`;
- `sbom_attached`: evidencia de `syft` com `sbom_summary` ou `metrics.package_count`.

O release gate reutiliza `AtlasToolGateService` com `require_evidence=true` e
modo `waiver_aware_failed_runs`. Isso e necessario porque scanners como Semgrep,
Trivy ou Grype podem sair com status `failed` apenas porque encontraram achados.
Se todos os findings bloqueantes persistidos tiverem waiver valido, o status
`failed` desse scanner deixa de bloquear o release; waivers expirados, revogados
ou failures independentes continuam bloqueando.

Code Intelligence tambem pode gravar evidencias com contexto:

```bash
atlas engineering knowledge index-code --workspace=<repo> --run-context-type=engineering_run --run-context-id=<run-id> --json
atlas engineering knowledge audit-code --workspace=<repo> --run-context-type=engineering_run --run-context-id=<run-id> --json
```

Isso nao acopla Code Intelligence ao Runner por padrao; apenas permite que
automacoes e cron jobs associem index/audit a um run, benchmark ou contexto
externo sem criar tabelas paralelas.

## API Contract Harness

O bloco inicial da Fase 4 implementa um sensor interno gratuito,
`atlas_api_contract`, para validar contratos OpenAPI contra as rotas Laravel
carregadas no processo. Ele nao substitui Schemathesis, Pact, WireMock ou Prism;
ele cria a base persistente e auditavel para essas ferramentas entrarem depois
pelo mesmo registry.

```bash
atlas engineering api-contract --workspace=<repo> --spec=docs/openapi.yaml --json
atlas engineering api-contract --workspace=<repo> --strict --json
```

A API equivalente e:

```http
POST /engineering/api-contract
```

Payload minimo:

```json
{"workspace": "/repo"}
```

Campos opcionais: `spec`, `strict`, `run_context_type` e `run_context_id`.

O detector procura `openapi.json`, `openapi.yaml`, `openapi.yml`,
`docs/openapi.*`, `docs/api/openapi.*` e `storage/api-docs/api-docs.json`. A
validacao atual cobre:

- root OpenAPI/Swagger version;
- objeto `paths`;
- operation documentada sem rota Laravel correspondente;
- operation sem `responses`;
- rota Laravel sem documentacao OpenAPI, como finding medium por padrao ou high
  bloqueante com `--strict`;
- equivalencia de nomes de parametros de path, evitando falso positivo para
  `/items/{id}` versus `/items/{itemId}`;
- redaction do spec copiado para artifacts;
- persistencia em `atlas_tool_runs`, `atlas_tool_artifacts` e
  `atlas_tool_findings` com surface `engineering_api_contract`.

O app Expo em `atlas-app/app/engineering.tsx` expoe uma acao operacional
`API Contract` dentro do painel Super Tool Runtime. A acao usa o workspace
informado na tela, roda `POST /engineering/api-contract`, usa `strict` quando o
gate esta em modo release e recarrega registry/evidencias apos a execucao.

Status `skipped` significa que nenhum spec foi encontrado; isso gera
recomendacao `add_openapi_spec`, mas nao falha por padrao. Status `failed`
significa quebra bloqueante do contrato, como operation documentada inexistente,
responses ausentes ou rotas nao documentadas em modo strict.

Validacao focada em 2026-05-03:

- `/opt/homebrew/bin/php -l app/Services/Tools/AtlasToolFindingWaiverService.php`
- `/opt/homebrew/bin/php -l app/Services/Tools/AtlasToolReleaseGateService.php`
- `/opt/homebrew/bin/php -l app/Services/Tools/AtlasToolGateService.php`
- `/opt/homebrew/bin/php artisan test --filter=AtlasToolRuntimeCoreTest` (21 testes, 162 assercoes)
- `/opt/homebrew/bin/php artisan test --filter=AtlasEngineeringQualityScanCommandTest` (7 testes, 64 assercoes)
- `/opt/homebrew/bin/php artisan test --filter=EngineeringHarnessRunnerTest::test_quality_scan_can_run_as_atlas_managed_sensor` (1 teste, 27 assercoes)
- `/opt/homebrew/bin/php artisan test --filter=EngineeringHarnessRunnerTest::test_managed_visual_smoke_satisfies_visual_gate_without_e2e_scripts` (1 teste, 16 assercoes)
- `/opt/homebrew/bin/php artisan test --filter=AtlasEngineeringKnowledgeBaseTest::test_code_intelligence_indexes_modules_symbols_routes_commands_migrations_tests_and_doc_links` (1 teste, 53 assercoes)
- `/opt/homebrew/bin/php artisan test --filter=AtlasEngineeringKnowledgeBaseTest::test_engineering_context_pack_includes_recent_tool_evidence_refs` (1 teste, 7 assercoes)
- `npm run typecheck`
- `npm run test:engineering`

## Normalizacao De Output

O parser canonico vive em `AtlasToolResultNormalizer`. Fluxos internos como
Quality Scan e execucoes diretas por `atlas tools run` devem chamar o mesmo
normalizer para evitar divergencia de findings, severidade, fingerprints e
blocking failures.

No Quality Scan, ferramentas de supply chain seguem postura local/gratuita:

- `osv_scanner` roda em `standard`, `release` e `deep` somente quando o workspace tem lockfile ou manifesto de dependencia reconhecido;
- `trivy`, `syft` e `grype` rodam nos perfis `release` e `deep`;
- ferramentas ausentes continuam gerando `skipped` e recomendacao de instalacao, sem tornar servico pago obrigatorio;
- vulnerabilidades normalizadas de OSV-Scanner, Trivy e Grype entram em `findings` e bloqueiam quando a severidade e `high` ou `critical`.
- `metrics` inclui contagens de pacotes, vulnerabilidades, matches, severidades e bloqueios quando o output estruturado permite.

Comandos dedicados usam o mesmo pipeline, mas reduzem o escopo:

```bash
atlas engineering security-scan --workspace=<repo> --profile=release --json
atlas engineering sbom --workspace=<repo> --profile=release --json
```

`security-scan` executa somente `gitleaks`, `semgrep`, `osv_scanner`,
`trivy` e `grype` quando aplicaveis. `sbom` executa somente `syft`, registrando
o resumo de SBOM em `normalized_result_json.artifacts` e as contagens em
`normalized_result_json.metrics`.

Endpoints equivalentes:

```http
POST /engineering/security-scan
POST /engineering/sbom
```

Payload minimo:

```json
{"workspace": "/repo", "profile": "release"}
```

Campos opcionais: `timeout`, `changed_only` para security scan,
`run_context_type` e `run_context_id`. A resposta usa HTTP 200 mesmo quando o
scan encontra falhas, porque falha de ferramenta/finding faz parte do payload
auditavel; erros HTTP ficam reservados para autenticacao, validacao e transporte.

## Regra Operacional

Ferramenta opcional ausente nao bloqueia conclusao. Ferramenta requerida ausente
ou bloqueada por policy vira evidencia auditavel e deve impedir `resolved` quando
o consumidor declarar esse requisito.
