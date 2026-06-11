---
title: AP-818 Folder Intelligence Fase 2 — Workspace Nasce Inteligente (on-link intelligence assembly)
status: approved
owner: atlas_code / code_graph / ai-runtime
line_limit: 220
related_paths:
  - app/Services/AtlasCode/WorkspaceFolderIntelligenceService.php
  - app/Http/Controllers/AtlasCodeWorkspaceController.php
  - app/Services/AtlasCode/AtlasCodeWorkspaceProfileService.php
  - app/Console/Commands/AtlasCodeGraphPipelineCommand.php
  - app/Console/Commands/AtlasCodeGraphIndexAllCommand.php
  - app/Services/Engineering/CodeGraph/CodeGraphWorkspaceIdentity.php
  - app/Services/Engineering/CodeGraph/CodeGraphContextRetriever.php
  - app/Services/Engineering/CodeGraph/CodeGraphRuntimeInvoker.php
  - app/Services/Ai/RuntimeBoundary/PythonManifestRuntimeClient.php
  - runtimes/python/semantic_rag/
  - runtimes/python/code_graph/main.py
  - docs/ap/AP-811-atlas-code-graph-real-edges-traversal.md
  - docs/ap/AP-812-python-ai-data-code-graph-runtime.md
  - docs/ap/AP-815-cross-project-context-engine.md
  - docs/engineering-knowledge-base/atlas-ai-runtime-language-boundaries.md
  - docs/engineering-knowledge-base/atlas-ai-local-performance-memory-strategy.md
  - docs/engineering-knowledge-base/atlas-ai-knowledge-governance-system.md
---

# [AP-818] Folder Intelligence Fase 2 — Workspace Nasce Inteligente

## 1. Propósito

Quando o operador **vincula uma pasta** no Atlas Code, o workspace deve **nascer
inteligente**: estrutura de inteligência completa montada automaticamente —
graph do código real (migrations/rotas/símbolos/arestas, não só contagens),
GraphRAG que entrega ao provedor apenas os arquivos mínimos necessários,
embeddings + RAG + agentic RAG; e, para pastas guarda-chuva, o índice agregado
de todos os repos filhos (ex.: `Atlas` = atlas-server + atlas-desktop +
atlas-app juntos). Tudo local-first: nada sai do Mac.

A Fase 1 (`WorkspaceFolderIntelligenceService`, schema
`atlas.code.workspace_folder_intelligence.v1`) já classifica
`git_repository | umbrella | plain_folder | missing`, enumera filhos de
guarda-chuva e expõe retrato estrutural barato via `git ls-files`. A Fase 2
usa **essa classificação/enumeração como fonte** do que indexar e como agregar.

## 2. Placement e gate

`php artisan atlas:ai:place-feature` classificou: `layer=runtime`,
`runtime=python_ai_data`, `requires_ap=true`, `gate_status=blocked`. **Este AP
é o contrato que destrava o gate.** Preflight obrigatório por fatia python:
`runtime_boundary_preflight_gate.v1` (invoke/result schema, Decision Receipt,
evidence contract, rollback, testes focados, architecture-validate). Promoção
de runtime = review humano (`runtime_promotion_policy.v1`), nunca automática.

## 3. O que JÁ existe — reuso obrigatório (anti-duplicação)

O épico NÃO é greenfield. AP-811/812/815 entregaram o substrato; duplicar
qualquer linha abaixo é violação de canon (`atlas-ai-knowledge-governance-system.md`).

> Nota de revisão (verificado no código, 2026-06-11): os códigos de bloco W-*
> referenciados abaixo NÃO estão definidos no texto do AP-815 — a âncora real
> é o CÓDIGO: W-4 `AtlasCodeGraphPipelineCommand`, W-7
> `CodeGraphWorkspaceIdentity`, W-9 `CodeGraphSchemaVersion`, W-10
> `CodeGraphIndexLock`, W-11 `CodeGraphFirstIndexPlanner`. Tratar os arquivos
> como fonte de verdade dos contratos W-*.

| Pedido do épico | Peça existente (reusar) | Origem |
|---|---|---|
| Graph do código real por workspace | `atlas:code-graph:pipeline` (certify→index→build, W-4) | AP-815 |
| Multi-repo com isolamento + secret scan | `atlas:code-graph:index-all <root>` (G-5+G-1 antes da ingestão) | AP-815 |
| Identidade estável de workspace | `CodeGraphWorkspaceIdentity` (path→workspace_id, W-7) | AP-815 |
| Read-model keyado por workspace | tabelas `atlas_engineering_code_*` com `workspace_id` (W-1/W-3) | AP-815 |
| GraphRAG "arquivos mínimos" | `CodeGraphContextRetriever` (BM25 A3 → pack E-3, fonte única) | AP-815 |
| Entrega compacta + expand-on-demand (agentic) | seam AOBG `buildInjection` + contrato de expansão Open Brain | AP-815/AOBG |
| Embeddings locais reais | `runtimes/python/semantic_rag` (fastembed ONNX, provado anti-fake) | python engine |
| Analytics pesada de grafo (28 ops, incl. cross-ws X-4) | `runtimes/python/code_graph` via `CodeGraphRuntimeInvoker` (receipt A2) | AP-812/815 |
| Lock, orçamento de 1º index, retenção, schema-version | W-10 / W-11 / W-8 / W-9 | AP-815 |
| Adapter PHP↔Python | `PythonManifestRuntimeClient` (regra do boundary doc) | canon |

A Fase 2 é **gatilho + agregação umbrella + perna semântica governada + schema
v2 do retrato** — glue por cima desse substrato.

## 4. Escopo — fatias

### F2.0 [php] Contrato + estado de inteligência
- `intelligence_status` no retrato: `portrait_only | assembly_pending | indexing | indexed | failed`,
  derivado do read-model keyado (existe índice para o `workspace_id`? freshness W-9?).
- Resolução umbrella→membros: `umbrella_members` = filhos da Fase 1 resolvidos a
  `workspace_id` via `CodeGraphWorkspaceIdentity` (respeita cap de 16 filhos +
  `children_truncated` honesto no payload).
- Aceite: schema v2 (`atlas.code.workspace_folder_intelligence.v2`) aditivo;
  payload v1 byte-compatível quando flag OFF; testes focados verdes.

### F2.1 [php] Gatilho on-link (assembly governado, NUNCA inline no HTTP)
- Seam: `AtlasCodeWorkspaceController::upsert()` → após persistir profile com
  `workspace_path` válido, **enfileira** assembly (job/queue chamando os MESMOS
  services do `atlas:code-graph:pipeline`; jamais shell-out, jamais síncrono na
  request — regra dura da Fase 1 + lição perf do create-path).
- `git_repository` → pipeline W-4 do workspace. `umbrella` → pipeline por filho
  (lógica do index-all, com G-5+G-1 por repo ANTES de ingerir). `plain_folder |
  missing` → retrato apenas, sem indexação.
- Reusar lock W-10 (sem corrida com index manual), orçamento W-11 (1º index
  staged/sampled), re-link = no-op se índice fresco (W-9).
- Dependências VERIFICADAS na revisão: fila Laravel real (`QUEUE_CONNECTION=database`
  + tabela `jobs`; o kernel do desktop já sobe um queue worker junto do serve).
  Atenção: o build de símbolos do pipeline é gateado por
  `ATLAS_CODE_GRAPH_REAL_EDGES` — a fatia declara explicitamente o comportamento
  com o env OFF (index Code Intelligence sem symbol graph) em vez de assumir ON.
- Flag `atlas.code_folder_intelligence.auto_assemble` default **OFF**; ON só
  após fatia provada. Workspace primário (`atlas-server`) byte-idêntico com flag OFF.
- Evidence: `FOLDER_INTEL_ASSEMBLY_{QUEUED,STARTED,COMPLETED,FAILED}` com
  workspace_id, contagens e duração (sem conteúdo de arquivo).

### F2.2 [php] Índice agregado umbrella
- Read-model `atlas.code.umbrella_intelligence.v1`: umbrella path →
  `workspace_id` membros + coverage/freshness agregadas + status por membro.
- Retrieval umbrella-scoped: `CodeGraphContextRetriever` aceita **lista** de
  workspace_ids (aditivo, default = comportamento atual de 1 workspace);
  resolvers W-3 continuam vetando cross-workspace fora do escopo pedido.
- Aceite: query no umbrella `Atlas` retorna pack com fontes de ≥2 repos filhos
  reais, e workspace fora do umbrella NUNCA aparece (teste de isolamento).

### F2.3 [php] Retrato estrutural v2 — fatos reais, não contagens
- Quando `indexed`: migrations/rotas/comandos/testes/símbolos **reais** lidos do
  read-model keyado (`atlas_engineering_code_*`), com amostras nomeadas e top-N
  por categoria; quando não indexado: fallback v1 (contagens `git ls-files`).
- Aceite: endpoint `/atlas-code/projects/workspaces` mostra v2 para atlas-server
  (indexado) e v1-fallback para pasta nunca indexada; custo HTTP inalterado
  (leitura de read-model + cache 60s, zero processo git extra).

### F2.4 [py] Perna semântica — embeddings + semantic RAG (python_ai_data)
- Embeddings por workspace via `semantic_rag` (fastembed local) invocados pelo
  Kernel via `PythonManifestRuntimeClient`-pattern + `CodeGraphRuntimeInvoker`
  (Decision Receipt sha256 obrigatório — A2); arestas semânticas P-4 / entity
  resolution X-7 do `code_graph/main.py`. Dependências VERIFICADAS na revisão
  (2026-06-11): `fastembed 0.8.0` + `onnxruntime 1.26.0` presentes em
  `runtimes/python/semantic_rag/.venv` — a contradição do STATUS-54-BLOCKS
  resolve para "instalado"; a fatia ainda roda o preflight antes de invocar.
- Retrieval híbrido: BM25 (A3) + vetores locais, rerank local, pack compacto
  citado (pipeline canônico do `atlas-ai-local-performance-memory-strategy.md`).
- Flag própria default **OFF**; sem a flag, retrieval = BM25 atual inalterado.
  Vetores ficam em store local governado (sem vector store externo).
- Aceite: prova semântica anti-fake no corpus do workspace (consulta sem
  overlap lexical recupera arquivo certo) + fallback explícito quando venv
  indisponível + evidence `RUNTIME_INVOKED/RETURNED/FAILED`.

### F2.5 [php] Consumo — agentic RAG no escopo do workspace ativo
- AOBG/MCP/Dev/Forge passam a pedir contexto no escopo do profile ativo
  (workspace único ou umbrella members), compact-first + expansão exata
  on-demand (contrato de expansão Open Brain já operacional).
- Aceite: `atlas_context_pack` com workspace umbrella devolve pack agregado;
  flag OFF = comportamento atual byte-idêntico (hash de prompt estável).

## 5. Não-escopo / proibições

- **Nenhum segundo grafo/índice paralelo** ao code-graph/Code Intelligence.
- Nenhuma indexação inline em request HTTP ou em fluxo de chat.
- Python não decide domínio/modelo/policy; não escreve Memory/Context/Policy;
  não vira source of truth (boundary doc).
- Sem auto-promote de runtime ou flag; promoção = review humano.
- Sem dep python nova sem OK explícito do operador (regra de soberania).
- Sem indexar repo externo sem G-5 (secret/PII) + G-1 (privacy class) antes;
  classes sensitive/secret/cyber nunca saem da máquina.
- Vocabulário proibido do canon respeitado em código e docs da obra.

## 6. Contratos

- Invoke/result python: `atlas.runtime.invoke.v1` / `atlas.runtime.result.v1`
  com `decision_receipt_hash` (idêntico a AP-812 §5).
- Schemas novos: `atlas.code.workspace_folder_intelligence.v2` (aditivo) e
  `atlas.code.umbrella_intelligence.v1`.
- Evidence: família `FOLDER_INTEL_*` + `RUNTIME_*` existente; nunca persiste
  conteúdo bruto de arquivo ou prompt renderizado.

## 7. Performance e memória (lições aplicadas)

- HTTP de workspaces continua read-model puro (cache 60s da Fase 1).
- Assembly roda em fila com orçamento W-11 e lock W-10; primeiro index de
  umbrella grande é staged (membros em série, não fan-out de processos).
- Pirâmide L3→L2→L1→L0 do strategy doc: índice em disco, hot pack em RAM,
  pack compacto no prompt; degradação por pressão de memória documentada.

## 8. Riscos e mitigações

- **Cérebro paralelo** → só glue sobre peças AP-811/812/815; tabela §3 é lei.
- **Indexação destrutiva cross-workspace** (incidente 208k doc-links) → reusar
  guards D-4 + prune workspace-scoped; teste de isolamento por fatia.
- **Over-claim** → fatia só fecha com teste nomeado + zero-regressão provada
  (stash quando houver red ambiente), padrão AP-815 §3.
- **Umbrella gigante** → cap 16 filhos da Fase 1 + `children_truncated` exposto
  + orçamento W-11; nunca scan ilimitado.
- **Segredos em repo filho** → G-5 antes da ingestão (precedente blackink:
  achou chave real antes de indexar).

## 9. Definition of Done da obra

Por fatia: código + testes focados verdes + `php -l` + zero-regressão na suíte
adjacente + status atualizado neste AP com nome do teste. Da obra: F2.0–F2.3
LIVE flag-ON com evidência; F2.4–F2.5 construídas flag-OFF aguardando promotion
review humano; docs-health + architecture-validate verdes; KB sync + index-code
rodados; retrato v2 visível na UI para um link real de umbrella.

## 10. Status da implementação (2026-06-11 — operador aprovou; obra executada)

Teste focado: `FolderIntelligencePhase2Test` (13 testes/47 asserções verdes);
zero-regressão: CodeGraph 279 + AOBG/AtlasCode 72 verdes.

- F2.0 LIVE flag-ON · v2 + status reader + scopedStatus por membro (fatia
  honesta do grafo do umbrella — nunca o total repetido).
- F2.1 LIVE flag-ON · job `folder-intel` (conexão database-long), drenado pelo
  scheduler; PATCH→assembly_pending provado por HTTP. Ativação AOBG (30s+
  medidos) foi DEFERIDA para o job — rodava inline e matava o save da ficha.
- F2.2 LIVE · packForWorkspaces (whereIn estrito; isolamento testado); umbrella
  real provou fontes atlas+atlas-server num pack.
- F2.3 LIVE flag-ON · indexPortrait (amostras nomeadas reais) no retrato v2.
- F2.4 construída flag-OFF · semanticRerank por embeddings reais (reordenação
  provada; ~2,4s/pack medidos → promotion review antes de ligar; store de
  embeddings persistente por workspace é o próximo passo dessa perna).
- F2.5 LIVE flag-ON · umbrella_context no AOBG (workspace_scope na proveniência).
