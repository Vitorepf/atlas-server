---
id: atlas-ai-canonical-authority-map
type: engineering_knowledge
title: Atlas AI Canonical Authority Map
status: active
category: architecture
priority: 96
summary: Detailed subject-to-document authority map for Atlas AI.
tags:
  - atlas-ai
  - architecture-index
  - authority
capabilities:
  - canonical_architecture_index
decisions:
  - Subject authority must be explicit to prevent duplicate docs and duplicate flows.
maintenance:
  - Update when a subject owner changes.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-canonical-architecture-index.md
---

# Atlas AI Canonical Authority Map

| Subject | Authority |
|---|---|
| Thesis/provider antifragility | `atlas-ai-thesis-multiplier-channel.md` + `thesis/*.md` |
| Session bootstrap | `atlas-ai-session-bootstrap.md` |
| Documentation governance | `atlas-ai-documentation-operating-system.md` |
| Knowledge governance | `atlas-ai-knowledge-governance-system.md` |
| Runtime languages | `atlas-ai-runtime-language-boundaries.md` |
| Kernel contracts | `atlas-ai-kernel-architecture.md` |
| Master product architecture | `atlas-ai-master-architecture.md` |
| Pipeline and topology | `atlas-ai-pipeline.md`, `atlas-ai-core-vs-domain.md`, `atlas-ai-operating-system.md` |
| Model selection and AP-99 | `atlas-ai-model-selection-strategy.md`, telemetry/performance docs, AP-146/AP-147 |
| Memory/Open Brain | `atlas-ai-memory-context-core-open-brain.md` + `memory/*.md` |
| Memory noise immunity, capture quarantine and promotion gates | `memory/cognitive-immune-learning-kernel.md` |
| Code Intelligence and external graph candidates | `code-intelligence.md` + `code-intelligence/external-graph-harness.md` |
| AtlasVault/Obsidian | `obsidian-atlas-vault.md` + `vault/*.md` |
| Mobile | `atlas-ai-mobile-surface-gateway.md` |
| Voice realtime | `atlas-ai-voice-realtime-surface.md` |
| CLI multimodal | `atlas-ai-cli-multimodal.md` |
| Programming | `domains/programming.md` + specialist docs |
| Self-Improvement | `domains/self-improvement.md` |
| Finance | `domains/finance.md` |
| Personal Development | `domains/personal-development.md` |
| Cognitive Development Plane | `cognitive/README.md` + cognitive APs |
| Business contexts | `atlas-ai-business-contexts.md` |
| Scenario simulation | `atlas-ai-scenario-simulation-harness.md` |
| Legacy/resolver corpus | `atlas-ai-resolver-corpus-audit.md`, `legacy-documentation-cleanup-report.md` |

## Rule

If the subject is not here, find the closest owner README/doc before creating a
new authority surface.
