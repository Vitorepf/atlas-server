---
id: atlas-super-tool-runtime-core
type: engineering_knowledge
title: Atlas Super Tool Runtime Core
status: active
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
- `AtlasToolExecutor` com cwd controlado, argv array, timeout, dry-run, output redigido e rejeicao de argumentos inseguros;
- `AtlasToolResultNormalizer` com contrato comum de status, findings, metrics, artifacts, recommendations e blocking failures;
- normalizacao estruturada compartilhada para outputs JSON de Gitleaks, Semgrep, ESLint, PHPStan, Psalm, ShellCheck, Trivy, OSV-Scanner e Grype;
- `AtlasToolEvidenceStore` para persistir runs, artifacts, hashes e findings normalizados;
- `AtlasToolEvidenceQueryService` para consultar evidencias recentes com filtros por workspace, tool, surface, status, policy decision, required e contexto, alem de carregar/exportar uma run por ID;
- `AtlasToolGateService` para avaliar evidencias normalizadas e produzir gate `passed`, `warning` ou `blocked`;
- `AtlasToolReleaseGateService` para aplicar um release gate Security/SBOM sobre evidencias persistidas, exigindo secret scan, static security scan, dependency vulnerability scan e SBOM normalizado;
- `AtlasToolFindingWaiverService` para conceder/revogar waivers auditaveis de findings bloqueantes, com motivo, operador, origem, TTL opcional e historico;
- CLI `atlas tools doctor|list|status|run|evidence|evidence-show|evidence-export|gate|release-gate|approve|revoke|waive-finding|revoke-finding-waiver|policies`;
- CLI operacional do Engineering Harness tambem expoe `atlas engineering security-scan` e `atlas engineering sbom`, ambos reutilizando o Quality Scan e o Evidence Store genericos com filtro de tools;
- API `GET /tools`, `GET /tools/doctor`, `GET /tools/evidence`, `GET /tools/evidence/{run}`, `GET /tools/evidence/{run}/export`, `GET /tools/gate`, `GET /tools/release-gate`, `GET /tools/policies`, `GET /tools/{tool}`, `POST /tools/{tool}/run`, `POST /tools/{tool}/approval`, `DELETE /tools/{tool}/approval`, `POST /tools/findings/{finding}/waiver` e `DELETE /tools/findings/{finding}/waiver`;
- API operacional do Engineering Harness tambem expoe `POST /engineering/security-scan` e `POST /engineering/sbom`, protegidos por `atlas.token`, com o mesmo contrato filtrado dos comandos CLI;
- `AtlasToolApprovalService` para aprovacoes auditaveis por workspace/global, TTL, motivo, operador, permissao de rede e revogacao sem apagar historico;
- integracao do `EngineeringQualityScanService` gravando evidencias no runtime generico, incluindo `run_context_type`/`run_context_id` quando chamado por automacao;
- Quality Scan em perfil `standard/release/deep` aciona scanners locais de seguranca e supply chain pelo mesmo contrato: OSV-Scanner quando ha lockfile/manifesto de dependencia, e Trivy, Syft e Grype nos perfis `release/deep`;
- outputs JSON de OSV-Scanner, Trivy, Syft e Grype tambem produzem metricas normalizadas; Syft publica resumo de SBOM em `artifacts` normalizados sem expor stdout bruto;
- integracao do `EngineeringTestMatrixService` espelhando o resultado capturado de quality scan para o Evidence Store quando a evidencia do subprocesso ainda nao esta visivel, evitando gaps em bancos de teste em memoria e em execucoes isoladas;
- integracao do `EngineeringHarnessRunnerService` gravando o controle `atlas_tool_runtime_gate` por run; o controle usa `AtlasToolGateService` filtrado por `workspace`, `surface=engineering_quality_scan`, `run_context_type=engineering_run` e `run_context_id`;
- integracao do `AtlasEngineeringVisualSmokeCommand` como sensor interno `atlas_visual_smoke`, com manifest, route artifacts, contexto de `engineering_run` quando chamado por automacao e findings para falha de rota/baseline/screenshot;
- integracao do Visual Smoke gerenciado com o controle `atlas_tool_runtime_visual_gate`, avaliando evidencias `engineering_visual_smoke` do run pelo `AtlasToolGateService`;
- integracao do `EngineeringCodeIntelligenceService` como analyzer interno `atlas_code_intelligence`, registrando index/audit, metricas de modulos/simbolos/doc links, contexto opcional de runtime e findings de drift.
- integracao inicial do API Contract Harness como sensor interno `atlas_api_contract`, detectando OpenAPI JSON/YAML, validando estrutura minima, comparando paths/metodos contra rotas Laravel, exigindo `responses` por operation, gerando findings por endpoint e persistindo evidencia em `engineering_api_contract`.

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
```

O gate bloqueia por padrao quando uma run filtrada tem status `failed`,
`timeout`, `requires_approval` ou `denied`, quando a policy decision foi
`requires_approval`/`denied`, quando existe finding com `blocks_resolved=true`
sem waiver valido ou quando o resultado normalizado declara
`blocking_failures`. A API equivalente e `GET /tools/gate`.

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
atlas tools release-gate --workspace=<repo> --surface=engineering_quality_scan --json
```

A API equivalente e:

```http
GET /tools/release-gate
```

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
