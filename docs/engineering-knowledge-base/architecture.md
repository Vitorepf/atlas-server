---
id: engineering-knowledge-base-architecture
type: engineering_knowledge
title: Arquitetura Da Knowledge Base De Engenharia
status: active
category: architecture
priority: 96
summary: Arquitetura da camada que conecta docs versionados, registry Postgres, context packs e manutencao automatizada do Atlas.
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
maintenance:
  - Manter frontmatter com id, title, category, priority e summary.
  - Evitar conteudo privado nesta pasta.
related_paths:
  - app/Services/Engineering/EngineeringKnowledgeBaseService.php
  - app/Http/Controllers/EngineeringKnowledgeController.php
  - app/Console/Commands/AtlasEngineeringKnowledgeCommand.php
---

# Arquitetura

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
