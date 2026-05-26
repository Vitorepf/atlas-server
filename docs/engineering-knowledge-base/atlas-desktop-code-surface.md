---
id: atlas-desktop-code-surface
type: engineering_knowledge
title: Atlas Desktop · Code Surface Specification
status: building
category: surface
priority: 98
implementation_state: surface_spec_building_not_runtime_complete
blocker: desktop_code_surface_requires_live_runtime_certification
summary: Canonical specification for the Atlas Desktop Code surface — the operational window into the Atlas Kernel for programming work. Materializes 11 canonical axes (Obras+Forge, Self-Construction OS, Multi-Agent Orchestration, Programming Domain+Harness, Spec OS, Kernel Pipeline, Memory+Continuity, Skill System+Cognitive Plane, Voice+Mobile+Multimodal, Documentation OS+Knowledge Governance, Tool Runtime+CLI). Replaces Cursor / Codex / Claude Code as Vitor's primary programming surface — the human directs, Atlas programs, evidence is signed.
tags:
  - atlas-ai
  - desktop
  - surface
  - code
  - programming
  - vibe-coding
  - kernel-window
capabilities:
  - atlas_desktop_code_surface
  - engineering_operations_system
  - governed_coding_surface
  - multi_agent_orchestration_ui
  - spec_driven_coding_ui
  - decision_receipt_ui
  - evidence_ledger_ui
  - continuity_session_ui
decisions:
  - Atlas Code materializes the Engineering Operations System category: the MES of software construction.
  - A primeira versao enterprise do Atlas Code se chama Atlas Code SCOR-1, Software Construction Operating Room v1.
  - Atlas Code e surface desktop de programacao, nao o Programming Domain nem o fluxo pesado inteiro.
  - Atlas Code SCOR-1 tem um unico modo operacional de surface: Forge.
  - Toda intencao do Atlas Code deve carregar `surface_id=atlas_code`, `flow_id=programming.forge`, `routing_task=forge`, `programming_profile=forge`, `obra_id` e `forge_workspace`.
  - Atlas Code nao inicia Forge sem Obra vinculada; conversa sem `obra_id` e rascunho, nao execucao Forge.
  - Atlas Code dispara execucao Forge real somente por Obra via `/atlas-code/works/{id}/forge/live-executions`, persistindo snapshot em `forge_live_execution`.
  - The Code surface is a window into the Kernel, not an editor. Vitor directs; Atlas programs.
  - All 11 canonical axes must be honored; no axis-invariant is skipped for UI simplicity.
  - Atlas Decide owns provider routing. The surface displays the decision; it never lets the user pick provider by dropdown without `manual_override` audit.
  - Every execution requires a signed Decision Receipt v2. Without receipt, no execution.
  - Multi-agent parallel work requires Collision Matrix + Scope Validator + Reservation Ledger; no parallel without governance.
  - Spec precedes plan precedes task precedes code. The surface enforces this order, not just displays it.
  - Documentation-first is enforced: no implementation begins without a canonical owner doc.
  - Voice is mobile-first; desktop voice mode arrives after mobile push-to-talk is stable.
  - The Code surface is read-the-real-file by default; markdown is never the source of runtime truth.
maintenance:
  - Update when any canonical axis evolves (new specialist profile, new tool tier, new gate, new autonomy level).
  - Keep ≤ 360 lines; if it grows, split per zone of the surface into child specs.
  - Bidirectional `related_paths` must stay in sync with each axis owner doc.
related_paths:
  - docs/engineering-knowledge-base/atlas-agentic-software-engineering-authority-map.md
  - docs/engineering-knowledge-base/atlas-code-forge-live-execution-surface-contract.md
  - docs/engineering-knowledge-base/atlas-code-scor-1-implementation-contract.md
  - docs/engineering-knowledge-base/atlas-code-long-session-programming-cockpit.md
  - docs/engineering-knowledge-base/atlas-desktop-backend-contract.md
  - docs/engineering-knowledge-base/atlas-code-category-evolution.md
  - docs/engineering-knowledge-base/atlas-ai-master-architecture.md
  - docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md
  - docs/engineering-knowledge-base/atlas-ai-pipeline.md
  - docs/engineering-knowledge-base/atlas-ai-obras-operating-system.md
  - docs/engineering-knowledge-base/atlas-ai-self-construction-os.md
  - docs/engineering-knowledge-base/atlas-ai-skill-system.md
  - docs/engineering-knowledge-base/atlas-ai-cognitive-runtime.md
  - docs/engineering-knowledge-base/atlas-ai-cognitive-development-plane.md
  - docs/engineering-knowledge-base/atlas-ai-memory-context-core-open-brain.md
  - docs/engineering-knowledge-base/atlas-ai-continuity-session-state.md
  - docs/engineering-knowledge-base/atlas-ai-voice-realtime-surface.md
  - docs/engineering-knowledge-base/atlas-ai-mobile-surface-gateway.md
  - docs/engineering-knowledge-base/atlas-ai-cli-multimodal.md
  - docs/engineering-knowledge-base/atlas-ai-documentation-operating-system.md
  - docs/engineering-knowledge-base/atlas-ai-knowledge-governance-system.md
  - docs/engineering-knowledge-base/atlas-ai-canonical-architecture-index.md
  - docs/engineering-knowledge-base/atlas-ai-model-selection-strategy.md
  - docs/engineering-knowledge-base/atlas-ai-provider-evolution-intelligence.md
  - docs/engineering-knowledge-base/atlas-ai-telemetry-evidence-performance.md
  - docs/engineering-knowledge-base/engineering-blueprint.md
  - docs/engineering-knowledge-base/engineering-blueprint-quality-gates.md
  - docs/engineering-knowledge-base/engineering-blueprint-contracts.md
  - docs/engineering-knowledge-base/domains/programming.md
  - docs/engineering-knowledge-base/atlas-programming-forge-flow.md
  - docs/engineering-knowledge-base/domains/programming-surfaces.md
  - docs/engineering-knowledge-base/domains/programming-specialist-profiles.md
  - docs/engineering-knowledge-base/domains/programming-repair-contract.md
  - docs/engineering-knowledge-base/programming-power-tools-catalog.md
  - docs/engineering-knowledge-base/tool-runtime/programming-tool-families.md
  - docs/engineering-knowledge-base/spec-operating-system/spec-graph-and-traceability.md
  - docs/engineering-knowledge-base/spec-operating-system/spec-compiler-and-critic.md
  - docs/engineering-knowledge-base/spec-operating-system/plan-task-and-receipt-contract.md
  - docs/engineering-knowledge-base/spec-operating-system/drift-detector-and-learning.md
  - docs/engineering-knowledge-base/self-construction/agent-control-plane-contract.md
  - docs/engineering-knowledge-base/self-construction/autonomous-implementation-loop.md
  - docs/engineering-knowledge-base/self-construction/multi-provider-agent-orchestration-contract.md
  - docs/engineering-knowledge-base/self-construction/work-splitter-contract.md
  - docs/engineering-knowledge-base/self-construction/scope-validator-contract.md
  - docs/engineering-knowledge-base/self-construction/ai-implementation-packet-contract.md
  - docs/engineering-knowledge-base/self-construction/collision-matrix-contract.md
  - docs/engineering-knowledge-base/self-construction/reservation-ledger-contract.md
  - docs/engineering-knowledge-base/self-construction/builder-persona-and-handoff.md
  - docs/engineering-knowledge-base/self-construction/capability-maturity-ladder.md
  - docs/engineering-knowledge-base/obras/shared-workspace-and-forge.md
  - docs/engineering-knowledge-base/obras/ai-harness-governance-and-quality.md
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-desktop-code-surface
graph_title: Atlas Desktop Code Surface
graph_world: atlas
graph_layer: system
graph_kind: surface
graph_parent: atlas-desktop
graph_status: building
graph_source: repo
human_name: Atlas Desktop Code Surface
canonical_name: Atlas Desktop Code Surface
technical_name: atlas-desktop-code-surface
cartography_type: surface
canonical_source: docs/engineering-knowledge-base/atlas-desktop-code-surface.md
owner: atlas-ai
layer: 1-surfaces
line_limit: 360
repo_paths:
  - docs/engineering-knowledge-base/atlas-desktop-code-surface.md
  - docs/engineering-knowledge-base/atlas-desktop-backend-contract.md
  - app/Http/Controllers/AtlasCodeForgeExecutionController.php
  - ../atlas-desktop/apps/desktop/src/App.tsx
  - ../atlas-desktop/apps/desktop/src/lib/bridge.ts
  - ../atlas-desktop/packages/atlas-domain/src/cartography.ts
allowed_changes:
  - Evoluir a especificacao da superficie Code quando o contrato backend ou o Desktop mudarem.
  - Ajustar zonas da tela, invariantes e fluxo operacional se preservar governanca, receipt e evidencia.
  - Traduzir trechos legados para portugues sem alterar semantica canonica.
forbidden_changes:
  - Transformar Atlas Code em editor comum com chat lateral.
  - Permitir execucao sem spec, plano, receipt, escopo, gates e evidencia.
  - Colocar escolha livre de provider/modelo fora do Atlas Decide.
depends_on:
  - atlas-desktop-backend-contract
  - atlas-ai-spec-operating-system
  - atlas-ai-self-construction-os
  - atlas-ai-documentation-operating-system
flows_to:
  - atlas-code-operating-room
  - atlas-desktop
unlocks:
  - engineering-operations-system
  - software-construction-operating-room
governs:
  - atlas-code
  - programming-domain
  - self-construction-os
evidence:
  - docs/engineering-knowledge-base/atlas-desktop-code-surface.md
  - ../atlas-desktop/apps/desktop/src/App.tsx
  - ../atlas-desktop/apps/desktop/src/lib/bridge.ts
required_tests:
  - php artisan atlas:engineering:knowledge docs-health --json
requires_evidence: true
risk_level: critical
next_actions:
  - Remover comportamento inutil ou mockado da tela e conectar conversas, Obras, terminal, receipts e evidence reais.
visual_tags:
  - module
  - surface
ai_entrypoints:
  - Leia Resumo, Contratos, Regras para IA, Evidencias e Riscos antes de implementar.
ai_usage_notes:
  - Use repo_paths, allowed_changes, forbidden_changes e required_tests como limites operacionais.
  - Atlas Code e surface. Para hierarquia de Agentic Engineering, Dev, Forge, Code, TEOS e Rivals, leia o Authority Map.
quality_gates:
  - "php artisan atlas:engineering:knowledge docs-health --json"
failure_modes:
  - Contexto desatualizado entre doc, codigo, teste e evidencia.
observability_signals:
  - docs-health status ok
---
# Atlas Desktop · Code Surface Specification
## Resumo
Este doc define a tela Atlas Code como cabine operacional de programacao, nao
como editor tradicional. A tela deve permitir declarar intencao, acompanhar SDD,
assinar contrato, observar execucao, validar gates, ler evidencia e operar
terminal real.
Nome canonico da primeira versao enterprise:
```text
Atlas Code SCOR-1
Software Construction Operating Room v1
```
## Papel no Atlas
Atlas Code materializa o Engineering Operations System: humano dirige,
Kernel decide, IAs executam, evidencia prova. Ele deve substituir o padrao
Cursor/Claude Code/Codex chat-wrapper por uma sala de operacao governada.
Atlas Code nao e o setor de programacao. O setor e Programming Domain. Para o
fluxo pesado completo, leia `atlas-programming-forge-flow.md`; Atlas Code apenas
exibe e opera esse fluxo como surface.

Regra de surface: Atlas Code SCOR-1 nao tem modos concorrentes como conversa,
spec, plan, replay ou repair. O unico modo operacional e Forge; conversa,
spec, plan, verify, evidence, replay, repair e review ledger sao etapas/artefatos
dentro de `programming.forge`.

## Onde Se Encaixa

Pai: `atlas-desktop`. Dependencias principais: Spec OS, Self-Construction OS, Programming Domain, Documentation OS, Atlas Decide, Evidence Ledger e backend real do `atlas-server`.

## Contratos

Sem receipt, nao executa. Sem spec, nao planeja. Sem evidence, nao promove. Sem fonte real, mostra vazio honesto. Atlas Decide escolhe provider; override exige auditoria.

Para sessoes longas e dificeis, Atlas Code deve cumprir tambem
`atlas-code-long-session-programming-cockpit.md`: contexto visivel, spec/plan/tasks
como objetos vivos, task contracts, checkpoint/resume, diff/scope guard, gates
reais, evidence ledger detalhado, repair loop e cartografia de execucao.

A primeira fatia implementavel de SCOR-1 esta fixada em
`atlas-code-scor-1-implementation-contract.md`. Qualquer IA que implemente a
surface deve seguir esse contrato antes de tentar Forge OS, streaming completo,
Scope Guard avancado ou DSL.

Obras ja e a primitiva de producao do Atlas. Atlas Code nao deve se
reposicionar como "workspace em vez de chat" de forma generica: `Obra` e a
unidade/production graph que transforma intencao em delivery/asset; `Obras
Shared Workspace` e o escritorio compartilhado para trabalho longo ou
multi-provider; chat, terminal, SDD, artifacts, gates e cartografia sao
interfaces/projecoes que precisam estar vinculadas a `obra_id`, node, contexto,
output e evidence. Conversa sem esse vinculo continua rascunho/loose chat.

Streaming UI de artefatos operacionais e obrigatorio em SCOR-1: spec, plan,
tasks, gates, evidence, diffs e cartografia devem atualizar em tempo real a
partir de eventos/artefatos, nao apenas aparecer como resposta textual final.

## Fluxo

Intencao humana passa por contexto, spec, critica, plano, tarefas, receipt,
execucao multiagente, gates, repair, evidence e learning proposal. A tela deve
mostrar esse fluxo de forma navegavel e verificavel.

Payload minimo do composer/bridge: `surface_id=atlas_code`,
`app_surface=atlas_code`, `flow_id=programming.forge`,
`routing_task=forge`, `programming_profile=forge`, `requires_obra=true`,
`obra_id=<obra>`, `work_id=<obra>` e `forge_workspace.obra_id=<obra>`.

Acao operacional real da surface: `POST /atlas-code/works/{obra_id}/forge/live-executions`
chama `AtlasForgeLiveExecutionService`, persiste `atlas.code.forge_live_execution.snapshot.v1`
e reaparece em `GET /atlas-code/works/{obra_id}/state` como `forge_live_execution`.
Detalhe canonico: `atlas-code-forge-live-execution-surface-contract.md`.

## Regras para IA

Toda IA que alterar Atlas Code deve preservar os invariantes deste doc, checar
o contrato backend e provar mudancas com paths reais. Nao pode adicionar mock,
estado fake ou UX que esconda ausencia de backend.

## Escopo de Implementacao

Permitido: UI, bridge, estados vazios honestos, terminal, SSE, evidence panels,
receipts e conexao com endpoints reais. Proibido: runtime paralelo, provider
dropdown livre, execucao sem contrato ou mocks persistentes.

## Dependencias

- `atlas-desktop-backend-contract`
- `atlas-ai-spec-operating-system`
- `atlas-ai-self-construction-os`
- `atlas-ai-documentation-operating-system`
- `atlas-canonical-module-doc-v1`

## Evidencias

- `../atlas-desktop/apps/desktop/src/App.tsx`
- `../atlas-desktop/apps/desktop/src/lib/bridge.ts`
- `docs/engineering-knowledge-base/atlas-desktop-backend-contract.md`

## Riscos

- A tela virar apenas mock visual sem uso real.
- Terminal parecer conectado mas nao receber input.
- Atlas Code duplicar Kernel em vez de consumir `atlas-server`.
- Falta de evidence permitir "implementado" falso.

## Exemplos

Correto: botao de envio desabilitado enquanto nao houver Obra ativa. Proibido:
criar conversas, receipts ou outputs ficticios para preencher a tela.

## Proximas Acoes

Conectar Obras, conversas, terminal PTY, receipts, gates e evidence reais; todo
painel sem backend deve mostrar estado vazio honesto.

Implementar o modo de sessao longa definido em
`atlas-code-long-session-programming-cockpit.md` antes de declarar Atlas Code
pronto para programacao assistida por IA em trabalho dificil.

## 1. Definition

The **Atlas Desktop Code Surface** is the human operational window into the Atlas Kernel for all programming work. It is the canonical desktop replacement for Cursor, Codex (OpenAI), Claude Code and any other AI coding IDE — not as a "better editor", but as a **governed conversation surface** where the human declares intent, Atlas compiles spec → plan → receipt, multi-agent providers execute under Collision Matrix and Scope Validator, evidence is appended to the Ledger, and every change is signed by a Decision Receipt v2.

The surface is **read-only on canon sources**. The human reads what Atlas wrote in real `.md` files (Documentation OS truth). Atlas writes via the Engineering Harness and the Self-Construction loop, not via a text editor that the human types into.

## 2. Anti-Definition

The Code surface is **not**:

- a text editor with a chat sidebar (Cursor pattern);
- a one-agent chat with `--model=foo` dropdown (Claude Code pattern);
- a free-form REPL connected to MCP tools (Codex pattern);
- a Cursor fork or Electron clone of any of the above.

It is the **operational window of a system** that already has Obras, Forge, Spec OS, Kernel Pipeline, Self-Construction OS, Memory Core, Skill System, Documentation OS and Tool Runtime — all governed.

## 3. The 11 Canonical Axes Materialized

| # | Axis | Owner doc | What the surface materializes |
|---|---|---|---|
| 1 | Obras + Forge | `atlas-ai-obras-operating-system.md`, `obras/shared-workspace-and-forge.md` | `obra_id` bar at top · mother contract panel · artifact bus visible |
| 2 | Self-Construction OS | `atlas-ai-self-construction-os.md`, `self-construction/autonomous-implementation-loop.md` | 13-stage loop indicator · Build Graph · Capability Maturity Ladder · Learning Proposals inbox |
| 3 | Multi-Agent Orchestration | `self-construction/multi-provider-agent-orchestration-contract.md`, `self-construction/work-splitter-contract.md`, `self-construction/collision-matrix-contract.md` | 5 parallel slots · Collision Matrix view · Reservation Ledger timeline · Scope Validator live |
| 4 | Programming Domain + Harness | `domains/programming.md`, `domains/programming-specialist-profiles.md`, `engineering-blueprint.md` | Specialist Profile lens · Engineering Harness pipeline · Quality Gates dashboard · Repair Loop |
| 5 | Spec OS | `spec-operating-system/spec-graph-and-traceability.md`, `spec-operating-system/spec-compiler-and-critic.md`, `spec-operating-system/plan-task-and-receipt-contract.md` | Spec → Critic → Clarification → Plan → Task → Receipt chain · Spec Graph clickable · Assumption Ledger |
| 6 | Kernel Pipeline | `atlas-ai-kernel-architecture.md`, `atlas-ai-model-selection-strategy.md`, `atlas-ai-provider-evolution-intelligence.md` | Atlas Decide panel (14 signals, score, fallback chain) · Decision Receipt v2 inline · Cost Gate live |
| 7 | Memory + Continuity | `atlas-ai-memory-context-core-open-brain.md`, `atlas-ai-continuity-session-state.md` | Context Pack auditable · Session Snapshot bridge · Compaction live · Continuity with mobile |
| 8 | Skill System + Cognitive Plane | `atlas-ai-skill-system.md`, `atlas-ai-cognitive-runtime.md`, `atlas-ai-cognitive-development-plane.md` | Active skill badge · Pareto Curriculum · Mastery Profile · Curator Proposals |
| 9 | Voice + Mobile + Multimodal | `atlas-ai-voice-realtime-surface.md`, `atlas-ai-mobile-surface-gateway.md`, `atlas-ai-cli-multimodal.md` | Composer voice button · Connected Devices panel · Multimodal attachments |
| 10 | Documentation OS + Knowledge Governance | `atlas-ai-documentation-operating-system.md`, `atlas-ai-knowledge-governance-system.md` | Canonical source labels (REPO/VAULT) · Doc gap blocker · Drift Detector · Anti-Duplication alert |
| 11 | Tool Runtime + Atlas CLI | `programming-power-tools-catalog.md`, `tool-runtime/programming-tool-families.md` | Tool registry T0–T3 · Evidence ledger · PTY embedded · `atlas dev`/`forge`/`fix`/`continue` aware |

## 4. The 8 Cross-Cutting Principles

1. **Atlas decides; surfaces solicit.** No surface picks provider, model, scope, tool tier, gate or autonomy. Everything flows from the Kernel via Receipt.
2. **Doc before spec before plan before code.** Each layer precedes the next. Documentation OS enforces this at parse time.
3. **Evidence is append-only and queryable.** Every execution, gate, repair, cost, decision leaves a trace with hash, timestamp, trace_id.
4. **Receipt v2 is the execution contract.** `allowed_files`, `forbidden_files`, tools, budget, gates, rollback, autonomy — declared up front. Runtime touches only what the Receipt allows.
5. **Autonomy shrinks when context weakens.** Stale docs → propose only. Missing context → ask. High risk → human gate. No tests → docs/spec only.
6. **Learning is proposal-only for critical change.** Every improvement is a JSON artifact for human review. Atlas never auto-mutates critical policy.
7. **Drift Detector runs continuously.** Spec ↔ Code ↔ Tests ↔ Evidence ↔ Receipt. Divergence blocks Report until resolved.
8. **Provider is engine; profile is identity.** Claude / Codex / Gemini / local llama are interchangeable. `programming.forge` is identity. Provider Evolution Intelligence detects when to switch; human approves.

## 5. The 18 Universal Invariants

The surface MUST honor every one. A UI choice that breaks any invariant is anti-canon.

| # | Invariant |
|---|---|
| I1 | Every operation lives inside an Obra (`obra_id` bar always visible) |
| I2 | Spec precedes plan; plan precedes code (Spec OS is mandatory, not optional) |
| I3 | Critic attacks ambiguity BEFORE any agent is invoked (Spec Critic panel runs first) |
| I4 | Context Pack compiled once and sliced per provider (never duplicated tokens across agents) |
| I5 | Decision Receipt v2 is signed before every execution (UI shows the receipt; user signs or rejects) |
| I6 | Atlas Decide chooses provider via 14 signals + AP-99 history (user only overrides via audited `manual_override`) |
| I7 | Fallback chain is explicit in the Receipt (every provider switch has registered reason) |
| I8 | Multi-agent parallel requires Collision Matrix validated (no parallel without governance) |
| I9 | Scope Validator classifies each file touched live (allowed / forbidden / unknown / hot_external) |
| I10 | Quality Gates P0/P1 with confidence ≥ 0.80 BLOCK promotion (never warnings) |
| I11 | Tool Runtime is governed: gates consume persisted evidence, never invoke tool directly |
| I12 | Evidence Normalization uses universal schema before "packet complete" is accepted |
| I13 | Cost real vs estimated is visible inline + budget window auditable |
| I14 | Capability Maturity L0–L8 is declared per piece (no skipping levels) |
| I15 | Session Snapshot (1–5 KB) preserves intent between sessions; `atlas continue` reconstructs without replay |
| I16 | Drift Detector spec↔code↔tests runs continuously; divergence blocks Report |
| I17 | Self-Construction Learning Proposals are JSON artifacts for human review; never auto-applied |
| I18 | Anti-Hallucination Rule before declaring "does not exist": exhaustive search in docs + Postgres + Code Intel + Evidence |

## 6. Surface Architecture

### 6.1 Layout zones (6 zones, fixed)

```
┌─ Topbar ────────────────────────────────────────────────────────┐
│ Atlas · Code · 5 tabs · Core daemon health · folio              │
├─ Forge Workspace Bar ────────────────────────────────────────────┤
│ obra_id · objective · mother contract ▸ · status board          │
├─ Status Routing ────────────────────────────────────────────────┤
│ atlas decide → providers · receipt · cost · trocar · explain    │
├──────────┬──────────────────────────────────┬────────────────────┤
│ Sessions │  Conversation + SDD Pipeline     │ Operational Panel  │
│ (260)    │  (1fr)                            │ (380)              │
│          │                                   │ tabs:              │
│          │  you: intent                      │ · Spec Graph       │
│          │  atlas: Context Scout ☑          │ · Decision Receipt │
│          │  atlas: Spec Compiler ☑          │ · Quality Gates    │
│          │  atlas: Spec Critic ☑            │ · Build Graph      │
│          │  atlas: Clarification (Q&A)      │ · Capability Ladder│
│          │  atlas: Plan Compiler            │ · Scope Heatmap    │
│          │  atlas: Decision Receipt         │ · Evidence Timeline│
│          │  atlas: Execute (5 slots)        │ · Drift Detector   │
│          │  atlas: Evidence + Drift         │ · Learning Inbox   │
│          │  atlas: Learning Proposal        │ · Repair Loop      │
│          │                                   │ · Specialist Lens  │
│          │  [composer · ✦ · voice]          │                    │
├──────────┴──────────────────────────────────┴────────────────────┤
│ Terminal PTY · 5 parallel slots when Multi-Agent fires           │
└──────────────────────────────────────────────────────────────────┘
```

### 6.2 Information density rules

- **Editorial cream DNA** (per `project_atlas_motion_principle.md` + `project_atlas_editorial_grid.md`): paper background, Cormorant Garamond italic for body, JetBrains Mono caps for metadata, bronze as signature, hairlines deliberate, never dark cyberpunk SaaS.
- **TDAH-aware** (per `feedback_atlas_tdah_design.md`): hierarchy by typography, not 2D grids. Linear editorial TOC for lists. Weight-decreasing scale (focus larger, background smaller).
- **No mock-data fallback** (per `feedback_atlas_no_mock.md`): if a panel has no data, it shows the canonical empty state, never invents.

### 6.3 Visual hierarchy

The surface follows the **Atlas Vault Cartography** sister-surface canon (`atlas-vault-cartography-schema.md`): same colors, same fonts, same hairlines. The two surfaces look like the same product. Code is the operational workbench; Cartography is the navigable truth — they share DNA.

## 7. Interaction Model

### 7.1 The conversation pipeline (mandatory order)

```
human intent
  → Context Scout (loads docs, repo, prior specs, design system)
  → Spec Compiler (intent → operational spec + requirements + AC)
  → Spec Critic (attacks ambiguity, design conflicts, missing tests)
  → Clarification Policy (short question + 3–5 options, only if blocking)
  → Plan Compiler (technical approach + target files)
  → Task Compiler (small ordered traceable tasks)
  → Decision Engine (signs Receipt v2 with allowed_files, gates, rollback)
  → Multi-Agent Executor (1–5 providers in parallel, Scope Validator live)
  → QA Agent + Evidence Agent (gates run, evidence persisted)
  → Drift Detector (spec ↔ code ↔ tests, blocks Report on divergence)
  → Learning Curator (proposal-only, never auto-applied to critical)
```

The surface renders each stage as it happens. The user can pause at any stage (especially Plan and Receipt) and either approve, request adjustment, or reject.

### 7.2 Multi-agent parallel work

When the Work Splitter emits multiple disjoint packets, the Conversation zone renders up to **5 parallel tracks** (one per provider/slot), each showing:

- packet contract (allowed_files, forbidden_files, required_gates);
- live Scope Validator status (allowed / forbidden / unknown / hot_external);
- progress, tokens, cost;
- evidence chain (run hash, gates passed, deviations);
- stop conditions (hot file touch, packet hash drift, forbidden write).

The **Collision Matrix** view in the Operational Panel proves the packets are disjoint before dispatch. No parallel without governance.

### 7.3 Voice + multimodal input

- **Composer voice button** (✦ canon): single tap = activate push-to-talk; long-press = voice mode (streaming STT/TTS via LiveKit Agents through Mobile gateway).
- **Audio + image attachments** flow through the Context Pack as first-class refs (path, MIME, hash, redaction status).
- **Continuity from mobile**: when a voice session is already open on iPhone, the Connected Devices panel shows it; the desktop can take control or watch live.

### 7.4 Continuity (between sessions and surfaces)

- On `atlas continue`, the surface loads the Session Snapshot (intent, evidence refs, context pack hash, pending risks) and reconstructs the workspace **without replaying chat**.
- On long sessions, automatic Compaction preserves intent + decisions + risks + evidence refs while dropping raw prompt/exploratory notes (per `atlas-ai-continuity-session-state.md`).
- Cross-surface handoff (mobile ↔ desktop) issues a new Decision Receipt v2 linking the parent session_id; the Evidence Ledger logs `HANDOFF` and `CONTINUITY_SESSION_BRIDGE`.

## 8. Decision Receipt v2 in the UI

The Receipt is **never hidden**. It is the contract of execution.

Receipt panel (Operational Panel · Decision Receipt tab) shows the full v2 schema:

- `receipt_id`, `signed_by`, timestamp
- `provider_selection` block (primary, fallback chain, reason per fallback, `selection_explanation` with confidence_score, evidence_level, primary signals)
- `policy_compiled` (budget window + used, tools allowlist + tier, autonomy level, privacy class)
- `gates_required` (hard / soft + status)
- `cost_contract` (estimated tokens + cost + confidence; actual after execution)
- `context_hash`, `context_size_tokens`
- `execution_limits` (timeout, max attempts, dry-run flag)
- `evidence_required` list

Two primary actions: **Apply** (sign and execute) · **Reject** (cancel with reason recorded). Plus **Replay** (frame-by-frame) and **Compare vs Previous**.

## 9. Anti-Canon (Forbidden in This Surface)

| Anti-pattern | Forbidden because |
|---|---|
| Central text editor with chat sidebar | Vitor does not type code; the surface is a window into the Kernel |
| Provider dropdown that bypasses Atlas Decide | Atlas Decide is the sole authority |
| Free chat without spec | Spec OS forbids execution without requirements + AC |
| 5 Codex CLIs in parallel without Collision Matrix | Multi-agent requires governed disjoint write-sets |
| "AI completed task" without `evidence_hash` + `scope_deviations` + signed Receipt | Evidence Normalization rejects free-form completion |
| Tools invoked directly by the provider | Tools are governed via registry + policy + evidence |
| Markdown as runtime source of truth | Postgres + Evidence Ledger are runtime truth; markdown is projection |
| Learning auto-applied to critical policy | Learning is proposal-only for critical |
| Specialist as a parallel domain | `programming.frontend` is a profile inside `programming`, not a new domain |
| Manual provider override without audit | Override always records `manual_override` in the Receipt |
| Receipt without rollback strategy | Execution forbidden without rollback |
| Mac Swift voice daemon before mobile push-to-talk stable | Mobile is the canonical voice surface (AP-179) |
| Obsidian as primary operational source | Obsidian is Human Surface, not runtime executor |
| Implementation without canonical owner doc | Documentation-First is Kernel law |

## 10. Capability Maturity Path (L0 → L5)

The Code surface ladders up explicitly, never claims maturity it does not have.

- **L0 · named** (today): this document exists; the v1 mockup (`public/atlas-truth-cartography.html` for cartography; `public/atlas-code-cockpit-mockup.html` for code) demonstrates direction.
- **L1 · documented** (next): this spec + child specs per zone + linked owner docs ratified.
- **L2 · specified**: AP-### plans cover each zone with acceptance criteria + tests.
- **L3 · scaffolded**: the Tauri + React shell exists; routes/views render against mock graph.
- **L4 · manual executable**: real Atlas Core daemon + readers + Kernel calls; human still confirms every receipt.
- **L5 · agent executable**: Multi-Agent Executor wired; Self-Construction loop closes end-to-end on small slices.

L6+ (`autonomous restricted`, `self-improving`, `strategic`) are blocked until L5 stable and Self-Programming OS safety contracts validated.

## 11. Definition of Done (v1 = L4)

The Code surface v1 ships when:

- all 18 universal invariants render visually and gate runtime;
- every panel reads from real Atlas Core sources (no mock data);
- every execution requires Receipt v2 sign-off by the human;
- Drift Detector blocks Report on spec↔code divergence;
- Capability Maturity Ladder is visible and accurate per piece;
- the surface integrates with the Cartography surface (sister window) and shares Source Authority canon;
- Cursor / Codex / Claude Code can be uninstalled from Vitor's Mac and the surface still covers every workflow.

## 12. Open Questions (Deferred)

- **Tauri vs Electron** final decision (`Atlas Desktop` shell): leaning Tauri 2 for DNA fit (smaller, native WKWebView, codesign pipeline) but Electron is industry norm. Documented in a separate ADR.
- **Local model inference** (Metal/MLX on Apple Silicon M3/M4): scope of which agents can run local-only.
- **Multi-window mode**: should Cartography and Code coexist as two windows of the same app, or split tabs?
- **Voice mode on desktop**: arrives after mobile voice is stable (per AP-179). Spec pending.
- **Self-Construction in-surface execution**: when L5, does the Loop run inside the surface or as a daemon background?

---

This document is the **scaffold** of the Atlas Desktop Code surface. Mockups, Tauri implementation, and React component architecture must trace back to it. Changes to the surface require updating this spec first, then the linked axis owner docs in `related_paths`.
