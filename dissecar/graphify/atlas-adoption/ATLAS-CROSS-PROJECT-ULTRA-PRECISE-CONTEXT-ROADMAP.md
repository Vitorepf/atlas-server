# Atlas · Roadmap de Contexto Ultra-Preciso Cross-Project (elevar a fundação)

> **O que é:** documentação TEMPORÁRIA de staging (dissection-lab) — **NÃO é AP**. Mapa dos
> blocos para levar o contexto do Atlas a "absurdamente preciso, bizarro de preciso E eficiente",
> **não só no atlas-server mas em QUALQUER projeto** que o operador abrir. Cada bloco só vira
> implementação depois de `place-feature` + `session-bootstrap` + AP + gates.
> **Casa do cross-project = AWIS** (Atlas Workspace Intelligence System) — o primitivo que já
> trata "escolher uma pasta" como contrato governado.
> Base: AP-811/812 (code-graph) + AP-813 (compression) + AP-814 (M-8 cross-domain) + sondagem
> code-grounded 2026-06-08 do AWIS e do scoping do code-graph.
>
> **Princípio (capture-not-cede):** não criar segundo grafo nem segundo runtime. Tudo abaixo
> ESTENDE o code-graph + a compression + o AWIS que já existem. Repo docs continuam canon.

---

## Estado atual (code-verified — pra não reinventar)

**JÁ PRONTO (shipado + provado nesta linha de trabalho):**
- **Motor do code-graph é ~90% project-agnostic:** `CodeGraphEdgeResolver/SymbolBuilder/CallResolver`
  são puros data-driven; os runtimes python são content-driven; `EngineeringCodeIntelligenceService::index()`
  **já aceita `--workspace`** e escaneia qualquer path.
- **Grafo rico (AP-811/812):** arestas reais, símbolo→símbolo, call graph **type-resolved denso**,
  communities (Louvain), centralidade (betweenness), god-nodes, blast-radius, multi-lang (6), PDF, viz, SSRF.
- **Multiplicadores Atlas-únicos:** **M-2 runtime-proof**, **M-9 unified-reality**, **M-8 cross-domain** (3 fases live).
- **AP-813 compression layer** (CacheAligner + CCR + SmartCrusher) — live.
- **AWIS já é a casa cross-project:** registry multi-workspace (atlas + blackink), **gate que bloqueia
  index-code/Dev/Forge sem workspace certificado**, certification runtime, snapshots por-workspace, path resolver.
  O doc AWIS declara que ele governa `atlas_code_intelligence.workspace_scope`.
- **Economia medida:** 24,3× / 95,9% por-query (1 workspace).

**O GAP REAL = camada de ISOLAMENTO, não o motor (hoje é single-tenant, atlas-on-atlas):**
- Sem `workspace_id` nas tabelas de código (modules/symbols/doc_links) + world-model → 2º projeto **sobrescreve** o 1º.
- Scope do world-model **hardcoded** `'atlas-server'` (AtlasCodeGraphBuildCommand:133); build sem `--workspace`.
- MCP tools + `WorldModelGraphRanker` + `CodeGraphRuntimeInvoker` resolvem **"latest global" / base_path()** — não miram workspace.
- Memória/outcome sem `workspace_id`.

---

## OS BLOCOS

> Legenda: **[DONE]** já existe · **[GAP]** a construir · **[KEYSTONE]** destrava os outros.

### Família W — Fundação cross-project (AWIS-governed multi-workspace)
- **W-1 [KEYSTONE][GAP] Keying por workspace** — `workspace_id` em `atlas_engineering_code_modules/symbols/doc_links` + world-model; índice único `(workspace_id, slug)`. Sem isto, multi-projeto não existe.
- **W-2 [GAP] World-model scoped por workspace** — `scope = <workspace>` (matar o `'atlas-server'` fixo); `atlas:code-graph:build --workspace`.
- **W-3 [GAP] Readers workspace-aware** — MCP code tools + ranker + `CodeGraphRuntimeInvoker` resolvem o grafo DO workspace (não latest-global / base_path).
- **W-4 [GAP] Pipeline AWIS por-workspace** — certify → index → build-graph → ready como UM fluxo governado, reusando o gate + registry AWIS que já existem.
- **W-5 [GAP] Isolamento de memória/outcome** — `workspace_id` nas tabelas de outcome/memória operacional.
- **W-6 [DONE] Registry + gate AWIS** — multi-workspace + bloqueio mutativo (já wired).

### Família P — Precisão bizarra
- **P-1 [GAP] Cauda dinâmica fechada** — type-flow grau-PHPStan (return-type/property-type propagation) p/ as ~854 `$var->m()` que o static não resolve.
- **P-2 [DONE→estender] Runtime-proof por workspace (M-2)** — quando AQUELE projeto roda, prova as arestas dele; o que nenhum tool estático tem.
- **P-3 [GAP] Arestas de data-flow / taint** — "este valor flui de X p/ Y" (mais fundo que call graph).
- **P-4 [GAP] Arestas semânticas governadas** — embedding sob Atlas Decide p/ "conceitualmente relacionado" cross-file que o AST perde (local-first p/ sensível).
- **P-5 [GAP] Profundidade multi-linguagem** — tree-sitter além das 6 langs (rumo às ~28) + ponte LSP/SCIP (relações compiler-grade).
- **P-6 [DONE] Confiança calibrada + proveniência** — EXTRACTED/INFERRED/runtime-proven por aresta.

### Família E — Eficiência extrema (preciso E barato)
- **E-1 [KEYSTONE][GAP] Compressão-no-retrieval** — plugar AP-813 na SAÍDA da query do grafo: o grafo reduz *o que* ler, a compressão encolhe *o que sobra* → contexto ultra-preciso ~90% mais barato. O composto.
- **E-2 [GAP] Indexação incremental/live por workspace** — fila por diff + git-hook opt-in → contexto sempre fresco, update barato.
- **E-3 [GAP] Context pack query-shaped** — o grafo monta o contexto MÍNIMO e preciso p/ a tarefa (token-budget + ranked), em vez de despejar arquivos.
- **E-4 [DONE→estender] CacheAligner no prefixo** — prefixo KV-cache estável por workspace.
- **E-5 [DONE] Token economy medida** — 24,3×/95,9% (estender p/ medição por-workspace).

### Família X — O salto cross-workspace (o que graphify estruturalmente não faz)
- **X-1 [GAP] Traversal cross-workspace** — "em QUALQUER repo meu, quem chama X / o que quebra se eu mudar Y".
- **X-2 [GAP] Blast-radius cross-workspace** — mudar uma lib compartilhada → impacto em TODOS os projetos que a usam.
- **X-3 [GAP] Padrões/aprendizado cross-workspace (AWEF)** — padrões abstratos entre projetos, governados, sem vazar conteúdo bruto entre projetos.
- **X-4 [GAP] Cross-workspace ∪ cross-domain (M-8)** — unir o grafo multi-projeto com o grafo de realidade cross-domain: "deste PR no projeto A → suas decisões de domínio → evidência no projeto B".

### Família G — Governança/soberania (o fosso)
- **G-1 [GAP] Privacy class por workspace** — projeto sensitive/cyber nunca cruza p/ o contexto de outro workspace.
- **G-2 [DONE→reusar] Veto ARPTL/mesh no contexto cross-workspace** — reusa o veto do M-8.
- **G-3 [DONE] Drift-as-governance por workspace** — índice stale bloqueia o Dev/Forge daquele workspace (gate AWIS já faz).
- **G-4 [DONE] Evidence ledger** — auditoria (estender com workspace_id).

---

## A tese "absurdamente preciso"

```
  GRAFO (o que ler)  ×  PRECISÃO (type-flow + runtime-proof + data-flow + semântico)
                     ×  EFICIÊNCIA (compressão-no-retrieval + incremental + pack mínimo)
                     ×  ESCOPO (multi-workspace + cross-workspace)
                     ×  GOVERNANÇA (privacy/ARPTL/drift/evidence por workspace)
        = contexto que nenhum tool externo consegue igualar, em QUALQUER projeto
```

graphify entrega 1 grafo por repo, local, sem precisão de runtime, sem governança, sem cross-repo.
Atlas, com W+P+E+X+G, entrega: grafo governado **por projeto**, **type+runtime-preciso**, **comprimido**,
**consultável entre projetos**, **soberano**. Esse é o "50×" real — não um número, e sim o produto dos eixos.

---

## Sequenciamento

- **P0 (destrava o cross-project sozinho):** W-1 + W-2 + W-3 + W-4 (keying + scope + readers + pipeline AWIS). Abrir um 2º projeto (blackink) e ter o grafo dele isolado + consultável.
- **P1 (precisão bizarra + eficiência):** E-1 (compressão-no-retrieval) + E-3 (pack mínimo) + P-1 (cauda dinâmica) + E-2 (incremental).
- **P2 (o salto):** X-1 (traversal cross-workspace) + X-2 (blast-radius cross-repo) + X-4 (cross-workspace ∪ cross-domain) + G-1/G-2 (privacy/veto cross-workspace).
- **P3 (profundidade):** P-3 (data-flow) + P-4 (semântico governado) + P-5 (LSP/SCIP + langs) + X-3 (AWEF).

## Filtro dos 5 (passa nos 5)
(1) multiplicador composto: o grafo governado vira substrato de Dev/Forge **em todo projeto**. (2) antifrágil: cresce com cada projeto/PR/linguagem. (3) execução fim-a-fim: contexto bizarro-preciso = builder melhor em qualquer repo. (4) destrava substituir função: code-graph alimenta engenharia autônoma multi-projeto. (5) soberania: roda local, sensível por-workspace não vaza.

## O que NÃO fazer (anti-padrões)
- NÃO criar segundo grafo/runtime ao lado do code-graph + AWIS (estender).
- NÃO misturar projetos sem `workspace_id` (o bug atual single-tenant).
- NÃO auto-instalar git-hooks em repos (opt-in sempre).
- NÃO cruzar contexto entre workspaces sem privacy class + veto ARPTL.
- NÃO tratar INFERRED/semântico como fato; NÃO promover runtime python sem review.
- NÃO ligar `--persist` do M-8 até a resolução de modelo por-escopo (sombrearia o latest-global; ver AP-814 §11).

## Honesto: DONE vs GAP (resumo)
- **DONE:** motor agnóstico, grafo rico (símbolo/call/type-resolved), M-2/M-8/M-9, compression (AP-813), AWIS registry+gate+certification, economia medida.
- **GAP (os blocos a construir):** W-1..W-5, P-1/P-3/P-4/P-5, E-1/E-2/E-3, X-1..X-4, G-1.
- **Maior leverage isolado:** W-1 (keying) + E-1 (compressão-no-retrieval) — destravam cross-project E ultra-eficiência ao mesmo tempo.
