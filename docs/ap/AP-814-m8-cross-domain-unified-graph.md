---
title: AP-814 M-8 Cross-Domain Unified Graph — one governed entity graph over the domains
status: implemented (Fase-1+2+3 live 2026-06-08; world-model persist gated pending scope-filtered resolution)
owner: cross_domain_governance / ai-runtime
line_limit: 260
related_paths:
  - app/Services/Ai/CrossDomain/AtlasCrossDomainMeshService.php
  - app/Services/Ai/Context/AtlasRetrievalPrivacyTrustLayerService.php
  - app/Services/Engineering/CodeGraph/CodeGraphUnifiedView.php
  - app/Services/Engineering/CodeGraph/DomainGraphAdapter.php
  - app/Services/Engineering/CodeGraph/CodeGraphRealityIngestionService.php
  - app/Models/AiCodebaseWorldModelNode.php
  - app/Models/AiCodebaseWorldModelEdge.php
  - app/Services/Ai/AutonomousEngineering/WorldModel/WorldModelGraphRanker.php
  - runtimes/python/code_graph/atlas_code_graph/centrality.py
  - docs/engineering-knowledge-base/atlas-cross-domain-mesh-arptl.md
  - dissecar/graphify/atlas-adoption/ATLAS-CODE-GRAPH-PARITY-AND-BEYOND-ROADMAP.md
---

# [AP-814] M-8 Cross-Domain Unified Graph

## 1. Proposito

Fechar **M-8** do roadmap graphify-e-além: **um grafo governado de ENTIDADES sobre os
domínios do Atlas** (código é uma fatia), respondendo a query que o graphify "não
consegue nem formular" (PARTE C): *deste PR → por estes god-nodes → até as decisões /
evidências / missões cross-domain que o governam*. Capture-not-cede: **estende** o que
já existe (mesh/ARPTL + M-9 + DomainGraphAdapter), **não cria** um grafo paralelo nem
recria a governança.

## 2. Status Real (code-verified 2026-06-08, 4 agentes)

**EXISTE + WIRED (estender/compor, NÃO recriar):**
- **Governança cross-domain**: `AtlasCrossDomainMeshService` (`bridge()/topology()/
  listRequests()/listVetoes()`, matriz `CROSS_RULES`, veto ARPTL absoluto p/
  sensitive/secret/cyber → audience domains, audit JSONL append-only) +
  `AtlasRetrievalPrivacyTrustLayerService` (ARPTL) + `AtlasTemporaryDomainCompositionService`.
  Doc canon `atlas-cross-domain-mesh-arptl.md`. **A privacidade cross-domain já está resolvida.**
- **Unificação código∪realidade (M-9)**: `CodeGraphUnifiedView` (merge por layer-tag, sem
  dedup cross-layer) + `CodeGraphRealityIngestionService` (docs/memória/evidence, capped, read-only).
- **Seed de domínio (M-8)**: `DomainGraphAdapter` — transforma relações `{from,to,type?}`
  de QUALQUER domínio em arestas canônicas namespaced `<domain>:<id>` (INFERRED 0.75);
  provado domain-agnostic (`CodeGraphAnalytics::godNodes` roda igual em arestas de finance).
- **Schema de nó/aresta extensível**: `ai_codebase_world_model_nodes/edges` — `node_type`
  string aberta, `node_id` namespaced, capabilities/risks/metadata JSON, path nullable.
- **Ranker type-agnostic**: `WorldModelGraphRanker` (~95% agnóstico; só boosts test/doc são
  code-specific; arestas de domínio caem no peso default 0.12 — seguro).
- **Algoritmos domain-agnostic**: `runtimes/python/code_graph/` (betweenness/communities/
  insights) operam sobre `{from_node_id,to_node_id}` genérico; o invoker `CodeGraphRuntimeInvoker`
  só hardcoda `domain_id='programming'`/`flow_id` no CONTEXTO de invocação (parametrizável).
- **Dados cross-domain reais**: tabela `ai_domain_handoffs` (source_domain_id→target_domain_id),
  `ai_domain_runtime_records` (domain_id), `ai_evidence_packs.domain_id` — arestas/nós reais já tagueados.

**GAP (o que falta — a cola):**
- **Ingestão de entidades por domínio** num grafo (hoje `DomainGraphAdapter` só TRANSFORMA
  relações fornecidas; falta o serviço que LÊ os dados reais dos domínios → nós/arestas).
- **Builder unificado cross-domain** que multiplexa todos os domínios + código∪realidade num
  único world-model, persistido (gated).
- **Traversal cross-domain / killer query** gated pelo mesh/ARPTL.
- **Parametrizar o invoker python** (domain_id/flow_id) p/ rodar algoritmos sobre o grafo cross-domain.

**FINDING (decisão do operador — §8):** existem **DUAS taxonomias de 15 domínios divergentes**:
mesh/ARPTL = `engineering/marketing/finance/trading/cyber/legal/ops/sales/design/research/
health/personal/learning/governance/infra`; profile-registry/orchestrators =
`general/research/learning/qa/security/operations/programming/finance/personal_development/
marketing/strategic_decision/writing/self_improvement/background/health`. **O grafo unificado
precisa de UMA taxonomia canônica + mapa entre elas** — não dá pra resolver sozinho.

## 3. Escopo (faseado)

**Fase-1 (esta AP, PHP atrás de flag, read-only, sem promoção — independente da taxonomia):**
- `CrossDomainGraphBuilder` (read-only): ingere `ai_domain_handoffs` (arestas cross-domain reais)
  + nós de domínio (registry + `ai_domain_runtime_records`) → world-model `cross-domain` namespaced
  `domain:<id>`, privacy-tagged por sensibilidade de domínio (config), capped, fail-open.
- Reusa `DomainGraphAdapter` (shape de aresta) + `CodeGraphAnalytics` (god-nodes/blast-radius) +
  as tabelas world-model. Command `atlas:cross-domain:graph-build` (gated).
- Flag `atlas.cross_domain_graph.enabled` (default OFF). Testes + run real.

**Fase-2 (gated):** wiring do veto mesh/ARPTL na ingestão (cada aresta cross-domain checada via
`AtlasCrossDomainMeshService` p/ a privacy class; vetadas marcadas, não silenciosamente dropadas)
— DEPENDE da decisão de taxonomia (§8). Traversal cross-domain (killer query) na MCP.

**Fase-3 (gated + promoção):** ingestão completa por-domínio (entidades reais de cada um dos 15),
unificação com M-9 código∪realidade, parametrização do invoker python p/ centrality/communities
cross-domain em escala (Leiden = dep-approval).

## 4. Não-Escopo (recusado por governança)
- Recriar mesh/ARPTL/matriz de privacidade (já existe — REUSAR).
- Criar segundo grafo paralelo ao world-model / Code Intelligence.
- Cruzar dado sensitive/secret/cyber sem o veto ARPTL.
- Escolher a taxonomia canônica unilateralmente (§8 = decisão do operador).
- Auto-promoção do runtime python cross-domain (runtime_promotion_policy.v1).

## 5. Contratos
- Nós namespaced `<domain>:<id>` (sem colisão cross-domain). Arestas de domínio = INFERRED
  (asserção, não extração de source).
- Ingestão **read-only**, capped, fail-open (mirror `CodeGraphRealityIngestionService`).
- Privacidade: cada nó/aresta carrega `privacy_class` + `domain`; egress/cross gated pelo mesh/ARPTL
  (Fase-2). Sensitive/secret/cyber nunca cruzam p/ audience domains.
- Persistência aditiva em `ai_codebase_world_model_*` (world model `scope='cross-domain'`), gated;
  nada removido.

## 6. Definition of Done / Gates
- Fase-1 aditiva; flag OFF = inerte (nada ingerido/gated). Reversível.
- Testes: builder (ingestão de fixture → nós/arestas namespaced + privacy-tag), analytics sobre
  arestas cross-domain, inércia flag-off. Run real no DB (handoffs/domínios reais).
- Sem novo gate bloqueante; é read-model que ALIMENTA, não decide.

## 7. Riscos e Mitigações
- **Duas taxonomias** → §8 decisão + mapa; Fase-1 não depende dela (usa os domain_id como vêm).
- Vazamento sensível cross-domain → veto mesh/ARPTL (Fase-2); Fase-1 só ingere + tagueia, não cruza p/ provider.
- Escala (15 domínios × N nós) → caps + timeout configurável do invoker (60–120s) na Fase-3.
- Ranker boosts code-only → arestas de domínio usam peso default (seguro); custom weights = futuro.

## 8. Operator Decision (governança)
1. **[DECIDIDO 2026-06-08 — "superset único"]** Taxonomia canônica = a UNIÃO reconciliada
   das duas listas = **21 domínios canônicos** em `CrossDomainTaxonomyMap` (9 sinônimos
   fundidos: engineering=programming, cyber=security, ops=operations, personal=
   personal_development, + finance/marketing/research/learning/health; 6 só-mesh; 6 só-registry).
   Cada canônico carrega ambos os aliases → resolve mesh-id E registry-id p/ um id só.
2. Aprovar wiring do veto mesh/ARPTL na traversal cross-domain (Fase-2).
3. Aprovar promoção do runtime python cross-domain + dep Leiden (Fase-3). Promoção sem review = proibida.

## 10. Implementation evidence (2026-06-08) — Fase-1 + reconciliação, LIVE

- `CrossDomainTaxonomyMap` (canonical 21 superset + alias resolution dos dois lados) — 6 testes.
- `CrossDomainGraphIngestionService` (read-only, fail-open): nós = os 21 canônicos privacy-tagged;
  2 fontes de aresta reais resolvidas pelo mapa — `ai_domain_handoffs` (`hands_off_to`) + a
  topologia do **mesh existente** (`allows_crossing`, REUSO de `AtlasCrossDomainMeshService`,
  não recriado) — 4 testes.
- `atlas:cross-domain:graph-build` (gated, flag `atlas.cross_domain_graph.enabled` default OFF,
  mesh resolvido defensivamente).
- **Run real (flag on inline):** **21 domínios, 198 arestas cross-domain** (todas allowed-crossing
  do mesh real), 6 sensíveis; **god-nodes reais** pela `CodeGraphAnalytics` domain-agnostic
  inalterada: governance(28)/legal(28)/design/engineering/finance/health(27). O merge que a
  divergência de taxonomia bloqueava agora funciona.
- 10 testes cross-domain verdes + 193 no dir CodeGraph (820 assert), 0 regressão. handoff_edge_count=0
  (nenhum handoff registrado ainda — honesto); as arestas vêm das regras reais do mesh.
- Fase-2 (veto ARPTL na traversal + killer query) e Fase-3 (per-domain entities + python cross-domain
  + Leiden) seguem gated.

## 11. Fase-2 + Fase-3 evidence (2026-06-08) — SHIPPED + LIVE

**Fase-2 (traversal + ARPTL veto + killer query + MCP) — via build inline:**
- `CrossDomainGraphTraversalService` — BFS bounded; cada hop CROSS-domain gated por
  `AtlasCrossDomainMeshService::evaluate()` (REUSO, pure/idempotente); floor conservador
  p/ os 6 domínios canônicos que o mesh não governa (nega sensitive/secret/cyber); vetos
  RECORDED, não dropados. `killerQuery()` resume reach + vetos.
- `atlas_cross_domain_query` MCP tool (privacy-gated, read-only); inventário 46→47.
- **Live (real DB):** finance `normal` → reachable **15** / vetoed 12; `sensitive` → **10** / 49;
  `secret` → **8** / 86; cyber `normal` → **1** / 2. A reach ENCOLHE e os vetos CRESCEM com a
  classe de privacidade — a query que o graphify "não consegue formular". 6 testes.
- BUG achado+corrigido no live: o container passava `null` no `?AtlasCrossDomainMeshService`
  (grafo sem arestas do mesh) → bindings explícitos no AppServiceProvider injetando o mesh.

**Fase-3 (entidades + python cross-domain + persist) — via workflow `wl1cxjad5` (3 serviços, verify clean):**
- `CrossDomainEntityIngestionService` (read-only): entidades reais por domínio
  (ai_domain_runtime_records/evidence_packs/claims) → nós `domain:<canonical>:<type>:<id>` +
  `belongs_to`, resolvidos pelo mapa, privacy-tagged, capped, fail-open. Merge defensivo no
  `gather()` (inerte até a classe existir). **Live: 24 entidades reais → 45 nós / 222 arestas.**
- Python cross-domain: `atlas:cross-domain:analytics --op=betweenness` reusa
  `CodeGraphRuntimeInvoker` + os algoritmos domain-agnostic via o venv governado. **Live:
  betweenness succeeded (exit 0, ranked 20) sobre 222 arestas cross-domain.** EXTENSÃO do
  runtime, não novo (Leiden segue dep-approval).
- `CrossDomainWorldModelPersister` + `atlas:cross-domain:graph-build --persist`: persiste no
  world-model existente (REUSO). **Live: 45 nós / 222 arestas persistidos.**

**Provas:** 212 testes verdes (1030 assert) — CodeGraph dir + analytics command + MCP inventário 47.

**CONSTRAINT honesto (anti-over-claim):** `persist` fica **gated OFF por default**. Um world model
`cross-domain` persistido vira o "latest" e **sombrearia** a resolução global-latest do code-graph
(AP-811) — o artefato de prova foi REMOVIDO após o live. Tornar persist seguro p/ produção exige
resolução de modelo por-escopo nos readers do code-graph (follow-up Fase-3.5). A capability está
provada; a ativação é gated por essa razão real, não por incompletude.

## 9. Próximas Ações
1. Implementar Fase-1 (builder + command + testes + run real), flag OFF.
2. Operador decide a taxonomia (§8.1) → destrava Fase-2 (mesh-veto + traversal/killer-query).
3. Fase-3 sob promotion review.
