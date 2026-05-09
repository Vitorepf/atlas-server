---
id: atlas-ai-master-planes-and-authority
type: engineering_knowledge
title: Master Architecture Planes and Authority
status: active
category: architecture
priority: 99
summary: Defines the Atlas AI planes and their authority boundaries so features enter the correct layer.
tags:
  - atlas-ai
  - master-architecture
  - planes
capabilities:
  - enterprise_orchestration
  - operational_intelligence
  - policy_profile_governance
decisions:
  - Planes are authority boundaries, not folders or UI sections.
  - Control Plane compiles the operation; it does not perform domain work.
  - Runtime executes; it never chooses mission or policy.
maintenance:
  - Update when a new plane or authority boundary is promoted.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-master-architecture.md
  - docs/engineering-knowledge-base/atlas-ai-core-vs-domain.md
---

# Planes and Authority

## Control Plane

Owns normalization and compilation: Input, Intent, Profile, Context, Policy,
Decide, Receipt. It chooses allowed path and constraints.

## Domain Plane

Owns semantic work. A domain defines flows, context needs, gates, memory
projection and evidence shape. It cannot create a private pipeline.

## Runtime Plane

Owns execution: providers, workers, harnesses, tools, Python, Go, Swift and
Laravel services. Runtime executes a receipt.

## Evidence Plane

Owns truth after execution: append-only events, projections, replay, trace,
quality packets and audit.

## Learning Plane

Owns improvement signals: memory promotion, metric trends, Curator proposals,
Rivals evidence and quality calibration.

## Surface Plane

Owns user entry and rendering: CLI, App, Mobile, API, MCP, Voice and local Mac
surfaces. Surface never decides provider, domain, tool or autonomy.

