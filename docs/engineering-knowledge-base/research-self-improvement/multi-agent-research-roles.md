---
id: atlas-ai-multi-agent-research-roles
type: engineering_knowledge
title: Atlas AI Multi-Agent Research Roles
status: active
category: orchestration
priority: 99
summary: Role contract for parallel research agents with persistent artifacts and anti-duplication rules.
tags:
  - atlas-ai
  - multi-agent
  - research
  - orchestration
capabilities:
  - multi_agent_research
  - research_roles
  - parallel_research
decisions:
  - Parallel agents must write artifacts, not only chat summaries.
  - Roles must be explicit to avoid duplicate searches and vague findings.
  - Red-team and citation audit are mandatory for critical reports.
maintenance:
  - Update when Atlas implements subagent runner, research artifacts or role scheduler.
related_paths:
  - docs/engineering-knowledge-base/research-self-improvement/research-operating-system.md
  - docs/engineering-knowledge-base/research-self-improvement/automation-runbook.md
---

# Atlas AI Multi-Agent Research Roles

Multi-agent research is for complex, high-value questions where parallel search
and independent verification improve quality.

## Roles

| Role | Responsibility |
|---|---|
| Research Director | Defines objective, scope, budget, subquestions and stop conditions. |
| Source Scout | Finds source candidates across registries, APIs and watchlists. |
| Academic Agent | Papers, authors, venues, citations, versions and limitations. |
| GitHub Agent | Releases, commits, PRs, issues, advisories, benchmarks and examples. |
| Web Agent | Official docs, changelogs, posts, screenshots and snapshots. |
| Data Agent | Runs safe analysis, tables, charts and benchmark parsing. |
| Claim Verifier | Maps atomic claims to evidence and statuses. |
| Citation Auditor | URL health, archive, quote support and source drift. |
| Contradiction Agent | Searches for contrary evidence and superseding sources. |
| Red Team Agent | Finds exaggeration, missing uncertainty, weak sources and unsafe action. |
| Synthesis Writer | Produces final report from verified artifacts only. |
| Memory Agent | Updates trends, history and candidate memories through gates. |

## Artifact Rule

Every role must write one or more artifacts:

- source candidates;
- evidence objects;
- claims;
- contradiction notes;
- citation health report;
- synthesis draft;
- eval report;
- promotion proposal.

The Research Director consumes artifacts, not raw hidden reasoning.

## Anti-Duplication Rules

- Each agent receives source classes and subquestions.
- Agents must declare already searched queries/sources.
- Source Scout owns discovery; verifier owns support judgment.
- Synthesis Writer cannot invent sources missing from artifacts.
- Red Team cannot modify final report directly; it emits findings.

## Promotion Rule

Critical reports require:

```text
Research Director
+ Source Scout
+ Claim Verifier
+ Citation Auditor
+ Contradiction Agent
+ Red Team Agent
```

Without these roles, output remains draft or low-risk summary.

