---
id: atlas-ai-master-domain-onboarding
type: engineering_knowledge
title: Master Architecture Domain Onboarding
status: active
category: architecture
priority: 99
summary: Enterprise onboarding protocol for adding complex Atlas domains without duplication or scope drift.
tags:
  - atlas-ai
  - domains
  - onboarding
capabilities:
  - domain_profile_orchestration
  - anti_duplication_governance
  - domain_manifest_sdk
decisions:
  - A domain is a cognitive/operational vertical, not a company or project.
  - Businesses such as Blackink are Business Context, not domains by default.
  - Domain onboarding requires gates, evidence, memory and surface integration.
maintenance:
  - Keep new domain specs linked from domains/README.md.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-master-architecture.md
  - docs/engineering-knowledge-base/domains/README.md
---

# Domain Onboarding

## Domain Test

A new domain is valid when it has distinct:

1. intents and flows;
2. context model;
3. specialist profiles;
4. tools or runtimes;
5. gates and evidence;
6. memory projection;
7. learning loop.

If it is only a customer, company, project or product, it is Business Context.

## Onboarding Phases

| Phase | Output |
|---|---|
| 0 Charter | purpose, scope, non-goals |
| 1 Profile Catalog | specialists, risk, autonomy |
| 2 Context Model | required sources, memory, documents |
| 3 Orchestrator | flows and planning contract |
| 4 Runtime/Tools | allowed runtimes and tools |
| 5 Gates/Evidence | quality, safety, audit |
| 6 Learning | memory promotion and calibration |
| 7 Surface Integration | CLI/App/Mobile/API/MCP/Voice behavior |
| 8 Maturity Gate | scaffold, pilot, ready, enterprise |

## Current Domain Families

Programming, Finance, Marketing, Cognitive/Learning, Personal Development,
Research, Writing, Health and Self-Improvement/Curator are valid domain
families. Companies and products attach as context.

