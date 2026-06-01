---
id: atlas-cognition-operating-system
type: engineering_knowledge
title: Atlas Cognition Operating System
status: active
implementation_state: structural_scorecard_ready_10
blocker: Nenhum blocker estrutural no scorecard ACOS v3. Real-world data volume continua sendo produzido pelo uso do operador e nao faz parte da nota estrutural.
category: macro-system
priority: 99
summary: Atlas Cognition Operating System (ACOS) e o SO cognitivo do Atlas — sistema operacional que governa toda area de memoria, contexto, retrieval, RAG, graph, ranking, freshness, privacy, embedding, ingestion, compounding e learning. Encompasses Memory Core + Cognitive Immune (G0-G8) + AUCRI (18 blocks) + APCR + AEMOR + TEOS-I1 long-horizon + Open Brain MCP + Evidence Ledger memory side + Self-Improvement L7 + Compounding Engineering Intelligence + AVCEL + ACQCG. Forge, AWIS, Mission Foundation, Specialist Flows, Domains, Vox, Cartografia e Self-Construction OS sao consumers de ACOS, nao parte dele.
tags: [atlas-ai, acos, cognition, memory, context, retrieval, rag, graph, ranking, freshness, compounding, self-improvement, macro-system, authority]
capabilities:
  - cognition_macro_authority
  - memory_governance_g0_g8
  - context_lifecycle_apcr_aemor
  - retrieval_aucri_18_blocks
  - long_horizon_teos_i1
  - open_brain_mcp_export
  - evidence_ledger_memory_side
  - compounding_engineering_intelligence
  - self_improvement_closed_loop_level7
  - cognitive_quality_certification
decisions:
  - ACOS e nome canonico da camada cognitiva do Atlas. Substitui nomenclatura difusa anterior (memoria/contexto/RAG/etc) por umbrella unificada com 62 subsistemas estruturais no scorecard v3 atual.
  - Boundary clara entre ACOS (cognicao) e consumidores (Forge, AWIS, Mission, Specialist Flows, Domains, Vox, Cartografia, Self-Construction OS). Quem consome ACOS nunca esta dentro de ACOS.
  - ACOS 10/10 estrutural e definido por `atlas:cognition:scorecard --strict --json`: code, doc e pipeline ready para 62 subsistemas. Volume real de outcomes e dimensao operacional de uso, nao blocker estrutural.
  - Pipeline canonico ACOS: Captura -> Quarentena (G0) -> Promotion Gates (G1-G8) -> Memory Registry (10 types x 9 scopes x 4 privacy) -> Embedding (ASEF) -> Retrieval (AHRI + AARF + AGRN + AURG) -> Ranking (ACRS) -> Freshness Gate (ACFQ) -> Privacy/Trust (ARPTL) -> Cost Governor (ARCLG) -> Compilation (ACCR + ACCCR + ATER + ACPFR) -> Working Memory (ACMF) -> Persistence (APCR) -> Injection (Open Brain) -> Outcome (AEMOR) -> Learning Signal -> Memory Candidate -> Promotion -> Compounding -> Self-Improvement L7.
  - Forge, AWIS, Mission Foundation, Specialist Flows, Domains, Vox, Cartografia, Self-Construction OS estao FORA de ACOS — sao consumers que chamam ACOS via APIs canonicas (Open Brain MCP, AiContextPackBuilder, AtlasMemoryRegistryService, AtlasEvidenceLedger).
  - Nenhuma absorcao externa (claude-mem/engram/mem0) entra no Atlas sem AP por absorcao e sem passar pelos G0-G8 gates. As 4 absorcoes aprovadas em atlas-external-memory-pattern-absorptions-v1.md sao melhorias dentro de ACOS, nao novos subsistemas.
maintenance:
  - Manter `AtlasCognitionScoreCardService::SUBSYSTEMS` sincronizado com docs, services e testes.
  - Rodar `php artisan atlas:cognition:scorecard --strict --json` depois de alterar qualquer bloco ACOS.
  - Separar score estrutural ACOS de volume organico produzido pelo operador.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-memory-context-core-open-brain.md
  - docs/engineering-knowledge-base/memory/cognitive-immune-learning-kernel.md
  - docs/engineering-knowledge-base/memory/contracts.md
  - docs/engineering-knowledge-base/memory/foundation-map.md
  - docs/engineering-knowledge-base/memory/open-brain-mcp.md
  - docs/engineering-knowledge-base/memory/retrieval-and-context.md
  - docs/engineering-knowledge-base/memory-core-contracts.md
  - docs/engineering-knowledge-base/memory-core-failure-modes.md
  - docs/engineering-knowledge-base/memory-core-maturity-dod.md
  - docs/engineering-knowledge-base/memory-core-runbook.md
  - docs/engineering-knowledge-base/memory-core-security-privacy.md
  - docs/engineering-knowledge-base/atlas-unified-context-retrieval-intelligence.md
  - docs/engineering-knowledge-base/atlas-context-intelligence-engine.md
  - docs/engineering-knowledge-base/atlas-persistent-context-runtime.md
  - docs/engineering-knowledge-base/atlas-execution-memory-outcome-runtime.md
  - docs/engineering-knowledge-base/atlas-cognitive-memory-fabric.md
  - docs/engineering-knowledge-base/atlas-agentic-rag-framework.md
  - docs/engineering-knowledge-base/atlas-hybrid-retrieval-infrastructure.md
  - docs/engineering-knowledge-base/atlas-semantic-embedding-foundation.md
  - docs/engineering-knowledge-base/atlas-semantic-graph.md
  - docs/engineering-knowledge-base/atlas-graph-retrieval-network.md
  - docs/engineering-knowledge-base/atlas-context-ranking-system.md
  - docs/engineering-knowledge-base/atlas-context-freshness-quality-gate.md
  - docs/engineering-knowledge-base/atlas-retrieval-feedback-loop.md
  - docs/engineering-knowledge-base/atlas-retrieval-cost-latency-governor.md
  - docs/engineering-knowledge-base/atlas-retrieval-evaluation-benchmark-arena.md
  - docs/engineering-knowledge-base/atlas-retrieval-privacy-trust-layer.md
  - docs/engineering-knowledge-base/atlas-context-observability-plane.md
  - docs/engineering-knowledge-base/atlas-context-cache-compiler-runtime.md
  - docs/engineering-knowledge-base/atlas-context-compiler-runtime.md
  - docs/engineering-knowledge-base/atlas-context-pareto-frontier-runtime.md
  - docs/engineering-knowledge-base/atlas-context-quality-certification-gate.md
  - docs/engineering-knowledge-base/atlas-knowledge-ingestion-fabric.md
  - docs/engineering-knowledge-base/atlas-local-agent-memory-ingestion.md
  - docs/engineering-knowledge-base/atlas-long-horizon-intelligence-layer.md
  - docs/engineering-knowledge-base/atlas-long-horizon-replay-manifest.md
  - docs/engineering-knowledge-base/atlas-verified-context-execution-loop.md
  - docs/engineering-knowledge-base/atlas-compounding-engineering-intelligence.md
  - docs/engineering-knowledge-base/atlas-self-improvement-closed-loop-level7-v1.md
  - docs/engineering-knowledge-base/atlas-external-memory-pattern-absorptions-v1.md
  - docs/engineering-knowledge-base/canonical-index/authority-map.md
doc_schema: atlas_canonical_module_doc.v1
macro_layer: true
product_name: Atlas Cognition Operating System
runtime_acronym: ACOS
internal_product_name: Atlas Cognition Operating System
technical_runtime: AtlasCognitionOperatingSystemRegistryService
graph_id: atlas-cognition-operating-system
graph_title: Atlas Cognition Operating System
graph_world: atlas
graph_layer: system
graph_kind: system
graph_parent: atlas-ai-master-architecture
graph_status: active
graph_source: repo
owner: atlas-ai
repo_paths:
  - docs/engineering-knowledge-base/atlas-cognition-operating-system.md
allowed_changes:
  - Atualizar status de cada subsistema ACOS quando scorecard, service, doc ou pipeline mudar.
  - Adicionar novo subsistema ACOS somente apos AP que prove pertencimento a camada cognitiva e ortogonalidade aos subsistemas existentes.
  - Atualizar definicao 10/10 conforme blocos amadurecem e novos sinais de qualidade emergem.
  - Registrar dependencias canon de cada subsistema sobre outros (ex: AEMOR depende de Evidence Ledger).
forbidden_changes:
  - Mover Forge OS, AWIS, Mission Foundation, Specialist Flows, Domains, Vox, Cartografia ou Self-Construction OS para dentro de ACOS. Eles sao consumers, nao parte.
  - Tratar score estrutural 10/10 como prova de volume organico real de uso.
  - Adicionar absorcao externa a ACOS sem AP individual + passagem por G0-G8 + cognitive_immune compliance.
  - Permitir bloco ACOS shippar sem `must_keep_coverage = 1.0` quando aplicavel.
  - Permitir bloco ACOS shippar sem privacy gate ARPTL aplicado quando aplicavel.
  - Tratar nota de ACOS como nota global do Atlas. Atlas tem outras areas (Forge, AWIS, Domains, etc) com suas proprias notas.
depends_on: [atlas-ai-master-architecture, atlas-ai-kernel-architecture, atlas-ai-knowledge-governance-system]
flows_to: [atlas-forge-operating-system, atlas-workspace-intelligence-system, atlas-kernel-mission-foundation, atlas-ai-specialist-flow-runtime, atlas-self-construction-os, atlas-cartographic-knowledge-os, atlas-ai-voice-realtime-surface]
unlocks: [cognition_macro_authority, governed_memory_lifecycle_end_to_end, antifragile_provider_wrapper, sovereign_cognition_local_first]
governs: [memory_governance, context_lifecycle, retrieval_quality, embedding_indexing, graph_retrieval, ranking_freshness, privacy_trust, cost_latency, observability, compounding_learning, self_improvement_loop, cognitive_certification]
evidence:
  - docs/engineering-knowledge-base/atlas-cognition-operating-system.md
  - docs/engineering-knowledge-base/canonical-index/authority-map.md
  - app/Services/Ai/AtlasMemoryRegistryService.php
  - app/Services/Ai/AtlasOpenBrainService.php
  - app/Services/Ai/AiContextPackBuilder.php
  - app/Services/Ai/AtlasHybridMemoryRetrievalService.php
  - app/Services/Ai/AtlasMemoryContextComposer.php
  - app/Services/Ai/Context/AtlasHybridRetrievalInfrastructureService.php
  - app/Services/Ai/Context/AtlasAgenticRagFrameworkService.php
  - app/Services/Semantic/EmbeddingService.php
evidence_refs:
  - symbol: AtlasMemoryRegistryService
  - command: atlas:memory:add
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
  - "php artisan atlas:cognition:scorecard --strict --json"
  - "php artisan test --filter=CognitionScorecard"
requires_evidence: true
risk_level: high
line_limit: 520
next_actions:
  - Manter scorecard v3 como fonte operacional de status dos subsistemas ACOS.
  - Atualizar este doc quando `AtlasCognitionScoreCardService::SUBSYSTEMS` mudar.
  - Continuar acumulando volume real de outcomes sem confundir uso organico com prontidao estrutural.
claim_policy:
  benchmark_claim_allowed: false
  rivals_claim_allowed: false
  superiority_claim_allowed: false
  external_rivals_certification_touched: false
  raw_capture_passive_allowed: false
  llm_compression_observer_allowed: false
  auto_apply_learning_allowed: false
  cognitive_immune_law_enforced: true
  must_keep_coverage_invariant: true
  provider_safe_only_enforced: true
---

# Atlas Cognition Operating System

## Resumo

Atlas Cognition Operating System (ACOS) e o sistema operacional cognitivo do
Atlas. Encompasses toda area de memoria, contexto, retrieval, RAG, graph,
ranking, freshness, privacy, embedding, ingestion, compounding e learning
sob uma autoridade unica. ACOS organiza 62 subsistemas estruturais no scorecard
v3 atual, com boundary clara e 3 dimensoes: code, doc e pipeline.

ACOS substitui nomenclatura difusa anterior (memoria/contexto/RAG/etc) por
umbrella unificada. Forge OS, AWIS, Mission Foundation, Specialist Flows,
Domains, Vox, Cartografia e Self-Construction OS sao consumers de ACOS,
nunca parte dele.

## Papel no Atlas

ACOS e a camada de **cognicao** do Atlas. Atlas como um todo e o substrato
de soberania pessoal sobre AI generativa, executando em nivel empresarial
em multiplas areas (engenharia de software, marketing, cyber, financas,
trading, qualquer funcao humana). Para fazer isso, Atlas precisa:

1. **Lembrar** o que foi decidido, aprendido, falhado, resolvido.
2. **Compor contexto** correto, pequeno, provider-safe, com lineage.
3. **Recuperar** evidencia relevante com qualidade auditavel.
4. **Aprender** com outcomes reais sem auto-promocao silenciosa.
5. **Compoundar** ganhos atraves de ciclos longitudinais.
6. **Bloquear** captura tossica via Cognitive Immune (G0-G8).
7. **Auditar** cada promocao com receipt determinismo.

Todas essas funcoes vivem em ACOS. Forge executa Obras consumindo ACOS.
Specialist Flows respondem prompts consumindo ACOS. Mission Foundation
orquestra missoes consumindo ACOS. Domains operam consumindo ACOS.

## Onde Se Encaixa

```text
Atlas (substrato de soberania pessoal sobre AI generativa)
|
+-- Kernel (Decision Receipt v2 + Event Sourcing + Capability Registry + SLO)
|
+-- ACOS - Atlas Cognition Operating System  [este doc]
|     |
|     +-- Cognitive Immune Learning Kernel (G0-G8 + immune_audit)
|     +-- Memory Core (10 types x 9 scopes x 4 privacy)
|     +-- ASEF - Semantic Embedding Foundation
|     +-- AHRI - Hybrid Retrieval Infrastructure
|     +-- AARF - Agentic RAG Framework
|     +-- ACRS - Context Ranking System
|     +-- ACFQ - Context Freshness Quality Gate
|     +-- ARFL - Retrieval Feedback Loop
|     +-- AGRN - Graph Retrieval Network
|     +-- AURG - Unified Reality Graph
|     +-- APDR - Python Data Retrieval Runtime
|     +-- AREBA - Retrieval Evaluation Benchmark Arena
|     +-- ARCLG - Retrieval Cost Latency Governor
|     +-- ACOP - Context Observability Plane
|     +-- ARPTL - Retrieval Privacy Trust Layer
|     +-- AKIF - Knowledge Ingestion Fabric
|     +-- ACMF - Cognitive Memory Fabric
|     +-- ACCR - Context Compiler Runtime
|     +-- ACCCR - Context Cache Compiler Runtime
|     +-- ATER - Token Economy Runtime
|     +-- ACPFR - Context Pareto Frontier Runtime
|     +-- ACIE - Context Intelligence Engine
|     +-- APCR - Persistent Context Runtime
|     +-- AEMOR - Execution Memory Outcome Runtime
|     +-- TEOS-I1 - Long-Horizon Intelligence Layer
|     +-- AVCEL - Verified Context Execution Loop
|     +-- ACQCG - Context Quality Certification Gate
|     +-- Open Brain MCP - external interface
|     +-- Evidence Ledger (memory side)
|     +-- Compounding Engineering Intelligence
|     +-- Self-Improvement Closed Loop Level 7
|
+-- AWIS - Workspace Intelligence System (consumes ACOS)
+-- Forge OS - Obras grandes (consumes ACOS)
+-- Mission Foundation + Mode + Follow-Through (consumes ACOS)
+-- Specialist Flows - atlas_dev, atlas_forge, atlas_research, ... (consume ACOS)
+-- 15 Domains - programming, finance, cyber, marketing, ... (consume ACOS)
+-- Atlas Vox - voz (consumes ACOS for recall)
+-- Cartografia (visualizes ACOS but is separate)
+-- Self-Construction OS (can rebuild ACOS but is meta-system)
+-- Surfaces - Mobile + Desktop Mac
```

## Contratos

### Subsistemas canonicos ACOS + status atual

O status operacional nao e mantido manualmente nesta tabela. A fonte de verdade
runtime e `php artisan atlas:cognition:scorecard --strict --json`, schema
`atlas.cognition.scorecard.v3`. Em 2026-05-26 o scorecard provou:

| Dimensao | Resultado |
|---|---:|
| subsistemas | 62 |
| code | 10/10 |
| doc | 10/10 |
| pipeline | 10/10 |
| overall | 10/10 |

Grupos canônicos: Cognitive Immune G0-G8, Memory Core, AUCRI/contexto, Atlas
Decide, compounding, self-construction, cartography, governance, programming e
research-domain. Todos devem permanecer `ready` nas tres dimensoes ou o strict
scorecard falha.

### Definicao operacional de 10/10

**Score estrutural 10/10**:
- Todos os subsistemas listados no scorecard com `code_status=ready`, `doc_status=ready` e `pipeline_status=ready`.
- Service PHP implementado, testavel, com testes unitarios e de integracao verdes.
- Schema canonico declarado, validado, persistido em Postgres com migration.
- Seguranca aplicada (ARPTL classes, `external_ai_allowed` enforced, hash determinismo).
- Receipt persistido em `atlas_ledger_events` quando aplicavel.
- Comando artisan canonico expondo capability (`atlas:cognition:*`).
- Documentacao canonica atualizada (12 secoes obrigatorias do schema).
- Hash determinismo (sha256) em todos artefatos persistidos.
- `must_keep_coverage = 1.0` quando aplicavel.
- Round-trip tests (encode -> decode -> assert equal) onde houver remap.
- Zero TODO comments em codigo de producao (TODOs viram AP).
- Zero `private` fields raw em prompts; ARPTL gate enforce.

**Volume real de uso**:

Nao faz parte do score estrutural. E uma dimensao operacional orgânica gerada
pelo uso do operador, relatórios, ledgers e traces longitudinais. Nao use falta
de volume organico para rebaixar o score estrutural, e nao use score estrutural
para afirmar que ha volume real suficiente.
- AEMOR com pelo menos 1000 episodios reais persistidos.
- Self-Improvement L7 ResultLedger com pelo menos 50 entries reais.
- Trust outcomes com volume real (`self_improvement_*` outcomes ledged).
- AKIF ingerindo de pelo menos 3 fontes reais (docs, PDFs, repos).
- ACMF gerenciando RAM hot context real com working set ativo.
- ATER aplicando budgets reais em chamadas de provider.
- AGRN servindo traversal real do Codebase World Model.
- AURG snapshot de Reality Graph com entidades reais.
- AREBA golden-set com 1000+ casos rodando como regression gate.
- ACOP com 30+ dias de traces persistidos para baseline longitudinal.
- ACQCG score sintetico cruzado com outcomes reais (correlacao verificada).

### Schema Boundary

ACOS nao concentra todos os schemas numa lista propria. Cada subsistema
mantem seus schemas no doc dono e no codigo/migration correspondente. Este
doc registra apenas a autoridade-mae, o fluxo macro e a fila de readiness.
Schemas novos exigem AP do subsistema, doc dono atualizado, testes e evidence
receipt antes de serem tratados como runtime atual.

## Fluxo

ACOS opera em 3 ciclos sobrepostos:

**Ciclo 1 - Captura & Promocao (long-running)**:
```text
input
-> AKIF detect
-> Cognitive Immune G0 quarantine
-> G1-G8 promotion gates (cada um pode bloquear)
-> Memory Registry (se aprovado)
-> Evidence Ledger receipt
```

**Ciclo 2 - Query-Time (per request)**:
```text
prompt
-> APCR bootstrap
-> AiContextPackBuilder build (recall + verbatim + semantic)
-> AHRI + AARF + ACRS + ACFQ + AGRN + ARPTL + ARCLG + ACMF + ACCCR + ACCR + ATER + ACPFR
-> ACQCG quality gate
-> AVCEL verified execution shadow
-> Open Brain MCP injection
-> provider call
```

**Ciclo 3 - Post-Execution & Learning (per outcome)**:
```text
provider response
-> AEMOR episode (open -> observe -> outcome -> distill -> candidates)
-> Judgment & Learning Guard
-> Promotion gates again
-> Memory updates + ARFL feedback
-> Compounding Engineering Intelligence
-> Self-Improvement L7 (proposal -> backlog -> activation -> obra -> evidence
   -> review -> delta -> trust -> learning -> next_cycle_recommendation)
-> Trust outcomes ledged
-> APCR cache update for next session
-> TEOS-I1 continuation pack + compaction receipt + replay manifest
-> ACOP observability trace persisted
```

## Regras para IA

- ACOS e autoridade-mae da camada cognitiva. Antes de propor mudanca em
  memoria/contexto/RAG/retrieval, consultar este doc + doc dono do
  subsistema especifico.
- Nenhum subsistema ACOS pode shippar sem passar pelos G0-G8 quando
  aplicavel + ARPTL privacy + Evidence Ledger receipt + must_keep_coverage
  invariant.
- Forge OS, AWIS, Mission Foundation, Specialist Flows, Domains, Vox,
  Cartografia, Self-Construction OS sao consumers. Nao mover capabilities
  deles para dentro de ACOS sem AP que prove pertencimento cognitivo
  ortogonal.
- Score estrutural por bloco ACOS deve refletir code + doc + pipeline reais,
  provados pelo scorecard v3 e testes. Volume organico de uso e reportado
  separadamente.
- Toda chamada de provider externo passa por ARPTL classify + redaction +
  trust receipt antes de sair da maquina.
- Toda compactacao de contexto preserva must_keep_coverage = 1.0. Bug
  critico se quebrar.
- Toda promocao de memoria passa por Judgment & Learning Guard
  (anti-false-learning).
- Nenhuma absorcao externa entra em ACOS sem AP + cognitive_immune
  compliance + boundary review.
- Nao confundir ACOS (cognicao) com Atlas (substrato completo). Atlas tem
  outras areas (Forge, AWIS, Domains) com suas proprias notas e roadmaps.
- Nao ressuscitar score historico parcial como verdade atual. O gate atual e
  `atlas:cognition:scorecard --strict --json`.

## Escopo de Implementacao

ACOS encompasses os subsistemas listados em Contratos. Cada subsistema tem
doc dono que declara repo_paths, allowed_changes, forbidden_changes,
required_tests e gates de qualidade especificos.

Fila estrutural atual: vazia no scorecard v3. Qualquer novo bloco ou regressao
de bloco existente deve alterar `AtlasCognitionScoreCardService::SUBSYSTEMS`,
doc dono, testes e este doc na mesma mudanca. Filas de volume organico
(outcomes, traces, ResultLedger, AEMOR episodes) sao operacionais e nao
rebaixam o score estrutural.

Absorcoes aprovadas (em `atlas-external-memory-pattern-absorptions-v1.md`)
contribuem para subir scores:
- **Absorcao 1 (Integer ID Mapping)** -> reduz alucinacao em prompts ACOS, melhora `prompt_hash` consistency.
- **Absorcao 2 (Conflict Verbs)** -> Memory Relations ganha enum tipado, melhora ACRS e MemoryConflictResolver.
- **Absorcao 3 (Doctor 3-Tier)** -> blindagem operacional em comandos mutativos ACOS (AEMOR promotion, L7 ResultLedger, Vault sync, Memory delta promotion).
- **Absorcao 4 (Progressive Disclosure MCP)** -> Open Brain MCP em 3 tiers, alimenta ACPFR + ARCLG.

## Dependencias

ACOS depende de:
- **Kernel** (`atlas-ai-kernel-architecture.md`) — Decision Receipt v2, Event Sourcing, Capability Registry, SLO Targets.
- **Master Architecture** (`atlas-ai-master-architecture.md`) — equacao base: `Provider capability * Atlas memory/context/tools/gates/evidence/learning = Atlas output`.
- **Knowledge Governance** (`atlas-ai-knowledge-governance-system.md`) — hierarquia de autoridade, repo docs como fonte autoral.
- **Cognitive Immune Law** (`memory/cognitive-immune-learning-kernel.md`) — Raw != Evidence != Learning != Memory != Context != Decision.
- **Postgres + pgvector** — storage canonico para `atlas_memory_entries`, `ai_memory_deltas`, `semantic_notes`, `atlas_ledger_events`, etc.
- **EmbeddingService** (`app/Services/Semantic/EmbeddingService.php`) — OpenAI ou local hash fallback.

Consumers de ACOS:
- **Forge OS** — Obras consomem ACOS context.
- **AWIS** — Workspace certifica antes de ACOS operar, mas consome ACOS para contexto operacional.
- **Mission Foundation + Mode + Follow-Through** — Mission consomem ACOS via APCR injection.
- **Specialist Flows** (atlas_dev, atlas_forge, atlas_research, atlas_debug, atlas_review, atlas_explain, atlas_conversation) — todos consomem ACOS.
- **15 Domains** — programming, finance, marketing, cyber, etc consomem ACOS com memory_type proprio.
- **Atlas Vox** — voz consome ACOS para memory recall.
- **Cartografia** — visualiza ACOS mas e separada (visualization-only).
- **Self-Construction OS** — pode reconstruir ACOS mas e meta-system.

## Evidencias

Evidencia minima para considerar ACOS estruturalmente ready:
- `php artisan atlas:cognition:scorecard --strict --json` retorna exit 0;
- schema `atlas.cognition.scorecard.v3`;
- 62 subsistemas no scorecard v3 atual;
- code/doc/pipeline = 10/10;
- claim policy bloqueia benchmark/rivals/superiority;
- `external_rivals_certification_touched=false`;
- `cognitive_immune_law_enforced=true`;
- `must_keep_coverage_invariant=true`;
- `php artisan test --filter=CognitionScorecard` verde.

Evidencia adicional para volume organico:
- volume real de inserts em tabelas canonicas correspondentes;
- correlation entre score sintetico e outcomes reais (quando aplicavel);
- pelo menos 30 dias de baseline longitudinal (ACOP);
- pelo menos 50 promotion receipts reais (L7 ResultLedger);
- pelo menos 1000 episodes reais (AEMOR);
- pelo menos 100 trust outcomes ledged (Self-Improvement);
- pelo menos 1 cycle completo de Compounding com delta medido.

## Riscos

- **Risco 1**: implementar bloco sem cognitive_immune compliance -> captura tossica vira memoria -> Atlas degrade silenciosamente. Mitigacao: G0-G8 enforced em todo path de captura.
- **Risco 2**: provider externo recebe raw private content sem ARPTL gate. Mitigacao: ARPTL classify obrigatorio antes de qualquer chamada de provider.
- **Risco 3**: compactacao quebra must_keep_coverage = 1.0 -> evidence loss -> Decision Receipt invalido. Mitigacao: invariant test em CI.
- **Risco 4**: auto-promocao silenciosa de learning sem evidence -> Atlas aprende coisa errada. Mitigacao: Judgment & Learning Guard + review humano + trust outcomes ledged.
- **Risco 5**: score estrutural ser confundido com volume organico. Mitigacao: scorecard v3 declara que readiness estrutural nao mede volume real produzido pelo operador.
- **Risco 6**: doc inflar mais rapido que codigo. Mitigacao: scorecard exige code/doc/pipeline e testes; docs sem runtime devem ficar em proposta explicita, fora do score estrutural.
- **Risco 7**: novas otimizacoes de token/contexto criarem runtime paralelo. Mitigacao: ATER/ACMF/ACCR/AUCRI sao owners; variantes precisam de boundary review.
- **Risco 8**: graph store externo (Neo4j/Memgraph) absorvido sem AP -> conflito arquitetural com pgvector decision. Mitigacao: AGRN/AURG via pgvector + entity store bipartite antes de externo.
- **Risco 9**: dados reais nao fluem por nao haver volume de uso -> AEMOR/L7 ficam sinteticos -> compounding nao acontece. Mitigacao: Atlas precisa rodar producao no Mac do operador com cobertura crescente de domains.
- **Risco 10**: external_rivals_certification e mencionado em codigo ACOS -> contaminacao com area de outra equipe. Mitigacao: claim_policy enforced (`external_rivals_certification_touched: false`).

## Exemplos

### Score ACOS hoje (2026-05-26)

`php artisan atlas:cognition:scorecard --strict --json` retorna
`overall_out_of_10=10`, `subsystem_count=62`, `code=10`, `doc=10`,
`pipeline=10`, `external_rivals_certification_touched=false`,
`cognitive_immune_law_enforced=true` e `must_keep_coverage_invariant=true`.
Isso e score estrutural ACOS, nao nota global do Atlas inteiro.

## Proximas Acoes

1. Manter `AtlasCognitionScoreCardService::SUBSYSTEMS` como reflexo fiel de code/doc/pipeline.
2. Rodar scorecard strict e testes CognitionScorecard apos qualquer mudanca ACOS.
3. Continuar alimentando volume organico de outcomes sem confundir isso com score estrutural.
4. Preservar boundary: Forge, AWIS, Domains, Cartografia e Self-Construction continuam consumers.
