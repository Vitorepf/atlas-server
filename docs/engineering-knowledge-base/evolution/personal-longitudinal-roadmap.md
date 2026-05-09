---
id: atlas-ai-evolution-personal-longitudinal-roadmap
type: engineering_knowledge
title: Personal Longitudinal Intelligence Roadmap
status: active
category: roadmap
priority: 92
summary: Roadmap for privacy-governed personal memory, life timeline, body-cognition signals and long-horizon Atlas-Vitor intelligence.
tags:
  - atlas-ai
  - personal-memory
  - longitudinal
  - privacy
capabilities:
  - personal_longitudinal_memory
  - atlasvault_sync
  - privacy_governance
decisions:
  - Personal memory is high sensitivity and local-first by default.
  - AtlasVault is a human knowledge surface, not raw operational truth.
  - Body, health and cognition signals require explicit policy and retention.
maintenance:
  - Keep personal data features tied to privacy classes and forgetting protocol.
  - Do not send raw personal memory to providers without redaction.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-atlasvault-obsidian.md
  - docs/engineering-knowledge-base/domains/personal-development.md
  - docs/engineering-knowledge-base/cognitive/README.md
---

# Personal Longitudinal Intelligence Roadmap

## Purpose

Atlas should become useful over years, not just per prompt. Longitudinal memory
lets it detect patterns in decisions, energy, learning, projects, companies and
strategy while preserving Vitor's autonomy.

## Memory Classes

| Class | Examples | Default |
|---|---|---|
| Work pattern | productive hours, recurring blockers | local projection |
| Cognitive pattern | learning friction, failure signatures | local projection |
| Health signal | sleep, NSDR, recovery | explicit opt-in |
| Personal values | goals, identity, boundaries | human-reviewed |
| Sensitive raw data | audio, private notes, biometric data | do not persist raw |

## AtlasVault Role

AtlasVault/Obsidian is the human knowledge workspace. It is powerful because it
supports reflection, identity, synthesis and manual review. It is not the raw
operational database. Sync events and curated notes may feed the Evidence Ledger
or memory projections through managed contracts.

## Curator Role

Curator may propose:

1. repeated life patterns;
2. schedule and recovery adjustments;
3. cognitive development gaps;
4. knowledge decay;
5. high-leverage review items.

Curator does not auto-change calendar, health plan, identity documents or active
curriculum without policy and human review.
