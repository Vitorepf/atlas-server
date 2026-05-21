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
  - app/Console/Commands/AtlasEngineeringKnowledgeCommand.php
  - app/Http/Controllers/EngineeringKnowledgeController.php
  - app/Models/AtlasEngineeringCodeModule.php
  - app/Models/AtlasEngineeringCodeSymbol.php
  - app/Models/AtlasEngineeringDocLink.php
  - database/migrations/2026_05_02_010000_create_atlas_engineering_code_intelligence_tables.php
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

`index-code` e `audit-code` retornam `duration_ms` e gravam a mesma duracao na
evidencia `atlas_code_intelligence`. Em workspaces grandes ou muito sujos, a
fase de persistencia/prune pode levar minutos sem emitir linhas intermediarias;
use `--dry-run --json` para separar custo de scan/parsing de custo de escrita.
Para gates automatizados ou sessoes longas, prefira `index-code --prune
--summary-only --json`: a indexacao persistida e a evidencia sao iguais, mas o
payload omite previews grandes de modulos/simbolos.

Estados de auditoria:

- `fresh`: modulos, simbolos e hashes de doc links persistidos acompanham o
  workspace atual;
- `drift_detected`: ha modulo/simbolo adicionado, removido ou alterado, ou doc
  link com target ausente/hash divergente;
- `empty_index`: tabelas existem, mas o indice ainda nao foi populado.

Snapshot operacional validado em 2026-05-02 no `atlas-server`:

- `audit-code` mostrou `fresh` apos a indexacao real final, com `total drift=0`.
- `code-status --json` mostrou indice persistido `ready`, com 22 modulos,
  7807 simbolos e 2450 doc links.
- A tela Engineering consome `GET /engineering/knowledge/code/audit` no painel
  `Code audit` e foi validada por `npm run typecheck` e
  `npm run test:engineering`.

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

1. Evoluir `index-code` para ACIR incremental, checkpointed e freshness-aware.
2. Alimentar ACRUI com o ACIR persistido para reachability real.
3. Implementar ASTR read-only antes de qualquer camada mutativa.
4. Implementar AVEOR somente depois do ASTR, usando AVER como executor verificado.
