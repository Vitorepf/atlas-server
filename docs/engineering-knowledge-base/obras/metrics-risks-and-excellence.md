---
id: atlas-ai-obras-metrics-risks-and-excellence
type: engineering_knowledge
title: Atlas Obras - Metrics Risks And Excellence
status: active
category: architecture
priority: 100
summary: Metrics, risks, mitigations and excellence criteria for every Obras maturity level.
tags:
  - atlas-ai
  - obras
  - metrics
  - risks
capabilities:
  - obras_operating_system
  - quality_gates
  - risk_governance
decisions:
  - Obras quality must be measured by artifact delivery, evidence, assets and autonomy gain.
  - The strongest risk is turning Obras into a pretty folder or renamed chat.
maintenance:
  - Update before changing Obras metrics, risk policy, excellence criteria or maturity promotion gates.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-obras-operating-system.md
  - docs/engineering-knowledge-base/obras/patamares-l0-l5.md
  - docs/engineering-knowledge-base/obras/ai-harness-governance-and-quality.md
owner: atlas-ai
layer: 2-product-primitive
line_limit: 260
---

# Atlas Obras - Metrics Risks And Excellence

## Metrics By Level

### L0 Metrics

- number of Obras created;
- active Obras;
- archived Obras;
- Obras without next step;
- Obras missing objective/type/status.

### L1 Metrics

- notes per Obra;
- tasks per Obra;
- sources per Obra;
- sections complete;
- last activity;
- Obra summary freshness;
- tasks linked to structure.

### L2 Metrics

- gates approved;
- gates failed;
- decisions registered;
- versions published;
- evidence events;
- feedbacks resolved;
- outputs traceable to versions.

### L3 Metrics

- time to delivery;
- number of repair loops;
- gate approval rate;
- outputs generated;
- human interventions;
- critical failures detected;
- unsupported claims repaired.

### L4 Metrics

- Obras that became assets;
- Obras paused correctly;
- Obras killed correctly;
- dependencies resolved;
- estimated strategic return;
- dispersion reduced;
- spin-offs generated.

### L5 Metrics

- autonomy increased;
- capital created;
- health preserved;
- relationships preserved;
- reputation created;
- technical capacity increased;
- revenue or opportunity generated;
- operator energy protected.

## Main Risks

### Risk 1 - Pretty Folder

Risk:

```text
Obras becomes only a collection of files.
```

Mitigation:

- every Obra requires objective, structure, next step, gates and output;
- Markdown may be projection/export, not the runtime brain.

### Risk 2 - Renamed Chat

Risk:

```text
User talks with AI, but nothing becomes an artifact.
```

Mitigation:

- every AI session must link to Obra, node, task, decision or output;
- every significant AI result must become note, task, draft, decision, gate
  finding, output or evidence.

### Risk 3 - Automation Without Governance

Risk:

```text
AI makes sensitive decisions without control.
```

Mitigation:

- human checkpoints;
- permissions;
- logs;
- approval flow;
- provider and data policy.

### Risk 4 - Fantasy Strategy

Risk:

```text
Foundry or Sovereign OS becomes grand speech without execution.
```

Mitigation:

- metrics;
- evidence;
- opportunity cost;
- periodic review;
- kill/pause/scale decisions.

### Risk 5 - Too Much Complexity Too Early

Risk:

```text
Atlas tries to build L5 before L0-L2 are real.
```

Mitigation:

- implement in layers;
- preserve final ontology;
- start with L0/L1 and the Atlas Self-Construction OS pilot.

## Excellence Criteria

Obras reaches state of the art when it combines:

1. persistent context per Obra;
2. agentic execution;
3. sources and evidence;
4. traceable decisions;
5. quality gates;
6. repair loops;
7. versioning;
8. real outputs;
9. strategic portfolio;
10. operator autonomy protection.

## Final Principle

```text
If the Obra does not become a delivery, it is not complete.
If the delivery does not become an asset, it has not reached Foundry.
If the asset does not increase autonomy, it has not reached Sovereign OS.
```

## Quality Claim Rules

Atlas may not claim an Obra is complete unless:

- output exists;
- current version is identified;
- required gates ran;
- critical failures are resolved or accepted;
- key decisions are recorded;
- evidence exists;
- next step or closure is explicit;
- learning is captured.

Atlas may not claim Foundry maturity unless:

- output became an asset;
- asset type is classified;
- dependency/portfolio relation exists;
- opportunity cost was considered;
- continue/pause/kill/scale decision exists.

Atlas may not claim Sovereign maturity unless:

- autonomy impact is explicit;
- capital impact is explicit;
- health/relationship/integrity constraints passed;
- success metrics exist;
- reversal or review plan exists.
