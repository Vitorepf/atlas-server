---
title: Atlas Code Graph - Real Edges, Traversal and Governed Roadmap
status: proposed
owner: Atlas Code Intelligence
line_limit: 220
related_paths:
  - docs/ap/AP-684-graphify-external-graph-harness.md
  - docs/ap/AP-683-local-rag-graph-promotion-review.md
  - docs/ap/AP-686-voice-realtime-python-runtime-boundary.md
  - docs/engineering-knowledge-base/atlas-ai-runtime-language-boundaries.md
  - docs/engineering-knowledge-base/code-intelligence.md
  - docs/engineering-knowledge-base/atlas-programming-governance-system.md
  - app/Services/Engineering/EngineeringCodeIntelligenceService.php
  - app/Services/Ai/AutonomousEngineering/WorldModel/WorldModelGraphRanker.php
  - app/Services/Ai/AutonomousEngineering/AtlasAutonomousEngineeringService.php
  - app/Models/AiCodebaseWorldModelEdge.php
  - app/Services/Ai/AtlasOpenBrainMcpService.php
  - ../../dissecar/graphify/atlas-adoption/ATLAS-CODE-GRAPH-PARITY-AND-BEYOND-ROADMAP.md
---

# AP-811 - Atlas Code Graph: Real Edges, Traversal and Governed Roadmap

## 1. Proposito

Tornar **real** o grafo de codigo do Atlas. Hoje a maquinaria de grafo ja existe
e roda, mas as arestas sao **fixture hardcoded**. Esta AP entrega o gap keystone -
um **resolver cross-file de simbolos** que produz arestas reais confidence-graded,
persistidas na tabela que ja existe, expostas por **traversal no Open Brain MCP** -
e governa a sequencia completa do roadmap (P0..P14 / M0..M10) **sem criar stack
paralela, cerebro Python nao-governado nem auto-promocao de runtime**.

Captura as tecnicas dissecadas do graphify (ver roadmap em `dissecar/`) como
**tecnica, nao autoridade**, dentro do read-model governado.

## 2. Status Real (code-verified 2026-06-08, sem over-claim)

EXISTE + WIRED (estender, nao recriar):
- Tabela de arestas `ai_codebase_world_model_edges` (from/to/edge_type/metadata +
  verdade temporal). Models `AiCodebaseWorldModelEdge` / `...Node`.
- Ranker/traversal real `WorldModelGraphRanker` (pesos por edge-type, in/out,
  relation_path, graph_top vs text_only) + CLI `atlas:context:graph-retrieval`.
- Code Intelligence vivo: 23 modulos, 108.018 simbolos, 163.415 doc-links
  (indexado 2026-06-08), com AST PHP real (nikic) e relacoes per-file em JSON.
- Runtime Python real `runtimes/python/programming_intelligence` (exec via
  `ProgrammingPythonRuntimeExecutor`) + `ProgrammingPythonRuntimeGraphProjector`.
- Harness graphify AP-684 (read-only validator) + contratos Graph-RAG future-gated.

GAP (greenfield, escopo P0 desta AP):
- **Resolver cross-file de simbolos NAO existe** - as arestas do world-model sao
  fixture em `AtlasAutonomousEngineeringService::buildWorldModel()`.
- **Tool MCP de traversal NAO existe** (so `atlas_code_find_relevant` substring).
- Nao ha bloco `code_graph` no config.

FUTURE-GATED por canon proprio (nao-escopo desta AP - ver secao 4):
- Heavy graph (Leiden, networkx, tree-sitter multi-ling, multimodal, Graph-RAG
  promotion) = `python_ai_data` runtime, marcado `future` na scaffold-matrix.
  `Runtime Promotion Policy` proibe auto-promocao: exige **review humano**.

## 3. Escopo P0 (esta AP - Kernel/PHP, sem runtime novo)

1. **Cross-file symbol resolver** (`CodeGraphSymbolResolver`): consome os simbolos +
   relacoes que o Code Intelligence ja extrai; resolve calls/imports/uses cross-file
   com **promocao de confianca por evidencia de import** + **matching single-candidate
   anti-god-node** + re-export transitivo. Saida: arestas tipadas com
   `confidence` (EXTRACTED/INFERRED/AMBIGUOUS) + `confidence_score` + `source_ref`.
2. **Persistencia aditiva**: grava arestas resolvidas em `ai_codebase_world_model_edges`
   por **caminho novo gated** (`code_graph.real_edges` flag), **sem remover o path
   fixture** (reversivel; operador troca apos review).
3. **Traversal MCP** read-only: `atlas_code_neighbors`, `atlas_code_path`,
   `atlas_code_explain` sobre `WorldModelGraphRanker`, **privacy/tombstone-filtered**,
   gravando evidencia de uso. Sem write, sem provider.
4. **Config** `code_graph` block (flags, limites, default-off).
5. **Evidence + gates**: receipts no Evidence Ledger; `docs-health`,
   `architecture-validate`, `index-code` e testes focados verdes.

## 4. Nao-Escopo (APs separadas + review humano obrigatorio)

- **Runtime `python_ai_data`** (Leiden/communities, god-nodes em escala, networkx,
  tree-sitter multi-linguagem, multimodal OCR/audio/video, Graph-RAG promotion) -
  exige `runtime_boundary_preflight_gate.v1` + `runtime_promotion_policy.v1`
  (review humano). Precede tudo isso uma AP `python_ai_data` dedicada.
- M-5 extracao multi-provider governada; M-7 self-construction de extrator;
  M-8 grafo cross-domain (15 dominios); M-9 grafo de realidade unificado.
- Nao escrever Memory Registry; nao injetar Context Builder; nao criar estrela de
  Constelacao; nao alterar Decide/Policy/provider; nao auto-promover.

## 5. Mapeamento do Roadmap (P0..P14 / M0..M10)

| Item | Capacidade | Destino |
|---|---|---|
| P0 | edge-table real + resolver + traversal + MCP | **esta AP (agora)** |
| P3/P4 | edge store + resolucao cross-file + confianca | **esta AP (agora)** |
| P5 | query traversal (neighbors/path/explain) | **esta AP (agora)** |
| P9 | blast-radius sobre arestas reais | esta AP (deriva do resolver) |
| P1/P2 | tree-sitter multi-ling + simbolos por AST | AP python_ai_data |
| P6/P7/P8 | Leiden + god-nodes + surprises/questions | AP python_ai_data |
| P10 | surface de PR (conflito-por-community) | AP apos P6 |
| P11 | multi-modal (PDF/imagem/video/PG-live/MCP-cfg/SCIP) | AP python_ai_data + KB |
| P12 | incremental por diff + hook opt-in | AP code-intel incremental |
| P13 | viz code-graph + Mermaid | estende cartografia existente |
| P14 | hardening SSRF ingest | estende guards de ingest |
| M-1 | arestas evidence-graded atadas ao gate | **esta AP (agora)** |
| M-2 | arestas provadas por runtime (ledger+usage) | esta AP + usage-intelligence |
| M-3 | reconciliacao doc-codigo no grafo | estende reconcile existente |
| M-4 | soberania/privacy nas queries | **esta AP (agora)** |
| M-5..M-10 | provider/self-construction/cross-domain/unificado | APs dedicadas + review |

## 6. Contratos

**Edge (resolvido):** `{from_node_id, to_node_id, edge_type, confidence,
confidence_score, source_ref, metadata:{resolver, inferred}}`. Reusa o schema da
tabela `ai_codebase_world_model_edges`; nao cria tabela nova.

**Runtime invoke/result** (para quando `python_ai_data` existir, AP futura):
`atlas.runtime.invoke.v1` / `atlas.runtime.result.v1` assinados pelo Kernel com
`decision_receipt_hash` (conforme runtime-language-boundaries). Esta AP **nao**
invoca runtime externo.

## 7. Gates / Definition of Done (P0)

- `CodeGraphSymbolResolver` com testes focados (resolucao, promocao por import,
  single-candidate, re-export) verdes.
- arestas reais persistidas por path gated; fixture path intacto; reversivel.
- 3 tools MCP read-only com teste de contrato + privacy filter; sem write.
- Evidence Ledger receipt em cada build/traversal.
- `docs-health`, `architecture-validate`, `index-code --summary-only` verdes.
- nenhuma aresta INFERRED promovida a fato sem review; nenhum write em Memory.

## 8. Riscos e Mitigacoes

- **Stack paralela** (risco #1): mitigado - estende tabela/ranker/runtime que ja
  existem; zero novo grafo runtime.
- **Over-claim**: mitigado - heavy graph fica `future`/`proposed`, nunca declarado
  pronto sem evidencia; status desta AP = `proposed` ate testes verdes.
- **Arvore suja (Hermes WIP)**: trabalho aditivo, sem commit; operador revisa diff.
- **Cerebro Python paralelo**: proibido por canon; respeitado - P0 e 100% Kernel/PHP.

## 9. Proximas Acoes

1. Implementar `CodeGraphSymbolResolver` + persistencia gated + testes (P0).
2. Tools MCP de traversal + config `code_graph` + testes de contrato.
3. Rodar gates; emitir evidence; atualizar `code-intelligence.md`.
4. Abrir AP `python_ai_data` dedicada para P1+/M-pesados (com preflight + review).
