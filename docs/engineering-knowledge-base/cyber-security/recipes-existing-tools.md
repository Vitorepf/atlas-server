---
id: atlas-ai-cyber-recipes-existing-tools
type: engineering_knowledge
title: Atlas AI Cyber Existing Tools
status: scaffold
category: knowledge-base
priority: 80
summary: Defensive and quality tools already available in the canonical Atlas Super Tool Runtime for cyber-security flows.
tags:
  - atlas-ai
  - cyber-security
  - tools
capabilities:
  - cyber_recipes_proposal
decisions:
  - Cyber skills must reuse existing registered tools before proposing new recipes.
maintenance:
  - Update when the canonical registry adds or retires security tools.
related_paths:
  - docs/engineering-knowledge-base/cyber-security/recipes-catalog.md
  - docs/engineering-knowledge-base/super-tool-runtime-core.md
  - docs/engineering-knowledge-base/programming-power-tools-catalog.md
---

# Atlas AI Cyber Existing Tools

Cyber flows can use these canonical registry tools immediately, subject to policy
and recipe availability.

| Tool | Use |
|---|---|
| `gitleaks` | Secret scanning. |
| `semgrep` | SAST and pattern-based source review. |
| `osv_scanner` | Dependency CVE scan. |
| `trivy` | Image, filesystem, repository and Kubernetes security scan. |
| `syft` | SBOM generation. |
| `checkov` | IaC scan. |
| `phpstan` | PHP static analysis in remediation flows. |
| `typescript` | TypeScript correctness in remediation flows. |
| `eslint` | JavaScript/TypeScript lint in remediation flows. |
| `biome` | JS/TS formatting and lint in remediation flows. |
| `hadolint` | Dockerfile lint and remediation evidence. |

## Rule

Do not create a new cyber recipe when an existing registry tool can produce the
same evidence. Add a new recipe only when the tool, mode or evidence shape is
missing.

## Verification

```bash
atlas tools list
atlas tools doctor
```
