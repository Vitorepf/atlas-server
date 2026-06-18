---
id: atlas-autonomous-evolution-loop
type: engineering_knowledge
title: Atlas Autonomous Evolution Loop
status: active
category: autonomous-evolution
priority: 100
summary: Canonical contract for AAEL, the Autonomous Evolution Portfolio OS that lets Atlas improve itself through governed opportunity selection, sandbox execution, evidence, promotion gates and learning.
tags:
  - atlas-ai
  - aael
  - autonomous-evolution
  - self-construction
  - portfolio-os
capabilities:
  - autonomous_evolution_portfolio
  - strategic_alignment_gate
  - autonomy_budget
  - evolution_audit_court
decisions:
  - AAEL is a portfolio governor, not a free self-programming runtime.
  - AAEL must reuse Self-Improvement, Self-Construction, ASEIF, AWEOS, AVER and AEMOR instead of creating parallel runtimes.
  - AAEL experiments must carry an AAEQ assisted-execution bridge with AEDPDS, AUCRI/ACMF, AREG and AEMOR feedback before promotion can be considered ready.
  - AAEL may plan and sandbox evolution work autonomously, but high-risk or irreversible promotion requires human approval.
  - AAEL must not run benchmarks, call providers directly or mutate production without certified evidence.
maintenance:
  - Update when AAEL persistence, promotion policy, control plane or runtime wiring changes.
related_paths:
  - app/Services/Ai/AutonomousEvolution/AtlasAutonomousEvolutionLoopService.php
  - app/Services/Ai/AutonomousEvolution/AtlasAutonomousEvolutionCertificationService.php
  - app/Console/Commands/AtlasAaelCommand.php
  - app/Console/Commands/AtlasAaelCertifyCommand.php
  - database/migrations/2026_05_20_210000_create_atlas_aael_tables.php
  - tests/Feature/Ai/AutonomousEvolution/AtlasAutonomousEvolutionLoopServiceTest.php
  - docs/engineering-knowledge-base/atlas-ai-self-construction-os.md
  - docs/engineering-knowledge-base/atlas-autonomous-work-execution-os.md
  - docs/engineering-knowledge-base/atlas-verified-execution-runtime.md
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
owner: atlas-ai
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-autonomous-evolution-loop
graph_title: Atlas Autonomous Evolution Loop
graph_world: atlas
graph_layer: system
graph_kind: module
graph_parent: atlas-ai-canonical-architecture-index
graph_status: active
graph_source: repo
human_name: Atlas Autonomous Evolution Loop
canonical_name: Atlas Autonomous Evolution Loop
technical_name: atlas-autonomous-evolution-loop
cartography_type: module
canonical_source: docs/engineering-knowledge-base/atlas-autonomous-evolution-loop.md
repo_paths:
  - docs/engineering-knowledge-base/atlas-autonomous-evolution-loop.md
  - app/Services/Ai/AutonomousEvolution
allowed_changes:
  - Extend AAEL when new evolution signals, gates or certified sidecars become available.
forbidden_changes:
  - Do not turn AAEL into unsupervised self-programming.
  - Do not bypass Self-Construction for Atlas-building-Atlas work.
  - Do not promote high-risk changes without human approval and certified evidence.
depends_on:
  - atlas-ai-self-construction-os
  - atlas-intelligence-factory-os
  - atlas-autonomous-work-execution-os
  - atlas-verified-execution-runtime
flows_to:
  - atlas-forge
  - atlas-dev
  - atlas-control-plane
unlocks:
  - autonomous-evolution-portfolio
governs:
  - atlas-evolution
evidence:
  - app/Services/Ai/AutonomousEvolution/AtlasAutonomousEvolutionLoopService.php
  - tests/Feature/Ai/AutonomousEvolution/AtlasAutonomousEvolutionLoopServiceTest.php
evidence_refs:
  - symbol: AtlasAutonomousEvolutionLoopService
  - command: atlas:aael
required_tests:
  - "php artisan test tests/Feature/Ai/AutonomousEvolution"
  - "php artisan atlas:aael:certify --json --strict"
  - "php artisan atlas:engineering:knowledge docs-health --json"
requires_evidence: true
risk_level: high
line_limit: 520
next_actions:
  - Keep AAEL wired into Atlas Control Plane and AAEL certification.
  - Add new evolution signals only when backed by receipts, tests and certified sidecars.
  - Review high-risk promotion policy whenever Self-Construction or Forge governance changes.
---

> ⚠️ **DEFINIÇÃO CANÔNICA DO LOOP — leia primeiro: `docs/loop-canonical-definition.md` + memórias `loop-*`.** Este doc descreve IMPLEMENTAÇÃO / ESTADO / HISTÓRICO; parte do framing aqui (refactor / ciclomática / landing-rate / best-of-N / proxy) é o **ALVO ERRADO**. O Loop = evolução autônoma **exponencial** de features REAIS do Atlas (entender escopo → projeção frontier + crítica cross-model → multi-agente → teste → wiring), **nunca faxina / proxy / one-shot**. Objetivo final: ser o ÚNICO que evolui o Atlas 24/7 sozinho.

# Atlas Autonomous Evolution Loop

## Resumo

AAEL is the Atlas Autonomous Evolution Loop. Its final product form is the
Autonomous Evolution Portfolio OS: a governed runtime that observes Atlas,
selects high-ROI evolution opportunities, creates sandboxed experiments,
delegates execution to existing Atlas runtimes, audits outcomes and decides
whether promotion is safe, blocked or requires human review.

## Papel no Atlas

AAEL exists to reduce human load in Atlas evolution without allowing uncontrolled
self-programming. It converts "Atlas should improve itself" into a portfolio
discipline:

```text
observe -> score -> select -> simulate -> sandbox -> verify -> promote/reject
-> learn -> repeat
```

The human role becomes strategic approval for high-risk or irreversible changes,
not constant prompt writing and context carrying.

## Onde Se Encaixa

AAEL sits above the construction and execution stack:

```text
Self-Improvement detects gaps
-> AAEL prioritizes the evolution portfolio
-> Self-Construction governs Atlas-building-Atlas work
-> ASEIF decides build/buy/borrow capability options
-> AWEOS plans autonomous work
-> AVER verifies execution
-> AEMOR records outcome and learning
-> Control Plane exposes status
```

AAEL is not Atlas AI chat, Atlas Dev, Atlas Forge or Self-Programming OS. It is
the background portfolio governor that decides what should evolve next.

## Contratos

Canonical names:

- Nome canonico / produto: Atlas Autonomous Evolution Loop
- Acronimo tecnico: AAEL
- Nome interno de experiencia / superficie: Atlas Evolution Command
- Runtime tecnico: `AtlasAutonomousEvolutionLoopService`
- Estado final: Autonomous Evolution Portfolio OS

Schemas:

- `atlas.aael.opportunity.v1`
- `atlas.aael.portfolio_cycle.v1`
- `atlas.aael.evolution_experiment.v1`
- `atlas.aael.promotion_decision.v1`
- `atlas.aael.audit_report.v1`
- `atlas.aael.control_plane.v1`
- `atlas.aael.certification.v1`
- `atlas.aael.assisted_execution_bridge.v1`

## Fluxo

1. AAEL observes runtime signals or receives an operator objective.
2. It creates opportunity records with ROI, risk and strategic alignment.
3. It applies the Evolution Portfolio Manager.
4. It enforces Strategic Alignment Gate.
5. It enforces Autonomy Budget.
6. It runs Anti-Drift Doctrine Gate.
7. It creates sandbox experiments for selected opportunities.
8. It attaches an AAEQ assisted-execution bridge so the experiment inherits
   AEDPDS selection/gate, AUCRI/ACMF context, AREG path and AEMOR feedback.
9. It uses ASEIF to check whether capability should be built, reused or blocked.
10. It uses AWEOS to create execution planning and verified sidecars.
11. It creates promotion decisions with trust levels.
12. It writes an audit report with Evolution Audit Court and learning plan.

## Regras para IA

- Reuse existing Self-Construction, ASEIF, AWEOS, AVER and AEMOR.
- Never create a parallel self-construction runtime.
- Never promote high-risk work without human approval.
- Never claim benchmark or external superiority.
- Never mutate production directly from AAEL.
- Always preserve rollback, evidence and promotion decision records.
- Treat AAEL as portfolio governance, not as a chat response pattern.
- Do not mark an experiment promotion-ready when the AAEQ/AEDPDS/AREG/AEMOR bridge is missing or not ready.

## Escopo de Implementacao

Implemented runtime scope:

- opportunity mining;
- portfolio selection;
- strategic alignment scoring;
- autonomy budget;
- impact simulation;
- anti-drift doctrine gate;
- sandbox experiment creation;
- AAEQ assisted-execution bridge for AEDPDS, context, AREG and AEMOR feedback;
- promotion trust levels;
- self-evolution memory plan;
- dormant capability activation plan;
- audit court;
- CLI control plane;
- certification command.

Out of scope:

- unsupervised production mutation;
- direct provider execution;
- benchmark execution;
- high-risk auto-merge;
- replacing Forge or Dev.

## Dependencias

AAEL depends on:

- Atlas Self-Construction OS for governed Atlas-building-Atlas work;
- AAEQ/AEDPDS for assisted execution doctrine and gate selection;
- ASEIF for capability build/buy/borrow and simulation;
- AWEOS for autonomous execution planning;
- AVER for verified execution evidence;
- AEMOR for outcome memory and anti-false-learning;
- Control Plane for observability;
- Human/operator approval for high-risk promotion.

## Evidencias

Evidence is stored in:

- `atlas_aael_opportunities`;
- `atlas_aael_portfolio_cycles`;
- `atlas_aael_evolution_experiments`;
- `atlas_aael_promotion_decisions`;
- `atlas_aael_audit_reports`.

Commands:

```bash
php artisan atlas:aael cycle --objective="..." --json
php artisan atlas:aael control-plane --json
php artisan atlas:aael:certify --json --strict
```

The certification includes `assisted_execution_bridge`, which proves that a
selected AAEL experiment receives `atlas.aael.assisted_execution_bridge.v1`.
The bridge stores only status, drivers, route, context status, AREG path,
AEMOR feedback status and hashes. It does not expose the raw objective, invoke
providers, run benchmarks or persist additional outcome feedback.

## Riscos

- Overengineering: mitigated by reuse-first policy.
- Drift from Atlas vision: mitigated by Anti-Drift Doctrine Gate.
- Unsafe autonomy: mitigated by Autonomy Budget and promotion trust levels.
- False learning: mitigated by AEMOR-required learning policy.
- Human overload: mitigated by operator queue only for high-risk or weak-evidence work.
- Dormant code proliferation: mitigated by dormant capability activation before build.

## Exemplos

Low-risk docs/test improvement:

```text
opportunity -> auto_safe_sandbox -> AAEQ/AEDPDS/AREG/AEMOR bridge
-> AWEOS plan -> AVER evidence -> promotion_ready
```

Router/provider topology change:

```text
opportunity -> forge_sandbox_operator_review -> signature_required
```

Parallel runtime proposal:

```text
opportunity -> anti_drift_doctrine_gate blocked -> no experiment
```

## Proximas Acoes

- Wire AAEL into the broader Atlas Control Plane read model.
- Feed AAEL with real Self-Improvement and AEMOR signals.
- Add mobile operational notifications for operator queue items.
- Add Forge Obra creation for multi-day high-impact selected opportunities.
- Add richer ASRE strategic scoring when business reality data is present.
