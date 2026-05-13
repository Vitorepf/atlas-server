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
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-research-reporting-publication-contract

graph_title: Atlas AI Research Reporting And Publication Contract

graph_world: atlas

graph_layer: module

graph_kind: contract

graph_parent: atlas-ai-canonical-architecture-index

graph_status: active

graph_source: repo

owner: research-self-improvement

repo_paths:
  - docs/engineering-knowledge-base/research-self-improvement/reporting-and-publication-contract.md

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
  - docs/engineering-knowledge-base/research-self-improvement/reporting-and-publication-contract.md

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: low

visual_tags:
  - module
  - contract
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

## Resumo

Output, publication, alert and report contract for verified Atlas research.

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
