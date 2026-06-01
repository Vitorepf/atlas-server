---
id: atlas-ai-os-domain-pipelines
type: engineering_knowledge
title: Atlas AI OS - Domain Pipelines
status: active
category: architecture
priority: 99
summary: Canonical pipeline shapes for Programming, Personal Development, Finance and Self-Improvement domains.
tags:
  - atlas-ai
  - domains
  - pipeline
capabilities:
  - programming_pipeline
  - personal_development_pipeline
  - finance_pipeline
  - self_evolution_pipeline
decisions:
  - Every operational domain follows the canonical pipeline.
  - Domain-specific harnesses customize content, not the law of policy/evidence/gates.
maintenance:
  - Update when domain flow shapes change.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-operating-system.md
  - docs/engineering-knowledge-base/domains/programming.md
  - docs/engineering-knowledge-base/domains/personal-development.md
  - docs/engineering-knowledge-base/domains/finance.md
  - docs/engineering-knowledge-base/domains/self-improvement.md
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-os-domain-pipelines

graph_title: Atlas AI OS - Domain Pipelines

graph_world: atlas

graph_layer: flow

graph_kind: flow

graph_parent: atlas-ai-pipeline

graph_status: active

graph_source: repo
human_name: Atlas AI OS - Domain Pipelines
canonical_name: Atlas AI OS - Domain Pipelines
technical_name: atlas-ai-os-domain-pipelines
cartography_type: flow
canonical_source: docs/engineering-knowledge-base/operating-system/domain-pipelines.md

owner: operating-system

repo_paths:
  - docs/engineering-knowledge-base/operating-system/domain-pipelines.md

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
  - operating-system

evidence:
  - docs/engineering-knowledge-base/operating-system/domain-pipelines.md
evidence_refs:
  - symbol: AtlasDomainPipelinesService
  - command: atlas:aaeos:domain-pipelines
  - test: AtlasDomainPipelinesTest

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: medium

visual_tags:
  - flow
  - flow
  - operating-system

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
# Atlas AI OS - Domain Pipelines

## Canonical Pipeline

```txt
input -> domain -> intent -> profile -> flow -> context -> policy -> decide
-> executor -> execution -> gates -> repair/escalation -> evidence -> learning -> output
```

No mature domain may skip policy, evidence or gates for operational work.

## Programming

Scope: dev, forge, fix, review, refactor, QA, security, tests, database,
release, benchmarks, memory of engineering and code intelligence.

Executors range from simple provider path to dev repair executor and Engineering
Harness. `atlas dev`, `atlas forge`, `atlas fix` and `atlas continue` are
intensities/aliases of this same domain.

## Personal Development

Scope: reflection, daily/weekly review, habits, focus, learning, energy,
objectives and recovery.

Limits: private by default, non-clinical language, no diagnosis, no medical
treatment, no automatic mutation of calendar/tasks/external systems.

## Finance

Scope: market research, risk review, portfolio analysis, thesis review, macro,
earnings, news impact, compliance, backtest plan and finance forge.

Limits: review-only, low autonomy by default, no market orders, no broker
execution, no rebalance/transfer payloads.

## Self-Improvement / Curator

Scope: detect duplication, loose capabilities, docs drift, architecture drift,
process gaps and proposed improvements.

Curator may propose and prepare changes. High-risk changes require gates and
human review before critical behavior changes.

## Resumo

Canonical pipeline shapes for Programming, Personal Development, Finance and Self-Improvement domains.

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
