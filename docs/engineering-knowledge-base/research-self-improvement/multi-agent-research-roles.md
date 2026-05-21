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
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-multi-agent-research-roles

graph_title: Atlas AI Multi-Agent Research Roles

graph_world: atlas

graph_layer: module

graph_kind: module

graph_parent: atlas-ai-canonical-architecture-index

graph_status: active

graph_source: repo
human_name: Atlas AI Multi-Agent Research Roles
canonical_name: Atlas AI Multi-Agent Research Roles
technical_name: atlas-ai-multi-agent-research-roles
cartography_type: module
canonical_source: docs/engineering-knowledge-base/research-self-improvement/multi-agent-research-roles.md

owner: research-self-improvement

repo_paths:
  - docs/engineering-knowledge-base/research-self-improvement/multi-agent-research-roles.md

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
  - docs/engineering-knowledge-base/research-self-improvement/multi-agent-research-roles.md

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: low

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

## Resumo

Role contract for parallel research agents with persistent artifacts and anti-duplication rules.

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
