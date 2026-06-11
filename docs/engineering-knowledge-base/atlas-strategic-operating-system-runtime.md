# Atlas Strategic Operating System Runtime

## Purpose

Atlas Strategic Operating System Runtime composes five provider-free read models into one strategic control plane:

1. Runtime Feedback Graph
2. Autonomous Experiment & Strategy Loop
3. Organization Twin / Operating System Twin
4. Portfolio / Capital Allocation Brain
5. Autonomous Governance & Policy Evolution

The runtime does not execute providers, spend capital, launch experiments, or auto-apply policy. It produces strategy, sequencing, allocation and governance contracts that must pass human review and Verified Execution before mutation.

## Runtime Feedback Graph

The feedback graph connects real or supplied signals to strategy:

- product delivery runtime receipts
- product delivery outcome memories
- provider memory/cost/flake signals
- logs
- incidents
- product metrics
- revenue and cost
- human feedback

When no real signal exists, the graph reports `watch` instead of inventing certainty.

## Autonomous Experiment & Strategy Loop

The experiment loop converts feedback into guarded hypotheses. Each hypothesis includes:

- success metric
- target or target delta
- risk controls
- rollback/stop policy
- Verified Execution sidecar

The loop only plans experiments. Running or mutating product still requires Verified Execution and review.

## Organization Twin

The organization twin models:

- ownership
- backlog
- dependencies
- debt and risk
- execution capacity
- recommended sequence

Incident pressure is sequenced before growth experiments so strategy does not optimize on contaminated signals.

## Portfolio / Capital Allocation Brain

The portfolio brain ranks experiments, stabilization work and strategic candidates by risk-adjusted expected value. It can recommend allocation shapes, but cannot spend capital. Every allocation requires human approval.

## Autonomous Governance & Policy Evolution

The governance loop proposes policy changes from runtime evidence and portfolio risk. Policy patches are never auto-applied. Sensitive policy changes require:

- human review
- AEMOR judgment
- Verified Execution
- rollback policy

## CLI

```bash
php artisan atlas:strategic-os snapshot --json
php artisan atlas:strategic-os feedback --json
php artisan atlas:strategic-os experiment --json
php artisan atlas:strategic-os organization --json
php artisan atlas:strategic-os portfolio --json
php artisan atlas:strategic-os governance --json
php artisan atlas:strategic-os certify --json --strict
```

## Claim Policy

This runtime is a strategic planning and governance read model. It never claims that experiments, policy changes, capital allocation or runtime mutations happened unless another verified runtime provides receipts.
