---
id: atlas-programming-power-tools-catalog
type: engineering_knowledge
title: Atlas Programming Power Tools Catalog
status: source_material
category: tool_runtime
priority: 97
summary: Catalogo operacional das ferramentas que tornam o Atlas forte para programacao pesada, com tiers, autoridade, status real, lacunas e backlog de implementacao.
tags:
  - atlas
  - tools
  - programming
  - quality
  - security
capabilities:
  - programming_power_tools_catalog
  - tool_authority_matrix
  - t0_t3_execution_policy
  - anti_duplication_policy
  - external_agent_boundary
  - local_open_source_first
decisions:
  - O Atlas e o control-plane; ferramentas externas sao sensores, validadores ou executores governados.
  - Ferramentas pagas/cloud nunca sao dependencia obrigatoria do core.
  - Ferramentas lentas ou invasivas nao rodam no mesmo ciclo de feedback que ferramentas T0/T1.
  - Grupos com ferramentas sobrepostas precisam declarar ferramenta primaria, complementar ou fallback.
  - Agentes externos como Aider, Continue e OpenHands editam sob indice, policy, approval, evidence e gates do Atlas.
maintenance:
  - Rode atlas tools authority --json depois de alterar tiers, autoridade ou catalogo.
  - Rode atlas tools commands <tool> --json antes de promover uma ferramenta para fluxo automatico.
  - Rode atlas tools evidence --recipe=<recipe> para auditar recipes especificas.
  - Atualize este catalogo quando uma ferramenta ganhar recipe real, normalizer especifico ou regra de gate.
  - Rode atlas engineering knowledge sync --prune e atlas engineering knowledge index-code --prune depois de alterar este documento.
related_paths:
  - app/Services/Tools/AtlasToolDefinitionCatalog.php
  - app/Services/Tools/AtlasToolAuthorityMatrixService.php
  - app/Services/Tools/AtlasToolPolicyEngine.php
  - app/Services/Tools/AtlasToolGateService.php
  - app/Services/Tools/AtlasToolResultNormalizer.php
  - app/Services/Tools/AtlasToolExecutor.php
  - app/Services/Tools/AtlasToolEvidenceStore.php
  - app/Console/Commands/AtlasToolsCommand.php
  - docs/engineering-knowledge-base/super-tool-runtime-core.md
  - docs/engineering-knowledge-base/code-intelligence.md
---

# Atlas Programming Power Tools Catalog

Este documento e a referencia de produto para transformar o Atlas em uma
ferramenta pesada de programacao. Ele nao substitui o registry persistente; ele
explica como o registry deve ser operado, quais ferramentas mandam em cada
familia, quais estao apenas catalogadas e qual trabalho falta para ficarem
usaveis em automacoes reais.

## Estado Real

O Super Tool Runtime Core ja entrega registry, policy engine, executor,
normalizer generico, Evidence Store, approvals, waivers, evidence gates,
release gates, command recipes, filtros por recipe, matriz de autoridade,
anti-duplicacao inicial e painel operacional no app Engineering. O primeiro
pacote P0 de recipes reais tambem ja esta no registry para scans locais e
release: Gitleaks, Semgrep, OSV-Scanner, Syft, Trivy, PHPStan, TypeScript,
ESLint, Laravel Pint, Biome, Hadolint e Checkov. Normalizers especificos ja
cobrem Pint, Biome, Hadolint e SARIF generico usado por CodeQL/Checkov.

O catalogo atual registra 61 ferramentas/capacidades:

| Tier | Intencao | Quantidade atual | Deve rodar em |
|---|---|---:|---|
| T0 | Feedback instantaneo e contexto de edicao | 7 | interacao, contexto, busca, IDE-like |
| T1 | Feedback local rapido | 17 | save, pre-commit, quality scan leve |
| T2 | Revisao media | 28 | PR, task gate, contract/visual/security review |
| T3 | Release pesado | 9 | nightly, release, auditoria profunda |

Status importante: `active` no registry significa que a ferramenta existe como
capacidade governada pelo Atlas, nao que o binario esteja instalado no host. A
disponibilidade real vem de `atlas tools doctor --workspace=<repo>`.

## Legenda De Status

| Status | Significado |
|---|---|
| `implemented_internal` | Sensor/servico interno ja implementado e integrado ao Evidence Store |
| `cataloged` | Ferramenta registrada como opcional, local/open-source quando possivel |
| `recipe_ready` | Possui command recipe seguro para diagnostico/versionamento pelo runtime |
| `recipe_needed` | Precisa de recipe real de scan/refactor/release alem de `version` |
| `normalizer_ready` | Output estruturado ja vira findings/metrics/artifacts normalizados |
| `normalizer_needed` | Ainda depende de normalizacao generica ou precisa parser especifico |
| `gate_ready` | Gates ja consomem a evidencia de forma util |
| `gate_needed` | Falta threshold/politica de bloqueio por severidade/familia |

## Politica De Execucao

O Atlas deve preservar velocidade e confianca separando ferramentas por custo.

| Tier | Budget | Exemplos | Regra |
|---|---|---|---|
| T0 | subsegundo a poucos segundos | ripgrep, Code Intelligence, Tree-sitter, ast-grep | Pode apoiar interacao direta, sem rede e sem escrita automatica |
| T1 | ate dezenas de segundos | typecheck, lint, PHPStan, Gitleaks, Knip | Bom para pre-commit, quality scan local e feedback de arquivo/modulo |
| T2 | minutos controlados | CodeQL, Semgrep, Schemathesis, visual smoke, axe | Bom para PR/task gate; precisa timeout, evidencia e policy clara |
| T3 | minutos longos a horas | Infection, Stryker, Lighthouse full, Syft/Trivy/Grype release | Nightly/release/deep; nao deve bloquear fluxo interativo comum |

Nenhum gate deve executar ferramentas diretamente. Gates avaliam evidencias ja
persistidas, filtradas por workspace, surface, contexto, tool, recipe e tier.

## Politica Anti-Duplicacao

Ferramentas sobrepostas sao aceitaveis quando a autoridade esta declarada.
Sem isso, o Atlas vira um agregador de relatorios concorrentes.

| Grupo | Primaria | Complementar/Fallback | Regra |
|---|---|---|---|
| `semantic_sast` | CodeQL | Semgrep | CodeQL decide falhas semanticas profundas; Semgrep acelera padroes e regras locais |
| `ts_js_architecture` | dependency-cruiser | Madge | dependency-cruiser governa regras arquiteturais; Madge ajuda em ciclos/visualizacao |
| `php_architecture` | Deptrac | n/a | Deptrac governa camadas PHP |
| `ts_js_dead_code` | Knip | ts-prune | Knip e fonte principal; ts-prune e fallback legado |
| `php_static_analysis` | PHPStan | Psalm | PHPStan e primary geral; Psalm pode complementar tipos/taint quando configurado |
| `vulnerability_scan` | Trivy | Grype | Trivy governa release gate; Grype complementa divergencias |
| `sbom` | Syft | n/a | Syft e inventario primario de componentes |
| `accessibility` | axe-core | Pa11y | axe e finding engine primario; Pa11y complementa navegacao/cobertura |

O Evidence Store preserva todos os achados. O gate pode suprimir duplicatas na
contagem de bloqueio quando fingerprints/localizacao/titulo indicam o mesmo
problema e o `authority_group` e o mesmo.

## Atlas Versus Agentes Externos

Aider, Continue, OpenHands, Claude Code, Codex CLI, Cursor e agentes futuros
nao devem virar donos do contexto nem dos gates. O contrato correto e:

1. Atlas indexa codigo, docs, memoria, rotas, testes, symbols e evidencias.
2. Atlas define policy, sandbox, approval, tier, privacy e provider-safety.
3. Agentes externos executam leitura/edicao apenas como `executor`.
4. Atlas captura diff, comando, artifacts, findings, tests e gates.
5. Atlas decide aprendizado e memoria canonica depois da evidencia.

Se um agente externo mantiver indice ou memoria propria, isso pode existir como
cache auxiliar, nunca como fonte primaria.

## Catalogo Por Familia

### Codigo E Contexto

| Ferramenta | Status | Tier | Autoridade | Uso | Falta |
|---|---|---|---|---|---|
| `atlas_code_intelligence` | `implemented_internal`, `normalizer_ready`, `gate_ready` | T0 | primary | Modulos, simbolos, rotas, comandos, migrations, testes e doc links | Grafo AST/impacto mais profundo |
| `ripgrep` | `cataloged`, `recipe_ready` | T0 | primary | Busca textual rapida e auditavel | Recipes de busca com evidencia contextual |
| `tree_sitter` | `cataloged`, `recipe_needed` | T0 | primary | AST/grafo local multi-linguagem | Servico interno de graph/index |
| `ast_grep` | `cataloged`, `recipe_needed` | T0 | primary | Busca/refactor estrutural | Recipes seguras de query e rewrite dry-run |
| `serena` | `cataloged`, `recipe_needed` | T0 | complementary | LSP/MCP semantic code intelligence | Boundary read/write, approval e normalizer |
| `universal_ctags` | `cataloged`, `recipe_ready` | T0 | fallback | Indice simbolico leve | Import para Code Intelligence |

### PHP Qualidade, Tipos E Refactor

| Ferramenta | Status | Tier | Autoridade | Uso | Falta |
|---|---|---|---|---|---|
| `composer` | `cataloged`, `recipe_ready` | T1 | primary | Validacao de dependencias e scripts | Recipes de validate/audit normalizadas |
| `laravel_pint` | `cataloged`, `recipe_ready`, `normalizer_ready` | T1 | primary | Format check e diff limpo | Thresholds por escopo de diff |
| `phpstan` | `cataloged`, `recipe_ready`, `normalizer_ready` | T1 | primary | Analise estatica PHP | Thresholds por identifier/baseline |
| `psalm` | `cataloged`, `normalizer_ready` | T1 | primary_or_complementary | Tipos e taint complementar | Thresholds por severity/type |
| `phpmd` | `cataloged`, `recipe_needed` | T1 | primary | Smells e complexidade | Normalizer XML/JSON |
| `phpcpd` | `cataloged`, `recipe_needed` | T2 | primary | Duplicacao PHP | Normalizer de clones |
| `composer_require_checker` | `cataloged`, `recipe_needed` | T1 | primary | Deps usadas sem declarar | Parser e gate de dependency hygiene |
| `composer_unused` | `cataloged`, `recipe_needed` | T1 | primary | Deps declaradas sem uso | Parser e recommendation segura |
| `rector` | `cataloged`, `recipe_needed` | T2 | primary | Refactor mecanico PHP | Dry-run obrigatorio, artifacts de diff |

### TypeScript, Frontend E Dead Code

| Ferramenta | Status | Tier | Autoridade | Uso | Falta |
|---|---|---|---|---|---|
| `typescript` | `cataloged`, `recipe_ready`, `normalizer_ready` | T1 | primary | Typecheck TS/JS | Melhorar parser para caminhos Windows/multiline |
| `eslint` | `cataloged`, `recipe_ready`, `normalizer_ready` | T1 | primary_or_complementary | Lint JS/TS | Thresholds por rule/severity |
| `biome` | `cataloged`, `recipe_ready`, `normalizer_ready` | T1 | primary_or_complementary | Lint/format rapido | Authority rule com ESLint |
| `knip` | `cataloged`, `recipe_needed` | T1 | primary | Dead code, exports e deps TS | Normalizer e thresholds |
| `ts_prune` | `cataloged`, `recipe_needed` | T1 | fallback | Exports TS nao usados | Manter como fallback, nao primary |

### Seguranca, Supply Chain E Licencas

| Ferramenta | Status | Tier | Autoridade | Uso | Falta |
|---|---|---|---|---|---|
| `gitleaks` | `cataloged`, `recipe_ready`, `normalizer_ready` | T1 | primary | Secret scanning local | Gate T1 por recipe required |
| `semgrep` | `cataloged`, `recipe_ready`, `normalizer_ready` | T2 | complementary | SAST rapido por regras | Policy de rulesets e suppressions |
| `codeql` | `cataloged`, `normalizer_ready` | T2 | primary | SAST semantico e SARIF | Database/cache e recipe de analyze |
| `infer` | `cataloged`, `recipe_needed` | T2 | primary | Static bug finding profundo | Normalizer JSON/SARIF |
| `osv_scanner` | `cataloged`, `recipe_ready`, `normalizer_ready`, `gate_ready` | T2 | primary | Vulnerabilidades por lockfile | Melhorar deteccao de manifests alvo |
| `syft` | `cataloged`, `recipe_ready`, `normalizer_ready`, `gate_ready` | T3 | primary | SBOM release | Artifact retention completo |
| `trivy` | `cataloged`, `recipe_ready`, `normalizer_ready`, `gate_ready` | T3 | primary | Vulnerabilidades fs/container | Thresholds por CVSS/severity |
| `grype` | `cataloged`, `normalizer_ready` | T3 | complementary | Scan complementar de vulnerabilidades | Anti-duplicacao CVE/package |
| `scancode` | `cataloged`, `recipe_needed` | T3 | primary | Licencas/source compliance | Normalizer license policy |
| `ort` | `cataloged`, `recipe_needed` | T3 | complementary | Compliance OSS profundo | Policy por projeto |
| `licensee` | `cataloged`, `recipe_ready` | T1 | fallback | Licenca principal do repo | Usar so como fallback rapido |

### Containers, IaC E Kubernetes

| Ferramenta | Status | Tier | Autoridade | Uso | Falta |
|---|---|---|---|---|---|
| `docker` | `cataloged`, `recipe_ready` | T2 | primary | Build/runtime container | Policy forte para daemon/volumes |
| `hadolint` | `cataloged`, `recipe_ready`, `normalizer_ready` | T1 | primary | Dockerfile lint | Cobrir multiplos Dockerfiles |
| `dockle` | `cataloged`, `recipe_needed` | T2 | primary | Hardening de imagem | Recipe por imagem/artifact |
| `checkov` | `cataloged`, `recipe_ready`, `normalizer_ready` | T2 | primary | IaC security | Thresholds por framework/severity |
| `terrascan` | `cataloged`, `recipe_needed` | T2 | complementary | IaC security complementar | Authority rules com Checkov |
| `kube_linter` | `cataloged`, `recipe_needed` | T2 | primary | Kubernetes manifests | Normalizer |
| `kube_score` | `cataloged`, `recipe_needed` | T2 | complementary | Score operacional Kubernetes | Recommendations nao bloqueantes por default |

### APIs, Contratos E Mocking

| Ferramenta | Status | Tier | Autoridade | Uso | Falta |
|---|---|---|---|---|---|
| `atlas_api_contract` | `implemented_internal`, `normalizer_ready`, `gate_ready` | T1 | primary | OpenAPI minimo vs rotas Laravel | Validacao semanticamente mais profunda |
| `schemathesis` | `cataloged`, `recipe_needed` | T2 | complementary | Fuzz/property OpenAPI | Recipe com base URL e artifact JUnit |
| `pact` | `cataloged`, `recipe_needed` | T2 | complementary | Consumer/provider contract | Modelo de artifact por consumer |
| `prism` | `cataloged`, `recipe_needed` | T2 | complementary | Mock/validation OpenAPI | Surface de mock controlada |
| `wiremock` | `cataloged`, `recipe_needed` | T2 | complementary | Mock de terceiros | Sandbox e artifact de mappings |
| `bruno` | `cataloged`, `recipe_needed` | T2 | complementary | Colecoes API locais | Runner recipe e normalizer |

### Visual, Acessibilidade, Performance E Testes Profundos

| Ferramenta | Status | Tier | Autoridade | Uso | Falta |
|---|---|---|---|---|---|
| `atlas_visual_smoke` | `implemented_internal`, `normalizer_ready`, `gate_ready` | T2 | primary | Smoke visual, screenshots, baseline | Mais heuristicas por componente |
| `playwright` | `cataloged`, `recipe_ready` | T2 | complementary | E2E/browser traces | Normalizer JUnit/trace linkage |
| `cypress` | `cataloged`, `recipe_ready` | T2 | complementary | E2E alternativo | Normalizer JUnit |
| `axe_core` | `cataloged`, `recipe_needed` | T2 | primary | A11y findings | Integrar com Visual Smoke/routes |
| `pa11y` | `cataloged`, `recipe_needed` | T2 | complementary | A11y CLI/cobertura | Normalizer JSON |
| `lighthouse_ci` | `cataloged`, `recipe_needed` | T3 | primary | Performance/a11y/best practices release | Budget e trend history |
| `fast_check` | `cataloged`, `recipe_needed` | T2 | primary | Property-based tests TS | Recipes por suite |
| `infection` | `cataloged`, `recipe_needed` | T3 | primary | Mutation testing PHP | Nightly/release only |
| `stryker` | `cataloged`, `recipe_needed` | T3 | primary | Mutation testing JS/TS | Nightly/release only |

### Arquitetura

| Ferramenta | Status | Tier | Autoridade | Uso | Falta |
|---|---|---|---|---|---|
| `dependency_cruiser` | `cataloged`, `recipe_needed` | T2 | primary | Regras de dependencia TS/JS | Normalizer e policy por camada |
| `madge` | `cataloged`, `recipe_needed` | T2 | complementary | Ciclos e visualizacao | Complementar apenas |
| `deptrac` | `cataloged`, `recipe_needed` | T2 | primary | Regras de dependencia PHP | Normalizer e thresholds |

### Agentes Externos

| Ferramenta | Status | Tier | Autoridade | Uso | Falta |
|---|---|---|---|---|---|
| `aider` | `cataloged`, `recipe_needed` | T2 | executor | Edicao assistida por agente | Approval, diff capture, provider-safe context |
| `continue` | `cataloged`, `recipe_needed` | T2 | executor | IDE/agent bridge | Contrato MCP/context pack |
| `openhands` | `cataloged`, `recipe_needed` | T3 | executor | Automacao pesada em sandbox | Worktree/Docker obrigatorio |

## Matriz Por Tipo De Tarefa

| Task type | Ferramentas preferidas | Gate default |
|---|---|---|
| Busca/contexto de codigo | `atlas_code_intelligence`, `ripgrep`, `tree_sitter`, `ast_grep`, `serena` | Nao bloqueante, evidencia contextual |
| Qualidade PHP | `laravel_pint`, `phpstan`, `psalm`, `phpmd`, `composer_require_checker`, `composer_unused` | Bloqueante em T1 quando configurado |
| Qualidade TS/JS | `typescript`, `eslint`, `biome`, `knip` | Bloqueante em T1 para type/lint |
| Revisao de seguranca | `gitleaks`, `semgrep`, `codeql`, `osv_scanner`, `trivy`, `grype` | Bloqueante por severity/policy |
| Release supply chain | `syft`, `trivy`, `grype`, `scancode`, `ort` | Bloqueante em release gate |
| API contract | `atlas_api_contract`, `schemathesis`, `pact`, `prism`, `wiremock`, `bruno` | Bloqueante para contratos required |
| Visual/a11y/perf | `atlas_visual_smoke`, `playwright`, `axe_core`, `pa11y`, `lighthouse_ci` | Visual/a11y bloqueante em PR; Lighthouse em release |
| Arquitetura | `deptrac`, `dependency_cruiser`, `madge` | Bloqueante quando regras de camada existem |
| Agentes externos | `aider`, `continue`, `openhands` | Nunca autoridade final; sempre approval/evidence/gate |

## Backlog De Implementacao

### P0 - Recipes Reais E Baratas

Status: implementado no registry para o primeiro pacote critico. Todas as
recipes abaixo usam argv declarado, metadata de category/surface/tier/privacy,
dry-run default conservador por tipo e evidence normalizada quando parser
existe.

| Ferramenta | Recipe alvo | Observacao |
|---|---|---|
| `gitleaks` | `detect-redacted` | Implementado; JSON redigido, high-risk exige approval para execucao real |
| `semgrep` | `scan-json` | Implementado; T2, output JSON normalizado |
| `osv_scanner` | `recursive-json` | Implementado; network explicitamente declarada |
| `syft` | `sbom-json` | Implementado; metricas/artifact summary normalizados |
| `trivy` | `fs-json` | Implementado; release surface e network declarada |
| `phpstan` | `analyse-json` | Implementado; JSON normalizado |
| `typescript` | `no-emit` | Implementado; parser textual `TSxxxx` normalizado |
| `eslint` | `lint-json` | Implementado; JSON normalizado |
| `laravel_pint` | `format-test` | Implementado; nao escreve no workspace |
| `biome` | `ci-json` | Implementado; JSON normalizado |
| `hadolint` | `dockerfile-json` | Implementado; Dockerfile default normalizado |
| `checkov` | `directory-sarif` | Implementado; SARIF normalizado, high-risk exige approval |

### P1 - Normalizers Especificos

Adicionar parsers e fingerprints para:

| Familia | Outputs |
|---|---|
| SARIF | Implementado para CodeQL/Checkov; falta ampliar coverage para Semgrep SARIF e edge cases |
| A11y/perf | axe-core JSON, Pa11y JSON, Lighthouse reports |
| Arquitetura | dependency-cruiser JSON, Deptrac JSON/XML, Madge JSON |
| API | Schemathesis JUnit/JSON, Pact reports, Prism validations |
| Mutation | Infection JSON, Stryker JSON |
| Licencas | ScanCode JSON, ORT reports |

### P2 - Gates Por Autoridade

Status: implementado para o primeiro conjunto de grupos criticos em
`AtlasToolAuthorityPolicyService`, consumido por `AtlasToolGateService` e
exposto por `atlas tools authority-policies --json` e
`GET /tools/authority/policies`. Overrides workspace/global usam
`atlas_tool_policies` com `tool_slug=authority:<group>` e podem ser aplicados
por `atlas tools set-authority-policy <group>` ou
`PUT /tools/authority/policies/{authorityGroup}`. O gate continua retornando
`passed`, `warning` ou `blocked`, mas `blocking_failures` e `warnings` incluem
`authority_group`, `authority_policy`, `severity` e razoes especificas. Isso
permite que findings high/critical bloqueiem mesmo quando um normalizer ainda
nao marcou `blocks_resolved`, e que medium vire warning sem inflar bloqueios.

| Grupo | Regra minima |
|---|---|
| `secret_scan` | critical/high/medium/low bloqueiam; info vira warning |
| `semantic_sast` | critical/high bloqueiam; medium vira warning |
| `dependency_vulnerability` | critical/high bloqueiam; medium vira warning |
| `vulnerability_scan` | critical/high bloqueiam; medium vira warning |
| `iac_security` | critical/high bloqueiam; medium vira warning |
| `sbom` | critical bloqueia; high/medium/low viram warning no gate generico |
| `formatter`, `php_static_analysis`, `ts_js_type_lint` | critical/high bloqueiam; medium vira warning |
| `container_quality`, `container_lint` | critical/high bloqueiam; medium vira warning |
| `api_contract`, `visual_regression`, `accessibility`, `php_architecture`, `ts_js_architecture` | critical/high bloqueiam; medium vira warning |

### P3 - UX Operacional

Evoluir o painel Engineering para:

| Area | Entrega |
|---|---|
| Recipes | selecionar recipe segura por ferramenta, nao argv livre |
| Evidencias | filtros por recipe/category/surface/tier/status |
| Gaps | mostrar ferramentas missing por autoridade primaria |
| Gates | explicar qual evidencia falta para liberar PR/release |
| Approvals | aprovar por recipe/tier/sandbox com TTL curto |

## Comandos De Manutencao

```bash
atlas tools list --json
atlas tools doctor --workspace=/path/to/repo --json
atlas tools authority --json
atlas tools commands <tool> --json
atlas tools run-recipe <tool> --recipe=<recipe> --workspace=/path/to/repo --dry-run --json
atlas tools evidence --workspace=/path/to/repo --recipe=<recipe> --json
atlas tools gate --workspace=/path/to/repo --recipe-category=static_security_scan --json
atlas tools release-gate --workspace=/path/to/repo --json
atlas engineering knowledge sync --prune
atlas engineering knowledge index-code --prune
```

## Criterio De Produto Final

O Atlas fica realmente forte para programacao quando:

1. cada familia critica tem primary/complementary/fallback declarados;
2. cada primary tem pelo menos uma recipe real, segura e testada;
3. outputs estruturados viram findings/metrics/artifacts normalizados;
4. gates usam severidade, authority group, tier e freshness;
5. UI e CLI mostram lacunas sem exigir lembrar comandos externos;
6. agentes externos recebem contexto do Atlas e devolvem evidencia ao Atlas;
7. ferramentas ausentes nao quebram o fluxo comum, mas bloqueiam release quando
   sao obrigatorias por policy.
