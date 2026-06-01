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
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-research-private-connectors-security-stack

graph_title: Atlas AI Research Private Connectors Security And Stack

graph_world: atlas

graph_layer: module

graph_kind: module

graph_parent: atlas-ai-canonical-architecture-index

graph_status: active

graph_source: repo
human_name: Atlas AI Research Private Connectors Security And Stack
canonical_name: Atlas AI Research Private Connectors Security And Stack
technical_name: atlas-ai-research-private-connectors-security-stack
cartography_type: module
canonical_source: docs/engineering-knowledge-base/research-self-improvement/private-connectors-security-and-stack.md

owner: research-self-improvement

repo_paths:
  - docs/engineering-knowledge-base/research-self-improvement/private-connectors-security-and-stack.md

allowed_changes:
  - Atualizar este doc quando codigo, arquitetura, fluxo, evidencia ou decisao canonica mudar.

forbidden_changes:
  - Declarar runtime, maturidade ou prontidao sem evidencia verificavel e gates verdes.

depends_on:
  - atlas-ai-documentation-operating-system

flows_to:
  - atlas-cartography
  - atlas-code

unlocks:
  - ai-safe-implementation-context

governs:
  - research-self-improvement

evidence:
  - docs/engineering-knowledge-base/research-self-improvement/private-connectors-security-and-stack.md
evidence_refs:
  - symbol: AtlasPrivateConnectorsSecurityAndStackService
  - command: atlas:aaeos:private-connectors-security-and-stack
  - test: AtlasPrivateConnectorsSecurityAndStackTest

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: high

visual_tags:
  - module
  - module
  - research-self-improvement

ai_entrypoints:
  - Leia Resumo, Contratos, Regras para IA, Evidencias e Riscos antes de implementar.

ai_usage_notes:
  - Use repo_paths, allowed_changes, forbidden_changes e required_tests como limites operacionais.

quality_gates:
  - "php artisan atlas:engineering:knowledge docs-health --json"

failure_modes:
  - Contexto desatualizado entre doc, codigo, teste e evidencia.

observability_signals:
  - docs-health status ok

next_actions:
  - Manter este doc sincronizado com codigo, testes, evidencias e Cartografia.
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

## Resumo

Security, connector and technical stack contract for enterprise research automation.

## Papel no Atlas

Define a responsabilidade desta peca dentro da arquitetura Atlas.

## Onde Se Encaixa

Relaciona esta peca com seu sistema, camada, fluxo ou modulo pai.

## Contratos

Declara invariantes, entradas, saidas, limites e obrigacoes relevantes.

## Fluxo

Descreve o caminho operacional ou a sequencia de uso quando aplicavel.

## Regras para IA

Agentes devem respeitar escopo, evidencias, testes e proibicoes antes de alterar codigo.

## Escopo de Implementacao

Mudancas devem permanecer nos caminhos e limites declarados no frontmatter.

## Dependencias

Dependencias canonicas vivem em frontmatter e no corpo deste documento.

## Evidencias

Evidencias aceitas incluem docs, comandos, testes, receipts, reports e paths verificaveis.

## Riscos

Riscos principais devem ser tratados antes de promover status, runtime ou claims de prontidao.

## Exemplos

Exemplos concretos devem ser adicionados quando reduzirem ambiguidade para humanos ou IAs.

## Proximas Acoes

Proximas acoes devem ser concretas, verificaveis e ligadas a gates de qualidade.
