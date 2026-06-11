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
  - editorial_source_readiness_map
  - public_archive_reconciliation
  - public_archive_coverage_map
  - governed_writing_packet
  - daily_editorial_operations_packet
  - reviewable_backlog_candidate_suggestions
  - reviewed_candidate_backlog_promotion
decisions:
  - O blog e de Vitor Freire; Atlas e assunto/colecao, nao identidade total do site.
  - A ordem editorial deve ir do superficial ao profundo.
  - O Atlas pode planejar e sugerir, mas nao publicar sem aprovacao humana.
  - P0 e deterministico/read-only; P1 anexa refs de KB/Code Intelligence existentes, reconcilia o arquivo publico com o backlog planejado, expoe mapa de fontes editoriais, calcula cobertura editorial, prepara pacote privado de escrita, monta pacote operacional diario, sugere candidatos revisaveis e promove candidatos aceitos para o backlog somente com escrita explicita; graph/RAG entra depois por AP-817 e runtime boundaries existentes.
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
  - Evoluir pacote de escrita para seed privado revisavel, ainda sem artigo completo automatico.
  - Manter promocao de candidatos append-only ate existir reordenacao segura.
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
- `source_map` com backlog, arquivo publico, Engineering Knowledge, Code
  Intelligence, Open Brain, vector retrieval e graph retrieval classificados
  como prontos, indiretos, indisponiveis ou futuros governados;
- `archive_reconciliation` dentro do `source_map`, comparando metadados de
  posts publicados com o backlog planejado, separando posts planejados ja
  publicados de posts externos e sugerindo pontes para posts planejados;
- `coverage_map` com fundacao planejada/publicada, indice de topicos,
  distribuicao por profundidade, warnings e proximos arcos seguros;
- `writing_packet` privado quando `--writing-packet` for usado, contendo
  posicao na sequencia, prerequisitos, contexto candidato, promessa ao leitor,
  contexto do arquivo publico anterior, risco de duplicacao/rewrite, outline,
  pontos obrigatorios, temas a evitar e prompts de revisao;
- `operations_packet` quando `--operations` for usado, agregando proxima acao,
  foco diario, writing packet, riscos do arquivo publico, bloqueios, snapshots
  de fonte/cobertura e candidatos para revisao;
- `backlog_candidates` revisaveis quando `--suggest-candidates` for usado;
- `candidate_acceptance` em dry-run ou escrita explicita para fila de revisao;
- `candidate_promotion` em dry-run ou escrita explicita append-only no backlog
  principal;
- prompts de revisao de seguranca;
- guardrails mantendo graph/RAG e Python runtime desligados.

## Fluxo

```text
site backlog -> parse -> validate order/prerequisites -> detect published posts
             -> next ready post -> JSON planner packet

with-context -> derive editorial terms -> read KB/code-intel read-models
             -> attach candidate refs -> human review before writing/publishing

source-map -> inspect available read-models and editorial source layers
           -> mark Open Brain/vector as governed handoff sources
           -> reconcile public archive metadata with planned backlog
           -> suggest bridge candidates without changing prerequisites
           -> keep graph retrieval future-governed until P2 promotion

coverage-map -> compare backlog/published slugs with foundation ladder
             -> show covered topics, depth warnings and safe next arcs

writing-packet -> choose next ready post, or explicit planned slug
               -> attach sequence, public archive context, refs, outline and safety prompts
               -> private preparation only, no full article and no file write

operations -> aggregate next action, writing packet, archive risks and blockers
           -> show source/coverage snapshot and candidate feed
           -> daily read-only operator packet

suggest-candidates -> read KB/code-intel read-models -> remove obvious duplicates
                   -> emit review packets -> human accepts before YAML changes

accept-candidate -> resolve candidate by slug -> emit YAML snippet
                 -> optional --write appends review queue, not main schedule

promote-candidate -> read accepted review queue item -> emit backlog YAML snippet
                  -> optional --write appends a reviewed week to main backlog
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
- mapa de fontes editoriais read-only para expor o que o Atlas pode consultar
  agora e o que ainda e P2/futuro governado;
- reconciliacao do arquivo publico contra o backlog, incluindo posts externos
  ao plano e possiveis pontes para textos planejados;
- mapa de cobertura read-only para saber o que ja foi planejado/publicado;
- candidatos revisaveis para alimentar backlog futuro;
- pacote privado de escrita para o proximo post ou slug planejado explicito;
- contexto do arquivo publico dentro do pacote de escrita, para linkar posts
  antigos, detectar risco de repeticao e decidir rewrite deliberadamente;
- pacote operacional diario read-only, para orientar o proximo trabalho sem
  abrir permissao de escrita, draft ou publicacao;
- escrita explicita somente em `content/backlog/blog-candidates.yaml`;
- promocao explicita append-only de candidatos aceitos para o backlog principal;
- prompts de seguranca e privacidade.

Proibido em P0:

- provider calls;
- embeddings;
- graph traversal;
- escrita em memoria;
- publicacao automatica.

Tambem proibido em P1:

- tratar refs como texto pronto para publicar;
- gerar artigo completo automaticamente a partir do pacote de escrita;
- escrever arquivo de draft a partir do pacote de escrita;
- escrever no backlog sem aprovacao explicita;
- promover candidato direto para calendario principal sem passar pela fila de
  revisao;
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
- `php artisan atlas:blog:editorial-plan --site=/Users/vitorepf/develop/vitorepf-site --source-map --json`
- `php artisan atlas:blog:editorial-plan --site=/Users/vitorepf/develop/vitorepf-site --coverage-map --json`
- `php artisan atlas:blog:editorial-plan --site=/Users/vitorepf/develop/vitorepf-site --writing-packet --json`
- `php artisan atlas:blog:editorial-plan --site=/Users/vitorepf/develop/vitorepf-site --writing-packet --writing-slug=<slug> --json`
- `php artisan atlas:blog:editorial-plan --site=/Users/vitorepf/develop/vitorepf-site --suggest-candidates --json`
- `php artisan atlas:blog:editorial-plan --site=/Users/vitorepf/develop/vitorepf-site --accept-candidate=<slug> --json`
- `php artisan atlas:blog:editorial-plan --site=/Users/vitorepf/develop/vitorepf-site --accept-candidate=<slug> --write --json`
- `php artisan atlas:blog:editorial-plan --site=/Users/vitorepf/develop/vitorepf-site --promote-candidate=<slug> --json`
- `php artisan atlas:blog:editorial-plan --site=/Users/vitorepf/develop/vitorepf-site --promote-candidate=<slug> --write --json`
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

1. P1: evoluir promocao append-only para reordenacao segura quando necessario.
2. P1: evoluir refs para context-pack hints sem escrever memoria.
3. P1: evoluir pacote de escrita para seed privado revisavel, ainda sem artigo completo automatico.
4. P2: revisar AP-817 antes de qualquer graph/RAG.
