---
id: engineering-knowledge-base-architecture
type: engineering_knowledge
title: Arquitetura Da Knowledge Base De Engenharia Legacy
status: deprecated
category: architecture
priority: 35
summary: Documento legado da primeira arquitetura da Engineering Knowledge Base; mantido apenas como historico e substituido por README, START_HERE, Code Intelligence e Canonical Architecture Index.
tags:
  - architecture
  - context-pack
  - postgres
capabilities:
  - doc_indexing
  - structured_registry
  - automatic_context_refs
decisions:
  - O registry nao substitui docs; ele indexa e operacionaliza os docs.
  - Context packs devem carregar referencias compactas, nao documentos inteiros por padrao.
  - Este documento nao e mais autoridade primaria; use os documentos em superseded_by.
maintenance:
  - Nao expandir este documento; promover qualquer regra ainda util para os docs canonicos.
superseded_by:
  - docs/engineering-knowledge-base/README.md
  - docs/engineering-knowledge-base/START_HERE.md
  - docs/engineering-knowledge-base/code-intelligence.md
  - docs/engineering-knowledge-base/atlas-ai-canonical-architecture-index.md
related_paths:
  - app/Services/Engineering/EngineeringKnowledgeBaseService.php
  - app/Http/Controllers/EngineeringKnowledgeController.php
  - app/Console/Commands/AtlasEngineeringKnowledgeCommand.php
doc_schema: atlas_canonical_module_doc.v1

graph_id: engineering-knowledge-base-architecture

graph_title: Arquitetura Da Knowledge Base De Engenharia Legacy

graph_world: atlas

graph_layer: system

graph_kind: module

graph_parent: atlas-ai-canonical-architecture-index

graph_status: deprecated

graph_source: repo

owner: architecture

repo_paths:
  - docs/engineering-knowledge-base/architecture.md

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
  - docs/engineering-knowledge-base/architecture.md

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: medium

visual_tags:
  - system
  - module
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
# Arquitetura

> Status: deprecated. Este documento foi a primeira descricao da Engineering
> Knowledge Base. A autoridade atual esta em `README.md`, `START_HERE.md`,
> `code-intelligence.md` e `atlas-ai-canonical-architecture-index.md`.

O Atlas Engineering Knowledge Base tem quatro responsabilidades:

1. Ser a fonte canonica de conhecimento de engenharia.
2. Indexar esse conhecimento em Postgres.
3. Entregar referencias compactas para context packs.
4. Dar ao operador uma forma auditavel de manter a propria documentacao do Atlas.

## Camadas

```txt
docs/engineering-knowledge-base
        |
        v
atlas_engineering_knowledge_items
        |
        v
EngineeringContextPackService
        |
        v
Atlas Engineering Harness Runner / Atlas AI / Atlas App
```

## Docs Canonicos

Cada markdown deve ter frontmatter minimo:

```yaml
---
id: stable-slug
type: engineering_knowledge
title: Human readable title
status: active
category: architecture
priority: 90
summary: Short operational summary.
tags:
  - engineering
capabilities:
  - context_pack_recall
decisions:
  - Versioned docs remain canonical.
maintenance:
  - Sync after editing.
---
```

## Registry Operacional

A tabela `atlas_engineering_knowledge_items` guarda:

- slug estavel;
- titulo, categoria, status e prioridade;
- path canonico;
- hash do path;
- hash do conteudo;
- resumo;
- excerpt do corpo;
- tags, capacidades, decisoes e instrucoes de manutencao.

O corpo completo continua no markdown. O banco guarda o suficiente para busca,
ranking, status operacional e context pack.

## Context Pack

O `EngineeringContextPackService` adiciona `knowledge_refs` ao payload do run.
Essas referencias sao pequenas: slug, titulo, categoria, summary, path e hash.

Isso evita inflar o prompt, mas garante que qualquer provider saiba quais docs
canonicos deve considerar antes de editar, revisar ou aprimorar o Atlas.

Para uso automatico por CLI/app, a regra canonica esta em
`open-brain-context-injection.md`. Esse fluxo transforma context packs em
injecao auditada para `atlas dev`, `atlas continue`, `atlas chat` e Atlas AI App
quando a tarefa for programacao, review ou debug.

## Politica De Privacidade

Esta pasta nao deve conter informacao privada. Informacao privada pertence ao
registry de memoria com redaction ou aos artifacts com path-safety.

## Obsidian

Obsidian pode espelhar ou facilitar leitura humana, mas nao deve ser fonte
primaria para automacao. O Atlas precisa de docs versionados e registry
consultavel pelo proprio sistema.

## Resumo

Documento legado da primeira arquitetura da Engineering Knowledge Base; mantido apenas como historico e substituido por README, START_HERE, Code Intelligence e Canonical Architecture Index.

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
