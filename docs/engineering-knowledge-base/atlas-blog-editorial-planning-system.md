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
  - blog_editorial_operating_state
  - blog_editorial_area_state_api
  - editorial_radar_packet
  - editorial_sequence_golden_set
  - bounded_editorial_graph_context
  - bounded_editorial_graph_candidate_feed
  - governed_writing_packet
  - editorial_concept_progression_map
  - daily_editorial_operations_packet
  - read_only_publishing_plan
  - read_only_editorial_topic_ledger
  - read_only_editorial_roadmap
  - read_only_editorial_dependency_matrix
  - read_only_backlog_intake
  - read_only_atlas_signal_mesh
  - read_only_public_knowledge_map
  - open_brain_editorial_context_handoff
  - audited_open_brain_editorial_context_execution
  - graph_rag_readiness_preflight
  - reviewable_backlog_candidate_suggestions
  - editorial_review_queue_state
  - reviewed_candidate_backlog_promotion
decisions:
  - O blog e de Vitor Freire; Atlas e assunto/colecao, nao identidade total do site.
  - A ordem editorial deve ir do superficial ao profundo.
  - O Atlas pode planejar e sugerir, mas nao publicar sem aprovacao humana.
  - P0 e deterministico/read-only; P1 anexa refs de KB/Code Intelligence existentes, reconcilia arquivo publico, expoe mapa de fontes, fila de revisao, cobertura, operating-state, radar, golden set, graph-context/candidates bounded, readiness graph/RAG, pacote privado de escrita, operacao diaria, Open Brain, candidatos e promocao explicita; graph/RAG ativo entra depois por AP-817 e runtime boundaries existentes.
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
  - app/Http/Controllers/AtlasBlogEditorialController.php
  - routes/api.php
  - ../atlas-desktop/apps/desktop/src/surfaces/blog-editorial/
  - ../atlas-desktop/apps/desktop/src/shell/surfaceRegistry.ts
  - ../atlas-desktop/apps/desktop/src/shell/SurfaceHost.tsx
  - app/Console/Commands/AtlasBlogEditorialPlanCommand.php
  - tests/Feature/Ai/Publishing/AtlasBlogEditorialPlanCommandTest.php
  - tests/Feature/Ai/Publishing/AtlasBlogEditorialAreaApiTest.php
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
  - app/Http/Controllers/AtlasBlogEditorialController.php
  - routes/api.php
  - app/Console/Commands/AtlasBlogEditorialPlanCommand.php
  - tests/Feature/Ai/Publishing/AtlasBlogEditorialPlanCommandTest.php
  - tests/Feature/Ai/Publishing/AtlasBlogEditorialAreaApiTest.php
required_tests:
  - php artisan atlas:blog:editorial-plan --json
  - php artisan test --filter=AtlasBlogEditorialPlanCommandTest
  - php artisan test --filter=AtlasBlogEditorialAreaApiTest
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
  - php artisan test --filter=AtlasBlogEditorialAreaApiTest
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
  - Evoluir draft seed privado para geracao de rascunho revisavel sob aprovacao humana.
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
- `operating_state` quando `--operating-state` for usado, servindo como payload
  compacto para a area do blog no Atlas: estagio, contadores, proximo post,
  semanas, fila, candidatos, fontes, cobertura e superficies disponiveis;
- `GET /blog/editorial/state` como superficie autenticada `atlas.token` para a
  UI/agentes do Atlas consumirem estado, fonte, fila, cobertura, radar,
  candidatos e readiness sem escrita, promocao, reordenacao ou publicacao;
- Atlas Desktop surface `blog_editorial` (`Blog`, atalho `Cmd+9`) consome
  somente `GET /blog/editorial/state`, renderiza proximo texto, fronteira,
  operacao diaria, postura de fontes, readiness graph/RAG, checklist de escrita,
  sinais bounded do Codebase World Model, candidatos de grafo revisaveis,
  semanas, candidatos, fila e guardrails, e nunca publica, reordena ou aceita
  candidato;
- `editorial_radar` quando `--editorial-radar` for usado, agregando estado
  atual, semanas, lanes de serie, lacunas, fila de revisao, janelas de insercao,
  candidatos e prontidao de fontes para alimentar a lista sem quebrar a ordem;
- `editorial_golden_set` quando `--editorial-golden-set` for usado, provando com
  fixtures deterministicas que a ordem comeca por identidade, avanca por
  prerequisitos, alerta saltos profundos e mantem termos futuros como futuros;
- `editorial_graph_context` quando `--editorial-graph-context` for usado,
  executando apenas o Codebase World Model bounded com traversal receipt,
  evidencias provider-safe e zero poder de reordenar, publicar ou escrever;
- `editorial_graph_candidates` quando `--editorial-graph-candidates` for usado,
  convertendo sinais do Codebase World Model bounded em candidatos de pauta
  futuros, sempre append-after do arco planejado, sem escrever backlog, fila de
  revisao, rascunho ou publicacao;
- `writing_packet` privado quando `--writing-packet` for usado, contendo
  posicao na sequencia, prerequisitos, contexto candidato, promessa ao leitor,
  contexto do arquivo publico anterior, risco de duplicacao/rewrite, outline,
  pontos obrigatorios, temas a evitar e prompts de revisao;
- `concept_progression_map` dentro do `writing_packet`, separando termos ja
  introduzidos, termos atuais permitidos, prerequisitos conceituais e termos
  futuros que nao devem ser tratados como conhecimento previo do leitor;
  - `operations_packet` quando `--operations` for usado, agregando proxima acao,
  foco diario, writing packet, riscos do arquivo publico, bloqueios, snapshots
  de fonte/cobertura, fila de revisao, handoff Open Brain e candidatos para
  revisao;
- `publishing_plan` dentro de `operations_packet`, com slots de publicacao
  derivados da ordem do backlog, dias configurados, status, etapa do pipeline,
  acao humana esperada e regra explicita de que um post por dia depende de seed
  privado, rascunho revisado e aprovacao humana;
- `topic_ledger` dentro de `operations_packet`, com mapa read-only dos assuntos
  planejados, publicados, candidatos e em revisao, indicando cobertura,
  lacunas de fundacao e proxima acao segura sem reordenar backlog ou promover
  candidatos sozinho;
- `editorial_roadmap` dentro de `operations_packet`, agrupando a ordem do
  backlog em fases da jornada do leitor (`fundacao`, `problema_contexto`,
  `local_privacidade`, `memoria_conhecimento`, `agentes_governanca`,
  `arquitetura_operacao`, `expansao`) para explicar por que cada assunto vem
  antes/depois sem substituir a fila cronologica;
- `editorial_dependency_matrix` dentro de `operations_packet`, com uma escada
  read-only por post: fase, nivel de profundidade, prerequisitos explicitos,
  prerequisitos faltantes, termos que o texto pode introduzir, termos ja
  disponiveis e assuntos futuros que nao devem ser exigidos do leitor;
- `backlog_intake` dentro de `operations_packet`, com recomendacoes read-only
  para alimentar a fila: aceitar em revisao, segurar por duplicata, segurar ate
  a escada de dependencias estar clara ou revisar para promocao append-only;
- `atlas_signal_mesh` dentro de `operations_packet`, consolidando backlog,
  arquivo publico, Engineering Knowledge, Code Intelligence, Open Brain,
  vector retrieval indireto, grafo bounded, fila, feed e intake em uma malha de
  sinais read-only com autoridade, status e proxima acao;
- `public_knowledge_map` dentro de `operations_packet`, projetando o que o
  leitor publico ja pode saber: posts publicados, assuntos assumiveis, assuntos
  ainda nao assumiveis, pontes com o arquivo externo e contrato do proximo texto
  desbloqueado;
- `open_brain_handoff` dentro de `operations_packet` e `writing_packet`, com
  objetivo, comando `atlas:open-brain:context`, payload provider-safe e
  guardrails que mantem invocacao automatica, graph/RAG, Python e publicacao
  desligados em P1;
- `open_brain_context` quando `--execute-open-brain` for usado com
  `--writing-packet` ou `--operations`, contendo apenas hash, resumo, safety,
  audit e refs resumidas do context pack, sem retornar o pacote bruto;
- `graph_rag_readiness` quando `--graph-rag-readiness` for usado, reportando
  componentes P2 existentes, bloqueios, checklist de promocao e plano de
  integracao sem invocar graph retrieval, vector runtime ou Python;
- `backlog_candidates` revisaveis quando `--suggest-candidates` for usado;
- `review_queue` quando `--review-queue` for usado, lendo
  `content/backlog/blog-candidates.yaml`, reportando candidatos aceitos,
  duplicatas e slugs ja em revisao; sugestões de KB/codigo e grafo usam essa
  fila para nao repetir candidatos ja aceitos;
- `candidate_acceptance` em dry-run ou escrita explicita para fila de revisao;
- candidatos vindos de `editorial_graph_candidates` podem usar o mesmo
  `candidate_acceptance`, mas somente quando `--editorial-graph-candidates`
  tambem for solicitado, mantendo escrita restrita a fila de revisao;
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

editorial-radar -> aggregate source/coverage/candidates into an operator view
                -> show week lanes, sequence lanes, gaps and insertion windows
                -> keep graph/RAG deferred until P2 promotion

operating-state -> aggregate compact area state for Atlas UI/agents
                -> expose next post, week board, queue, candidates and source posture
                -> read-only, no publication or backlog mutation

area-state-api -> expose operating-state/source/coverage/radar via atlas.token
               -> support Atlas UI and agents without terminal coupling
               -> render operations packet, source posture and writing brief as read-only cockpit
               -> render bounded graph context and graph-derived candidates as review signals
               -> no acceptance, promotion, reorder, draft write or publication

writing-packet -> choose next ready post, or explicit planned slug
               -> attach sequence, public archive context, refs, outline and safety prompts
               -> attach concept progression map for introduced/current/future terms
               -> optionally execute audited Open Brain context export
               -> private preparation only, no full article and no file write

operations -> aggregate next action, writing packet, archive risks and blockers
           -> show source/coverage snapshot, review queue state and candidate feed
           -> derive publishing plan slots from backlog order and cadence
           -> derive editorial roadmap phases from slots and topic ledger
           -> derive public knowledge map from archive and topic ledger
           -> include audited Open Brain handoff for the next post
           -> optionally include Open Brain execution summary
           -> daily read-only operator packet

graph-rag-readiness -> inspect AP/docs/classes/tables for P2 editorial context
                    -> include editorial golden-set status as a P2 blocker gate
                    -> report blockers and promotion checklist
                    -> no traversal, no Python, no backlog mutation

editorial-graph-context -> choose next ready post -> run bounded World Model retrieval
                        -> attach receipt/evidence as context hints
                        -> no global graph/RAG, no Python, no publication power

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
- Cada pacote de escrita deve proteger a progressao do leitor: usar conceitos
  ja introduzidos, explicar a camada atual e evitar detalhes que pertencem a
  posts futuros.
- Trate calendario como slot operacional; a ordem conceitual manda mais que a
  data. Um post por dia so e seguro quando o slot atual esta pronto.
- Trate `editorial_roadmap` como explicacao da jornada, nao como autoridade para
  reordenar, pular fase ou promover candidato automaticamente.
- Trate `editorial_dependency_matrix` como contrato de entendimento do leitor:
  antes de escrever um post, confirme que seus prerequisitos estao publicados ou
  explicados na propria camada; se faltar base, escreva primeiro o texto
  introdutor.
- Trate `backlog_intake` como triagem, nao como escrita: ele pode recomendar
  entrada na fila de revisao, bloqueio ou promocao append-only, mas nao deve
  alterar backlog, publicar, reordenar ou aprovar candidato sem humano.
- Trate `atlas_signal_mesh` como painel de controle dos sinais do Atlas: ela
  coordena fontes existentes, mas nao cria memoria, grafo, RAG, fila paralela,
  reordenacao automatica ou autoridade de publicacao.
- Trate `public_knowledge_map` como memoria publica do leitor, nao como memoria
  canonica do Atlas: ele so diz o que ja pode ser assumido em texto publico e o
  que ainda precisa ser introduzido antes de aprofundar.
- Use `--editorial-radar` para decidir onde alimentar a lista; ele nao muda
  backlog, nao aceita candidatos e nao publica.
- Use `--editorial-golden-set` para provar que a sequencia ainda ensina do raso
  ao profundo antes de aceitar sugestoes mais inteligentes.
- Use `--editorial-graph-context` somente como evidência auxiliar; ele pode
  informar o pacote editorial, mas nao muda ordem, candidato ou publicacao.
- Use `--graph-rag-readiness` para ver o caminho de P2; ele nao executa graph,
  RAG, Python nem reordena a lista.

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
- radar editorial read-only para semanas, lanes, lacunas e janelas de insercao;
- golden set editorial read-only para validar fixtures de sequencia antes de P2;
- contexto editorial via World Model bounded, com receipt e sem poder de escrita;
- candidatos revisaveis para alimentar backlog futuro;
- pacote privado de escrita para o proximo post ou slug planejado explicito,
  exposto na Blog surface como pergunta, promessa, outline, obrigatorios e
  limites conceituais;
- draft seed privado dentro do pacote de escrita, com tese, abertura, secoes,
  fechamento e checklist de revisao, sem escrever arquivo e sem gerar artigo
  completo;
- mapa de progressao conceitual dentro do pacote privado de escrita;
- contexto do arquivo publico dentro do pacote de escrita, para linkar posts
  antigos, detectar risco de repeticao e decidir rewrite deliberadamente;
- pacote operacional diario read-only, para orientar o proximo trabalho sem
  abrir permissao de escrita, draft ou publicacao;
- mapa de conhecimento publico dentro do pacote operacional, derivado do arquivo
  publicado e do ledger de assuntos, para impedir que IA assuma conceitos que o
  leitor ainda nao viu;
- plano de publicacao read-only dentro do pacote operacional, derivado da
  cadencia e da ordem do backlog, com status de slot, etapa do pipeline e acao
  humana esperada;
- roadmap editorial read-only dentro do pacote operacional, derivado dos slots
  e do ledger de assuntos, para mostrar a jornada raso -> profundo e a fase
  ativa sem criar uma segunda fila;
- handoff auditavel para `atlas:open-brain:context`, sem invocacao automatica;
- execucao auditada opcional de Open Brain, retornando resumo seguro e refs
  resumidas, nunca o context pack bruto;
- preflight read-only de graph/RAG para mostrar APs, runtime boundaries,
  classes, tabelas, bloqueios e checklist antes de qualquer promocao P2;
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
- tratar `open_brain_context` como texto pronto para publicar;
- escrever arquivo de draft a partir do pacote de escrita;
- usar calendario ou cadencia para pular prerequisito;
- usar roadmap como fonte canonica alternativa ao backlog;
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
- `php artisan atlas:blog:editorial-plan --site=/Users/vitorepf/develop/vitorepf-site --operating-state --json`
- `php artisan atlas:blog:editorial-plan --site=/Users/vitorepf/develop/vitorepf-site --editorial-radar --json`
- `php artisan atlas:blog:editorial-plan --site=/Users/vitorepf/develop/vitorepf-site --editorial-golden-set --json`
- `php artisan atlas:blog:editorial-plan --site=/Users/vitorepf/develop/vitorepf-site --editorial-graph-context --json`
- `php artisan atlas:blog:editorial-plan --site=/Users/vitorepf/develop/vitorepf-site --graph-rag-readiness --json`
- `php artisan atlas:blog:editorial-plan --site=/Users/vitorepf/develop/vitorepf-site --writing-packet --json`
- `php artisan atlas:blog:editorial-plan --site=/Users/vitorepf/develop/vitorepf-site --writing-packet --writing-slug=<slug> --json`
- `php artisan atlas:blog:editorial-plan --site=/Users/vitorepf/develop/vitorepf-site --suggest-candidates --json`
- `php artisan atlas:blog:editorial-plan --site=/Users/vitorepf/develop/vitorepf-site --accept-candidate=<slug> --json`
- `php artisan atlas:blog:editorial-plan --site=/Users/vitorepf/develop/vitorepf-site --accept-candidate=<slug> --write --json`
- `php artisan atlas:blog:editorial-plan --site=/Users/vitorepf/develop/vitorepf-site --promote-candidate=<slug> --json`
- `php artisan atlas:blog:editorial-plan --site=/Users/vitorepf/develop/vitorepf-site --promote-candidate=<slug> --write --json`
- `php artisan test --filter=AtlasBlogEditorialPlanCommandTest`
- `php artisan test --filter=AtlasBlogEditorialAreaApiTest`
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
3. P1: evoluir draft seed privado para rascunho revisavel sob aprovacao humana.
4. P2: revisar AP-817 antes de qualquer graph/RAG.
