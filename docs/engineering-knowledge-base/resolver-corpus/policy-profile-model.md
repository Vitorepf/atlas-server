---
id: atlas-ai-resolver-corpus-policy-profile-model
type: engineering_knowledge
title: Atlas AI Resolver Corpus Policy Profile Model
status: active
category: architecture
priority: 86
summary: Compact resolver-derived model for Domain Profile, Flow Profile, Policy/Profile and Atlas Decide.
tags:
  - atlas-ai
  - policy-profile
  - atlas-decide
capabilities:
  - policy_profile_architecture
  - decision_receipt_governance
decisions:
  - Domain, flow, policy and model selection are separate layers.
maintenance:
  - Keep aligned with Kernel, Operating System and Model Selection Strategy.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-resolver-corpus-audit.md
  - docs/engineering-knowledge-base/atlas-ai-model-selection-strategy.md
  - docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-resolver-corpus-policy-profile-model

graph_title: Atlas AI Resolver Corpus Policy Profile Model

graph_world: atlas

graph_layer: system

graph_kind: policy

graph_parent: atlas-ai-canonical-architecture-index

graph_status: active

graph_source: repo

owner: resolver-corpus

repo_paths:
  - docs/engineering-knowledge-base/resolver-corpus/policy-profile-model.md

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
  - resolver-corpus

evidence:
  - docs/engineering-knowledge-base/resolver-corpus/policy-profile-model.md

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: medium

visual_tags:
  - system
  - policy
  - resolver-corpus

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
# Atlas AI Resolver Corpus Policy Profile Model

## Layering

```text
Atlas AI Core
-> Domain Profile
-> Flow Profile
-> Policy/Profile
-> Atlas Decide
-> Domain Orchestrator
-> Runtime / Executor
```

## Definitions

| Term | Meaning |
|---|---|
| Domain | Cognitive/operational vertical such as Programming, Finance or Learning. |
| Flow | Specific operational process inside a domain, such as `programming.dev`. |
| Profile | Layered policy bundle for domain, flow, surface, risk and session. |
| Model selection | Decision made by Atlas Decide under policy, not by the surface. |
| Decision Receipt | Signed/auditable contract authorizing runtime execution. |

## Examples

- `atlas dev` enters `programming.dev`.
- `atlas forge` enters `programming.forge`.
- `atlas fix` enters a Programming repair flow.
- Finance review uses Finance domain policy and never auto-executes trades.

## Invariant

Manual model override is allowed, but it is an audited override. It does not make
the provider or model the owner of the flow.

## Resumo

Compact resolver-derived model for Domain Profile, Flow Profile, Policy/Profile and Atlas Decide.

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
