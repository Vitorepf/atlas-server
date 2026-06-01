---
id: atlas-ai-master-domain-onboarding
type: engineering_knowledge
title: Master Architecture Domain Onboarding
status: active
category: architecture
priority: 99
summary: Enterprise onboarding protocol for adding complex Atlas domains without duplication or scope drift.
tags:
  - atlas-ai
  - domains
  - onboarding
capabilities:
  - domain_profile_orchestration
  - domain_manifest_sdk
decisions:
  - A domain is a cognitive/operational vertical, not a company or project.
  - Businesses such as Blackink are Business Context, not domains by default.
  - Domain onboarding requires gates, evidence, memory and surface integration.
maintenance:
  - Keep new domain specs linked from domains/README.md.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-master-architecture.md
  - docs/engineering-knowledge-base/domains/README.md
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-master-domain-onboarding

graph_title: Master Architecture Domain Onboarding

graph_world: atlas

graph_layer: system

graph_kind: module

graph_parent: atlas-ai-canonical-architecture-index

graph_status: active

graph_source: repo
human_name: Master Architecture Domain Onboarding
canonical_name: Master Architecture Domain Onboarding
technical_name: atlas-ai-master-domain-onboarding
cartography_type: module
canonical_source: docs/engineering-knowledge-base/master-architecture/domain-onboarding.md

owner: master-architecture

repo_paths:
  - docs/engineering-knowledge-base/master-architecture/domain-onboarding.md

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
  - master-architecture

evidence:
  - docs/engineering-knowledge-base/master-architecture/domain-onboarding.md
evidence_refs:
  - symbol: AtlasDomainOnboardingService
  - command: atlas:aaeos:domain-onboarding
  - test: AtlasDomainOnboardingTest

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: medium

visual_tags:
  - system
  - module
  - master-architecture

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
# Domain Onboarding

## Domain Test

A new domain is valid when it has distinct:

1. intents and flows;
2. context model;
3. specialist profiles;
4. tools or runtimes;
5. gates and evidence;
6. memory projection;
7. learning loop.

If it is only a customer, company, project or product, it is Business Context.

## Onboarding Phases

| Phase | Output |
|---|---|
| 0 Charter | purpose, scope, non-goals |
| 1 Profile Catalog | specialists, risk, autonomy |
| 2 Context Model | required sources, memory, documents |
| 3 Orchestrator | flows and planning contract |
| 4 Runtime/Tools | allowed runtimes and tools |
| 5 Gates/Evidence | quality, safety, audit |
| 6 Learning | memory promotion and calibration |
| 7 Surface Integration | CLI/App/Mobile/API/MCP/Voice behavior |
| 8 Maturity Gate | scaffold, pilot, ready, enterprise |

## Current Domain Families

Programming, Finance, Marketing, Cognitive/Learning, Personal Development,
Research, Writing, Health and Self-Improvement/Curator are valid domain
families. Companies and products attach as context.

## Resumo

Enterprise onboarding protocol for adding complex Atlas domains without duplication or scope drift.

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
