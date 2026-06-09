---
title: AP-815 Cross-Project Ultra-Precise Context Engine — 54-block program
status: implemented (54/54 blocks live + independently-verified green 2026-06-08: 521 PHP code-graph tests/2524 asserts + 28 python test files; behind flags + default-safe, NOT auto-promoted per runtime_promotion_policy.v1; details in STATUS-54-BLOCKS.md)
owner: code_graph / awis / ai-runtime
line_limit: 220
related_paths:
  - app/Services/Engineering/CodeGraph/CodeGraphWorkspaceIdentity.php
  - app/Services/Engineering/EngineeringCodeIntelligenceService.php
  - database/migrations/2026_06_08_233000_add_workspace_id_to_code_intelligence_tables.php
  - config/atlas.php
  - tests/Feature/CodeGraph/CodeGraphWorkspaceKeyingTest.php
  - dissecar/graphify/atlas-adoption/ATLAS-CROSS-PROJECT-CONTEXT-DEEP-ROADMAP.md
  - dissecar/graphify/atlas-adoption/STATUS-54-BLOCKS.md
  - docs/engineering-knowledge-base/atlas-ai-runtime-language-boundaries.md
---

# [AP-815] Cross-Project Ultra-Precise Context Engine

## 1. Propósito

Implementar os **54 blocos** do backlog `ATLAS-CROSS-PROJECT-CONTEXT-DEEP-ROADMAP.md`:
contexto **bizarro de preciso E eficiente em QUALQUER projeto**, governado pelo AWIS.
Capture-not-cede: **estende** o code-graph (AP-811/812), a compression (AP-813) e o
cross-domain (AP-814) — **não cria** 2º grafo/runtime.

## 2. Contrato de governança (vale pra TODOS os 54 blocos)

- **Flag-gated + default-safe.** Comportamento novo nasce atrás de flag; o caminho do
  workspace primário (`atlas-server`) fica byte-idêntico ao atual.
- **Sem auto-promote** (`runtime_promotion_policy.v1`): nada de merge-to-main / promoção de
  runtime sem review humano. `--persist` cross-escopo continua gated (AP-814 §11).
- **Anti-over-claim:** um bloco só é "verde" com **teste passando + zero-regressão provada**
  (não claim). Red pré-existente é provado por `git stash` antes de atribuir.
- **Melhor linguagem por bloco** (`runtime-language-boundaries.md`): `[php]` Kernel,
  `[py]` python_ai_data atrás do invoker assinado, `[native]` ferramenta da linguagem-alvo.
  Promover op python nova = review humano + dep-approval.
- **Soberania:** indexar repo externo exige **G-5 (secret/PII scan) + G-1 (privacy class)**
  antes; sensitive/secret/cyber nunca cruzam workspace/domínio sem veto ARPTL.

## 3. Critério de aceite por bloco

Código real + teste(s) verde(s) + `php -l` limpo + zero-regressão na suíte adjacente
(provado por stash quando há red ambiente) + status atualizado no STATUS ledger com a
evidência (nome do teste). Sequência: P0 (cross-project seguro) → P1 (precisão+eficiência)
→ P2 (salto cross-workspace) → P3 (profundidade). Keystones primeiro: W-1, E-1, P-7, Q-2, G-5.

## 4. Evidência — entregue até agora

### W-1 [php] · keystone · workspace keying do read-model code-intel — **LIVE + VERDE**
- **Migration** `2026_06_08_233000_add_workspace_id_to_code_intelligence_tables.php`: adiciona
  `workspace_id` NOT-NULL default `atlas-server` (backfill on-add) às 4 tabelas
  `atlas_engineering_code_*` e troca os unique globais por compostos workspace-scoped.
  Cross-driver: cobre pgsql (CONSTRAINT em modules/doc_links/file_snapshots vs INDEX raw em
  symbols) e sqlite; idempotente (hasTable/hasColumn, IF EXISTS).
- **`CodeGraphWorkspaceIdentity`** (semente W-7): path → workspace_id estável (primary=default;
  senão git-remote slug; senão basename+hash). Puro de DB/clock.
- **Writers keyados** em `EngineeringCodeIntelligenceService` (modules/symbols/doc_links/
  file_snapshots): stamp + conflict-key + prune/archive workspace-scoped, todos defensivos
  (`workspaceKeyed()` checa a coluna → fallback ao caminho single-workspace se ausente).
- **Teste:** `CodeGraphWorkspaceKeyingTest` (3/14 asserts verde): migration real na cadeia
  sqlite, backfill, mesmo slug coexiste em 2 workspaces + colide dentro de 1, resolver estável.
- **Zero-regressão provada:** os 2 reds de `AtlasEngineeringKnowledgeBaseTest`
  (`cache.quality_guard.key` = drift do `EXTRACTOR_VERSION=2`) já falham na árvore limpa
  (provado via `git stash -u`) — não são desta mudança.

## 5. Estado

Programa multi-onda. O detalhe vivo de cada um dos 54 blocos (linguagem, status, evidência)
está em `dissecar/graphify/atlas-adoption/STATUS-54-BLOCKS.md`. Este AP é o contrato; o
backlog é a spec; o STATUS é o placar honesto.
