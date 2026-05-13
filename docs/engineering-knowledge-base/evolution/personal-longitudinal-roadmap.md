---
id: atlas-ai-evolution-personal-longitudinal-roadmap
type: engineering_knowledge
title: Personal Longitudinal Intelligence Roadmap
status: active
category: roadmap
priority: 92
summary: Roadmap for privacy-governed personal memory, life timeline, body-cognition signals and long-horizon Atlas-Vitor intelligence.
tags:
  - atlas-ai
  - personal-memory
  - longitudinal
  - privacy
capabilities:
  - personal_longitudinal_memory
  - atlasvault_sync
  - privacy_governance
decisions:
  - Personal memory is high sensitivity and local-first by default.
  - AtlasVault is a human knowledge surface, not raw operational truth.
  - Body, health and cognition signals require explicit policy and retention.
maintenance:
  - Keep personal data features tied to privacy classes and forgetting protocol.
  - Do not send raw personal memory to providers without redaction.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-atlasvault-obsidian.md
  - docs/engineering-knowledge-base/domains/personal-development.md
  - docs/engineering-knowledge-base/cognitive/README.md
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-evolution-personal-longitudinal-roadmap

graph_title: Personal Longitudinal Intelligence Roadmap

graph_world: atlas

graph_layer: flow

graph_kind: module

graph_parent: atlas-ai-pipeline

graph_status: active

graph_source: repo

owner: evolution

repo_paths:
  - docs/engineering-knowledge-base/evolution/personal-longitudinal-roadmap.md

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
  - evolution

evidence:
  - docs/engineering-knowledge-base/evolution/personal-longitudinal-roadmap.md

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: low

visual_tags:
  - flow
  - module
  - evolution

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
# Personal Longitudinal Intelligence Roadmap

## Purpose

Atlas should become useful over years, not just per prompt. Longitudinal memory
lets it detect patterns in decisions, energy, learning, projects, companies and
strategy while preserving Vitor's autonomy.

## Memory Classes

| Class | Examples | Default |
|---|---|---|
| Work pattern | productive hours, recurring blockers | local projection |
| Cognitive pattern | learning friction, failure signatures | local projection |
| Health signal | sleep, NSDR, recovery | explicit opt-in |
| Personal values | goals, identity, boundaries | human-reviewed |
| Sensitive raw data | audio, private notes, biometric data | do not persist raw |

## AtlasVault Role

AtlasVault/Obsidian is the human knowledge workspace. It is powerful because it
supports reflection, identity, synthesis and manual review. It is not the raw
operational database. Sync events and curated notes may feed the Evidence Ledger
or memory projections through managed contracts.

## Curator Role

Curator may propose:

1. repeated life patterns;
2. schedule and recovery adjustments;
3. cognitive development gaps;
4. knowledge decay;
5. high-leverage review items.

Curator does not auto-change calendar, health plan, identity documents or active
curriculum without policy and human review.

## Resumo

Roadmap for privacy-governed personal memory, life timeline, body-cognition signals and long-horizon Atlas-Vitor intelligence.

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
