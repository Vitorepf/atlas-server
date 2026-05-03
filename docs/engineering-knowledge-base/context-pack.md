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
  - engineering_blueprint
  - task_contracts
  - open_brain_context_injection
decisions:
  - Context pack carrega referencias compactas por padrao.
  - O markdown completo permanece versionado no repo.
  - Task contract e blueprint snapshot sao entradas obrigatorias para execucao autonoma de engenharia.
  - Open Brain Context Injection define quando esse contexto deve entrar automaticamente em CLI/app.
maintenance:
  - Manter summaries curtos e paths corretos para que refs sejam uteis.
  - Aumentar limite de refs apenas se houver evidencia de recall insuficiente.
related_paths:
  - app/Services/Engineering/EngineeringContextPackService.php
  - app/Services/Engineering/EngineeringKnowledgeBaseService.php
  - app/Services/Engineering/EngineeringTaskContractService.php
  - app/Services/Engineering/EngineeringBlueprintService.php
  - docs/engineering-knowledge-base/engineering-blueprint.md
  - docs/engineering-knowledge-base/open-brain-context-injection.md
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

## Blueprint Refs

Para tarefas de engenharia, o context pack deve tratar estes dados como nucleo,
nao como contexto opcional:

- `task_contract`;
- `engineering_blueprint`;
- `engineering_blueprint_snapshot` quando existir;
- `contingency_policy`;
- `review_gates`;
- `acceptance_matrix`;
- `scenario_inventory`;
- `knowledge_refs` da familia `engineering-blueprint*.md` quando a task tocar
  planejamento, QA, review, Postgres ou geracao de tasks;
- `code_refs` para services, rotas, comandos, migrations, app surfaces e testes
  relacionados.

Sem esses dados, o provider pode ajudar a investigar, mas nao deve receber
autonomia total para concluir task de risco medio/alto.

## Beneficio

Isso melhora manutencao porque o Atlas consegue reidratar contexto de engenharia
sem depender da conversa atual. Codex, Claude, GPT ou outro motor recebem a mesma
lista de referencias canonicas.

## Relacao Com Open Brain Context Injection

Este documento explica o formato compacto dos refs. O documento
`open-brain-context-injection.md` define quando e como esses refs devem entrar
automaticamente no prompt de `atlas dev`, `atlas continue`, `atlas chat` e Atlas
AI App em modos de programacao, review e debug.

Regra: nao duplique context pack. Se o Open Brain ja renderizou uma secao com o
mesmo `context_pack_hash`, o prompt final deve usar uma unica secao auditavel.

## Politica De Tamanho

Por padrao, o Harness usa ate 8 referencias. O ranking prioriza arquitetura,
manutencao e matriz de capacidades, com boost para categorias relacionadas a
tags da task quando existirem.

## Falha Segura

Se a migration ainda nao rodou ou a tabela nao existe, o context pack continua
funcionando sem knowledge refs. Isso evita quebrar o Runner durante deploy ou
ambientes parciais.
