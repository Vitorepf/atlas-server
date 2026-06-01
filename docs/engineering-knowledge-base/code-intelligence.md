---
id: engineering-code-intelligence-index
type: engineering_knowledge
title: Atlas Engineering Code Intelligence Index
status: active
category: architecture
priority: 97
summary: Indice operacional que transforma o codigo real do Atlas em modulos, simbolos, rotas, comandos, migrations, testes e links docs->codigo para uso em context packs e manutencao por IA.
tags:
  - atlas
  - engineering
  - code-intelligence
  - context-pack
capabilities:
  - code_intelligence_index
  - docs_to_code_links
  - context_pack_code_recall
  - maintenance_navigation
  - external_graph_candidate
decisions:
  - Docs canonicos continuam sendo a fonte de verdade conceitual.
  - O indice de codigo e a fonte operacional para localizar implementacao real.
  - Context packs devem carregar refs de docs e refs de codigo juntos.
  - Grafos externos sao candidatos read-only e precisam passar por AP-684 antes de influenciar Code Intelligence.
maintenance:
  - Rode atlas engineering knowledge index-code --prune depois de mudar Harness, CLI, API, migrations, testes ou docs canonicos.
  - Rode atlas engineering knowledge modules --docs-status=undocumented para achar lacunas de documentacao.
  - Rode atlas engineering knowledge sync --prune antes do index-code quando alterar esta pasta.
  - Leia code-intelligence/external-graph-harness.md antes de usar Graphify ou outro grafo externo.
related_paths:
  - docs/engineering-knowledge-base/code-intelligence/README.md
  - docs/engineering-knowledge-base/code-intelligence/external-graph-harness.md
  - docs/engineering-knowledge-base/atlas-software-twin-verified-evolution-runtime.md
  - docs/ap/AP-684-graphify-external-graph-harness.md
  - docs/engineering-knowledge-base/archive/source-material/code-intelligence/graphify-v0-7-11-dissection-2026-05-09.md
  - app/Services/Engineering/EngineeringCodeIntelligenceService.php
  - app/Services/Engineering/EngineeringContextPackService.php
  - app/Services/Engineering/EngineeringBlueprintService.php
  - app/Services/Engineering/EngineeringTaskContractService.php
  - app/Console/Commands/AtlasEngineering
  - app/Console/Commands/AtlasEngineeringKnowledgeCommand.php
  - app/Http/Controllers/Engineering
  - app/Http/Controllers/EngineeringKnowledgeController.php
  - app/Models/AtlasEngineering
  - app/Models/AtlasEngineeringCodeModule.php
  - app/Models/AtlasEngineeringCodeSymbol.php
  - app/Models/AtlasEngineeringDocLink.php
  - database/migrations/2026_05_02_010000_create_atlas_engineering_code_intelligence_tables.php
  - database/migrations/2026_05_21_210702_add_performance_indexes_to_atlas_engineering_code_intelligence_tables.php
  - database/migrations/2026_05_21_211200_create_atlas_engineering_code_file_snapshots_table.php
  - docs/engineering-knowledge-base/engineering-blueprint.md
  - routes/api.php
  - tests/Feature/AtlasEngineeringKnowledgeBaseTest.php
doc_schema: atlas_canonical_module_doc.v1

graph_id: engineering-code-intelligence-index

graph_title: Atlas Engineering Code Intelligence Index

graph_world: atlas

graph_layer: system

graph_kind: index

graph_parent: atlas-ai-canonical-architecture-index

graph_status: active

graph_source: repo
human_name: Atlas Engineering Code Intelligence Index
canonical_name: Atlas Engineering Code Intelligence Index
technical_name: engineering-code-intelligence-index
cartography_type: index
canonical_source: docs/engineering-knowledge-base/code-intelligence.md

owner: architecture

repo_paths:
  - docs/engineering-knowledge-base/code-intelligence.md

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
  - architecture

evidence:
  - docs/engineering-knowledge-base/code-intelligence.md

evidence_refs:
  - symbol: EngineeringCodeIntelligenceService
  - command: atlas:engineering:knowledge
  - test: AtlasEngineeringKnowledgeBaseTest
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: medium

visual_tags:
  - system
  - index
  - architecture

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
# Atlas Engineering Code Intelligence Index

Esta camada faz a ponte entre o que o Atlas sabe em documentos e o que existe
de fato no codigo.

A Knowledge Base responde "qual e a decisao, a arquitetura e a regra". O Code
Intelligence Index responde "onde isso esta implementado, quais simbolos existem,
quais rotas e comandos operam a capacidade e quais testes protegem o fluxo".

## Objetivo

O Atlas precisa conseguir manter, revisar e evoluir o Harness sem depender de
lembranca de conversa. Para isso, o contexto de engenharia deve conter:

- docs canonicos relevantes;
- modulos reais do codigo;
- simbolos importantes;
- rotas de API;
- comandos CLI;
- migrations e tabelas tocadas;
- testes relacionados;
- links atuais entre docs e implementacao.

## Tabelas

| Tabela | Funcao |
|---|---|
| `atlas_engineering_code_modules` | Agrupa arquivos em modulos operacionais como Harness services, API, CLI, schema, tests e docs. |
| `atlas_engineering_code_symbols` | Guarda classes, metodos, comandos CLI, rotas, migrations, exports TS/JS e headings Markdown. |
| `atlas_engineering_doc_links` | Liga knowledge items a modulos/simbolos, detectando se o alvo ainda existe e se o hash mudou. |

## Fluxo Correto

Depois de alterar docs canonicos:

```bash
atlas engineering knowledge sync --prune
atlas engineering knowledge index-code --prune
```

Depois de alterar codigo, rotas, migrations ou testes:

```bash
atlas engineering knowledge index-code --prune
```

Para auditoria:

```bash
atlas engineering knowledge code-status
atlas engineering knowledge audit-code --json
atlas engineering knowledge code-readiness --json
atlas engineering knowledge code-gate --strict --json
atlas engineering knowledge code-gate --auto-refresh --strict --json
atlas engineering knowledge modules
atlas engineering knowledge symbols --symbol-type=cli_command
atlas engineering knowledge show-module engineering_harness_services
```

No app, abra `Home > Atlas Engineering > abrir`. O card `Engineering knowledge`
mostra os mesmos modulos e simbolos, com filtros por `layer`, `docs_status` e
`symbol_type`. Toque em um modulo para ver docs relacionados, testes
relacionados, doc links e simbolos principais. O painel `Code audit` executa o
mesmo `audit-code` via API, em dry-run sem escrita, e mostra drift por modulos,
simbolos e doc links.

## Dry-run E Status

`index-code --dry-run` valida descoberta de arquivos, modulos e simbolos sem
persistir nada. Como os links docs->codigo dependem do estado persistido, o
`doc_link_count` do dry-run pode aparecer como `0`. Para comparar o scan atual
com o indice persistido sem escrever, use `atlas engineering knowledge
audit-code --json`.

`index-code` e `audit-code` retornam `duration_ms`, `performance` e gravam a
mesma evidencia em `atlas_code_intelligence`. `performance` usa schema
`atlas.code_intelligence.performance.v1` com `phase_timings_ms`,
`memory_peak_mb`, throughput e status `healthy|watch|slow`. Em workspaces
grandes, use `--dry-run --json` para separar scan/parsing de persistencia. Para
gates automatizados ou sessoes longas, prefira `index-code --prune
--summary-only --json`: a indexacao persistida e a evidencia sao iguais, mas o
payload omite previews grandes de modulos/simbolos.

Estados de auditoria:

- `fresh`: modulos, simbolos e hashes de doc links persistidos acompanham o
  workspace atual;
- `drift_detected`: ha modulo/simbolo adicionado, removido ou alterado, ou doc
  link com target ausente/hash divergente;
- `empty_index`: tabelas existem, mas o indice ainda nao foi populado.

Snapshot operacional validado em 2026-05-21 no `atlas-server`:

- `index-code --prune --summary-only --json` terminou em aproximadamente 57s
  com 23 modulos, 70826 simbolos, 516 rotas, 348 comandos, 1202 migrations,
  13500 testes e 124110 doc links.
- `audit-code --json` terminou em aproximadamente 13s, mostrou `fresh` e
  `total drift=0`.
- `code-status --json` mostrou indice persistido `ready`, com 23 modulos
  documentados e zero `undocumented`.
- A tela Engineering consome `GET /engineering/knowledge/code/audit` no painel
  `Code audit` e foi validada por `npm run typecheck` e
  `npm run test:engineering`.

## Performance E Readiness

O contrato operacional minimo para considerar `index-code` saudavel:

- `index-code --prune --summary-only --json` deve concluir sem timeout local;
- `audit-code --json` deve retornar `status=fresh` depois do index;
- `code-readiness --json` deve retornar schema
  `atlas.code_intelligence.readiness.v1` e `status=ready`;
- `performance.phase_timings_ms` deve mostrar scan, persistencia, doc links e
  refresh separadamente;
- `performance.cache.schema_version` deve ser
  `atlas.code_intelligence.file_snapshot_cache.v1`;
- `performance.cache.quality_guard.key` deve ser `sha256_file_content`;
- cache por `mtime` sem hash de conteudo e proibido para este indice;
- `memory_peak_mb` deve ficar abaixo do budget CLI aplicado pelo runtime;
- `drift.total=0` apos reindex real;
- `docs_status.undocumented` deve ser tratado como lacuna de documentacao, nao
  erro de scan.

Quando `performance.status=slow`, a proxima acao correta e otimizar a fase mais
cara indicada por `phase_timings_ms`, nao adicionar cache cego.

O cache permitido e checkpoint por arquivo com hash SHA-256 do conteudo. O
indice pode reutilizar simbolos e relacoes persistidos somente quando o hash
atual do arquivo for identico ao snapshot. Qualquer mudanca real vira miss e o
arquivo e reparseado. Esse contrato preserva qualidade: nao existe reuso por
timestamp, tamanho ou heuristica.

`code-readiness` bloqueia quando tabelas estao ausentes, indice nao esta ready,
audit nao esta fresh, contagens estruturais estao vazias ou `drift.total > 0`.
Modulos `undocumented` viram warning, nao falha critica.

## Gate Automatico

`code-gate` e o bloqueio automatico de runtime em cima do `index-code`.
Ele existe para impedir que Atlas Dev, Forge, ACRUI, Software Twin e AVCEL
trabalhem com mapa de codigo vazio, stale ou sem consumidor downstream.

Comandos canonicos:

```bash
php artisan atlas:engineering:knowledge code-gate --strict --json
php artisan atlas:engineering:knowledge code-gate --auto-refresh --strict --json
```

Sem `--auto-refresh`, o gate e read-only: le `summary()`, roda readiness quando
em modo estrito, avalia drift e devolve `ready|watch|blocked`. Com
`--auto-refresh`, ele pode executar `index-code --prune --summary-only --json`
quando a falha e recuperavel (`drift_detected`, `audit_not_fresh`,
`index_not_ready` ou indice stale). Ele nunca chama provider, nunca roda rivals
e nunca declara contexto confiavel quando `status=blocked`.

Consumidores obrigatorios cobertos pelo gate:

- contexto correto;
- cartografia;
- deteccao de duplicacao;
- selecao de arquivos;
- impacto de patch;
- Forge;
- Atlas Dev;
- ACRUI;
- Software Twin;
- AVCEL;
- reducao de erro das IAs.

O gate bloqueia quando:

- as tabelas de Code Intelligence nao existem;
- `summary.status` nao e `ready`;
- `module_count`, `symbol_count`, `route_count`, `command_count` ou
  `test_count` estao vazios;
- `code-readiness` retorna blocked em modo estrito;
- `last_indexed_at` esta ausente, invalido ou acima do limite de idade;
- algum consumidor obrigatorio perdeu evidencia de arquivo/doc;
- cache por snapshot de arquivo deixou de usar hash de conteudo.

Este gate ja esta no `AtlasSessionBootstrapService`, no
`AtlasFeaturePlacementService` e no `ProgrammingCodeIntelligenceGate`. Se ele
bloquear, a IA deve reindexar ou corrigir drift antes de escrever codigo.

Modulos virtuais sao validos. Alguns roots como `app/Models/Ai`,
`app/Console/Commands/AtlasCli`, `app/Console/Commands/AtlasEngineering`,
`app/Console/Commands/AtlasMemory`, `app/Models/AtlasEngineering` e
`app/Models/AtlasMemory` representam prefixos de classificacao, nao diretorios
fisicos obrigatorios. Links documentais de nivel modulo podem ficar `current`
mesmo sem alvo fisico; links de simbolo/arquivo continuam exigindo alvo real.

Estado validado em 2026-05-21:

- primeira execucao apos criar snapshot cache: 5.944 misses, 5.944 snapshots
  persistidos, `duration_ms=61354`;
- execucao quente de `index-code --prune --summary-only --json`: 23 modulos,
  70.841 simbolos, 124.211 doc links, 516 rotas, 348 comandos, 1.204
  migrations, 13.500 testes, `performance.status=healthy`, `duration_ms=47071`,
  `cache.hits=5944`, `cache.misses=0`, `cache.hit_rate=1`;
- `audit-code --json`/`code-readiness --json`: `status=fresh`,
  `drift.total=0`, `audit_duration_ms=10957`, `cache.hit_rate=1`;
- `code-readiness --json`: `status=ready`, `critical_failures=0`,
  `warnings=0`, `drift_total=0`;
- `modules --docs-status=undocumented --summary-only --json`: zero modulos.

## Como A IA Deve Usar

Ao montar um context pack de engenharia, o Atlas deve incluir `knowledge_refs`
e `code_refs`.

- `knowledge_refs` orientam decisao, escopo, regras e playbook.
- `code_refs` apontam para os arquivos, modulos e testes que devem ser lidos
  antes de editar.
- `related_tests` dos modulos entram em `selected_files` para evitar manutencao
  sem cobertura.
- `docs_status=undocumented` vira sinal de lacuna de documentacao, nao sinal de
  ausencia de codigo.

Para tarefas do Engineering Blueprint System, o indice deve apontar no minimo:

- services de blueprint, contracts, snapshots, context pack, runner, scoring,
  controls e review findings;
- rotas de task engineering e engineering runs;
- migrations de blueprints, evidence, runs, controls, tests e findings;
- comandos `atlas dev`, `atlas engineering run`, benchmark, quality scan e
  futuros comandos `atlas qa`, `atlas review --deep` e `atlas db review`;
- superficies do app em `/projects` e `/engineering`;
- testes unitarios/feature que protegem cada gate.

## Regra De Manutencao

Nenhuma capacidade core do Harness deve ficar apenas em codigo ou apenas em doc.
O padrao profissional e:

1. doc canonico ou ADR descrevendo a decisao;
2. modulo de codigo indexado;
3. doc link atual entre doc e implementacao;
4. testes relacionados visiveis no modulo;
5. context pack carregando docs e codigo juntos.

## Limites Atuais

O indice e deterministico e local. Ele nao substitui busca semantica vetorial nem
analise profunda por AST completa. O proximo salto profissional e adicionar:

- parser AST para PHP/TS quando o custo justificar;
- busca semantica sobre docs e simbolos;
- historico temporal de mudancas em simbolos;
- grafo de dependencias entre modulos;
- health score de documentacao por modulo.

O limite tecnico superior desta area esta definido em
`atlas-software-twin-verified-evolution-runtime.md`: ACIR deve evoluir de indice
batch para runtime incremental; depois ASTR cria o gemeo vivo do software; depois
AVEOR controla evolucao verificada em cima desse twin. `AVER` permanece o
executor verificado existente e nao deve ser confundido com AVEOR.

Estado atual: `index-code` esta funcional, auditavel e performatico para uso
operacional batch. Ja possui readiness dedicado, telemetry de performance,
drift audit, cobertura documental zerada, suporte correto a modulos virtuais e
checkpoint persistente por arquivo com hash de conteudo. Ainda nao e o ACIR
final porque nao tem fila incremental por diff, grafo delta do Software Twin nem
commit-level selective write; o indice batch atual, porem, ja possui cache
seguro e readiness bloqueante.

## External Graph Harness

Ferramentas como Graphify podem acelerar cartografia de codigo, comunidades,
god nodes e relacoes surpreendentes, mas entram apenas como evidencia externa
read-only.

O contrato canonico esta em
`code-intelligence/external-graph-harness.md`; a primeira implementacao planejada
esta em `docs/ap/AP-684-graphify-external-graph-harness.md`.

Regra curta: grafo externo pode sugerir melhoria do Code Intelligence, mas nao
pode escrever memoria, contexto, Constelacao, Policy/Profile, Decide ou runtime.

## Resumo

Indice operacional que transforma o codigo real do Atlas em modulos, simbolos, rotas, comandos, migrations, testes e links docs->codigo para uso em context packs e manutencao por IA.

## Papel no Atlas

Define a responsabilidade desta peca dentro da arquitetura Atlas.

## Onde Se Encaixa

Relaciona esta peca com seu sistema, camada, fluxo ou modulo pai.

## Contratos

Contratos principais:

- `atlas.code_intelligence.performance.v1` para timings/memoria/throughput;
- `atlas_code_intelligence` como evidencia runtime em `atlas_tool_runs`;
- tabelas `atlas_engineering_code_modules`, `atlas_engineering_code_symbols` e
  `atlas_engineering_doc_links` como read model operacional;
- `audit-code` e a prova de frescor: `status=fresh` e `drift.total=0`.
- `atlas.code_intelligence.readiness.v1` como envelope de prontidao para Dev,
  Forge, Cartografia, ACRUI e Software Twin.

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

1. Integrar `code-gate` como preflight duro em todos os pontos mutativos de Dev
   e Forge.
2. Evoluir `index-code` para ACIR incremental, checkpointed e freshness-aware.
3. Alimentar ACRUI com o ACIR persistido para reachability real.
4. Implementar ASTR read-only antes de qualquer camada mutativa.
5. Implementar AVEOR somente depois do ASTR, usando AVER como executor verificado.
