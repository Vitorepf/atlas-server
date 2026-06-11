---
id: atlas-blog-editorial-planning-system
type: engineering_knowledge
title: Atlas Blog Editorial Planning System
status: active
category: content-intelligence
priority: 80
summary: Area governada do Atlas para planejar a ordem do blog pessoal de Vitor Freire, validando backlog, prerequisitos e progresso publico sem criar memoria, grafo ou RAG paralelo.
tags:
  - atlas-ai
  - blog
  - editorial
  - planning
  - content-intelligence
capabilities:
  - blog_editorial_planning
  - backlog_order_validation
  - prerequisite_gate
  - public_archive_sequence
  - governed_editorial_context_refs
  - reviewable_backlog_candidate_suggestions
decisions:
  - O blog e de Vitor Freire; Atlas e assunto/colecao, nao identidade total do site.
  - A ordem editorial deve ir do superficial ao profundo.
  - O Atlas pode planejar e sugerir, mas nao publicar sem aprovacao humana.
  - P0 e deterministico/read-only; P1 anexa refs de KB/Code Intelligence existentes e sugere candidatos revisaveis; graph/RAG entra depois por AP-817 e runtime boundaries existentes.
  - O sistema nao cria store paralelo de memoria, contexto, grafo ou publicacao.
maintenance:
  - Atualizar quando mudar calendario, contrato editorial, comando, schema ou integracao com graph/RAG.
related_paths:
  - docs/ap/AP-817-blog-editorial-planning-contract.md
  - docs/engineering-knowledge-base/atlas-ai-knowledge-governance-system.md
  - docs/engineering-knowledge-base/atlas-ai-runtime-language-boundaries.md
  - docs/engineering-knowledge-base/atlas-ai-memory-context-core-open-brain.md
  - docs/engineering-knowledge-base/code-intelligence.md
  - app/Services/Ai/Publishing/BlogEditorialPlannerService.php
  - app/Services/Ai/Publishing/BlogEditorialContextService.php
  - app/Console/Commands/AtlasBlogEditorialPlanCommand.php
  - tests/Feature/Ai/Publishing/AtlasBlogEditorialPlanCommandTest.php
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-blog-editorial-planning-system
graph_title: Atlas Blog Editorial Planning System
graph_world: atlas
graph_layer: module
graph_kind: module
graph_parent: atlas-ai-knowledge-governance-system
graph_status: active
graph_source: repo
owner: content-intelligence
repo_paths:
  - docs/engineering-knowledge-base/atlas-blog-editorial-planning-system.md
allowed_changes:
  - Atualizar contrato, schema, paths e regras de sequenciamento editorial.
  - Evoluir o planejador deterministico read-only.
  - Anexar refs editoriais read-only vindas de read-models existentes do Atlas.
forbidden_changes:
  - Declarar graph/RAG runtime pronto sem Decision Receipt, AP review e evidencia.
  - Usar este sistema como publicador automatico.
  - Criar memoria, contexto ou grafo paralelo.
depends_on:
  - atlas-ai-knowledge-governance-system
  - atlas-ai-runtime-language-boundaries
flows_to:
  - atlas-ai-memory-context-core-open-brain
  - code-intelligence
unlocks:
  - governed-public-editorial-sequence
governs:
  - content-intelligence
evidence:
  - docs/engineering-knowledge-base/atlas-blog-editorial-planning-system.md
  - docs/ap/AP-817-blog-editorial-planning-contract.md
  - app/Services/Ai/Publishing/BlogEditorialPlannerService.php
  - app/Services/Ai/Publishing/BlogEditorialContextService.php
  - app/Console/Commands/AtlasBlogEditorialPlanCommand.php
  - tests/Feature/Ai/Publishing/AtlasBlogEditorialPlanCommandTest.php
required_tests:
  - php artisan atlas:blog:editorial-plan --json
  - php artisan test --filter=AtlasBlogEditorialPlanCommandTest
requires_evidence: true
risk_level: medium
visual_tags:
  - content-intelligence
  - planning
ai_entrypoints:
  - Leia Resumo, Contratos, Regras para IA e Evidencias antes de alterar codigo.
ai_usage_notes:
  - Use AP-817 e runtime-language-boundaries antes de adicionar graph/RAG.
quality_gates:
  - php artisan atlas:blog:editorial-plan --json
  - php artisan test --filter=AtlasBlogEditorialPlanCommandTest
failure_modes:
  - Publicar topico profundo antes da base publica.
  - Confundir backlog do site com memoria canonica do Atlas.
  - Tratar RAG/graph como atalho sem runtime boundary.
observability_signals:
  - blog editorial planner status ready
patamar_current:
patamar_next_of:
patamar_next:
patamar_after: []
version_family:
versions: []
version_note:
next_actions:
  - Evoluir P1 para context-pack hints sem escrever memoria.
---
# Atlas Blog Editorial Planning System

## Resumo

Esta area do Atlas planeja a ordem do blog pessoal de Vitor Freire. Ela le o
backlog publico do site, valida prerequisitos e mostra qual texto pode ser
escrito/publicado em seguida sem quebrar a curva de entendimento do leitor.

## Papel no Atlas

O papel e transformar conhecimento interno e evolucao do Atlas em uma sequencia
publica compreensivel. O sistema decide ordem editorial, nao publica conteudo.

## Onde Se Encaixa

Fica sob Knowledge Governance e Content Intelligence. P0 e um comando Laravel
read-only. P1 anexa referencias editoriais vindas dos read-models existentes de
Engineering Knowledge e Code Intelligence. P1 futuro pode consultar Open Brain/
Context Pack. P2 so pode usar graph/RAG por AP-817, AP-811, AP-812, AP-815 e
runtime-language-boundaries.

## Contratos

Entrada P0:

- site root;
- backlog YAML relativo;
- posts publicos em `src/data/site.js`.

Saida P0:

- schema `atlas.blog_editorial_planner.v1`;
- status `ready`, `blocked` ou `failed`;
- proximo post pronto;
- posts bloqueados;
- findings estruturados;
- guardrails read-only.

Entrada P1:

- as mesmas entradas de P0;
- `--with-context`;
- read-models existentes `atlas_engineering_knowledge_items`,
  `atlas_engineering_code_modules` e `atlas_engineering_code_symbols`.

Saida P1:

- mode `read_only_governed_p1`;
- `editorial_context` por post;
- refs canônicas candidatas para docs, modulos e simbolos;
- `backlog_candidates` revisaveis quando `--suggest-candidates` for usado;
- prompts de revisao de seguranca;
- guardrails mantendo graph/RAG e Python runtime desligados.

## Fluxo

```text
site backlog -> parse -> validate order/prerequisites -> detect published posts
             -> next ready post -> JSON planner packet

with-context -> derive editorial terms -> read KB/code-intel read-models
             -> attach candidate refs -> human review before writing/publishing

suggest-candidates -> read KB/code-intel read-models -> remove obvious duplicates
                   -> emit review packets -> human accepts before YAML changes
```

## Regras para IA

- Nao publique automaticamente.
- Nao pule prerequisitos.
- Nao crie memoria, contexto, grafo ou RAG paralelo.
- Nao use Python/runtime externo sem Decision Receipt e AP-817.
- Quando um post tecnico parecer interessante mas nao tiver base publica,
  sugira primeiro o texto introdutor.

## Escopo de Implementacao

Permitido em P0:

- `BlogEditorialPlannerService`;
- `AtlasBlogEditorialPlanCommand`;
- testes focados;
- docs/AP do contrato.

Permitido em P1:

- `BlogEditorialContextService`;
- leitura de Engineering Knowledge e Code Intelligence ja indexados;
- refs candidatas para orientar escrita;
- candidatos revisaveis para alimentar backlog futuro;
- prompts de seguranca e privacidade.

Proibido em P0:

- provider calls;
- embeddings;
- graph traversal;
- escrita em memoria;
- publicacao automatica.

Tambem proibido em P1:

- tratar refs como texto pronto para publicar;
- escrever no backlog sem aprovacao explicita;
- expor paths/ids internos sem revisao humana;
- criar indice, memoria ou fila editorial paralela.

## Dependencias

- `atlas-ai-knowledge-governance-system`;
- `atlas-ai-runtime-language-boundaries`;
- `AP-817-blog-editorial-planning-contract`;
- backlog do site em `content/backlog/blog-first-month.yaml`.
- Engineering Knowledge Base;
- Code Intelligence read-model.

## Evidencias

- `php artisan atlas:blog:editorial-plan --site=/Users/vitorepf/develop/vitorepf-site --json`
- `php artisan atlas:blog:editorial-plan --site=/Users/vitorepf/develop/vitorepf-site --with-context --json`
- `php artisan atlas:blog:editorial-plan --site=/Users/vitorepf/develop/vitorepf-site --suggest-candidates --json`
- `php artisan test --filter=AtlasBlogEditorialPlanCommandTest`
- `php artisan atlas:ai:runtime-boundary --json`

## Riscos

- Transformar o blog em despejo tecnico sem ordem.
- Publicar arquitetura profunda antes de explicar Atlas, contexto e memoria.
- Criar uma fila editorial paralela fora do site.
- Chamar graph/RAG como atalho e violar o Kernel-first.

## Exemplos

Se o Atlas quiser publicar "memoria como ledger", o planejador deve verificar se
ja existem textos publicos sobre Atlas, contexto, problema da memoria e memoria
como problema de banco de dados. Se faltarem, esses textos entram antes.

## Proximas Acoes

1. P1: permitir aprovacao explicita de candidato para patch controlado do YAML.
2. P1: evoluir refs para context-pack hints sem escrever memoria.
3. P1: gerar seed privado revisavel a partir do `next_ready_post`.
4. P2: revisar AP-817 antes de qualquer graph/RAG.
