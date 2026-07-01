---
id: atlas-ai-research-operating-system
type: engineering_knowledge
title: Atlas AI Research Operating System
status: active
category: architecture
priority: 100
summary: Universal evidence-first Research OS core and domain acquisition system for every Atlas research domain, from small reusable questions to long-lived research Obras, with domain adapters for finance, companies, science, learning, music, languages, technology and self-improvement.
tags:
  - atlas-ai
  - research-os
  - evidence-first
  - universal-research
  - domain-adapters
capabilities:
  - research_operating_system
  - evidence_first_research
  - deep_research_architecture
  - living_research_workspace
  - research_knowledge_triage
  - domain_acquisition_system
  - research_strategy_governor
  - research_to_mastery_loop
  - research_application_engine
  - research_frontier_scanner
  - world_model_integrator
  - research_taste_judgment_engine
decisions:
  - Atlas research must produce evidence dossiers before knowledge claims.
  - Research OS is a governed system, not a single chat response or search call.
  - Reports publish verified claims, not unsupported generated knowledge.
  - Research OS is the single universal research core; domain-specific research must be an adapter/profile, not a parallel research runtime.
  - Research Company Runtime and Research Domain Runtime are current adapters/executors of this core, not separate authorities.
  - Research OS must support a lightweight-to-deep funnel: quick question, learning note, research trail and living research Obra.
  - Small questions must not be lost just because the operator does not yet know they deserve a large research project.
  - Research OS must be designed as domain acquisition, not research for its own sake.
  - Research OS must govern research depth, priority and capability ROI before escalating work into trails or Obras.
  - Research OS must turn research into operator mastery, not only stored knowledge.
  - Research OS must apply relevant verified research at the moment of answer, plan or decision.
  - Research OS must scan external frontiers so domain maps do not age into stale libraries.
  - Research OS must integrate cross-domain research into a coherent world model.
  - Research OS must judge depth, usefulness, elegance and shallowness, not only truth.
maintenance:
  - Update when Source Registry, Evidence Lake, scheduler, agents or eval harness become executable.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-research-self-improvement-runtime.md
  - docs/engineering-knowledge-base/research-self-improvement/evidence-lake-and-citation-health.md
  - docs/engineering-knowledge-base/research-self-improvement/multi-agent-research-roles.md
  - docs/engineering-knowledge-base/research-self-improvement/scheduled-research-and-triggers.md
  - docs/engineering-knowledge-base/research-self-improvement/reporting-and-publication-contract.md
  - docs/engineering-knowledge-base/research-self-improvement/private-connectors-security-and-stack.md
  - docs/engineering-knowledge-base/research-self-improvement/source-connectors-and-capture.md
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-research-operating-system

graph_title: Atlas AI Research Operating System

graph_world: atlas

graph_layer: system

graph_kind: module

graph_parent: atlas-ai-canonical-architecture-index

graph_status: active

graph_source: repo
human_name: Atlas AI Research Operating System
canonical_name: Atlas AI Research Operating System
technical_name: atlas-ai-research-operating-system
cartography_type: module
canonical_source: docs/engineering-knowledge-base/research-self-improvement/research-operating-system.md

owner: research-self-improvement

repo_paths:
  - docs/engineering-knowledge-base/research-self-improvement/research-operating-system.md

allowed_changes:
  - Atualizar este doc quando codigo, arquitetura, fluxo, evidencia ou decisao canonica mudar.

forbidden_changes:
  - Declarar runtime, maturidade ou prontidao sem evidencia verificavel e gates verdes.

depends_on:
  - atlas-ai-documentation-operating-system

flows_to:
  - atlas-cartography
  - atlas-code

unlocks:
  - ai-safe-implementation-context

governs:
  - research-self-improvement

evidence:
  - docs/engineering-knowledge-base/research-self-improvement/research-operating-system.md
evidence_refs:
  - symbol: AtlasResearchOperatingSystemService
  - command: atlas:aaeos:research-operating-system
  - test: AtlasResearchOperatingSystemTest

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: medium

visual_tags:
  - system
  - module
  - research-self-improvement

ai_entrypoints:
  - Leia Resumo, Contratos, Regras para IA, Evidencias e Riscos antes de implementar.

ai_usage_notes:
  - Use repo_paths, allowed_changes, forbidden_changes e required_tests como limites operacionais.

quality_gates:
  - "php artisan atlas:engineering:knowledge docs-health --json"

failure_modes:
  - Contexto desatualizado entre doc, codigo, teste e evidencia.

observability_signals:
  - docs-health status ok

next_actions:
  - Manter este doc sincronizado com codigo, testes, evidencias e Cartografia.
---
# Atlas AI Research Operating System

Research OS is the target architecture for automatic Atlas research at maximum
reliability.

It is not "web search plus summary". It is a factory for evidence-backed
knowledge where every important conclusion has source, timestamp, provenance,
confidence, contradiction search and review state.

## Canonical Decision

Atlas has exactly one canonical research architecture: **Atlas Research OS**.
It is the universal core for deep research across every subject and domain.

Domain-specific research does not get to create a second research system.
Finance, company/runtime research, science, programming, marketing, music,
language learning, personal development, technology and future domains must plug
into Research OS through adapters/profiles.

```text
Research OS Core
-> Domain Research Adapter
-> Domain source/trust profile
-> Domain claim/eval policy
-> Domain output contract
```

The core owns how to research. Adapters own what counts as a good source,
claim, contradiction, synthesis and promotion target for a domain.

## Authority Boundary

| Layer | Authority | Examples |
|---|---|---|
| Research OS Core | Pipeline, evidence rules, claim store, citation health, contradiction search, synthesis, promotion gates | `atlas:aaeos:research-operating-system`, this doc |
| Domain Adapter | Domain-specific source types, trust ladder, risk policy, synthesis shape, output destination | Finance, Company Runtime, Music, Language Learning, Science |
| Runtime Executor | Current implementation that runs part of the core contract for one domain | `atlas:ai:research-domain`, `ResearchRuntimeService` |
| Knowledge Destination | Where verified outputs become reusable context | Memory, Semantic Notes, Vault, Docs, Open Brain, Constelacao |

No domain may bypass the core by inventing its own source scoring, claim
publication, contradiction, synthesis or memory-promotion rules unless this doc
explicitly delegates that rule to a domain adapter.

## Architecture

```text
Research intent
-> Domain Research Profile
-> Research Strategy Governor
-> Source Plan
-> Source Registry
-> Collectors
-> Evidence Lake
-> Hybrid Index
-> Multi-Agent Research
-> Claim Verification
-> Citation Health
-> Contradiction Search
-> Synthesis Report
-> Eval Harness
-> Promotion Gate
-> Memory / Vault / Semantic Notes / Docs / AP / Constelacao
-> Self-Improvement proposal
```

## Domain Acquisition System

Research OS is not research for its own sake. Its highest form is a domain
acquisition system: it turns curiosity, gaps and small questions into durable
operator capability.

```text
Research OS
-> Curiosity Capture
-> Knowledge Triage
-> Research Strategy Governor
-> Domain Map
-> Evidence Fabric
-> Claim Graph
-> Contradiction Engine
-> Living Research Trails
-> Research Obras
-> Synthesis Studio
-> Memory / Notes / Constelacao
-> Capability Growth
```

Operating question: what capability, domain model or future intelligence can
Atlas build from this?

The core blocks are Curiosity Capture, Knowledge Triage, Domain Map, Evidence
Fabric, Claim Graph, Contradiction Engine, Living Research Trails, Research
Obras, Synthesis Studio, Synapse Layer, Knowledge Promotion Gate and Capability
Growth Ledger.

This prevents losing small learning opportunities and over-bureaucratizing
every question into a large research project.

## Research Strategy Governor

The next decision after triage is strategic allocation: whether a question
deserves 30 seconds, 30 minutes, 3 days or 3 months.

## Research-to-Mastery Loop

Research OS must convert verified research into operator understanding,
practice, transfer, mastery score and next learning move.

## Research Application Engine

Research OS must apply the right verified knowledge at the moment of answer,
plan or decision. Its chain is Situation Recognition, Relevant Knowledge
Retrieval, Claim-to-Decision Mapping, Action Pattern Selection, Answer / Plan /
Decision Support and Feedback From Outcome.

## Research Frontier Scanner

Research OS must watch the outside frontier: changed facts, new methods,
state-of-the-art shifts, weak internal domains and external opportunities. Its
chain is External Signal Watch, State-of-the-Art Tracker, Novelty Detector,
Gap Detector, Opportunity-to-Research Converter and Domain Map Update.

## World Model Integrator

Research OS must integrate domains into a coherent model of reality. Its chain
is Cross-Domain Merge, Belief Consistency Check, Assumption Registry, Causal
Map, Decision Model Update, Conflict Resolution and Worldview Versioning.

## Research Taste And Judgment Engine

Research OS must develop criteria for excellence. It judges depth, usefulness,
insight density, explanation quality, source quality, operator taste alignment
and shallow-vs-deep work so correct-but-mediocre research does not compound.

## Living Research Funnel

Research OS must not treat every question as a large project. It must capture
small learning opportunities cheaply and promote them only when evidence,
reuse or strategic value justifies deeper work.

`Quick Question -> Learning Note -> Research Trail -> Living Research Obra`

Quick Questions are cheap answers; Notes persist atomic claims; Trails connect
notes into domain paths; Research Obras govern months-long work. The operator
need not know in advance that a small question can become a major domain.

## Research Knowledge Triage

Every research interaction should be classified before promotion: disposable,
note, domain connection, trail or living research Obra.

The minimum triage record is:

```text
type: question | note | trail | obra
topic
summary
source_refs
confidence
connections
gaps
next_questions
state: fresh | needs_review | contradicted | consolidated
future_use: answer | note | constelacao | obra | course | decision
```

Triage must stay lightweight. A quick question must not create a heavy Obra by
default. A repeated or strategically connected question must not be thrown away
as chat history.

## Living Research Workspace

A Living Research Obra uses the same workspace-vivo principle as Obras/Forge,
but for knowledge work instead of code mutation.

```text
Research Obra
-> Research Workspace
-> Source and Evidence Artifacts
-> Claim Store
-> Contradiction Tracker
-> Synthesis Versions
-> Review Loop
-> Promotion Gate
-> Memory / Notes / Docs / Constelacao / Reports
```

The workspace owns:

- the research contract: theme, objective, scope, question, definition of done,
  quality bar, deadline and output shape;
- the domain map: topics, subtopics, concepts, authors, schools, debates,
  source tiers, gaps and next leverage points;
- the artifact bus: sources, fichamentos, notes, claims, critiques,
  hypotheses, syntheses, contradiction reports and publication drafts;
- the evidence ledger: source, quote/fragment ref, timestamp, provenance,
  confidence, freshness and review state for important claims;
- the contradiction tracker: stale claims, weak sources, source conflict,
  disputed conclusions and required re-checks;
- the revision loop: scheduled or event-driven review of old claims,
  synthesis quality, drift and missing evidence;
- the synthesis engine: conversion of evidence into answers, notes, reports,
  chapters, lessons, courses, strategies or decisions.

The workspace is not a chat log and not a single report. It is the persistent
office where long research can survive months, model changes, source drift and
operator context loss.

## Components

| Component | Responsibility |
|---|---|
| Curiosity Capture | Keeps small questions and weak learning signals from disappearing before triage. |
| Research Knowledge Triage | Classifies each interaction as disposable answer, learning note, research trail or living research Obra. |
| Research Strategy Governor | Decides priority, depth, horizon, stop/continue/promote and capability ROI before deeper work. |
| Domain Map | Tracks concepts, subareas, source tiers, gaps, maturity and next leverage points for each domain. |
| Claim Graph | Stores verified knowledge as connected claims that can be reused, contradicted and synthesized. |
| Living Research Workspace | Persistent office for long research: contract, sources, claims, artifacts, contradictions, reviews and synthesis versions. |
| Capability Growth Ledger | Measures how research increased operator capability, vocabulary, decisions, repertoire and learning speed. |
| Domain Research Profile | Declares domain source policy, risk class, claim policy, synthesis shape and promotion targets. |
| Scheduler | Time/event based research jobs. |
| Source Registry | Allowed sources, trust tier, permissions, rate limits. |
| Collectors | API, RSS, GitHub, browser, PDF, transcript, dataset and repo capture. |
| Evidence Lake | Raw immutable evidence, hashes, snapshots, extracted text and screenshots. |
| Hybrid Index | BM25, embeddings, graph edges, temporal metadata and authority scoring. |
| Research Agents | Plan, scout, inspect, verify, red-team and synthesize. |
| Claim Store | Atomic claims and evidence links. |
| Citation Health | URL liveness, archive, quote support and source drift. |
| Eval Harness | Quality, factuality, citation, cost, latency and utility metrics. |
| Promotion Gate | Decides docs/AP/code/memory/vault/semantic-note/Constelacao/report action. |

## Domain Adapter Contract

Every domain adapter must provide:

- domain id and supported research flows;
- allowed source classes and forbidden source classes;
- source trust ladder and freshness rules;
- claim types and contradiction policy;
- minimum source diversity and confidence thresholds;
- synthesis output contract;
- promotion targets: memory, vault, semantic note, docs, AP, report or task;
- privacy/provider-safety limits;
- runnable readiness or certification command.

Adapters must be thin. If two adapters need the same mechanism, it belongs in
Research OS Core.

## Universal Knowledge Loop

Verified research must become reusable knowledge through the same governed path:

```text
Research Synthesis
-> Verified Claims
-> Promotion Gate
-> Memory / Semantic Notes / AtlasVault / Canonical Docs
-> Open Brain Context Pack
-> Constelacao semantic positions
-> Future answers and cross-domain recall
```

This is the required path for the "synapse" behavior: a deep research run about
music, harmonica, English, French, body language, power language, technology or
finance can later help an apparently unrelated question when the claim is
provider-safe, promoted and semantically related.

The current runtime may be partial. The architecture must still point all new
work toward this unified loop instead of creating another research store.

## Maximum Leverage Roadmap

To push Research OS to its highest useful form, prioritize these levers in
order:

1. **Domain Research Profiles**: one thin profile per area, such as music,
   languages, technology, finance, companies, science or self-improvement.
   Each profile defines trusted sources, freshness, risk, synthesis shape and
   knowledge destinations.
2. **Research-to-Knowledge Promotion Gate**: every useful synthesis must have a
   governed path into Memory, AtlasVault, Semantic Notes, Open Brain,
   canonical docs, APs and Constelacao. Reports that are never reusable do not
   compound.
3. **Contradiction and Drift Engine**: every promoted claim must know what
   contradicts it, what changed, what went stale and when to re-check.
4. **AutoResearch Runner**: use the autoresearch pattern as a governed runner:
   propose, test, measure, keep strict improvements, discard regressions and
   repeat. It is a runner inside Research OS, not a separate architecture.
5. **Research Benchmark / Eval Harness**: measure source quality, citation
   coverage, contradiction discovery, freshness, reuse rate and downstream
   usefulness. No metric, no compounding claim.
6. **Synapse Layer**: connect promoted research across domains so old verified
   work helps new questions, for example body-language research helping
   negotiation, music research helping harmonica learning, or technology
   research helping engineering.

The biggest compounding jump is **Promotion Gate + Synapse Layer**. Without
them, Atlas researches well once. With them, research becomes reusable
intelligence.

## Core Rule

Atlas must not publish raw "knowledge". Atlas publishes verified claims with
evidence, then promotes those claims into governed knowledge surfaces.

Research output is not automatically memory. Promotion requires the promotion
gate and must preserve source refs, confidence, uncertainty and contradiction
status.

## Implementation Phases

1. Read-only Source Registry and source scoring.
2. Manual Evidence Lake packet import.
3. Claim extraction and citation health checks.
4. Research report compiler.
5. Domain adapter/profile contract.
6. Memory/Vault/Semantic Notes promotion gate.
7. Constelacao/Open Brain consumption bridge.
8. Self-Improvement proposal integration.
9. Scheduled read-only research jobs.
10. Multi-agent parallel research.
11. Approved low-risk docs promotion.

No phase may skip evidence, citation health or promotion gates.

## Resumo

Universal architecture for automatic high-reliability research as an evidence-first operating system.

## Papel no Atlas

Define a autoridade unica de pesquisa do Atlas. Outros runtimes de pesquisa
devem ser adapters ou executores deste OS, nunca sistemas paralelos.

## Onde Se Encaixa

Pesquisa empresarial, financeira, cientifica, pessoal, musical, linguistica,
tecnologica e de self-improvement compartilham este core. A diferenca vive no
Domain Research Profile.

## Contratos

Declara invariantes, entradas, saidas, limites e obrigacoes relevantes.

## Fluxo

Descreve o caminho operacional ou a sequencia de uso quando aplicavel.

## Regras para IA

Agentes devem respeitar escopo, evidencias, testes e proibicoes antes de alterar codigo.

## Escopo de Implementacao

Mudancas devem permanecer nos caminhos e limites declarados no frontmatter.

## Dependencias

Dependencias canonicas vivem em frontmatter e no corpo deste documento.

## Evidencias

Evidencias aceitas incluem docs, comandos, testes, receipts, reports e paths verificaveis.

## Riscos

Riscos principais devem ser tratados antes de promover status, runtime ou claims de prontidao.

## Exemplos

Exemplos concretos devem ser adicionados quando reduzirem ambiguidade para humanos ou IAs.

## Proximas Acoes

Proximas acoes devem ser concretas, verificaveis e ligadas a gates de qualidade.
