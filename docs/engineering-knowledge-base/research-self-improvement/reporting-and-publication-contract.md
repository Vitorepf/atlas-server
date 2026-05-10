---
id: atlas-ai-research-reporting-publication-contract
type: engineering_knowledge
title: Atlas AI Research Reporting And Publication Contract
status: active
category: reporting
priority: 98
summary: Output, publication, alert and report contract for verified Atlas research.
tags:
  - atlas-ai
  - research-report
  - publication
  - alerts
capabilities:
  - research_report_compiler
  - controlled_publication
  - critical_alerts
decisions:
  - Research reports publish verified claims, uncertainty and evidence logs.
  - Critical topics require human review before final publication or action.
  - Alerts are tasks/proposals, not automatic runtime changes.
maintenance:
  - Update when report compiler, notification channels, dashboards or proposal inbox integration become executable.
related_paths:
  - docs/engineering-knowledge-base/research-self-improvement/research-operating-system.md
  - docs/engineering-knowledge-base/research-self-improvement/evidence-lake-and-citation-health.md
  - docs/engineering-knowledge-base/research-self-improvement/scheduled-research-and-triggers.md
---

# Atlas AI Research Reporting And Publication Contract

## Enterprise Report Shape

Every high-rigor report should include:

1. Executive summary.
2. What changed since last run.
3. Key findings.
4. Primary evidence.
5. Secondary evidence.
6. Verified claims.
7. Uncertain claims.
8. Contradictions found.
9. Practical impact for Atlas.
10. Risks.
11. Recommendations.
12. Sources cited.
13. Technical appendix.
14. Research log.

## Per-Conclusion Fields

Each important conclusion must carry:

- source;
- date;
- evidence quote or pointer;
- confidence;
- source type;
- fact/inference/recommendation classification;
- citation health;
- contradiction status.

## Daily Research Flow

Example schedule:

```text
05:55 prepare job
06:00 collect priority sources
06:10 deduplicate and compare history
06:20 classify importance
06:30 run deep multi-agent research
07:10 extract claims
07:20 verify evidence and citations
07:40 search contradictions
07:50 draft report
08:00 audit final report
08:10 publish verified report
08:15 update memory candidates and dashboards
08:20 create alerts/tasks/proposals
```

## Publication Channels

Allowed future channels:

- Atlas dashboard;
- email summary;
- Slack/Teams notification;
- Notion/Confluence export;
- PDF/HTML report;
- GitHub issue/Jira ticket;
- Proposal Inbox item.

All channels must point back to the research run and evidence records.

## Critical Topic Gate

Security, medical, legal, finance, compliance, privacy, credentials and
infrastructure-critical reports require human review before final publication or
action.

## Alert Rule

Alerts can create tasks or proposals. Alerts cannot directly change Atlas
policy, memory truth, provider routing, runtime code, credentials or production
configuration.

