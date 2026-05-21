---
id: atlas-engineering-blueprint-quality-gates
type: engineering_knowledge
title: Atlas Engineering Blueprint Quality Gates
status: active
category: quality
priority: 97
summary: Gates finais de contrato, QA, review profundo, Postgres, telemetry e release evidence para o Atlas Engineering Blueprint System.
tags:
  - atlas
  - engineering
  - quality
  - qa
  - postgres
capabilities:
  - engineering_blueprint_quality_gates
  - blueprint_quality_qa_evidence
  - blueprint_quality_review_gates
  - blueprint_quality_postgres_gate
  - release_readiness
decisions:
  - Evidencia persistida e requisito de conclusao, nao detalhe opcional.
  - P0/P1 com confianca alta bloqueiam task e release.
  - Mudanca de banco exige gate especializado quando tocar schema, query critica ou dados.
maintenance:
  - Atualizar quando scoring, findings, QA workflow, visual smoke, quality scan ou database review mudarem.
  - Criar teste focado para qualquer novo gate bloqueante.
related_paths:
  - app/Services/Engineering/EngineeringControlRegistryService.php
  - app/Services/Engineering/EngineeringRunScoringService.php
  - app/Services/Engineering/EngineeringReviewFindingService.php
  - app/Services/Engineering/EngineeringQualityScanService.php
  - app/Services/Engineering/EngineeringVisualSmokeService.php
  - tests/Feature/EngineeringHarnessRunnerTest.php
  - tests/Feature/AtlasEngineeringQualityScanCommandTest.php
  - tests/Feature/AtlasEngineeringVisualSmokeCommandTest.php
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-engineering-blueprint-quality-gates

graph_title: Atlas Engineering Blueprint Quality Gates

graph_world: atlas

graph_layer: module

graph_kind: module

graph_parent: atlas-ai-canonical-architecture-index

graph_status: active

graph_source: repo
human_name: Atlas Engineering Blueprint Quality Gates
canonical_name: Atlas Engineering Blueprint Quality Gates
technical_name: atlas-engineering-blueprint-quality-gates
cartography_type: module
canonical_source: docs/engineering-knowledge-base/engineering-blueprint-quality-gates.md

owner: quality

repo_paths:
  - docs/engineering-knowledge-base/engineering-blueprint-quality-gates.md

allowed_changes:
  - Atualizar este doc quando codigo, arquitetura, fluxo, evidencia ou decisao canonica mudar.

forbidden_changes:
  - Declarar runtime, maturidade ou prontidao sem evidencia verificavel e gates verdes.

depends_on:
  - atlas-ai-documentation-operating-system

flows_to:
  - atlas-cartography
  - atlas-code

unlocks:
  - ai-safe-implementation-context

governs:
  - quality

evidence:
  - docs/engineering-knowledge-base/engineering-blueprint-quality-gates.md

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: low

visual_tags:
  - module
  - module
  - quality

ai_entrypoints:
  - Leia Resumo, Contratos, Regras para IA, Evidencias e Riscos antes de implementar.

ai_usage_notes:
  - Use repo_paths, allowed_changes, forbidden_changes e required_tests como limites operacionais.

quality_gates:
  - "php artisan atlas:engineering:knowledge docs-health --json"

failure_modes:
  - Contexto desatualizado entre doc, codigo, teste e evidencia.

observability_signals:
  - docs-health status ok

next_actions:
  - Manter este doc sincronizado com codigo, testes, evidencias e Cartografia.
---
# Atlas Engineering Blueprint Quality Gates

Quality gate e a regra objetiva que impede o Atlas de declarar sucesso sem
evidencia suficiente.

## Gate Final De Uma Task

Uma task Engineering so pode ficar `ready`, `resolved` ou equivalente quando:

- task contract minimo existe;
- blueprint snapshot atual existe ou a task foi explicitamente marcada como
  excecao;
- todos os acceptance criteria possuem evidencia;
- testes exigidos passaram ou foram justificados;
- QA manual passou quando requerido;
- deep review nao possui finding bloqueante aberto;
- Postgres gate passou quando requerido;
- telemetry e artifacts essenciais foram persistidos;
- app, API e CLI mostram estado coerente.

## Estados De Decisao

| Estado | Significado | Pode promover? |
|---|---|---|
| `resolved` | Todos os gates obrigatorios passaram | Sim |
| `partial` | Parte do objetivo foi entregue, mas ha lacuna nao bloqueante ou escopo menor | Nao sem decisao humana |
| `unresolved` | Tentativa nao entregou criterio principal | Nao |
| `blocked` | Dependencia, permissao, dado ou decisao humana ausente | Nao |
| `unsafe` | Risco alto, teste falhou, finding bloqueante ou banco inseguro | Nao |

## Acceptance Gate

Cada acceptance criterion deve mapear para pelo menos uma evidencia:

- teste automatizado;
- QA manual;
- screenshot/trace;
- database review;
- deep code review;
- artifact de quality scan;
- justificativa humana com risco aceito.

Regras:

- Criterio sem evidencia bloqueia conclusao.
- Evidencia com `failed` bloqueia.
- Evidencia com `needs_review` bloqueia ate decisao humana.
- Evidencia com `not_applicable` so passa com justificativa.

## Scenario Coverage Gate

Toda mudanca de produto deve cobrir cenarios relevantes:

| Tipo | Exigencia |
|---|---|
| Happy path | Obrigatorio |
| Alternative path | Obrigatorio quando ha escolha, filtro, permissao ou branch de fluxo |
| Exception path | Obrigatorio quando ha erro, rede, validacao ou estado vazio |
| Regression | Obrigatorio quando toca comportamento existente |
| Visual | Obrigatorio quando toca UI, layout, canvas, screenshot ou responsividade |
| Database | Obrigatorio quando toca schema, query critica, backfill ou migration |

O gate deve bloquear freeze project-level quando uma superficie declarada no
inventory nao possui scenario ou acceptance criterion associado.

## Manual QA Gate

Manual QA e obrigatorio quando:

- a task toca UX visual;
- screenshot ou Playwright tem diferenca relevante;
- o comportamento depende de interacao humana;
- a superficie e mobile/responsiva;
- a IA declarou sucesso mas o risco visual continua medio/alto.

Evidencia minima:

- passos executados;
- resultado esperado;
- resultado real;
- status;
- confidence;
- screenshot ou justificativa;
- console/network quando web/app;
- notas de risco residual.

## Visual Harness Gate

Playwright, visual smoke e screenshots nao substituem julgamento humano, mas
devem ser usados para reduzir risco visual.

Regras:

- canvas ou 3D precisa prova de pixel nao branco e enquadramento.
- layout responsivo precisa desktop e mobile quando a tela e user-facing.
- console error relevante bloqueia ate triagem.
- network failure bloqueia quando impacta fluxo testado.
- baseline visual deve ser atualizado apenas com intencao registrada.

## Deep Review Gate

Deep review procura bugs, regressao, seguranca, integridade de dados, teste
faltando e manutencao ruim.

Threshold alvo:

| Finding | Regra |
|---|---|
| `P0` aberto | Bloqueia sempre |
| `P1` aberto com `confidence >= 0.80` | Bloqueia |
| `P1` com confidence menor | Advisory, salvo categoria seguranca/dados |
| `P2` | Nao bloqueia por padrao, mas entra em risk notes |
| `P3` | Nao bloqueia |
| `fixed` | Nao bloqueia |
| `false_positive` | Nao bloqueia se justificativa existir |
| `accepted_risk` | Nao bloqueia apenas com aprovacao humana |

Categorias alvo:

- `correctness`;
- `security`;
- `data_integrity`;
- `performance`;
- `ux`;
- `test_gap`;
- `maintainability`;
- `observability`;
- `migration_risk`.

## Postgres Gate

Postgres nao pode ser tratado como detalhe generico de teste. Ele precisa gate
proprio quando a mudanca tocar:

- migrations;
- schema, tipos, constraints, FKs ou indices;
- JSONB usado como contrato;
- timestamps ou timezone;
- backfill;
- queries com risco de scan/lock;
- `DB::unprepared`;
- jobs ou transacoes que alteram dados em lote.

Checks obrigatorios do produto final:

| Check | Bloqueia quando |
|---|---|
| Rollback | `down()` ausente, incoerente ou destrutivo sem aprovacao |
| Lock risk | ALTER/backfill em tabela grande sem estrategia |
| FK/index | FK sem indice adequado ou cardinalidade ignorada |
| Unique/constraint | Regra de negocio fica apenas na app quando deveria ser constraint |
| JSONB contract | JSONB usado sem schema/check quando vira contrato operacional |
| Timestamp | Datas criticas sem politica de timezone |
| Explain | Query critica sem plano ou com scan arriscado sem justificativa |
| Raw SQL | `DB::unprepared` sem reversibilidade, escaping ou comentario de seguranca |
| Backfill | Sem chunking, retry, idempotencia ou observabilidade |

`atlas db review` deve retornar JSON estavel e evidencia `database_review`.

## Tool Evidence Gate

Quando Super Tool Runtime registra findings ou artifacts relevantes, o Blueprint
deve conseguir consumi-los como evidencia.

Exemplos:

- `quality_scan` encontra secret, dependency risk ou dead code;
- `playwright` gera screenshot e console output;
- `code_intelligence` aponta modulo sem doc link;
- `postgres_review` registra finding de lock;
- `eslint/phpstan/pest` registram falha de teste.

Gate deve usar evidencia normalizada, nao parsing ad hoc de texto solto.

## Telemetry Gate

Runs e gates devem carregar contexto suficiente para auditoria:

- `trace_id`;
- `run_id`;
- `attempt_id` quando houver;
- provider/model quando aplicavel;
- comando executado;
- artifacts curtos;
- status;
- confidence;
- tempo;
- decisao final.

Telemetry nao deve vazar segredo, token, path privado desnecessario ou output
bruto longo.

## Testes Que Protegem Os Gates

Testes obrigatorios para maturidade final:

- freeze de blueprint com hash deterministico e versionamento;
- bloqueio de freeze com inventory/scenario incompleto;
- `atlas dev --task-id` injeta contrato e blueprint;
- completion nao passa com AC sem evidencia;
- manual QA falho bloqueia;
- visual smoke falho bloqueia quando gate visual exigido;
- P0/P1 aberto bloqueia conforme threshold;
- `accepted_risk` exige aprovacao humana;
- migration destrutiva falha no Postgres gate;
- `DB::unprepared` sem rollback falha;
- API retorna 422 para evidence invalida;
- app mostra snapshot stale, gates e missing evidence;
- CLI retorna exit code diferente de zero quando gate bloqueia.

## Regra De Ouro

Sem evidencia persistida, o Atlas pode dizer "provavelmente funciona", mas nao
pode dizer "pronto".

## Resumo

Gates finais de contrato, QA, review profundo, Postgres, telemetry e release evidence para o Atlas Engineering Blueprint System.

## Papel no Atlas

Define a responsabilidade desta peca dentro da arquitetura Atlas.

## Onde Se Encaixa

Relaciona esta peca com seu sistema, camada, fluxo ou modulo pai.

## Contratos

Declara invariantes, entradas, saidas, limites e obrigacoes relevantes.

## Fluxo

Descreve o caminho operacional ou a sequencia de uso quando aplicavel.

## Regras para IA

Agentes devem respeitar escopo, evidencias, testes e proibicoes antes de alterar codigo.

## Escopo de Implementacao

Mudancas devem permanecer nos caminhos e limites declarados no frontmatter.

## Dependencias

Dependencias canonicas vivem em frontmatter e no corpo deste documento.

## Evidencias

Evidencias aceitas incluem docs, comandos, testes, receipts, reports e paths verificaveis.

## Riscos

Riscos principais devem ser tratados antes de promover status, runtime ou claims de prontidao.

## Exemplos

Exemplos concretos devem ser adicionados quando reduzirem ambiguidade para humanos ou IAs.

## Proximas Acoes

Proximas acoes devem ser concretas, verificaveis e ligadas a gates de qualidade.
