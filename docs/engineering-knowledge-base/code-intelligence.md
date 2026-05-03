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
decisions:
  - Docs canonicos continuam sendo a fonte de verdade conceitual.
  - O indice de codigo e a fonte operacional para localizar implementacao real.
  - Context packs devem carregar refs de docs e refs de codigo juntos.
maintenance:
  - Rode atlas engineering knowledge index-code --prune depois de mudar Harness, CLI, API, migrations, testes ou docs canonicos.
  - Rode atlas engineering knowledge modules --docs-status=undocumented para achar lacunas de documentacao.
  - Rode atlas engineering knowledge sync --prune antes do index-code quando alterar esta pasta.
related_paths:
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
