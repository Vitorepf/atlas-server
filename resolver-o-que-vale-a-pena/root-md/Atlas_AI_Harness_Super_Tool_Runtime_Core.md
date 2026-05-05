# Atlas AI Harness - Super Tool Runtime Core

**Camada core de ferramentas, sensores, validadores e atuadores que transforma o Atlas em um sistema operacional de execucao, verificacao e aprendizado**

---

| | |
|---|---|
| Operador | Vitor Emanuel |
| Sistema | Atlas |
| Documento | Atlas AI Harness - Super Tool Runtime Core |
| Versao | 1.0 |
| Data | 2 de maio de 2026 |
| Status | Especificacao arquitetural; Fase 0 implementada incrementalmente no `atlas-server`, incluindo registry/policy/evidence/gates e diagnostico operacional de autoridade |
| Autoridade superior | `Atlas_Documento_Mestre_v6.md` |
| Documentos relacionados | `Atlas_AI_Documentacao_Final.md`, `Atlas_AI_Harness_v1.md`, `Atlas_Engineering_Harness_Runner_Plano_Profissional.md`, `Atlas_AI_Memory_Context_Core_Open_Brain.md`, `Atlas_AI_Skill_System_v1.md` |

---

## 0. Decisao Executiva

### Status de implementacao em 2 de maio de 2026

A Fase 0 esta implementada como fundacao operacional usavel no `atlas-server`:
registry persistente, detection por workspace, policy engine, executor seguro,
normalizador, evidence store, CLI/API, integracao inicial com Quality Scan,
Visual Smoke e Code Intelligence, painel operacional no app Engineering e fluxo
de aprovacao auditavel por workspace/global com TTL, motivo, operador, rede e
revogacao. O Quality Scan gerenciado pelo Engineering Harness tambem propaga
`run_context_type=engineering_run` e `run_context_id`, espelha evidencias
capturadas pelo test matrix quando necessario e grava o controle
`atlas_tool_runtime_gate` no run. O Visual Smoke gerenciado segue o mesmo
contrato: evidencia `atlas_visual_smoke` com contexto do run e controle
`atlas_tool_runtime_visual_gate`. Code Intelligence tambem aceita contexto
explicito de runtime em `index-code`/`audit-code`, permitindo associar
evidencias `atlas_code_intelligence` a runs, benchmarks ou automacoes externas.
O Evidence Store tambem possui consulta/export por run id com envelope
`atlas.tool_evidence.v1`, incluindo hashes de comando/workspace/artifacts e
fingerprints de findings para auditoria sem expor conteudo bruto, paths
absolutos ou previews de artifact. O CLI permite lookup direto por run id sem
filtro implicito de cwd; `--workspace` restringe explicitamente a consulta.
O Quality Scan agora inclui o primeiro bloco de seguranca/supply chain no
runtime comum: OSV-Scanner em `standard/release/deep` quando ha lockfile ou
manifesto de dependencia, e Trivy, Syft e Grype em `release/deep`, sempre como
ferramentas locais/gratuitas opcionais com evidencia auditavel. Os outputs JSON
dessas ferramentas tambem alimentam `metrics` normalizadas; Syft gera resumo de
SBOM no contrato comum sem obrigar consumidores a ler stdout bruto. A CLI do
Engineering Harness tambem possui comandos dedicados `atlas engineering
security-scan` e `atlas engineering sbom`, ambos filtrando o mesmo pipeline em
vez de criar execucoes paralelas. A API protegida expõe os equivalentes
`POST /engineering/security-scan` e `POST /engineering/sbom`, retornando o mesmo
payload auditavel.

Comandos principais disponiveis:

```bash
atlas tools doctor --workspace=<repo> --json
atlas tools list --json
atlas tools status <tool> --workspace=<repo> --json
atlas tools run <tool> --workspace=<repo> --command=<argv> --dry-run --json
atlas tools approve <tool> --workspace=<repo> --reason="..." --ttl-hours=24 --json
atlas tools policies --workspace=<repo> --json
atlas tools revoke <tool> --workspace=<repo> --json
atlas tools evidence [tool] --workspace=<repo> --status=failed --surface=engineering_quality_scan --limit=20 --json
atlas tools evidence-show --run-id=<tool-run-id> --workspace=<repo> --json
atlas tools evidence-export --run-id=<tool-run-id> --workspace=<repo> --json
atlas tools gate [tool] --workspace=<repo> --required-tool=<slug> --require-evidence --json
```

Endpoints protegidos equivalentes:

```text
GET /tools
GET /tools/doctor
GET /tools/evidence
GET /tools/evidence/{run}
GET /tools/evidence/{run}/export
GET /tools/gate
GET /tools/policies
GET /tools/{tool}
GET /tools/{tool}/commands
POST /tools/{tool}/commands/{recipe}/run
POST /tools/{tool}/run
POST /tools/{tool}/approval
DELETE /tools/{tool}/approval
```

O Atlas nao deve ser apenas uma interface melhor para chamar modelos.

O Atlas deve virar uma camada operacional que usa modelos como motores e ferramentas reais como sensores, validadores e atuadores. E esta combinacao que cria poder bruto:

```text
modelo bom + contexto certo + ferramenta certa + evidencia real + memoria acumulada
```

Playwright sozinho nao transforma o Atlas. Semgrep sozinho nao transforma o Atlas. OpenTelemetry sozinho nao transforma o Atlas.

O que transforma o Atlas e uma camada propria que:

1. conhece quais ferramentas existem;
2. sabe quando cada ferramenta deve ser usada;
3. executa ferramentas em sandbox;
4. normaliza resultados;
5. transforma output bruto em evidencia;
6. aplica gates;
7. registra traces;
8. aprende com o resultado;
9. melhora o proximo uso do Atlas.

Essa camada e o **Atlas AI Harness - Super Tool Runtime Core**.

Ela deve ser tratada como core do Atlas porque e a diferenca entre:

| Uso comum de IA | Atlas com Super Tool Runtime |
|---|---|
| Modelo opina | Modelo executa com evidencia |
| Resposta parece correta | Resultado passa por sensores |
| Usuario precisa testar tudo | Atlas roda testes e gates |
| Cada ferramenta fica isolada | Atlas integra tudo em um trace |
| Aprendizado se perde | Aprendizado volta para memoria |
| Provider dita limite | Atlas aumenta capacidade com ferramentas locais |

A tese central:

> **O Atlas deve ser o control-plane inteligente que combina IA, memoria, repo, browser, containers, scanners, observabilidade, testes, contratos, performance, acessibilidade e refatoracao em um sistema unico de execucao auditavel.**

---

## 1. Definicao Curta

**Super Tool Runtime Core** e a subcamada do Atlas AI Harness responsavel por descobrir, registrar, executar, governar e aprender com ferramentas locais ou self-hosted que aumentam a capacidade operacional do Atlas.

Ela nao e uma lista de integracoes.

Ela e uma arquitetura para transformar ferramentas gratuitas, open-source ou locais em capacidades padronizadas do Atlas.

---

## 2. Fronteira Conceitual

```text
Atlas
+-- Atlas AI
    +-- Memory Context Core
    +-- Atlas AI Harness
        +-- Skill System
        +-- Context Pack Builder
        +-- Provider/Model Router
        +-- Super Tool Runtime Core
        |   +-- Tool Registry
        |   +-- Tool Policy Engine
        |   +-- Tool Executor
        |   +-- Result Normalizer
        |   +-- Evidence Store
        |   +-- Learning Loop
        +-- Engineering Harness Runner
```

### 2.1 Atlas

Sistema inteiro: app, backend, banco, Vault, CLI, sensores, automacoes, memorias, tarefas, projetos, inbox e superficies.

### 2.2 Atlas AI

Core cognitivo persistente: identidade, memoria, contexto, leis, objetivos, preferencias, decisoes, workflows e continuidade.

### 2.3 Atlas AI Harness

Infraestrutura operacional do Atlas AI: classifica tarefa, escolhe skill, monta contexto, escolhe modelo, executa ferramentas, aplica gates, registra traces e escreve aprendizado.

### 2.4 Super Tool Runtime Core

Parte do Harness que da corpo operacional ao Atlas.

Ele responde:

- qual ferramenta existe;
- se esta instalada;
- se pode ser usada;
- em qual sandbox;
- com quais argumentos;
- com qual timeout;
- com qual politica de seguranca;
- como ler o resultado;
- qual evidencia foi produzida;
- se o resultado bloqueia a conclusao;
- o que deve virar memoria.

### 2.5 Engineering Harness Runner

Vertical de engenharia de software que usa o Super Tool Runtime para programar melhor.

Exemplos:

- usar Playwright para verificar UI real;
- usar Semgrep/PHPStan/Biome para qualidade;
- usar Trivy/Gitleaks/Syft/Grype para seguranca e supply chain;
- usar Testcontainers/Docker para ambiente real;
- usar Schemathesis/Pact/WireMock para API e contratos;
- usar k6/Locust/Lighthouse para performance;
- usar Tree-sitter/ast-grep para entender codigo;
- usar Rector/jscodeshift/OpenRewrite para refatoracao controlada.

---

## 3. Por Que Isso E Core Do Atlas

### 3.1 Modelos sao fortes, mas nao veem o mundo sozinhos

Um modelo pode raciocinar, escrever codigo, propor arquitetura e explicar riscos.

Mas sem ferramentas ele nao consegue garantir:

- se a tela realmente abriu;
- se o botao esta clicavel;
- se uma migracao rodou;
- se a API respeita contrato;
- se existe secret vazado;
- se uma dependencia tem CVE;
- se o bundle piorou;
- se uma rota ficou lenta;
- se o diff escapou do escopo;
- se a memoria usada era atual;
- se o resultado melhorou o benchmark.

O Super Tool Runtime fecha essa lacuna.

### 3.2 Ferramentas sem Atlas tambem ficam fracas

Ferramentas isoladas geram outputs soltos.

O Atlas precisa transformar cada output em:

- evidencia;
- finding;
- controle;
- score;
- decisao;
- memoria;
- benchmark;
- aprendizado de politica.

Sem essa camada, uma ferramenta boa vira apenas mais uma aba, mais um comando e mais um relatorio esquecido.

### 3.3 O ganho real vem da composicao

O poder bruto aparece quando o Atlas compoe capacidades:

```text
Context Pack -> modelo -> patch -> testes -> visual smoke -> security scan -> API contract -> score -> memoria
```

Exemplo pratico:

1. Vitor pede uma mudanca na UI.
2. Atlas monta contexto do projeto, design system e historico.
3. Modelo implementa.
4. Atlas roda typecheck.
5. Atlas roda teste unitario relevante.
6. Atlas abre app com Playwright.
7. Atlas tira screenshot.
8. Atlas compara baseline.
9. Atlas roda axe-core.
10. Atlas registra diff, logs, artifacts e score.
11. Atlas decide se pode dizer "resolvido".
12. Atlas grava o que aprendeu.

Isto e muito diferente de pedir para um modelo "fazer a tela".

---

## 4. Principios Nao-Negociaveis

### P1. Atlas primeiro, ferramenta depois

Ferramenta nao vira arquitetura.

Playwright, Testcontainers, Semgrep, Trivy, OpenTelemetry e similares sao plugins de capacidade. O Atlas continua sendo o control-plane.

### P2. Preferencia por gratuito, open-source, local e self-hosted

O Atlas deve priorizar ferramentas que:

- rodam localmente;
- aceitam CLI;
- geram JSON ou artifacts parseaveis;
- nao exigem assinatura;
- nao prendem dados em cloud externa;
- podem ser desativadas sem quebrar o core.

Servicos pagos podem existir futuramente como aceleradores opcionais, nunca como dependencia estrutural.

### P3. Provider e motor, nao autoridade

O modelo pode interpretar findings, sugerir reparos e priorizar acao.

Mas a evidencia vem de ferramentas, testes, traces, diff, runtime e memoria.

### P4. Sem evidencia, nao ha "resolvido" confiavel

Para tarefas criticas, o Atlas nao deve concluir por linguagem.

Ele deve concluir por evidencias:

- teste passou;
- controle passou;
- visual smoke passou;
- contrato passou;
- scanner nao achou bloqueador;
- diff ficou no escopo;
- benchmark nao regrediu;
- operador aceitou quando necessario.

### P5. Ferramenta falhando tambem e evidencia

Se uma ferramenta nao esta instalada, travou, expirou ou retornou output invalido, isso deve virar um evento auditavel.

O Atlas nao deve esconder ausencia de ferramenta.

Estados canonicos:

| Estado | Significado |
|---|---|
| `ready` | ferramenta disponivel e executavel |
| `missing` | ferramenta nao instalada |
| `disabled` | ferramenta desativada por politica |
| `skipped` | ferramenta nao aplicavel a tarefa |
| `failed` | ferramenta executou e falhou |
| `timeout` | ferramenta excedeu limite |
| `invalid_output` | output nao pode ser normalizado |
| `passed` | controle derivado passou |
| `warning` | achado nao bloqueante |
| `blocked` | achado bloqueia conclusao |

### P6. Sandbox antes de autonomia

Ferramentas podem ler, escrever, subir servidor, abrir browser, rodar containers e chamar rede.

Cada capacidade exige permissao separada.

O default deve ser:

- leitura segura;
- escrita restrita ao workspace;
- rede desligada quando nao necessaria;
- timeout explicito;
- artifact path isolado;
- comandos auditados;
- destructive actions bloqueadas sem permissao.

### P7. JSON canonico acima de output humano

Sempre que possivel, o Atlas deve executar ferramentas em modo JSON, SARIF, JUnit, OpenAPI, HAR, trace, cobertura ou outro formato estruturado.

Output humano e secundario.

### P8. Resultado normalizado antes de ir para modelo

O modelo nao deve receber um log gigante bruto como fonte primaria.

O Runtime deve normalizar:

- resumo;
- severidade;
- arquivo;
- linha;
- regra;
- comando;
- exit code;
- artifact;
- hash;
- sugestao deterministica quando houver.

### P9. Aprendizado deve voltar para memoria e benchmark

Cada ferramenta pode gerar aprendizado:

- comando correto do repo;
- teste confiavel;
- rota visual critica;
- padrao de erro recorrente;
- dependencia vulneravel frequente;
- regra de Semgrep util;
- baseline visual aprovado;
- threshold de performance;
- harnessability real do projeto.

Esse aprendizado deve alimentar:

- Memory Context Core;
- Atlas-Bench;
- Tool Policy Engine;
- Context Pack Builder;
- Engineering Harness Runner.

### P10. A melhor ferramenta e a que vira rotina invisivel

O objetivo nao e Vitor lembrar comandos.

O objetivo e o Atlas saber que, para determinado tipo de tarefa, determinado conjunto de sensores deve rodar automaticamente.

---

## 5. Arquitetura Alvo

### 5.1 Fluxo Geral

```text
intencao do operador
  -> Task Classifier
  -> Skill Router
  -> Context Pack Builder
  -> Tool Policy Engine
  -> Tool Plan
  -> Tool Executor
  -> Artifact Capture
  -> Result Normalizer
  -> Control Results
  -> Evaluator
  -> Trace Store
  -> Memory Writer
  -> Benchmark/Learning Loop
```

### 5.2 Componentes

| Componente | Responsabilidade |
|---|---|
| Tool Registry | Cadastro canonico de ferramentas, capacidades, comandos, formatos e riscos |
| Tool Installation Detector | Descobre se a ferramenta existe no host, workspace, Docker ou runtime Atlas-managed |
| Tool Policy Engine | Decide quando rodar, obrigatoriedade, timeout, sandbox e failure policy |
| Tool Planner | Monta sequencia de ferramentas para a tarefa |
| Tool Executor | Executa comando de forma auditada e isolada |
| Result Normalizer | Converte output bruto para schema Atlas |
| Evidence Store | Guarda artifacts, reports, screenshots, logs, SARIF, JUnit, HAR, traces e hashes |
| Control Mapper | Transforma resultados em controles e gates |
| Finding Store | Persiste achados acionaveis com severidade, arquivo, linha e regra |
| UI/CLI Reporter | Exibe resultado para operador sem ruido |
| Learning Loop | Atualiza politica, memoria e benchmark |

### 5.3 Camadas De Execucao

| Camada | Uso |
|---|---|
| `host` | ferramentas instaladas no Mac do operador |
| `workspace` | ferramentas do repo, como `node_modules/.bin`, `vendor/bin`, `venv/bin` |
| `atlas_managed` | runtime mantido pelo Atlas, como Playwright gerenciado |
| `docker` | execucao isolada em container do projeto |
| `service` | servico local/self-hosted, como Jaeger, Prometheus, Temporal |
| `remote_optional` | cloud opcional e nao-core |

Regra:

> Para ferramentas criticas, o Atlas deve preferir `workspace` quando o repo define sua versao, depois `atlas_managed`, depois `host`.

### 5.4 Tipos De Ferramenta

| Tipo | Papel |
|---|---|
| Sensor | Observa sem alterar estado relevante |
| Validator | Decide se uma regra passou/falhou |
| Actuator | Executa acao no sistema, como refatorar, migrar, gerar arquivo |
| Profiler | Mede performance, custo ou tempo |
| Scanner | Encontra risco, vulnerabilidade, segredo, duplicidade |
| Recorder | Captura trace, screenshot, video, HAR, logs |
| Simulator | Simula browser, API, carga, servico, erro |
| Indexer | Cria indice de codigo, doc, memoria ou dependencia |
| Transformer | Aplica mudancas mecanicas, codemod ou refactor |

### 5.5 Contrato De Resultado

Todo resultado de ferramenta deve convergir para um schema comum.

```json
{
  "tool": "semgrep",
  "capability": "static_security_scan",
  "status": "failed",
  "required": true,
  "exit_code": 1,
  "duration_ms": 1842,
  "workspace": "/repo",
  "command_hash": "sha256:...",
  "artifacts": [
    {
      "type": "sarif",
      "path": "storage/app/atlas-tool-runs/123/semgrep.sarif",
      "sha256": "..."
    }
  ],
  "findings": [
    {
      "severity": "high",
      "rule_id": "php.lang.security.sql-injection",
      "file": "app/Http/Controllers/UserController.php",
      "line": 42,
      "title": "Possible SQL injection",
      "blocks_resolved": true
    }
  ],
  "summary": {
    "high": 1,
    "medium": 0,
    "low": 3
  }
}
```

### 5.6 Contrato De Tool Definition

```json
{
  "id": "playwright",
  "name": "Playwright",
  "category": "browser_automation",
  "license_posture": "open_source",
  "cost_posture": "free_local",
  "execution_layers": ["workspace", "atlas_managed", "host"],
  "capabilities": [
    "browser_open",
    "click",
    "screenshot",
    "visual_compare",
    "trace",
    "accessibility_hook"
  ],
  "detect": {
    "binaries": ["playwright"],
    "node_modules": ["playwright", "@playwright/test"]
  },
  "outputs": ["json", "png", "html", "trace.zip"],
  "risks": ["network", "starts_browser", "may_start_server"],
  "default_timeout_seconds": 120,
  "default_failure_policy": "warning",
  "required_when": [
    "task_touches_ui",
    "contract_requires_visual_qa"
  ]
}
```

---

## 6. Mapa De Ferramentas Prioritarias

Esta secao define as familias que mais aumentam o poder do Atlas sem exigir pagamento como base.

### 6.1 Browser E UI Real

| Ferramenta | Uso No Atlas | Por Que Importa |
|---|---|---|
| Playwright | abrir app real, clicar, preencher, screenshot, trace, baseline visual | prova que UI funciona no browser real |
| Cypress | alternativa quando repo ja usa Cypress | respeita stack existente |
| axe-core | acessibilidade automatica em DOM real | evita UI bonita mas inacessivel |
| Pa11y | varredura acessibilidade via CLI | boa opcao simples para sites |
| Lighthouse | performance, SEO tecnico, acessibilidade, best practices | mede qualidade de pagina no Chrome |

Implementacao profissional:

- detectar se o repo ja usa Playwright/Cypress;
- usar runtime do workspace quando existir;
- usar runtime Atlas-managed quando repo nao tiver;
- capturar screenshot, DOM, console errors, network failures e trace;
- comparar baseline quando existir;
- criar finding para texto cortado, erro de console, rota quebrada, layout vazio e acessibilidade critica;
- nao bloquear por mudanca visual em modo `observe`;
- bloquear em modo `strict` quando baseline aprovada divergir acima do threshold.

Comandos Atlas alvo:

```bash
atlas engineering visual-smoke --workspace=/repo --routes=/,/login --screenshot-driver=auto
atlas engineering visual-baseline list --workspace=/repo
atlas engineering visual-baseline promote --workspace=/repo --apply
```

### 6.2 Ambientes Reais E Containers

| Ferramenta | Uso No Atlas | Por Que Importa |
|---|---|---|
| Docker Compose | subir stack real local | replica ambiente de desenvolvimento |
| Testcontainers | criar DB, Redis, fila, servicos temporarios em testes | reduz falso positivo de teste mockado |
| Dev Containers | padronizar ambiente por repo | onboarding e reproducibilidade |
| Nix | ambiente declarativo e reprodutivel | reduz drift entre maquinas |
| Dagger | pipelines locais/containerizados como codigo | opcional para CI local forte |

Implementacao profissional:

- detectar `docker-compose.yml`, `compose.yaml`, `.devcontainer`, `flake.nix`;
- criar perfil de harness por projeto;
- subir apenas servicos necessarios;
- aguardar healthchecks;
- capturar logs por servico;
- anexar logs a run;
- limpar recursos antigos;
- bloquear conclusao se ambiente obrigatorio nao subiu.

Comandos Atlas alvo:

```bash
atlas engineering env doctor --workspace=/repo
atlas engineering run --sandbox=docker --workspace=/repo
atlas benchmark cleanup --dry-run
```

### 6.3 Qualidade Estatica, Tipos E Lint

| Ferramenta | Stack | Uso No Atlas |
|---|---|---|
| PHPStan | PHP/Laravel | analise estatica e tipos |
| Psalm | PHP | analise estatica profunda |
| Psalm taint analysis | PHP/security | fluxo de dados perigoso em entrada/saida |
| Laravel Pint | PHP | formatacao padrao Laravel |
| Rector | PHP | refactors mecanicos e upgrades seguros |
| PHP Mess Detector | PHP | complexidade, smell e regras de manutencao |
| PHPCPD | PHP | copia/duplicacao de codigo |
| Composer Require Checker | PHP | dependencias usadas sem declarar |
| Composer Unused | PHP | dependencias declaradas sem uso |
| ESLint | JS/TS | lint e regras de frontend |
| TypeScript `tsc` | TS | typecheck canonico |
| Biome | JS/TS | lint/format rapido |
| Knip | TS/JS | detectar codigo morto, exports e deps nao usados |
| ts-prune | TS | exports nao usados |
| ShellCheck | shell | scripts seguros |
| Hadolint | Dockerfile | qualidade Docker |
| markdownlint | docs | padrao de docs quando aplicavel |

Implementacao profissional:

- preferir scripts do repo (`npm run typecheck`, `composer test`, `vendor/bin/phpstan`);
- se nao existir script, detectar binarios locais;
- rodar escopo minimo quando possivel;
- transformar erros em findings com arquivo e linha;
- diferenciar bloqueadores de melhorias;
- salvar output completo como artifact, mas mostrar resumo limpo.

Comando Atlas alvo:

```bash
atlas engineering quality-scan --workspace=/repo --profile=auto
```

### 6.4 Seguranca, Secrets E Supply Chain

| Ferramenta | Uso No Atlas | Por Que Importa |
|---|---|---|
| Gitleaks | detectar secrets em arquivos e git | evita vazamento de chaves |
| Trivy | vulnerabilidades em deps, filesystem, containers, IaC | scanner amplo e pratico |
| Syft | gerar SBOM | inventario de dependencias |
| Grype | scan de vulnerabilidades a partir de SBOM | analise complementar |
| Semgrep | regras de seguranca e bug patterns | acha padroes perigosos no codigo |
| OSV-Scanner | vulnerabilidades por lockfile | bom para deps open-source |
| CodeQL CLI | analise semantica de vulnerabilidades | queries profundas e SARIF local |
| Infer | bug finding estatico | detecta null/resource/concurrency bugs em stacks suportadas |
| Checkov | IaC security | Terraform/Kubernetes/CloudFormation seguro |
| Terrascan | IaC security | cobertura complementar de policy |
| kube-linter | Kubernetes | manifests e charts com boas praticas |
| kube-score | Kubernetes | score operacional de manifests |
| Dockle | container image lint | hardening de imagens Docker |
| ScanCode Toolkit | licencas e compliance | inventario legal offline |
| ORT | OSS review/compliance | auditoria de licencas e deps |
| licensee | licenca do repo | sinal rapido para compliance |

Implementacao profissional:

- rodar Gitleaks sempre em alteracoes com risco de segredo;
- rodar Trivy/Syft/Grype em releases, Docker e dependencias;
- gerar SBOM como artifact;
- bloquear P0/P1 de segredo e vulnerabilidade critica;
- permitir waivers auditaveis com motivo, validade e escopo;
- nunca enviar secrets para modelo;
- redigir outputs antes de entrar no Context Pack.

Comandos Atlas alvo:

```bash
atlas engineering security-scan --workspace=/repo --profile=release
atlas engineering sbom --workspace=/repo --profile=release
```

### 6.5 API, Contratos E Mocking

| Ferramenta | Uso No Atlas | Por Que Importa |
|---|---|---|
| Schemathesis | testar APIs a partir de OpenAPI | descobre edge cases automaticamente |
| Pact | contract testing consumidor/provedor | evita quebrar cliente/API |
| WireMock | mock de APIs externas | testes sem depender de terceiros |
| Prism | mock/validation OpenAPI | valida contrato HTTP |
| Bruno | colecoes API locais em arquivo | alternativa free/local para colecoes |

Implementacao profissional:

- detectar OpenAPI/Swagger;
- rodar contrato em rotas tocadas quando possivel;
- gerar casos negativos automaticamente;
- gravar requests/responses redigidos;
- bloquear se API nova quebra contrato;
- abrir findings para divergencia de schema, status code, auth, required fields e compatibilidade.

Comandos Atlas alvo:

```bash
atlas engineering api-contract --workspace=/repo --openapi=openapi.yaml
atlas engineering api-smoke --workspace=/repo --collection=bruno
```

### 6.6 Observabilidade E Traces

| Ferramenta | Uso No Atlas | Por Que Importa |
|---|---|---|
| OpenTelemetry | traces, metrics e logs padronizados | lingua comum de observabilidade |
| Prometheus | metricas time-series | medicao local/self-hosted |
| Grafana | dashboards | leitura operacional |
| Jaeger | tracing distribuido | entender fluxo entre servicos |
| Loki | logs agregados | busca de logs local/self-hosted |
| Sentry self-hosted | erros em runtime | opcional, mais pesado |

Implementacao profissional:

- propagar `trace_id` Atlas em execucoes;
- anexar logs e traces a runs;
- criar spans para provider, ferramenta, teste, browser, API, DB e queue;
- medir duracao, custo, tokens, retries, falhas, tempo de fila e timeout;
- usar observabilidade como evidencia de regressao;
- manter dashboards locais para Atlas AI, Harness e App.

Comandos Atlas alvo:

```bash
atlas observability doctor
atlas engineering trace inspect --run=123
atlas ai telemetry report --window=7d
```

### 6.7 Performance, Carga E Regressao

| Ferramenta | Uso No Atlas | Por Que Importa |
|---|---|---|
| k6 | carga HTTP scripted | mede API e fluxos de backend |
| Locust | carga com Python | cenarios mais programaveis |
| autocannon | benchmark HTTP Node | smoke rapido |
| Lighthouse CI | performance web | regressao de frontend |
| hyperfine | benchmark CLI | compara comandos |

Implementacao profissional:

- manter perf smoke pequeno por default;
- rodar carga maior apenas sob comando explicito;
- armazenar baseline por projeto/rota/comando;
- bloquear release se regressao ultrapassar threshold;
- diferenciar ruido local de tendencia historica;
- anexar graficos e resumo estatistico.

Comandos Atlas alvo:

```bash
atlas engineering perf-smoke --workspace=/repo --profile=api
atlas benchmark run --suite=engineering-core --include=performance
```

### 6.8 Code Intelligence E Busca Estrutural

| Ferramenta | Uso No Atlas | Por Que Importa |
|---|---|---|
| ripgrep | busca textual rapida | primeira camada de contexto |
| Tree-sitter | parse AST multi-linguagem | entende codigo por estrutura |
| ast-grep | busca/refactor estrutural | encontra padroes melhores que texto |
| ctags | indice simbolico simples | navegacao e contexto |
| Serena | LSP/MCP para agentes de codigo | IDE semantica para leitura/edicao por simbolo |
| Codebase-Memory-like graph | grafo persistente de codigo | call graph, impacto e comunidades de codigo |
| Semantiq/Vera-like local semantic search | busca semantica local | encontra conceitos sem depender de SaaS |
| JetBrains MCP | capacidades IDE via MCP | navegacao/refactor em IDEs JetBrains |
| VS Code MCP | capacidades IDE via MCP | ponte operacional com VS Code/Cursor-like flows |
| Aider repo-map | mapa compacto do repo | contexto barato para edicoes multi-arquivo |
| Continue CLI/IDE | assistente open-source IDE/CLI | integracao opcional com modelos locais/remotos |
| OpenHands | agente autonomo open-source | benchmark/agente externo para comparacao |
| Sourcegraph local/opcional | busca em repos grandes | avaliar se necessario |

Implementacao profissional:

- indexar simbolos relevantes por repo;
- mapear arquivos tocados a funcoes/classes/componentes;
- montar Context Pack com trechos certos;
- detectar mudancas de API interna;
- detectar duplicidade;
- permitir queries estruturais em vez de regex fraca;
- alimentar memoria de arquitetura.
- tratar MCP/IDE agents como tools externas, nunca como autoridade unica;
- registrar capabilities por ferramenta: `symbol_lookup`, `find_references`, `rename_symbol`, `impact_analysis`, `repo_map`, `semantic_search`, `agent_run`;
- exigir dry-run e policy explicita para qualquer ferramenta com capacidade de escrita;
- preservar Code Intelligence interno do Atlas como indice canonico e usar Serena/MCP como aceleradores interativos.

Comandos Atlas alvo:

```bash
atlas engineering code-index --workspace=/repo
atlas engineering code-query --workspace=/repo --pattern='function $NAME($$$ARGS) { $$$BODY }'
atlas engineering semantic-doctor --workspace=/repo
atlas engineering semantic-query --workspace=/repo --q="onde a autorizacao de tools e aplicada?"
```

### 6.8.1 Camada IDE/MCP Para Programacao Pesada

Objetivo: dar ao Atlas uma camada de entendimento de codigo comparavel a uma IDE,
sem prender o produto a um unico agente ou editor.

Prioridade de roadmap:

| Prioridade | Ferramenta | Papel | Obrigatoria? |
|---|---|---|---|
| P0 | Serena | LSP/MCP, simbolos, referencias e edicao semantica | nao |
| P0 | Tree-sitter graph interno | grafo local canonico do Atlas | sim, quando implementado |
| P0 | ast-grep | query/refactor estrutural local | nao |
| P1 | Aider repo-map | comparar/absorver estrategia de repo-map | nao |
| P1 | Codebase-Memory/Semantiq/Vera-like search | busca semantica local token-efficient | nao |
| P1 | JetBrains/VS Code MCP | capacidades IDE quando operador usar editor | nao |
| P2 | Continue CLI/IDE | agente assistivo open-source opcional | nao |
| P2 | OpenHands | agente externo para benchmark/comparacao | nao |

Contrato desejado no Runtime:

- `category=semantic_code_intelligence`;
- `risk_level=medium` para leitura; `high` quando houver edicao;
- `runtime=mcp|cli|ide_bridge`;
- capabilities separadas entre leitura e escrita;
- evidence com queries executadas, simbolos encontrados, arquivos afetados,
  hashes e recomendacoes;
- policy bloqueia rede e escrita por padrao em automacoes;
- nenhuma ferramenta MCP pode receber secrets ou artifacts privados sem redaction.

O Atlas deve ser o control-plane: Serena, Aider, Continue e OpenHands sao
fornecedores de capacidade. A decisao final continua vindo dos gates, evidencias,
testes, diffs, waivers e memoria canonica do Atlas.

### 6.8.2 Politica De Execucao T0-T3

Ferramenta forte sem politica de custo vira ruído. O Atlas deve declarar quando
cada ferramenta roda, qual budget ela tem e se pode bloquear.

| Tier | Momento | Budget alvo | Exemplos | Papel no gate |
|---|---|---:|---|---|
| T0 interactive | leitura, planejamento e edicao incremental | <1s por query/resposta incremental | Serena/LSP leitura, ripgrep, ast-grep query, symbol lookup, repo-map cache | orienta contexto, nao bloqueia sozinho |
| T1 local fast | antes de concluir patch local ou replay curto | <30s por escopo pequeno | lint/format escopado, typecheck incremental, secret scan em diff, coverage de arquivo tocado, boundary pequeno | bloqueia erro direto e escopado |
| T2 PR/review | benchmark, review ou risco medio/alto | <10min | full typecheck, Semgrep full, CodeQL, Schemathesis, axe por rotas tocadas, Docker smoke | bloqueia por severidade/policy |
| T3 nightly/release | release, nightly e auditoria | minutos a horas | Infection/Stryker, Lighthouse full, license audit, ScanCode/ORT, Trivy full, carga/performance | bloqueia release ou gera debt priorizado |

Regras:

- T0 nao escreve sem approval.
- T1 deve preferir changed-files scope e falhar rapido.
- T2 usa worktree/cache/artifacts e so vira required quando risco justificar.
- T3 nunca deve travar fluxo interativo; ele governa release/nightly.
- Tool definition deve guardar `execution_tier`, `expected_cost` e `default_trigger`.
- Policy pode elevar/rebaixar tier por workspace, task type, risco, historico e cache.

### 6.8.3 Matriz De Autoridade Anti-Duplicacao

Ferramentas sobrepostas sao aceitaveis quando existe autoridade. Sem isso, o
dev recebe tres relatórios concorrentes e ignora todos.

| Categoria | Primaria | Complementares | Regra |
|---|---|---|---|
| PHP static analysis | PHPStan ou Psalm conforme repo | Psalm taint, PHPMD | primaria bloqueia; complementares bloqueiam high/critical ou policy |
| TS/JS type/lint | `tsc` + ESLint/Biome | Knip, ts-prune | type/lint bloqueia; dead-code vira warning salvo release policy |
| Arquitetura PHP | Deptrac | custom Atlas boundary rules | violacao nova bloqueia, debt legado usa baseline |
| Arquitetura TS/JS | dependency-cruiser | Madge | ciclos novos em core bloqueiam |
| SAST semantico | CodeQL | Semgrep, Psalm taint | high/critical bloqueia; duplicatas correlacionadas nao contam duas vezes |
| Secrets | Gitleaks | Trivy secret scan, Atlas redaction scanner | segredo real bloqueia sempre |
| Vulnerabilidades | OSV-Scanner | Trivy, Grype | high/critical bloqueia release sem waiver |
| SBOM | Syft | Trivy SBOM | release exige artifact SBOM |
| API contract | `atlas_api_contract` | Schemathesis, Pact, Prism, WireMock, Bruno | contrato quebrado bloqueia; fuzz entra por tier |
| Acessibilidade | axe-core | Pa11y | critical/serious em rota tocada bloqueia |
| Performance frontend | Lighthouse CI | bundle analyzer | bloqueia por budget versionado |
| Visual | Playwright screenshots + pixelmatch | trace analyzer | strict bloqueia, observe vira warning |
| Mutacao | Infection/Stryker | coverage | T3 bloqueia quando policy exige score |
| Licencas | ScanCode/ORT | licensee | licenca proibida bloqueia release |

Regras:

- um finding correlacionado nao deve gerar multiplos bloqueios;
- a primaria define severidade default;
- complementares podem elevar severidade quando trazem evidencia mais precisa;
- discordancia fica registrada no Evidence Store, mas gate usa a matriz;
- waiver aponta para finding/fingerprint, nao para desligar ferramenta.

### 6.8.4 Contrato De Agentes Externos

Aider, Continue, OpenHands e agentes similares so sao fortes se o Atlas continuar
como substrato. O contrato de produto e:

- Atlas fornece contexto: Code Intelligence, semantic graph, task contract,
  evidencias recentes, policy, selected files e constraints.
- Agente externo executa em worktree/sandbox e devolve patch, arquivos tocados,
  comandos, stdout/stderr redigidos e rationale resumido.
- Atlas valida com diff scope, testes, quality/security scan, API/visual
  contract, architecture boundaries e release gate.
- Atlas persiste evidencias, artifacts, findings, custo, duracao e decisao.
- Agente nunca recebe secrets, artifacts privados brutos ou rede sem approval.
- Agente nunca decide `resolved`; Harness/Gate/Operator decidem.
- MCP/IDE bridge deve consumir o indice do Atlas quando possivel; quando nao
  consumir, o agente perde autoridade e recebe validacao mais conservadora.

### 6.9 Refatoracao E Transformacoes Mecanicas

| Ferramenta | Stack | Uso No Atlas |
|---|---|---|
| Rector | PHP | upgrades e refactors seguros |
| jscodeshift | JS/TS | codemods |
| ts-morph | TS | transformacoes AST com TypeScript |
| OpenRewrite | Java/Kotlin e mais | refactors em larga escala |
| comby | multi-linguagem | substituicao estrutural |
| ast-grep rewrite | multi-linguagem | refactor estrutural com pattern AST |
| Semgrep autofix | multi-linguagem | correcao guiada por regra |
| prettier/pint/biome | formatacao | estabilizar diff |

Implementacao profissional:

- nunca rodar transformacao grande sem dry-run;
- mostrar plano de arquivos afetados;
- aplicar por lote;
- rodar testes entre lotes;
- comparar diff;
- permitir rollback via git/worktree;
- registrar transform recipe como artifact.

Comandos Atlas alvo:

```bash
atlas engineering refactor plan --workspace=/repo --recipe=rector
atlas engineering refactor apply --workspace=/repo --recipe=rector --batch=20
```

### 6.11 Testes Profundos, Mutacao E Propriedades

| Ferramenta | Stack | Uso No Atlas |
|---|---|---|
| Infection | PHP | mutation testing para medir qualidade real dos testes |
| Stryker | JS/TS | mutation testing frontend/backend Node |
| fast-check | TS/JS | property-based testing |
| Hypothesis | Python | property-based testing |
| PHPUnit/Pest coverage | PHP | cobertura de linhas/branches |
| Vitest/Jest coverage | TS/JS | cobertura frontend/lib |
| Playwright trace analyzer | web | diagnostico de falha E2E |
| snapshot-diff/pixelmatch | UI | regressao visual local |

Implementacao profissional:

- mutation testing nao roda em todo commit por padrao; entra em perfil `deep` ou
  release de alto risco;
- coverage baixo nao deve ser score cosmetico: precisa mapear arquivos tocados;
- property tests entram quando task altera parsers, policies, normalizers,
  autorizacao, contratos ou transformacoes;
- artifacts guardam mutants sobreviventes, seeds, coverage summary e exemplos
  minimizados.

Comandos Atlas alvo:

```bash
atlas engineering mutation --workspace=/repo --profile=changed
atlas engineering property-test --workspace=/repo --target=normalizer
```

### 6.12 Frontend, Acessibilidade E Produto

| Ferramenta | Uso No Atlas | Por Que Importa |
|---|---|---|
| axe-core | acessibilidade automatica | bloqueia regressao a11y grave |
| Pa11y | a11y por rota | smoke simples em paginas |
| Lighthouse CI | performance/best practices/SEO | budget operacional de frontend |
| Playwright traces | debug de UI real | evidencia reproduzivel |
| bundle analyzer | tamanho de bundle | evita degradacao silenciosa |
| source-map-explorer | analise de bundle | identifica dependencias pesadas |
| pixelmatch | visual diff local | regressao visual sem SaaS |

Implementacao profissional:

- a11y critical/serious bloqueia quando rota tocada;
- Lighthouse roda em smoke pequeno por padrao e completo em release;
- budgets vivem por projeto/rota;
- traces e screenshots entram como artifacts redigidos;
- nao usar SaaS visual pago como requisito.

### 6.13 Arquitetura, Dependencias E Boundaries

| Ferramenta | Stack | Uso No Atlas |
|---|---|---|
| Deptrac | PHP | regras de camada e dependencia |
| dependency-cruiser | JS/TS | boundaries, ciclos e imports |
| Madge | JS/TS | circular dependencies |
| ArchUnit-like rules | Java/.NET quando aplicavel | arquitetura como teste |
| custom Atlas boundary rules | qualquer stack | regras de produto/harness |

Implementacao profissional:

- boundaries viram policy versionada no repo;
- ciclos novos em camadas core bloqueiam;
- exceptions exigem waiver com TTL;
- impacto arquitetural entra no Context Pack antes de patch grande.

### 6.10 Documentos, Dados E Conhecimento

| Ferramenta | Uso No Atlas | Por Que Importa |
|---|---|---|
| Pandoc | conversao de documentos | padroniza ingestao/export |
| Tesseract OCR | texto de imagens/PDFs escaneados | memoria a partir de documentos reais |
| ffmpeg | audio/video | preparacao de midia |
| Whisper/local | transcricao | captura reunioes/videos |
| DuckDB | analise local de dados | SQL em arquivos |
| SQLite | storage leve local | caches e indices |

Implementacao profissional:

- documentos entram como artifacts;
- extracao vira texto redigido e indexavel;
- fonte original fica hashada;
- memoria nao deve aceitar documento bruto sem provenance;
- dados sensiveis precisam de classificacao antes de ir para provider.

Comandos Atlas alvo:

```bash
atlas knowledge ingest --file=documento.pdf --scope=project
atlas data inspect --file=metrics.csv
```

### 6.11 Automacao E Workflows Longos

| Ferramenta | Uso No Atlas | Por Que Importa |
|---|---|---|
| Temporal | workflows duraveis | tarefas longas, retries e estado |
| BullMQ/queues | jobs simples | execucao assinc local |
| cron/launchd | agendamentos locais | rotinas |
| Make/Taskfile/Just | comandos padronizados | DX e reproducibilidade |

Implementacao profissional:

- Harness continua decidindo;
- orquestrador executa;
- cada etapa gera trace;
- retry tem politica;
- tarefa longa tem estado visivel no app;
- operador pode pausar/cancelar.

---

## 7. Matriz De Decisao Por Tipo De Tarefa

| Tipo De Tarefa | Ferramentas Minimas | Ferramentas Fortes | Gate Para `resolved` |
|---|---|---|---|
| UI visual | typecheck, visual smoke | Playwright, axe, Lighthouse, baseline | rota abriu, sem console error critico, screenshot capturado |
| API backend | teste unitario/feature | Schemathesis, Pact, WireMock | contrato e testes passam |
| Banco/migration | migrate status, testes | DB container, schema diff | migration roda em DB limpo |
| Seguranca | Gitleaks | Trivy, Semgrep, Syft/Grype | nenhum segredo ou P0/P1 |
| Refactor amplo | typecheck, tests | AST query, codemod dry-run | diff dentro do escopo e testes passam |
| Performance | smoke de tempo | k6, Lighthouse CI, hyperfine | sem regressao acima do threshold |
| Dependencias | lockfile check | SBOM, OSV, Trivy | sem CVE critica sem waiver |
| CLI | help, completion, smoke | hyperfine, snapshot tests | comando executa e help documenta |
| Mobile/app | typecheck | Detox futuro, Playwright web, Health checks | tela principal nao quebra e deep links passam |
| Memoria/AI | unit tests, trace smoke | evals, benchmark, privacy scan | trace e memoria redigida corretos |

---

## 8. Politicas De Uso

### 8.1 Failure Policy

Cada ferramenta deve declarar uma politica de falha.

| Policy | Comportamento |
|---|---|
| `advisory` | registra warning, nao bloqueia |
| `blocks_resolved` | impede decision `resolved` |
| `blocks_apply` | impede aplicar patch |
| `blocks_release` | impede release/promo |
| `requires_human` | exige decisao do operador |

Exemplos:

- Gitleaks achando secret: `blocks_resolved`;
- Playwright ausente em repo sem UI: `skipped`;
- Playwright ausente em task que exige QA visual: `blocks_resolved`;
- Lighthouse com score menor que baseline em landing critica: `blocks_release`;
- Semgrep low severity: `advisory`;
- Semgrep high security: `blocks_resolved`.

### 8.2 Required Vs Opportunistic

Ferramentas podem rodar em dois modos:

| Modo | Significado |
|---|---|
| `required` | a tarefa exige aquela evidencia |
| `opportunistic` | roda se estiver disponivel e barato |

O Atlas nao deve bloquear tarefa simples porque uma ferramenta opcional nao esta instalada.

Mas deve bloquear tarefa critica quando a ferramenta obrigatoria falha ou esta ausente.

### 8.3 Time Budget

Cada execucao precisa de budget.

| Perfil | Tempo Alvo | Uso |
|---|---|---|
| `fast` | 10-60s | feedback rapido no CLI/app |
| `standard` | 1-5min | run normal do Harness |
| `deep` | 5-30min | benchmark, release, refactor grande |
| `overnight` | >30min | scans amplos, indexacao, pesquisa pesada |

### 8.4 Data Safety

O Runtime deve classificar dados antes de enviar ao modelo:

| Tipo | Regra |
|---|---|
| secrets | nunca enviar; redigir |
| tokens/logins | redigir |
| dados pessoais | minimizar e marcar |
| codigo proprietario | permitido dentro do contexto local do operador |
| logs grandes | resumir e anexar artifact |
| screenshots | mostrar ao operador; enviar ao modelo apenas quando necessario |
| traces | resumir spans relevantes |

### 8.5 Waivers

Waiver e excecao auditavel, nao silencio.

Todo waiver deve ter:

- id;
- motivo;
- operador;
- data;
- validade;
- ferramenta;
- regra;
- escopo;
- risco aceito;
- link para run/finding;
- politica de expiracao.

---

## 9. Modelo De Dados Alvo

O Atlas pode evoluir usando tabelas existentes inicialmente, mas a arquitetura alvo deve ter entidades claras.

### 9.1 `atlas_tool_definitions`

Cadastro canonico de ferramentas.

Campos alvo:

- `id`;
- `slug`;
- `name`;
- `category`;
- `description`;
- `homepage`;
- `license_posture`;
- `cost_posture`;
- `default_enabled`;
- `default_timeout_seconds`;
- `default_failure_policy`;
- `capabilities_json`;
- `detect_json`;
- `outputs_json`;
- `risks_json`;
- `created_at`;
- `updated_at`.

### 9.2 `atlas_tool_installations`

Estado detectado por workspace/runtime.

Campos alvo:

- `id`;
- `tool_definition_id`;
- `workspace_hash`;
- `execution_layer`;
- `status`;
- `version`;
- `binary_path_hash`;
- `node_modules_path_hash`;
- `detected_at`;
- `metadata_json`.

### 9.3 `atlas_tool_runs`

Execucao individual.

Campos alvo:

- `id`;
- `tool_definition_id`;
- `surface`;
- `workspace`;
- `run_context_type`;
- `run_context_id`;
- `status`;
- `required`;
- `failure_policy`;
- `command_hash`;
- `exit_code`;
- `started_at`;
- `finished_at`;
- `duration_ms`;
- `stdout_artifact_id`;
- `stderr_artifact_id`;
- `summary_json`;
- `metadata_json`.

### 9.4 `atlas_tool_artifacts`

Artifacts gerados por ferramenta.

Campos alvo:

- `id`;
- `tool_run_id`;
- `type`;
- `path`;
- `filename`;
- `mime_type`;
- `size_bytes`;
- `sha256`;
- `is_redacted`;
- `preview_json`;
- `created_at`.

### 9.5 `atlas_tool_findings`

Achados normalizados.

Campos alvo:

- `id`;
- `tool_run_id`;
- `rule_id`;
- `title`;
- `message`;
- `severity`;
- `confidence`;
- `file_path`;
- `line`;
- `end_line`;
- `fingerprint`;
- `blocks_resolved`;
- `waiver_id`;
- `status`;
- `metadata_json`;
- `created_at`;
- `updated_at`.

### 9.6 `atlas_tool_policies`

Politica configuravel por workspace/projeto.

Campos alvo:

- `id`;
- `scope_type`;
- `scope_id`;
- `tool_slug`;
- `enabled`;
- `required_when_json`;
- `failure_policy`;
- `timeout_seconds`;
- `thresholds_json`;
- `created_at`;
- `updated_at`.

### 9.7 Relacao Com Tabelas Ja Existentes

O Super Tool Runtime deve se integrar sem duplicar indevidamente:

| Area Existente | Relacao |
|---|---|
| `ai_tool_events` | eventos gerais de ferramenta no Atlas AI |
| `ai_traces` | trace de IA/modelo que originou ou consumiu a ferramenta |
| `atlas_engineering_runs` | run de engenharia que orquestra ferramentas |
| `atlas_engineering_test_runs` | testes e visual smoke ja capturados |
| `atlas_engineering_control_results` | controles derivados dos resultados |
| `atlas_engineering_review_findings` | findings de engenharia que bloqueiam resolucao |
| `atlas_memory_entries` | aprendizado promovido |
| `atlas_verbatim_memories` | decisoes/logs/outputs que precisam voltar verbatim |
| `atlas_engineering_benchmark_results` | comparacao historica de qualidade |

Regra:

> Nao criar tabela nova se a entidade existente representa o mesmo conceito. Criar tabela nova apenas quando o conceito e transversal e nao pertence so ao Engineering Harness.

---

## 10. CLI Alvo

O CLI deve deixar ferramentas poderosas acessiveis sem obrigar Vitor a lembrar comandos individuais.

### 10.1 Descoberta E Saude

```bash
atlas tools doctor
atlas tools doctor --workspace=/repo
atlas tools list
atlas tools status playwright
```

Saida esperada:

- ferramenta;
- status;
- versao;
- camada usada;
- comandos disponiveis;
- riscos;
- se e obrigatoria em algum perfil;
- como instalar quando faltar.

### 10.2 Quality Scan

```bash
atlas engineering quality-scan --workspace=/repo
atlas engineering quality-scan --workspace=/repo --profile=fast
atlas engineering quality-scan --workspace=/repo --profile=release
atlas engineering quality-scan --workspace=/repo --changed-only
```

Perfis:

| Perfil | Inclui |
|---|---|
| `fast` | typecheck/lint/testes detectados de baixo custo |
| `standard` | fast + security leve + scripts de repo |
| `release` | standard + SBOM + Trivy/Grype + visual/API/perf quando aplicavel |
| `deep` | release + varreduras amplas e benchmark |

### 10.3 Visual E Browser

```bash
atlas engineering visual-smoke --workspace=/repo --routes=/,/inbox
atlas engineering visual-smoke --workspace=/repo --screenshot-driver=auto
atlas engineering visual-baseline list --workspace=/repo
atlas engineering visual-baseline promote --workspace=/repo --apply
```

### 10.4 API E Contratos

```bash
atlas engineering api-contract --workspace=/repo --openapi=openapi.yaml
atlas engineering api-smoke --workspace=/repo
```

### 10.5 Seguranca

```bash
atlas engineering security-scan --workspace=/repo
atlas engineering sbom --workspace=/repo
atlas engineering secrets-scan --workspace=/repo
```

### 10.6 Observabilidade

```bash
atlas observability doctor
atlas observability traces --run=123
atlas observability dashboard
```

### 10.7 Refatoracao

```bash
atlas engineering refactor plan --workspace=/repo --recipe=upgrade-laravel
atlas engineering refactor apply --workspace=/repo --recipe=upgrade-laravel --batch=20
```

---

## 11. App Alvo

O app nao deve virar painel tecnico caotico.

Ele deve mostrar:

### 11.1 Home Ou Inbox Operacional

Cards resumidos:

- "Engineering Harness";
- "Tool Health";
- "Quality Risks";
- "Visual Baselines";
- "Security Findings";
- "Benchmark Trends".

### 11.2 Tela Engineering

Blocos:

- ultimo run;
- score;
- gates;
- findings bloqueantes;
- artifacts visuais;
- diff;
- attempts;
- baseline;
- replay;
- accept/reject/needs human.

### 11.3 Tela Tool Runtime

Blocos:

- ferramentas detectadas;
- ferramentas faltantes;
- versoes;
- workspaces;
- politicas;
- comandos recomendados;
- ultimas falhas;
- custo/tempo medio;
- impacto em benchmark.

### 11.4 Tela De Finding

Um finding deve ser acionavel:

- titulo;
- severidade;
- ferramenta;
- regra;
- arquivo/linha;
- motivo;
- evidence/artifact;
- recomendacao;
- botao "criar task";
- botao "reparar com Harness";
- botao "waiver auditavel";
- botao "marcar falso positivo".

---

## 12. Integracao Com Atlas AI

### 12.1 Context Pack Builder

O Context Pack deve receber apenas o necessario:

- resumo de ferramenta;
- findings relevantes;
- artifacts com links;
- trechos pequenos;
- hash/provenance;
- limites e lacunas.

Nao deve jogar outputs gigantes no prompt.

### 12.2 Provider/Model Router

A politica de modelo deve considerar ferramentas disponiveis.

Exemplos:

- se ha Playwright e screenshot, modelo visual pode ser util;
- se ha SARIF normalizado, modelo barato pode resumir;
- se ha refactor amplo, modelo melhor pode planejar;
- se ha teste deterministico falhando, talvez nao precise modelo caro para diagnostico simples.

### 12.3 Memory Writer

Ferramentas podem propor memorias:

- "Neste repo, `npm run typecheck` e o gate confiavel de TS";
- "A rota `/inbox` e visual critica";
- "Playwright do workspace esta ausente; usar runtime Atlas-managed";
- "Trivy gera falso positivo X ate versao Y";
- "Migration deve ser testada com `/opt/homebrew/bin/php` neste ambiente";
- "Para app Expo web, erros de notificacao no web precisam guard por Platform".

Memoria tecnica nao deve ser aceita automaticamente quando tiver risco alto. Ela vira delta/review.

### 12.4 Atlas-Bench

O benchmark deve medir:

- tempo ate resolved;
- pass rate;
- regressao visual;
- seguranca;
- taxa de falso positivo;
- custo por run;
- qualidade percebida;
- necessidade de intervencao humana;
- provider/model policy usada;
- ferramentas que mais ajudaram.

---

## 13. Integracao Com Engineering Harness Runner

O Engineering Harness Runner deve ser o primeiro grande consumidor do Super Tool Runtime.

### 13.1 Antes Da Execucao

O Runner deve:

1. ler task contract;
2. calcular harnessability;
3. detectar stack;
4. montar tool plan;
5. decidir required/opportunistic;
6. escolher sandbox;
7. registrar estrategia.

### 13.2 Durante A Execucao

O Runner deve:

1. aplicar patch em ambiente controlado;
2. capturar diff;
3. rodar ferramentas;
4. capturar artifacts;
5. normalizar resultados;
6. criar controls/findings;
7. permitir repair loop quando util.

### 13.3 Depois Da Execucao

O Runner deve:

1. calcular score;
2. decidir `resolved|partial|failed|needs_human`;
3. registrar benchmark candidate;
4. gravar memoria candidata;
5. atualizar policy historica;
6. exibir run no app.

### 13.4 Gates Minimos Por Categoria

| Categoria | Gates |
|---|---|
| UI | typecheck + visual smoke quando rota afetada |
| Backend | teste detectado + migration/schema se aplicavel |
| API | feature test + contract quando OpenAPI existe |
| Seguranca | Gitleaks em diff + scanner quando deps/config mudam |
| Refactor | changed-files scope + tests + static analysis |
| Mobile | typecheck + web smoke quando Expo web permite |

---

## 14. Implementacao Por Fases

### Fase 0 - Fundacao De Registry E Politica

Objetivo: criar base transversal para ferramentas.

Status em 2 de maio de 2026: **implementada como bloco backend inicial no `atlas-server`**.

Entregue:

- migration `2026_05_02_011000_create_atlas_tool_runtime_tables`;
- models `AtlasToolDefinition`, `AtlasToolInstallation`, `AtlasToolPolicy`, `AtlasToolRun`, `AtlasToolArtifact` e `AtlasToolFinding`;
- services `AtlasToolRegistryService`, `AtlasToolPolicyEngine`, `AtlasToolExecutor`, `AtlasToolResultNormalizer`, `AtlasToolEvidenceStore`, `AtlasToolEvidenceQueryService` e `AtlasToolFindingWaiverService`;
- catalogo inicial de ferramentas gratuitas/project-local ja usadas ou previstas pelo Harness;
- CLI `atlas tools doctor|list|authority|authority-policies|set-authority-policy|revoke-authority-policy|status|run|evidence|evidence-show|evidence-export|gate|approve|revoke|waive-finding|revoke-finding-waiver|policies`;
- API `GET /tools`, `GET /tools/doctor`, `GET /tools/authority`, `GET /tools/authority/policies`, `PUT /tools/authority/policies/{authorityGroup}`, `DELETE /tools/authority/policies/{authorityGroup}`, `GET /tools/evidence`, `GET /tools/evidence/{run}`, `GET /tools/evidence/{run}/export`, `GET /tools/gate`, `GET /tools/policies`, `GET /tools/{tool}`, `GET /tools/{tool}/commands`, `POST /tools/{tool}/commands/{recipe}/run`, `POST /tools/{tool}/run`, `POST /tools/{tool}/approval`, `DELETE /tools/{tool}/approval`, `POST /tools/findings/{finding}/waiver` e `DELETE /tools/findings/{finding}/waiver`;
- `EngineeringQualityScanService` passou a registrar evidencias tambem no runtime generico, preservando seus artifacts e fluxo atuais;
- `AtlasEngineeringQualityScanCommand` aceita `--run-context-type` e `--run-context-id` para vincular evidencias ao contexto executor;
- `EngineeringTestMatrixService` injeta contexto de `engineering_run` no quality scan gerenciado e espelha o output capturado para o Evidence Store quando a evidencia do subprocesso ainda nao esta visivel;
- `EngineeringHarnessRunnerService` grava o controle `atlas_tool_runtime_gate`, filtrando evidencias por workspace, surface `engineering_quality_scan` e id do run;
- sensores internos `atlas_visual_smoke` e `atlas_code_intelligence` registrados no catalogo como tools Atlas-managed;
- `AtlasEngineeringVisualSmokeCommand` registra manifest, route artifacts, contexto de run quando informado e findings normalizados em `engineering_visual_smoke`;
- `EngineeringTestMatrixService` injeta contexto de `engineering_run` no Visual Smoke gerenciado e espelha o manifest capturado para o Evidence Store quando necessario;
- `EngineeringHarnessRunnerService` grava o controle `atlas_tool_runtime_visual_gate`, filtrando evidencias visuais por workspace, surface `engineering_visual_smoke` e id do run;
- `EngineeringCodeIntelligenceService` registra index/audit em `engineering_code_intelligence`, incluindo metricas, contexto opcional de runtime e findings de drift.
- Quality Scan aciona OSV-Scanner quando ha lockfile/manifesto de dependencia nos perfis `standard/release/deep` e Trivy, Syft e Grype nos perfis `release/deep`, preservando `skipped` auditavel quando as ferramentas nao existem.
- `AtlasToolResultNormalizer` centraliza parsers estruturados para Gitleaks, Semgrep, ESLint, PHPStan, Psalm, ShellCheck, Trivy, OSV-Scanner e Grype, usados tanto por `atlas tools run` quanto pelo Quality Scan; para OSV/Trivy/Syft/Grype tambem extrai metricas de pacotes, vulnerabilidades, severidades e bloqueios.
- `atlas engineering security-scan` executa somente `gitleaks`, `semgrep`, `osv_scanner`, `trivy` e `grype` pelo mesmo Quality Scan filtrado.
- `atlas engineering sbom` executa somente `syft` pelo mesmo runtime e persiste metricas/resumo de SBOM no Evidence Store.
- `POST /engineering/security-scan` e `POST /engineering/sbom` expõem os mesmos fluxos para app/automacoes protegidas por token.
- Waivers auditaveis de findings usam `atlas_tool_findings.status=waived`, `waiver_id` e `metadata_json.waiver`, com motivo, operador, origem, TTL opcional, revogacao e historico; gates ignoram apenas findings com waiver valido e voltam a bloquear quando o waiver expira ou e revogado.
- Release gate Security/SBOM implementado por `AtlasToolReleaseGateService`, CLI `atlas tools release-gate` e API `GET /tools/release-gate`; exige evidencia de secret scan, static security, dependency vulnerability scan e SBOM normalizado, reutilizando `AtlasToolGateService` em modo waiver-aware para nao bloquear release quando o scanner falhou apenas por findings com waiver valido.
- Gates suportam freshness auditavel por CLI/API: `max_age_minutes` e `stale_blocks`, com `summary.stale_evidence_count`, `freshness` e `runs[].evidence_age_minutes` no payload. O release gate usa freshness forte por padrao, bloqueando evidencias acima de 24h.
- Gates suportam selecao `latest_per_tool` para avaliar somente a evidencia mais nova por ferramenta, mantendo `summary.input_run_count` para auditoria. O release gate usa `latest_per_tool` por padrao para que falhas historicas nao bloqueiem release quando uma evidencia mais nova e valida existe.
- API Contract Harness inicial implementado por `EngineeringApiContractService`, sensor interno `atlas_api_contract`, CLI `atlas engineering api-contract` e API `POST /engineering/api-contract`; detecta OpenAPI JSON/YAML, valida estrutura minima, compara paths/metodos contra rotas Laravel, exige `responses`, gera findings por endpoint e persiste evidencia `engineering_api_contract`.
- Politica T0-T3 com autoridade anti-duplicacao iniciada no runtime: migration adiciona `execution_tier`, `expected_cost`, `default_trigger`, `authority_role` e `authority_group` ao registry; `AtlasToolPolicyEngine` publica esses campos na decisao e respeita `max_execution_tier` em CLI/API.
- Programming Power Tools do roadmap foram semeados como ferramentas opcionais no registry, incluindo Serena, Tree-sitter, ast-grep, ctags, CodeQL, Infer, Checkov, Terrascan, Kubernetes/container/license tools, Rector/PHPMD/PHPCPD/dependency hygiene, Knip/ts-prune, Schemathesis/Pact/Prism/WireMock/Bruno, Infection/Stryker/fast-check, axe/Pa11y/Lighthouse, dependency-cruiser/Madge/Deptrac e Aider/Continue/OpenHands.
- `AtlasToolAuthorityMatrixService`, `atlas tools authority --json` e `GET /tools/authority` expoem a matriz T0-T3/autoridade como contrato operacional: resumo por tier, grupos de autoridade, primarias, complementares, fallbacks, executores, ferramentas high-risk/release-heavy e recomendacoes de governanca.
- O painel Engineering consome essa matriz no card Super Tool Runtime, junto com doctor/evidence/gate, exibindo distribuicao T0-T3, recomendacoes, grupos sem primaria, coautoridade e top grupos de autoridade.
- `AtlasToolAuthorityPolicyService`, `atlas tools authority-policies --json` e `GET /tools/authority/policies` expoem o contrato de severidade por authority group, incluindo severidades bloqueantes, severidades de warning e razoes auditaveis usadas pelos gates.
- Overrides workspace/global de authority policy usam `atlas_tool_policies` com `tool_slug=authority:<group>` e `thresholds_json.schema=atlas.tool_authority_policy_override.v1`; `atlas tools set-authority-policy`, `atlas tools revoke-authority-policy`, `PUT /tools/authority/policies/{authorityGroup}` e `DELETE /tools/authority/policies/{authorityGroup}` permitem ajustar thresholds sem editar codigo e sem apagar historico.
- O painel Engineering consome essas policies no card Super Tool Runtime e exibe group/policy/severity nas linhas de gate para explicar por que uma evidencia bloqueou ou apenas avisou.
- O painel Engineering tambem permite operar overrides workspace de authority policy: `Medium bloqueia` aplica threshold conservador para o grupo e `Revogar` remove o override sem apagar historico.
- O primeiro pacote P0 de Programming Power Tools deixou de ser apenas catalogo e ganhou recipes reais no registry: `gitleaks detect-redacted`, `semgrep scan-json`, `osv_scanner recursive-json`, `syft sbom-json`, `trivy fs-json`, `phpstan analyse-json`, `typescript no-emit`, `eslint lint-json`, `laravel_pint format-test`, `biome ci-json`, `hadolint dockerfile-json` e `checkov directory-sarif`.
- `AtlasToolResultNormalizer` parseia tambem diagnosticos textuais de TypeScript (`TSxxxx`), JSON do Laravel Pint, JSON do Biome, JSON do Hadolint e SARIF generico para CodeQL/Checkov, registrando findings normalizados com arquivo, linha, rule id, mensagem, severidade e bloqueio.
- `AtlasToolExecutor` agora aceita env seguro e limite de output como contrato publico: CLI `--tool-env=KEY=VALUE`/`--output-limit`, API `env`/`output_limit`, rejeicao de chaves sensiveis e metadados auditaveis `env_keys`, `output_limit`, `stdout_truncated` e `stderr_truncated`.
- `AtlasToolPolicyEngine` foi ampliado para considerar `sandbox_mode`, `privacy_level`, `task_type` e `requires_provider_safe`; ferramentas com risco de escrita exigem `worktree`/`docker` ou approval, e outputs nao seguros para provider podem ser bloqueados antes da execucao.
- `AtlasToolApprovalService` agora persiste guardrails junto com o approval (`max_execution_tier`, `sandbox_mode`, `privacy_level`, `task_type`, `requires_provider_safe`), de modo que aprovar uma ferramenta nao remove budget, sandbox ou provider-safety.
- O client do app agora possui `runAtlasTool(tool, input)` tipado para o contrato completo de execucao e o painel Engineering exibe as dimensoes de policy nas evidencias recentes.
- O client do app tambem cobre approvals: `listAtlasToolPolicies`, `approveAtlasTool` e `revokeAtlasToolApproval` aceitam/retornam guardrails persistentes; o painel Engineering ja mostra `Approval policies` no card Super Tool Runtime com status, escopo, TTL e guardrails efetivos por workspace, alem de actions operacionais para `Dry-run`, `Aprovar 2h` e `Revogar`.
- O registry agora publica command recipes seguros por ferramenta (`safe_commands`) via `atlas tools commands <tool>` e `GET /tools/{tool}/commands`; recipes incluem categoria, surface recomendada, evidenciabilidade e capacidade de bloqueio, rodam por `atlas tools run-recipe <tool> --recipe=<name>` e `POST /tools/{tool}/commands/{recipe}/run`, e o app usa esse endpoint no `Dry-run`, evitando argv ad hoc na UI.
- O gate agora respeita `recipe_blocking_capable=false`: falhas de recipes diagnosticas viram warning auditavel, sem bloquear release/resolution por um version check, enquanto policy denial e findings bloqueantes continuam fortes.
- Evidence/gate agora filtram por `recipe`, `recipe_category`, `recipe_recommended_surface` e `recipe_blocking_capable`, permitindo consultas e gates por tipo de recipe sem misturar diagnostico com scans bloqueantes.
- O launcher/help/completion do Atlas foi sincronizado com o runtime: `atlas help` e `bin/atlas-completion.bash` divulgam `atlas tools authority` e as opcoes de env/output/tier/sandbox/privacy/provider-safe.
- `AtlasToolFindingCorrelationService` implementa a primeira versao executavel da matriz anti-duplicacao: o gate correlaciona findings bloqueantes entre ferramentas do mesmo `authority_group`, escolhe o achado autoritativo por `authority_role`/severidade, retorna `finding_correlations` e suprime duplicatas apenas na decisao de gate, preservando todos os findings no Evidence Store.
- `AtlasToolGateService` agora aplica thresholds por `authority_group`: secret scan bloqueia qualquer segredo confirmado; SAST, dependency vulnerability, vulnerability scan, IaC, container lint, type/static analysis, formatter, API contract, visual, accessibility e architecture bloqueiam critical/high e promovem medium para warning; SBOM bloqueia critical e deixa severidades menores como warning no gate generico.
- A logica de thresholds foi extraida para `AtlasToolAuthorityPolicyService`, evitando regra escondida dentro do gate e permitindo auditoria por CLI/API/app.
- O gate usa a policy efetiva workspace/global/default em tempo de avaliacao, preservando defaults seguros e permitindo endurecer ou suavizar thresholds por repo com razao auditavel.

Entregas:

- inventario inicial de ferramentas;
- detector de instalacao;
- schema de tool definition;
- policy engine simples;
- normalizador comum;
- artifact store comum;
- CLI `atlas tools doctor`;
- testes com ferramentas fake;
- documentacao de como registrar ferramenta nova.

Criterio de pronto:

- Atlas consegue dizer quais ferramentas existem no workspace;
- ausencia de ferramenta vira estado auditavel;
- output vira JSON Atlas.

### Fase 1 - Quality Scan Profissional

Objetivo: primeiro modulo de alto impacto para todo repo.

Ferramentas:

- scripts do repo;
- PHPStan/Psalm/Pint quando existirem;
- TypeScript/Biome/ESLint quando existirem;
- ShellCheck/Hadolint quando existirem;
- Semgrep quando existir;
- Gitleaks quando existir.
- OSV-Scanner quando existir e houver lockfile/manifesto de dependencia;
- Trivy/Syft/Grype em perfil release/deep quando existirem.

Entregas:

- comando `atlas engineering quality-scan`;
- profiles `fast|standard|release|deep`;
- findings normalizados;
- artifact viewer;
- controle no Engineering Harness;
- app card de riscos.

Criterio de pronto:

- scan roda sem depender de instalacao global;
- ferramentas ausentes geram skip auditavel;
- findings bloqueantes impedem `resolved`.

### Fase 2 - Browser/Visual Power Core

Objetivo: fazer o Atlas enxergar e validar UI real.

Ja existe base:

- `atlas engineering visual-smoke`;
- screenshot driver `auto|workspace|atlas|off`;
- baseline visual;
- artifact capture.

Proximas entregas:

- console error classification;
- axe-core;
- trace viewer;
- rotas criticas por projeto;
- threshold por rota;
- auto-sugestao de baseline promotion;
- mobile viewport matrix.

Criterio de pronto:

- UI touch sem evidencia visual fica `partial` ou `needs_human`;
- screenshot e DOM aparecem no app;
- baseline strict bloqueia regressao aprovada.

### Fase 3 - Security/SBOM Core

Objetivo: impedir que Atlas gere ou aceite risco grave.

Ferramentas:

- Gitleaks;
- Trivy;
- Syft;
- Grype;
- OSV-Scanner;
- Semgrep security.

Entregas:

- `atlas engineering security-scan`;
- `atlas engineering sbom`;
- waivers auditaveis implementados para findings persistidos (`atlas tools waive-finding`, `atlas tools revoke-finding-waiver`, `POST/DELETE /tools/findings/{finding}/waiver`) com motivo, operador, origem, TTL opcional, historico e efeito correto nos gates;
- redaction de secrets;
- release gate Security/SBOM implementado como avaliador de evidencias persistidas (`atlas tools release-gate`, `GET /tools/release-gate`) com requisitos de Gitleaks, Semgrep, OSV/Trivy/Grype e Syft/SBOM.

Criterio de pronto:

- secret em diff bloqueia;
- CVE critica sem waiver bloqueia release;
- SBOM fica anexado em run release.

### Fase 4 - API Contract Harness

Objetivo: validar APIs como contratos, nao so como codigo.

Ferramentas:

- Schemathesis;
- Pact;
- WireMock;
- Prism;
- Bruno collections.

Entregas:

- detector OpenAPI implementado para `openapi.*`, `docs/openapi.*`, `docs/api/openapi.*` e `storage/api-docs/api-docs.json`;
- command `api-contract` implementado como `atlas engineering api-contract`;
- API `POST /engineering/api-contract`;
- request/response redaction inicial: spec copiado para artifact redigido e payload sem body bruto;
- findings por endpoint para operation sem rota, operation sem responses e rota Laravel sem documentacao;
- comparacao de paths trata nomes de parametros como equivalentes para reduzir falso positivo de contrato;
- App `atlas-app/app/engineering.tsx` adiciona acao operacional `API Contract` no painel Super Tool Runtime, usando o workspace informado e `strict` quando o gate esta em modo release;
- contratos no benchmark.

Criterio de pronto:

- endpoint alterado com OpenAPI roda teste de contrato via CLI/API e Evidence Store;
- quebra estrutural ou operation documentada inexistente vira finding bloqueante;
- Schemathesis/Pact/WireMock/Prism ainda ficam como ferramentas externas futuras pelo mesmo registry.

### Fase 5 - Real Environment Harness

Objetivo: reduzir falso positivo de ambiente mockado.

Ferramentas:

- Docker Compose;
- Testcontainers;
- Dev Containers;
- Nix quando repo usar.

Entregas:

- `atlas engineering env doctor`;
- perfis de servico;
- healthcheck orchestration;
- log capture;
- cleanup;
- DB fresh smoke.

Criterio de pronto:

- migration roda em DB limpo;
- servico obrigatorio ausente bloqueia;
- logs de falha aparecem no artifact viewer.

### Fase 6 - Observability Core

Objetivo: cada execucao importante do Atlas ter trace observavel.

Ferramentas:

- OpenTelemetry;
- Prometheus;
- Grafana;
- Jaeger;
- Loki opcional.

Entregas:

- trace id unico;
- spans por modelo/ferramenta/teste/browser;
- metrics por duracao/custo/falha;
- dashboard local;
- correlacao run -> trace -> artifacts.

Criterio de pronto:

- operador consegue abrir um run e entender onde tempo/falha ocorreu;
- performance vira dado historico, nao impressao.

### Fase 7 - Code Intelligence E Refactor Engine

Objetivo: Atlas entender e modificar codigo por estrutura.

Ferramentas:

- Tree-sitter;
- ast-grep;
- ctags;
- Rector;
- jscodeshift;
- ts-morph;
- OpenRewrite quando aplicavel.

Entregas:

- code index por workspace;
- queries estruturais;
- refactor plan;
- codemod dry-run;
- batch apply;
- tests entre lotes.

Criterio de pronto:

- Atlas consegue localizar simbolos e impactos sem depender so de `rg`;
- refactor amplo tem plano, diff, lote e rollback.

### Fase 8 - Workflow Engine

Objetivo: tarefas longas e compostas com estado.

Ferramentas:

- queues atuais;
- Temporal futuro se necessario;
- schedules locais;
- app status.

Entregas:

- runs pausaveis;
- retry policies;
- operator checkpoints;
- estado no app;
- notificacoes de bloqueio;
- memory write ao finalizar.

Criterio de pronto:

- tarefa longa nao depende de uma sessao aberta;
- operador pode pausar, cancelar, revisar e retomar.

---

## 15. Roadmap De Implementacao Recomendada

### Bloco A - Imediato

Construir o `quality-scan`.

Motivo:

- impacto alto;
- custo baixo;
- quase todo repo se beneficia;
- nao exige instalar tudo;
- complementa o Engineering Harness ja implementado;
- cria padrao de Tool Registry, Normalizer e Findings.

Entregas:

- `AtlasEngineeringQualityScanCommand`;
- service `EngineeringQualityScanService`;
- registry inicial;
- fake tool tests;
- findings normalizados;
- artifact capture;
- docs CLI/help/completion.

### Bloco B - Visual Hardening

Fortalecer o visual smoke ja existente.

Entregas:

- axe-core opcional;
- console errors;
- viewport matrix;
- trace artifact;
- app preview melhor;
- critical route registry.

### Bloco C - Security Core

Adicionar Gitleaks + Semgrep + SBOM.

Entregas:

- secrets scan;
- security scan;
- waivers;
- release gate.

### Bloco D - API Contract

Adicionar Schemathesis/Pact/WireMock quando existir contrato.

Entregas:

- OpenAPI detector;
- contract run;
- request/response artifacts.

### Bloco E - Observability

Instrumentar spans e dashboards.

Entregas:

- trace id;
- spans;
- metrics;
- dashboard.

### Bloco F - Code Intelligence

Indexacao AST e refactors.

Entregas:

- Tree-sitter/ast-grep adapter;
- symbol index;
- refactor recipes.

---

## 16. Regras Para Adicionar Nova Ferramenta

Nenhuma ferramenta deve entrar no Atlas so porque parece interessante.

Checklist obrigatorio:

1. Qual problema real resolve?
2. E sensor, validator, actuator, profiler, scanner, recorder, simulator, indexer ou transformer?
3. Roda local/self-hosted?
4. Tem modo CLI?
5. Tem output JSON/SARIF/JUnit/trace estruturado?
6. Tem licenca compativel?
7. Tem custo zero como base?
8. Quais permissoes exige?
9. Quais dados acessa?
10. Qual sandbox deve usar?
11. Qual failure policy?
12. Qual timeout?
13. Qual artifact produz?
14. Como normalizar findings?
15. Quando e required?
16. Quando e opportunistic?
17. Como aparece no app?
18. Que memoria pode gerar?
19. Como testar com fake binary?
20. Como remover sem quebrar o core?

---

## 17. Anti-Patterns

| Anti-pattern | Por Que E Ruim | Correcao |
|---|---|---|
| Instalar ferramenta e chamar direto no prompt | cria dependencia invisivel e sem trace | registrar no Tool Registry |
| Colar log gigante no modelo | desperdica contexto e vaza dado | normalizar e resumir |
| Bloquear tudo quando ferramenta opcional falta | cria friccao falsa | separar required/opportunistic |
| Ignorar ferramenta ausente | esconde lacuna | registrar skip auditavel |
| Usar cloud paga como requisito | prende o core | cloud so opcional |
| Rodar scanner sem redaction | risco de segredo | redigir antes do modelo |
| Rodar codemod amplo sem dry-run | risco alto | plano, lote e testes |
| Tratar output humano como contrato | fragil | preferir JSON/SARIF/JUnit |
| Deixar findings sem acao | vira ruido | cada finding precisa status e caminho |
| Achar que mais ferramenta sempre melhora | aumenta tempo e ruido | usar politica por tarefa |

---

## 18. Definicao De Pronto Do Super Tool Runtime

O Super Tool Runtime so pode ser considerado profissional quando:

1. cada ferramenta tem definition versionada;
2. cada execucao tem trace;
3. cada artifact tem path isolado e hash;
4. cada finding tem severidade e status;
5. cada gate tem failure policy;
6. cada skip e auditavel;
7. cada comando tem timeout;
8. cada ferramenta pode ser fakeada em teste;
9. cada output sensivel passa por redaction;
10. cada resultado pode aparecer no app;
11. cada aprendizado pode virar memoria candidata;
12. cada benchmark pode comparar impacto;
13. cada provider recebe resumo, nao log bruto;
14. cada acao destrutiva exige permissao;
15. cada ferramenta paga/cloud e opcional.

---

## 19. Metricas De Eficiencia E Poder Bruto

O Atlas nao deve declarar que ficou mais poderoso por sensacao.

O Super Tool Runtime deve provar ganho por metricas.

### 19.1 Metricas De Eficiencia

| Metrica | O Que Mede | Por Que Importa |
|---|---|---|
| `time_to_validated_result` | tempo ate resultado com evidencia | mede velocidade real, nao so resposta rapida |
| `manual_interventions_count` | quantas vezes Vitor precisou corrigir/rodar comando | mede autonomia util |
| `first_pass_resolved_rate` | taxa de runs resolvidos na primeira tentativa | mede qualidade do fluxo inicial |
| `repair_loop_success_rate` | taxa de reparo apos falha detectada | mede capacidade de autocorrecao |
| `tool_skip_rate` | ferramentas esperadas que nao rodaram | mede lacunas de runtime |
| `artifact_coverage_rate` | runs com artifacts suficientes | mede auditabilidade |
| `context_reuse_rate` | quanto aprendizado anterior foi usado | mede memoria operacional |
| `provider_cost_per_resolved` | custo por tarefa resolvida | mede eficiencia economica |

### 19.2 Metricas De Poder Bruto

| Metrica | O Que Mede | Exemplo |
|---|---|---|
| `capability_coverage` | quantas familias de ferramenta estao prontas | visual, security, API, perf, observability |
| `validated_surface_count` | quantas superficies o Atlas consegue validar | app, CLI, API, browser, DB |
| `sensor_depth_score` | profundidade de verificacao por tarefa | teste + browser + security + contract |
| `environment_realism_score` | quao perto do ambiente real a run ocorreu | host, workspace, Docker, services |
| `cross_tool_correlation_rate` | quantas decisoes usam mais de uma evidencia | Semgrep + tests + visual + trace |
| `benchmark_learning_rate` | quanto as politicas melhoram com historico | modelo, sandbox, gates, retries |
| `autonomous_safe_action_rate` | acoes executadas sem ajuda e sem regressao | refactor, tests, baseline, scan |
| `blocked_bad_completion_rate` | quantas conclusoes erradas foram impedidas | UI quebrada, secret, contrato quebrado |

### 19.3 Score De Capacidade Atlas

O Atlas pode calcular um score operacional por workspace:

```text
atlas_power_score =
  tool_availability_score
  + evidence_coverage_score
  + benchmark_quality_score
  + memory_reuse_score
  + safety_score
  + automation_score
  - unresolved_risk_penalty
```

Interpretacao:

| Score | Nivel |
|---|---|
| 0-30 | Atlas observa pouco e depende muito do operador |
| 31-55 | Atlas valida partes importantes, mas ainda tem lacunas |
| 56-75 | Atlas opera com boa evidencia e repetibilidade |
| 76-90 | Atlas funciona como harness profissional forte |
| 91-100 | Atlas se aproxima de um sistema operacional autonomo e auditavel de engenharia |

### 19.4 Indicador Mais Importante

A metrica mais importante nao e quantidade de ferramentas.

A metrica central e:

```text
percentual de tarefas importantes em que o Atlas consegue produzir resultado melhor, mais rapido e mais verificavel do que o uso direto de um provider.
```

Se uma ferramenta nao melhora essa metrica, ela nao e prioridade.

---

## 20. Conclusao

O Super Tool Runtime Core e uma das pecas que mais pode aumentar a eficiencia e o poder bruto do Atlas.

Ele transforma o Atlas de:

```text
IA com memoria e interface
```

para:

```text
sistema operacional inteligente com memoria, ferramentas, sensores, verificacao, artifacts, benchmark e aprendizado continuo
```

O objetivo nao e ter uma colecao enorme de ferramentas.

O objetivo e fazer o Atlas saber:

- quando usar cada uma;
- como executar com seguranca;
- como interpretar resultado;
- como bloquear conclusoes fracas;
- como aprender com evidencia;
- como melhorar a proxima tarefa.

Esta e a camada que permite ao Atlas competir com o uso direto de Claude Code, Codex CLI, Cursor e ferramentas isoladas.

Nao porque o Atlas tera sempre o melhor modelo.

Mas porque o Atlas tera o melhor sistema ao redor do modelo.
