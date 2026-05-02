---
id: engineering-knowledge-context-pack
type: engineering_knowledge
title: Knowledge Refs No Context Pack
status: active
category: context_pack
priority: 90
summary: Como o Atlas injeta referencias canonicas da Knowledge Base no context pack do Engineering Harness Runner.
tags:
  - context-pack
  - harness
  - recall
capabilities:
  - compact_recall
  - provider_portability
  - maintenance_context
decisions:
  - Context pack carrega referencias compactas por padrao.
  - O markdown completo permanece versionado no repo.
maintenance:
  - Manter summaries curtos e paths corretos para que refs sejam uteis.
  - Aumentar limite de refs apenas se houver evidencia de recall insuficiente.
related_paths:
  - app/Services/Engineering/EngineeringContextPackService.php
  - app/Services/Engineering/EngineeringKnowledgeBaseService.php
---

# Knowledge Refs No Context Pack

O context pack do Harness deve lembrar o provider de quais fontes canonicas
importam para a tarefa. Ele nao precisa colar todos os documentos no prompt.

Cada referencia inclui:

- id;
- slug;
- titulo;
- categoria;
- prioridade;
- path canonico;
- content hash;
- resumo;
- motivo da inclusao.

## Beneficio

Isso melhora manutencao porque o Atlas consegue reidratar contexto de engenharia
sem depender da conversa atual. Codex, Claude, GPT ou outro motor recebem a mesma
lista de referencias canonicas.

## Politica De Tamanho

Por padrao, o Harness usa ate 8 referencias. O ranking prioriza arquitetura,
manutencao e matriz de capacidades, com boost para categorias relacionadas a
tags da task quando existirem.

## Falha Segura

Se a migration ainda nao rodou ou a tabela nao existe, o context pack continua
funcionando sem knowledge refs. Isso evita quebrar o Runner durante deploy ou
ambientes parciais.
