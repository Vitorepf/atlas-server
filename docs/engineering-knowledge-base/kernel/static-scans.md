---
id: atlas-ai-kernel-static-scans
type: engineering_knowledge
title: Atlas AI Kernel Static Scans
status: active
category: architecture
priority: 99
summary: Architectural static scans and compliance tests that turn Kernel doctrine into enforceable CI behavior.
tags:
  - atlas-ai
  - kernel
  - static-scans
  - compliance
capabilities:
  - architectural_test_doctrine
  - capability_registry_enforcement
  - surface_adapter_contract
decisions:
  - Doctrine is not accepted unless a scan, test, gate or runtime guard can enforce it.
  - Static scans prevent bypasses before runtime.
  - Compliance tests must include at least one negative regression case when possible.
maintenance:
  - Add new scan IDs to architecture validation output.
  - Keep failures actionable and tied to owner docs.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md
  - docs/engineering-knowledge-base/atlas-ai-session-bootstrap.md
---

# Kernel Static Scans

## Scan Families

| Family | Prevents |
|---|---|
| Surface/provider bypass | surfaces calling providers or tools directly |
| Context bypass | surfaces building privileged context outside Context Builder |
| Receipt propagation | runtime execution without Decision Receipt |
| Capability parity | capability supported in one surface but missing in required peers |
| Provider identity | provider calls without Atlas identity fragment |
| Ledger projection drift | read models diverging from append-only event truth |
| Inbox/proposal parity | Curator proposals missing review surfaces |
| Documentation health | oversized or malformed docs entering canonical KB |

## Required Scan Behavior

Each scan should return:

1. stable scan id;
2. pass/fail;
3. violation count;
4. paths and symbols involved;
5. owner area;
6. remediation hint;
7. whether failure blocks merge.

## Negative Regression Examples

- Paste-image only in `atlas ask` must fail capability parity.
- `atlas dev` provider call without receipt must fail runtime guard.
- Curator proposal without inbox action must fail proposal parity.
- New doc without required frontmatter must fail docs-health.

## Validation Surface

`php artisan atlas:ai:architecture-validate --json` is the primary machine
surface. Human summaries can exist, but CI and agents should consume JSON.

