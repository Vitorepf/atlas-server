---
id: atlas-ai-research-private-connectors-security-stack
type: engineering_knowledge
title: Atlas AI Research Private Connectors Security And Stack
status: active
category: security
priority: 98
summary: Security, connector and technical stack contract for enterprise research automation.
tags:
  - atlas-ai
  - mcp
  - connectors
  - security
  - stack
capabilities:
  - private_research_connectors
  - mcp_security
  - research_runtime_stack
decisions:
  - Private connectors are read-only by default and never expose secrets to models.
  - Browser, code and MCP tools must run through sandbox, logs and least privilege.
  - Stack choices are implementation candidates, not mandates outside AP approval.
maintenance:
  - Update before adding MCP tools, browser workers, external crawlers, object storage or private data connectors.
related_paths:
  - docs/engineering-knowledge-base/research-self-improvement/research-operating-system.md
  - docs/engineering-knowledge-base/research-self-improvement/automation-runbook.md
  - docs/engineering-knowledge-base/research-self-improvement/source-connectors-and-capture.md
---

# Atlas AI Research Private Connectors Security And Stack

## Private Connectors

Future research connectors may expose:

- internal GitHub;
- document stores;
- Slack/Teams;
- Google Drive;
- Notion;
- Jira;
- Confluence;
- CRM;
- SQL database;
- data warehouse;
- private search;
- filesystem;
- Evidence Lake.

All private connectors start read-only and provider-safe.

## Security Rules

- Domain/source allowlist.
- Minimum permissions.
- Secrets never enter model context.
- Browser and code execution sandboxed.
- Write disabled by default.
- Tool call logs retained.
- Approval required for sensitive actions.
- Tool outputs validated before use.
- DLP/redaction for private data.
- Prompt injection in fetched content treated as hostile input.

## Stack Candidates

Orchestration:

- Laravel scheduler for local/simple phase;
- Airflow, Dagster, Temporal or Prefect for enterprise workflows.

Workers:

- Python for crawling, parsing, embeddings, verification and data analysis;
- Node/Python Playwright for controlled browser capture;
- Redis/RabbitMQ/Kafka for queues when needed.

Storage:

- PostgreSQL for metadata, claims, runs and reports;
- S3/MinIO for raw evidence;
- OpenSearch/Elasticsearch for BM25;
- pgvector/Qdrant/Weaviate/Milvus for vectors;
- Postgres graph tables or Neo4j for relationships.

AI:

- strong reasoning model for planning/synthesis;
- fast model for classification;
- embeddings;
- reranker;
- verifier/NLI model;
- multimodal model for screenshots/PDFs;
- sandboxed code interpreter for calculations.

Observability:

- Evidence Ledger;
- tool-call logs;
- research-run tracing;
- source dashboard;
- citation accuracy metrics;
- cost/token/latency metrics.

## Stack Rule

Stack candidates require AP, source gate and implementation plan before runtime
adoption. This doc defines options and constraints, not automatic dependency
approval.

