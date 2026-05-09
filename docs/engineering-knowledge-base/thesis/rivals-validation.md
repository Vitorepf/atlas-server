---
id: atlas-thesis-rivals-validation
type: engineering_knowledge
title: Atlas Thesis - Rivals Validation
status: active
category: constitutional
priority: 100
summary: Empirical validation contract for proving whether Atlas multiplies, matches or degrades direct provider output.
tags:
  - atlas-ai
  - thesis
  - rivals
  - benchmark
capabilities:
  - atlas_rivals
  - multiplier_measurement
  - stop_the_line
decisions:
  - Without Rivals, the thesis is philosophy; with Rivals, it is measurable.
  - Multiplicador negativo is stop-the-line.
maintenance:
  - Keep metrics aligned with current Rivals implementation.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-thesis-multiplier-channel.md
  - docs/atlas-cli-fair-claude-benchmark.md
  - app/Console/Commands/AtlasRivalsCommand.php
  - app/Services/Engineering/EngineeringBenchmarkService.php
---

# Atlas Thesis - Rivals Validation

## Purpose

Rivals runs the same task through:

1. Atlas path: Kernel pipeline, memory, policy, provider selection, gates,
   repair, evidence.
2. Direct path: same provider/model and workspace without the Atlas ecosystem.

It answers: did Atlas multiply, match or degrade the provider?

## Outcomes

| Outcome | Meaning | Action |
|---|---|---|
| Positive multiplier | Atlas beats direct provider in quality/efficiency/gates | Continue and record evidence |
| Neutral multiplier | Atlas roughly equals direct provider | Investigate overhead and missed leverage |
| Negative multiplier | Atlas is worse than direct provider | Stop-the-line; isolate and fix/remove culprit |

Negative sources can include stale memory, bad skill prompt, wrong provider
selection, overactive constitutional filter, false-positive gate, divergent
repair loop or latency/UX overhead.

## Metrics

Track at minimum:

- score gap;
- gate pass/fail delta;
- iterations to success;
- time to acceptable output;
- cost;
- failure source attribution;
- user regret/acceptance when available.

## Cadence

- quick: before commits touching Kernel, Memory, Skills, Gates or Providers;
- medium: weekly health check;
- full: before significant release;
- on-demand: whenever Atlas feels worse than direct provider.

## Domain Expansion

Every major domain eventually needs its own suite:

- Rivals-Programming;
- Rivals-Finance;
- Rivals-Research;
- Rivals-Learning;
- Rivals-Voice;
- Rivals-Marketing.

The structure stays universal: Atlas path vs direct/provider/best-market path,
measured through evidence and fed back to Curator.
