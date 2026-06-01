---
id: atlas-memory-architecture-diagram
type: engineering_knowledge
title: Atlas Memory Architecture Diagram
status: active
category: memory
priority: 95
summary: Visual canonico (AURC + Mermaid) da arquitetura de memoria, contexto, recall e Open Brain do Atlas. Cidade viva com 7 camadas canonicas.
human_summary: Mapa visual do sistema de memoria do Atlas seguindo a metafora de cidade viva do Cartographic Knowledge OS.
human_what: Diagrama canonico de 5 andares da cidade-memoria (mundo, setor, bairro, predio, engrenagem) com 7 camadas de inspecao (Essencial, Fluxo, Relacoes, Evolucao, Patamares, Versoes, Prova e Seguranca).
human_purpose: Permitir que humano e IA naveguem o sistema de memoria do Atlas sem ler centenas de paginas.
human_input: Recebeu docs canonicos (memory-core-runbook, open-brain-context-injection, retrieval-and-context, contracts, knowledge-governance) e inspecao do codigo real.
human_output: Diagrama AURC + Mermaid + glossario de nos.
human_change_when: Mexer quando camadas, contratos, superficies ou gates mudarem.
human_block_when: Bloquear quando o mapa mostrar memoria como fonte de verdade (sempre read model) ou esconder ausencia com layout bonito.
tags:
  - atlas
  - memory
  - aurc
  - cartography
  - mermaid
  - open-brain
  - context-pack
  - architecture
  - visual
related_paths:
  - docs/engineering-knowledge-base/memory/retrieval-and-context.md
  - docs/engineering-knowledge-base/memory/open-brain-mcp.md
  - docs/engineering-knowledge-base/memory/contracts.md
  - docs/engineering-knowledge-base/memory/cognitive-immune-learning-kernel.md
  - docs/engineering-knowledge-base/memory/foundation-map.md
  - docs/engineering-knowledge-base/open-brain-context-injection.md
  - docs/engineering-knowledge-base/atlas-ai-knowledge-governance-system.md
  - docs/engineering-knowledge-base/atlas-cartographic-knowledge-os.md
  - docs/engineering-knowledge-base/atlas-universal-reality-cartography.md
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-memory-architecture-diagram
graph_title: Atlas Memory Architecture
graph_world: atlas
graph_layer: system
graph_kind: cartography
graph_parent: atlas-universal-reality-cartography
graph_status: active
graph_source: repo
human_name: Mapa da Memoria Atlas
canonical_name: Atlas Memory Architecture Diagram
technical_name: atlas_memory_architecture_diagram
cartography_type: cartography
canonical_source: docs/engineering-knowledge-base/memory/diagrams/atlas-memory-architecture.md
owner: memory
visual_tags:
  - system
  - memory
  - cartography
  - aurc
  - mermaid
ai_entrypoints: Use este arquivo para entender arquitetura de memoria sem ler 4.000+ linhas. Cada node aponta para doc dono.
ai_usage_notes: Mapa visual; texto denso vive nos modais/docs donos. Cada node tem source_path canonico.
quality_gates:
  - "php artisan atlas:engineering:knowledge docs-health --json"
failure_modes:
  - Humano achar que o mapa E a verdade (ele E read model da verdade canonica).
  - Node sem source_path.
observability_signals:
  - docs-health status ok
  - Mermaid renderiza em GitHub/Obsidian/VSCode
next_actions:
  - Adicionar variantes por surface (CLI, app, mobile, MCP).
  - Adicionar cena por engrenagem (Composer, Open Brain Injection, etc).
---

# Atlas Memory Architecture — AURC + Mermaid

> Visual canonico do sistema de **memoria, contexto, recall, Open Brain, governance e privacy** do Atlas. Cada node aponta para o doc/servico/migration dono. O mapa **nao** e fonte de verdade — ele e a superficie visual da verdade canonica.

Metafora central (do `atlas-cartographic-knowledge-os.md`): **cidade viva** com 5 andares (Mundo → Setor → Bairro → Predio → Engrenagem) e 7 camadas de inspecao por node (Essencial, Fluxo, Relacoes, Evolucao, Patamares, Versoes, Prova e Seguranca).

---

## 1. Vista Panoramica (Mundo → Setor) — Mermaid

```mermaid
%%{init: {'theme': 'dark', 'themeVariables': {'primaryColor': '#0f1e2e', 'primaryTextColor': '#e6f3ff', 'primaryBorderColor': '#3a7ca5', 'lineColor': '#5fa8d3', 'secondaryColor': '#1a2f3f', 'tertiaryColor': '#0a1620'}}}%%
flowchart TB
    classDef canon fill:#1e3a5f,stroke:#5fa8d3,stroke-width:2px,color:#e6f3ff
    classDef runtime fill:#2d5016,stroke:#7ab348,stroke-width:2px,color:#e6ffe6
    classDef surface fill:#5c2e1e,stroke:#d97757,stroke-width:2px,color:#ffe6d9
    classDef external fill:#3d2e5c,stroke:#9d7ad9,stroke-width:2px,color:#f0e6ff
    classDef gate fill:#5c1e1e,stroke:#d94545,stroke-width:3px,color:#ffe6e6

    TITLE["<b>Atlas Memory City</b><br/>Cidade Viva da Memoria Canonica"]:::canon

    %% === MUNDO (camada de autoridade) ===
    WORLD["<b>MUNDO — Knowledge Governance</b><br/>atlas-ai-knowledge-governance-system.md<br/>Regra de autoridade: docs canônicos > codigo > evidence > KB > Obsidian > projections > chat"]:::canon

    %% === SETOR: BACKBONE CANONICO ===
    SECTOR_DOCS["<b>SETOR 1 — Canonical Docs</b><br/>docs/engineering-knowledge-base/<br/>~22 docs donos, versionados em git"]:::canon
    SECTOR_CODE["<b>SETOR 2 — Codigo + Testes + Migrations</b><br/>app/Services/Ai/*<br/>99.358 simbolos, 20.065 testes, 1.269 migrations"]:::runtime
    SECTOR_LEDGER["<b>SETOR 3 — Evidence Ledger</b><br/>runtime append-only<br/>prova de eventos, replay, projections"]:::runtime

    %% === SETOR: POSTGRES REGISTRIES (read/write) ===
    SECTOR_PG["<b>SETOR 4 — Postgres Operational Registries</b><br/>read/write — fonte de verdade de estado vivo"]:::runtime
    SECTOR_PG_BAIRRO["atlas_memory_entries · atlas_verbatim_memories<br/>atlas_memory_entry_usages · atlas_memory_entry_relations<br/>atlas_open_brain_access_logs · atlas_memory_quality_snapshots<br/>ai_memory_deltas · engineering_* + semantic_notes"]:::canon

    %% === SETOR: READ MODELS ===
    SECTOR_RM["<b>SETOR 5 — Read Models (aceleradores)</b><br/>Postgres KB + Code Intelligence"]:::canon
    SECTOR_RM_BAIRRO["Engineering Knowledge Base + Code Intelligence Index<br/>modules, symbols, routes, commands, tests, doc links"]:::canon

    %% === BAIRRO: COMPOSER (engrenagem central) ===
    SECTOR_COMP["<b>BAIRRO — Context Composer</b><br/>AiContextPackBuilder + AtlasMemoryContextComposer<br/>pega 3 fontes → ranked + budgeted + provider-safe"]:::runtime

    %% === PREDIO: OPEN BRAIN INJECTION (engrenagem externa) ===
    SECTOR_OB["<b>PREDIO — Open Brain Injection</b><br/>AtlasOpenBrainService + AtlasOpenBrainContextInjectionService<br/>Core runtime behavior, 5 status, 3 policy modes"]:::runtime

    %% === SUPERFICIES ===
    SURF_CLI["CLI<br/>atlas dev/chat/continue/forge/ask/decide"]:::surface
    SURF_APP["Atlas Desktop App<br/>Tauri 2 + React 19"]:::surface
    SURF_MOBILE["Atlas Mobile<br/>Expo 54 + RN 0.81"]:::surface
    SURF_MCP["Open Brain MCP<br/>stdio local + HTTP JSON-RPC"]:::external
    SURF_NATIVE["Atlas Mac Native<br/>Swift"]:::surface

    %% === PROJECTIONS (controladas) ===
    PROJ["<b>PROJECTIONS</b><br/>CLAUDE.md · AGENTS.md<br/>geradas, regeneráveis, NUNCA fonte de verdade"]:::external

    %% === EXTERNAL (fora da cidade) ===
    EXT_PROVIDER["Providers externos<br/>OpenAI · Anthropic · Google · MiniMax · local"]:::external
    EXT_OBSIDIAN["Obsidian / AtlasVault<br/>Human Knowledge Surface<br/>(NAO fonte operacional)"]:::external

    %% === GATES ===
    GATE_PRIV["<b>GATE — Privacy Guard</b><br/>AtlasMemoryPrivacyService<br/>+ AtlasMemorySourcePrivacyPolicy<br/>bloqueia private/sensitive/secret de provider"]:::gate
    GATE_IMMUNE["<b>GATE — Cognitive Immune Law</b><br/>Raw Capture ≠ Evidence ≠ Memory ≠ Context ≠ Decision<br/>captures começam memory_eligible=false"]:::gate

    %% === FLOWS ===
    TITLE --> WORLD
    WORLD --> SECTOR_DOCS
    WORLD --> SECTOR_CODE
    WORLD --> SECTOR_LEDGER
    WORLD --> SECTOR_PG
    WORLD --> SECTOR_RM

    SECTOR_PG --> SECTOR_PG_BAIRRO
    SECTOR_RM --> SECTOR_RM_BAIRRO

    SECTOR_PG_BAIRRO --> GATE_PRIV
    GATE_PRIV --> SECTOR_COMP
    SECTOR_RM_BAIRRO --> SECTOR_COMP
    SECTOR_DOCS --> SECTOR_COMP

    SECTOR_COMP --> SECTOR_OB

    SECTOR_OB --> SURF_CLI
    SECTOR_OB --> SURF_APP
    SECTOR_OB --> SURF_MOBILE
    SECTOR_OB --> SURF_MCP
    SECTOR_OB --> SURF_NATIVE

    SECTOR_OB -->|"exports provider-safe"| EXT_PROVIDER
    SECTOR_OB -->|"projection (regenerated)"| PROJ

    EXT_OBSIDIAN -.->|"import (review only)"| GATE_IMMUNE
    GATE_IMMUNE -.->|"promotion explicit only"| SECTOR_PG_BAIRRO

    class TITLE canon
```

---

## 2. Vista do Predio — Open Brain (5 andares detalhados)

```mermaid
%%{init: {'theme': 'dark', 'themeVariables': {'primaryColor': '#0f1e2e', 'lineColor': '#5fa8d3'}}}%%
flowchart LR
    classDef input fill:#5c2e1e,stroke:#d97757,color:#ffe6d9
    classDef gate fill:#5c1e1e,stroke:#d94545,color:#ffe6e6
    classDef service fill:#2d5016,stroke:#7ab348,color:#e6ffe6
    classDef data fill:#1e3a5f,stroke:#5fa8d3,color:#e6f3ff
    classDef output fill:#3d2e5c,stroke:#9d7ad9,color:#f0e6ff

    %% === ENTRADA (superficies) ===
    subgraph INPUTS["ANDAR 1 — SURFACES (declaram intent)"]
        CLI["atlas dev/chat/continue<br/>mode: dev/debug/review/plan"]:::input
        APP["Atlas App<br/>programming/debug/review"]:::input
        MOBILE["Mobile Surface Adapter"]:::input
        MCP_C["MCP Consumer<br/>(read-only)"]:::input
    end

    %% === GATEWAY ===
    subgraph GATEWAY["ANDAR 2 — GATEWAY (nao decide)"]
        GW["AiGatewayService<br/>surface → policy hints"]:::gate
        PB["AiPromptBuilder<br/>system + task + objective"]:::gate
    end

    %% === OPEN BRAIN INJECTION PROFILE ===
    subgraph INJECTION["ANDAR 3 — OPEN BRAIN INJECTION (Core)"]
        OBS["AtlasOpenBrainService<br/>contextPack()"]:::service
        OBIS["AtlasOpenBrainContextInjectionService<br/>1262 linhas — 5 status, 3 policy modes"]:::service
    end

    %% === CONTEXT COMPOSER (3 fontes) ===
    subgraph COMPOSER["ANDAR 4 — CONTEXT COMPOSER (engrenagem)"]
        CPB["AiContextPackBuilder<br/>584 linhas"]:::service
        ACP["AtlasMemoryContextComposer<br/>333 linhas — score deterministico"]:::service
        AHM["AtlasHybridMemoryRetrievalService<br/>316 linhas — 3 fontes + budget"]:::service
    end

    %% === 3 FONTES DE MEMORIA ===
    subgraph SOURCES["ANDAR 5a — 3 FONTES DE MEMORIA"]
        REG["AtlasMemoryRegistryService<br/>505 linhas — 11 tipos, 6 escopos"]:::data
        VERB["AtlasVerbatimMemoryService<br/>560 linhas — redacted_text only"]:::data
        SEM["SemanticSearchService<br/>+ AtlasMemorySourcePrivacyPolicy"]:::data
    end

    %% === READ MODELS (auxiliares) ===
    subgraph READ["ANDAR 5b — READ MODELS"]
        KB["Engineering KB<br/>docs indexed"]:::data
        CI["Code Intelligence<br/>99.358 symbols"]:::data
    end

    %% === POSTGRES ===
    subgraph PG["ANDAR 5c — POSTGRES"]
        ME[("atlas_memory_entries<br/>+ temporal truth + privacy")]:::data
        VM[("atlas_verbatim_memories<br/>+ privacy_class")]:::data
        SN[("semantic_notes")]:::data
        DL[("ai_memory_deltas<br/>+ promotions")]:::data
    end

    %% === OUTPUTS ===
    subgraph OUTPUTS["SAIDA — Provider-safe"]
        PKG["Context Pack<br/>+ audit log hash"]:::output
        INJ["Prompt Section<br/># Atlas Open Brain Context"]:::output
        AUD[("atlas_open_brain_access_logs<br/>hash-only, no raw")]:::output
    end

    %% === FLOWS ===
    CLI --> GW
    APP --> GW
    MOBILE --> GW
    MCP_C --> GW
    GW -->|"policy.mode: off/auto/required"| OBIS
    OBIS -->|"build request"| OBS
    OBS -->|"contextPack()"| CPB
    CPB --> AHM
    CPB --> ACP

    AHM --> REG
    AHM --> VERB
    AHM --> SEM
    AHM --> KB
    AHM --> CI

    REG --> ME
    VERB --> VM
    SEM --> SN
    REG -->|"promote delta"| DL

    CPB --> PKG
    PB --> INJ
    OBS --> AUD
    PKG --> INJ
```

---

## 3. Engrenagem do Composer (zoom no mecanismo de ranking)

```mermaid
%%{init: {'theme': 'dark', 'themeVariables': {'primaryColor': '#0f1e2e', 'lineColor': '#5fa8d3'}}}%%
flowchart TB
    classDef input fill:#1e3a5f,stroke:#5fa8d3,color:#e6f3ff
    classDef filter fill:#5c2e1e,stroke:#d97757,color:#ffe6d9
    classDef score fill:#5c4a1e,stroke:#d9a857,color:#fff4d9
    classDef output fill:#2d5016,stroke:#7ab348,color:#e6ffe6
    classDef gate fill:#5c1e1e,stroke:#d94545,color:#ffe6e6

    Q(["query + context + filters + options"]):::input

    Q --> R{"registry?<br/>+ privacy.providerAllowed"}
    Q --> V{"verbatim?<br/>+ external_ai_allowed=true"}
    Q --> S{"semantic?<br/>+ privacy_class allowed"}

    R -->|yes| REG_CANDS["registry candidates<br/>+ hybrid_score"]
    V -->|yes| VERB_CANDS["verbatim candidates<br/>(redacted_text only)"]
    S -->|yes| SEM_CANDS["semantic candidates<br/>(policy-filtered)"]

    REG_CANDS --> MERGE
    VERB_CANDS --> MERGE
    SEM_CANDS --> MERGE

    MERGE["MERGE + NORMALIZE<br/>source, source_ref_type/id<br/>type, scope, title, summary, body<br/>importance, confidence, hybrid_score"]:::filter

    MERGE --> SCORE["SCORE (deterministic)<br/>score = priority<br/>       + (importance x 10)<br/>       + (confidence x 10)<br/>       + (hybrid_score x 30)<br/>       + scopeWeight(scope)<br/>       + typeWeight(type)"]:::score

    SCORE --> SORT["SORT<br/>score DESC, source ASC, title ASC"]

    SORT --> BUDGET["BUDGET GATE<br/>limit: ATLAS_AI_MEMORY_RECALL_LIMIT<br/>budget: ATLAS_AI_MEMORY_RECALL_BUDGET_CHARS<br/>item: ATLAS_AI_MEMORY_RECALL_ITEM_CHARS"]:::gate

    BUDGET --> EXCERPT["EXCERPT<br/>min(budget, itemChars)<br/>truncate with ... if needed"]

    EXCERPT --> ATTACH["ATTACH METADATA<br/>lineage: source, ref id, content_hash<br/>freshness: recorded_at, last_used_at, age<br/>audit_trail: provider-safe, privacy, content_hash"]

    ATTACH --> OUT(["recall[] ranked items<br/>+ summary counts<br/>+ safety v1"]):::output

    style Q fill:#5c2e1e
    style OUT fill:#2d5016
```

---

## 4. Fluxo de Promocao (raw → memory canonica)

```mermaid
%%{init: {'theme': 'dark', 'themeVariables': {'primaryColor': '#0f1e2e', 'lineColor': '#5fa8d3'}}}%%
flowchart LR
    classDef raw fill:#5c1e1e,stroke:#d94545,color:#ffe6e6
    classDef gate fill:#5c4a1e,stroke:#d9a857,color:#fff4d9
    classDef quasi fill:#5c2e1e,stroke:#d97757,color:#ffe6d9
    classDef canon fill:#2d5016,stroke:#7ab348,color:#e6ffe6

    RAW["Raw Capture<br/>chat · Obsidian · voice · CLI prompt<br/>file · vault item"]:::raw
    CAPTURE["Capture Pipeline<br/>+ cognitive_quarantine metadata<br/>flags: memory_eligible=false<br/>embedding_allowed=false"]:::raw

    DELTA_PENDING["ai_memory_deltas<br/>status: pending"]:::quasi
    REVIEW["Review (human or governed)<br/>receipt: atlas.memory_delta.review_receipt.v1<br/>accept | reject"]:::gate

    DELTA_ACC["ai_memory_deltas<br/>status: accepted"]:::quasi
    PROMOTE["AtlasMemoryDeltaPromotionService<br/>+ types mapping<br/>+ idempotente (source_id)"]:::gate

    MEMORY["atlas_memory_entries<br/>canal: decision / preference /<br/>issue / technical_context /<br/>resolution / harness_learning /<br/>benchmark_observation /<br/>anti_memory / strategic_insight"]:::canon
    VERBATIM["atlas_verbatim_memories<br/>canal: decision / command /<br/>evidence / quote / requirement /<br/>review / failure"]:::canon

    USAGE["Usage tracking<br/>atlas_memory_entry_usages<br/>+ feedback by trace"]:::canon
    GOV["Governance<br/>AtlasMemoryGovernanceService<br/>+ AtlasMemoryConflictResolutionService<br/>(6 conflict verbs)"]:::canon

    RAW --> CAPTURE
    CAPTURE --> DELTA_PENDING
    DELTA_PENDING --> REVIEW
    REVIEW -->|accept| DELTA_ACC
    REVIEW -->|reject| X(["REJECTED — not eligible"]):::raw

    DELTA_ACC --> PROMOTE
    PROMOTE --> MEMORY
    PROMOTE --> VERBATIM

    MEMORY --> USAGE
    VERBATIM --> USAGE
    USAGE --> GOV
    GOV -->|"duplicate/conflict<br/>(visible verdicts)"| MEMORY
```

---

## 5. AURC — 7 Camadas de Inspecao (por node)

Cada node do mapa tem 7 camadas canonicas. Para inspecionar qualquer node do diagrama:

| # | Camada | Pergunta canonica | Onde olhar |
|---|---|---|---|
| 1 | **Essencial** | O que e? Quem e o owner? | doc dono `atlas-ai-*` + frontmatter `owner:` |
| 2 | **Fluxo** | De onde vem? Para onde vai? Quando executa? | `flowchart` acima + `runtime_rule` no doc |
| 3 | **Relacoes** | Com quem conversa? Quais contratos? | `related_paths:` no frontmatter + `depends_on`/`flows_to` |
| 4 | **Evolucao** | Quando mudou? Qual fase? | `git log` + secoes `### Fase N` no doc-mãe |
| 5 | **Patamares** | Em que nivel de maturidade esta? (L1..L8) | tabela "Modelo de Maturidade" no doc-mãe |
| 6 | **Versoes** | Qual schema version? Qual API version? | `schema_version` em JSON payloads + `routes/api.php` |
| 7 | **Prova e Seguranca** | Como sei que funciona? Quais gates? | `evidence_refs` + `quality_gates` + `required_tests` + `failure_modes` |

---

## 6. Glossario de Nodes (source_path canonico)

### Predio: BACKBONE

| Node | source_path | owner |
|---|---|---|
| Knowledge Governance | `docs/engineering-knowledge-base/atlas-ai-knowledge-governance-system.md` | `knowledge-governance` |
| Session Bootstrap | `docs/engineering-knowledge-base/atlas-ai-session-bootstrap.md` | `onboarding` |
| Documentation OS | `docs/engineering-knowledge-base/atlas-ai-documentation-operating-system.md` | `documentation` |

### Predio: REGISTRIES (Postgres)

| Node | Tabela / Servico | source_path |
|---|---|---|
| Memory Registry | `atlas_memory_entries` | `database/migrations/2026_05_02_000000_create_atlas_memory_entries_table.php` |
| Verbatim Store | `atlas_verbatim_memories` | `database/migrations/2026_05_02_004000_create_atlas_verbatim_memories_table.php` |
| Memory Usages | `atlas_memory_entry_usages` | `database/migrations/2026_05_02_001000_*` |
| Memory Relations | `atlas_memory_entry_relations` + Temporal Truth (2026_05_25) | `database/migrations/2026_05_02_003000_*` + `2026_05_25_030500_add_conflict_verbs_*` |
| Open Brain Audit | `atlas_open_brain_access_logs` | `database/migrations/2026_05_03_130000_*` |
| Quality Snapshots | `atlas_memory_quality_snapshots` | `database/migrations/2026_05_03_190000_*` |
| Memory Deltas | `ai_memory_deltas` (existente) | (pre-existente) |
| Temporal Truth fields | (additive) | `database/migrations/2026_05_18_080100_add_temporal_truth_to_atlas_memory_entries.php` |
| Privacy columns | (additive) | `database/migrations/2026_05_02_005000_add_privacy_columns_*` |
| Supersede by id | (additive) | `database/migrations/2026_05_04_010000_add_superseded_by_*` |
| Provider Projection Audits | `atlas_memory_provider_projection_audits` | `database/migrations/2026_05_02_008000_*` |

### Predio: SERVICES (PHP)

| Node | Servico | source_path | LOC |
|---|---|---|---|
| Memory Registry Svc | `AtlasMemoryRegistryService` | `app/Services/Ai/AtlasMemoryRegistryService.php` | 505 |
| Verbatim Svc | `AtlasVerbatimMemoryService` | `app/Services/Ai/AtlasVerbatimMemoryService.php` | 560 |
| Privacy Svc | `AtlasMemoryPrivacyService` | `app/Services/Ai/AtlasMemoryPrivacyService.php` | - |
| Source Privacy Policy | `AtlasMemorySourcePrivacyPolicy` | `app/Services/Ai/AtlasMemorySourcePrivacyPolicy.php` | - |
| Context Composer | `AtlasMemoryContextComposer` | `app/Services/Ai/AtlasMemoryContextComposer.php` | 333 |
| Hybrid Retrieval | `AtlasHybridMemoryRetrievalService` | `app/Services/Ai/AtlasHybridMemoryRetrievalService.php` | 316 |
| Context Pack Builder | `AiContextPackBuilder` | `app/Services/Ai/AiContextPackBuilder.php` | 584 |
| Open Brain Svc | `AtlasOpenBrainService` | `app/Services/Ai/AtlasOpenBrainService.php` | 181 |
| Open Brain Injection | `AtlasOpenBrainContextInjectionService` | `app/Services/Ai/AtlasOpenBrainContextInjectionService.php` | 1262 |
| Open Brain MCP | `AtlasOpenBrainMcpService` | `app/Services/Ai/AtlasOpenBrainMcpService.php` | - |
| Governance | `AtlasMemoryGovernanceService` | `app/Services/Ai/AtlasMemoryGovernanceService.php` | 520 |
| Usage | `AtlasMemoryUsageService` | `app/Services/Ai/AtlasMemoryUsageService.php` | 285 |
| Quality | `AtlasMemoryQualityService` | `app/Services/Ai/AtlasMemoryQualityService.php` | 1020 |
| Review Queue | `AtlasMemoryReviewQueueService` | `app/Services/Ai/AtlasMemoryReviewQueueService.php` | - |
| Delta Promotion | `AtlasMemoryDeltaPromotionService` | `app/Services/Ai/AtlasMemoryDeltaPromotionService.php` | - |
| Provider Projection | `AtlasProviderProjectionService` | `app/Services/Ai/AtlasProviderProjectionService.php` | - |
| Maintenance | `AtlasMemoryMaintenanceService` | `app/Services/Ai/AtlasMemoryMaintenanceService.php` | - |
| Conflict Resolution | `AtlasMemoryConflictResolutionService` | `app/Services/Ai/Memory/AtlasMemoryConflictResolutionService.php` | 480 |

### Predio: SURFACES

| Node | Stack | source_path |
|---|---|---|
| Atlas CLI | bash + PHP | `bin/atlas` |
| Atlas Desktop | Tauri 2 + React 19 + Rust | `atlas-desktop/` |
| Atlas App (mobile) | Expo 54 + RN 0.81 | `atlas-app/` |
| Atlas Mac Native | Swift | `atlas-mac-native/` |
| Open Brain MCP | stdio local + HTTP JSON-RPC | `app/Http/Controllers/AtlasOpenBrainMcpController.php` |

### Predio: CONTRACT DOCS

| Node | source_path |
|---|---|
| Memory Core Runbook | `docs/engineering-knowledge-base/memory-core-runbook.md` |
| Memory Contracts (focused) | `docs/engineering-knowledge-base/memory/contracts.md` |
| Retrieval & Context | `docs/engineering-knowledge-base/memory/retrieval-and-context.md` |
| Open Brain MCP & API | `docs/engineering-knowledge-base/memory/open-brain-mcp.md` |
| Open Brain Context Injection | `docs/engineering-knowledge-base/open-brain-context-injection.md` |
| Cognitive Immune Learning Kernel | `docs/engineering-knowledge-base/memory/cognitive-immune-learning-kernel.md` |
| Foundation Map | `docs/engineering-knowledge-base/memory/foundation-map.md` |
| Doc-mae (full) | `docs/engineering-knowledge-base/archive/source-material/atlas-ai-memory-context-core-open-brain-full-2026-05-08.md` (4023 linhas) |

---

## 7. Vista 2: 5 Andares da Cidade (de cima)

```mermaid
%%{init: {'theme': 'dark', 'themeVariables': {'primaryColor': '#0f1e2e', 'lineColor': '#5fa8d3'}}}%%
flowchart TB
    classDef world fill:#1e3a5f,stroke:#5fa8d3,stroke-width:3px,color:#e6f3ff
    classDef sector fill:#2d5016,stroke:#7ab348,stroke-width:2px,color:#e6ffe6
    classDef bairro fill:#5c4a1e,stroke:#d9a857,stroke-width:2px,color:#fff4d9
    classDef predio fill:#5c2e1e,stroke:#d97757,stroke-width:2px,color:#ffe6d9
    classDef gear fill:#3d2e5c,stroke:#9d7ad9,stroke-width:2px,color:#f0e6ff

    MUNDO["<b>MUNDO</b><br/>Knowledge Governance<br/>axioma: memoria pertence ao Atlas"]:::world

    MUNDO --> S1["<b>SETOR 1 — Canonical Docs</b>"]:::sector
    MUNDO --> S2["<b>SETOR 2 — Codigo + Testes + Migrations</b>"]:::sector
    MUNDO --> S3["<b>SETOR 3 — Postgres Registries</b>"]:::sector
    MUNDO --> S4["<b>SETOR 4 — Read Models (KB + Code Intelligence)</b>"]:::sector
    MUNDO --> S5["<b>SETOR 5 — Evidence Ledger</b>"]:::sector
    MUNDO --> S6["<b>SETOR 6 — Surfaces (CLI/App/Mobile/MCP)</b>"]:::sector
    MUNDO --> S7["<b>SETOR 7 — Provider Projections (CLAUDE.md/AGENTS.md)</b>"]:::sector

    S3 --> B1["<b>BAIRRO 1 — Memory Registry</b><br/>+ Temporal Truth + Privacy"]:::bairro
    S3 --> B2["<b>BAIRRO 2 — Verbatim Store</b><br/>+ Privacy Class 4 niveis"]:::bairro
    S3 --> B3["<b>BAIRRO 3 — Memory Deltas</b><br/>+ Promotion Pipeline"]:::bairro
    S3 --> B4["<b>BAIRRO 4 — Memory Relations</b><br/>+ 6 conflict verbs"]:::bairro
    S3 --> B5["<b>BAIRRO 5 — Memory Usages</b><br/>+ Feedback"]:::bairro
    S3 --> B6["<b>BAIRRO 6 — Open Brain Audit</b><br/>+ Quality Snapshots"]:::bairro

    B1 --> P1["<b>PREDIO 1 — AtlasMemoryRegistryService</b><br/>11 tipos, 6 escopos"]:::predio
    B1 --> P2["<b>PREDIO 2 — AtlasVerbatimMemoryService</b><br/>redacted_text only"]:::predio

    B3 --> P3["<b>PREDIO 3 — AtlasMemoryDeltaPromotionService</b><br/>idempotente por source_id"]:::predio
    B4 --> P4["<b>PREDIO 4 — AtlasMemoryConflictResolutionService</b><br/>+ AtlasMemoryGovernanceService"]:::predio

    S4 --> P5["<b>PREDIO 5 — EngineeringKnowledgeBaseService</b><br/>+ EngineeringCodeIntelligenceService"]:::predio

    S1 --> P6["<b>PREDIO 6 — AiContextPackBuilder</b><br/>+ AiPromptBuilder + AiGatewayService"]:::predio

    P1 --> G1["<b>ENGRENAGEM 1</b><br/>relevantForContext()<br/>+ privacy filter"]:::gear
    P2 --> G2["<b>ENGRENAGEM 2</b><br/>relevantForContext()<br/>+ budget"]:::gear
    P6 --> G3["<b>ENGRENAGEM 3</b><br/>build() 3 fontes → ranked"]:::gear
    G1 --> G3
    G2 --> G3

    G3 --> G4["<b>ENGRENAGEM 4</b><br/>AtlasMemoryContextComposer<br/>score = priority + importance*10 + confidence*10 + hybrid*30 + scope + type"]:::gear
    G3 --> G5["<b>ENGRENAGEM 5</b><br/>AtlasHybridMemoryRetrievalService<br/>3 fontes + audit + usage"]:::gear
    G4 --> G6["<b>ENGRENAGEM 6</b><br/>AtlasOpenBrainService.contextPack()<br/>+ safety v1 + audit hash-only"]:::gear
    G5 --> G6
    G6 --> G7["<b>ENGRENAGEM 7</b><br/>AtlasOpenBrainContextInjectionService<br/>5 status x 3 policy modes<br/>(injected/skipped/degraded/failed_open/failed_closed)"]:::gear
    G7 --> G8["<b>ENGRENAGEM 8</b><br/>Prompt Section: # Atlas Open Brain Context"]:::gear
```

---

## 8. Gates Criticos (visualizados)

```mermaid
%%{init: {'theme': 'dark', 'themeVariables': {'primaryColor': '#5c1e1e', 'lineColor': '#d94545'}}}%%
flowchart LR
    classDef gate fill:#5c1e1e,stroke:#d94545,stroke-width:3px,color:#ffe6e6
    classDef fail fill:#3d2e5c,stroke:#9d7ad9,color:#f0e6ff

    G1["<b>Privacy Guard</b><br/>AtlasMemoryPrivacyService<br/>+ AtlasMemorySourcePrivacyPolicy<br/>bloqueia private/sensitive/secret<br/>de provider"]:::gate
    G2["<b>Cognitive Immune Law</b><br/>Raw Capture ≠ Evidence ≠ Memory<br/>captures: memory_eligible=false<br/>+ immune_audit v1"]:::gate
    G3["<b>Open Brain Failed-Closed</b><br/>mode=required + context missing<br/>= STOP antes de provider"]:::gate
    G4["<b>Provider Projection Drift</b><br/>manual edit em CLAUDE.md/AGENTS.md<br/>= bloqueio de overwrite sem --force"]:::gate
    G5["<b>AI Boundary Contract</b><br/>surface/provider/tool NUNCA<br/>decide, executa, promote,<br/>change policy"]:::gate
    G6["<b>Audit Hash-Only</b><br/>query_json = hashes only<br/>nunca raw objective/workspace"]:::gate
    G7["<b>Verbatim Redaction Gate</b><br/>external_ai_allowed=true<br/>+ redacted_text (nao verbatim_text)<br/>+ PromptInjectionScanner"]:::gate
    G8["<b>Docs Authority</b><br/>docs canônicos > KB > Obsidian<br/>> projections > chat<br/>(Knowledge Governance rule)"]:::gate

    F1(["Meltdown: provider vaza segredo"]):::fail
    F2(["Meltdown: raw capture vira context"]):::fail
    F3(["Meltdown: required mode envia sem context"]):::fail
    F4(["Meltdown: projection sobrescreve humano"]):::fail
    F5(["Meltdown: IA escolhe provider/policy"]):::fail
    F6(["Meltdown: audit log tem raw text"]):::fail
    F7(["Meltdown: verbatim bruto vaza em prompt"]):::fail
    F8(["Meltdown: chat vence doc canonico"]):::fail

    G1 --> F1
    G2 --> F2
    G3 --> F3
    G4 --> F4
    G5 --> F5
    G6 --> F6
    G7 --> F7
    G8 --> F8
```

---

## 9. Como Renderizar / Usar Este Mapa

### Visualização
- **GitHub / Obsidian / VSCode** — Mermaid renderiza nativo em preview Markdown
- **Atlas Desktop / Mobile** — eventual superficie visual AURC consume este doc como `graph_id`
- **Cartography runtime** — `app/Services/Engineering/AtlasUniversalRealityCartographyService.php` pode indexar este arquivo via `php artisan atlas:engineering:knowledge index-code --prune`

### Próximas cenas a derivar deste mapa
1. Cena por **engrenagem** (Composer, Open Brain Injection, Conflict Resolution, etc) com 7 camadas
2. Cena por **surface** (CLI, App, Mobile, MCP) mostrando injeção
3. Cena por **caso de uso** (recall, promote, review, project, conflict)
4. Cena por **patamar** (P1..P5 do `atlas-ai-qualitative-levels-roadmap.md`)

### Manutenção
- Quando `atlas-ai-knowledge-governance-system.md` mudar autoridade → atualizar camada 1 (Essencial) de todos os nodes
- Quando tabela nova for criada → adicionar em §6 Glossario + atualizar §1 Vista Panoramica
- Quando novo servico for entregue → adicionar em §6 Glossario + criar nova engrenagem em §2 Vista do Predio
- Quando gate novo for adicionado → adicionar em §8 Gates Criticos

### Validação
```bash
# Confirmar que este doc nao quebrou health
php artisan atlas:engineering:knowledge docs-health --json
# Re-indexar para surfaces (se aplicavel)
./bin/atlas engineering knowledge sync --prune
```

---

## 10. Leitura Minima para uma Sessão Nova

1. Este arquivo (mapa visual)
2. `memory-core-runbook.md` (operacional)
3. `atlas-ai-knowledge-governance-system.md` (autoridade)
4. `atlas-ai-session-bootstrap.md` (bootstrap)
5. Doc dono do assunto especifico (apontado em cada node)
