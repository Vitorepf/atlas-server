---
id: atlas-ai-cyber-recipes-promotion-runbook
type: engineering_knowledge
title: Atlas AI Cyber Recipes Promotion Runbook
status: building
category: knowledge-base
priority: 80
implementation_state: cyber_security_scaffold_not_runtime_promoted
summary: Operational checklist for promoting an individual cyber recipe into the canonical Super Tool Runtime.
tags:
  - atlas-ai
  - cyber-security
  - runbook
capabilities:
  - cyber_recipes_proposal
  - tool_runtime_registry
decisions:
  - A cyber recipe becomes real only after registry entry, wrapper, sandbox profile, tests and evidence validation.
maintenance:
  - Keep commands aligned with Super Tool Runtime CLI.
related_paths:
  - docs/engineering-knowledge-base/cyber-security/recipes-catalog.md
  - docs/engineering-knowledge-base/super-tool-runtime-core.md
  - docs/engineering-knowledge-base/tool-runtime/contracts.md
  - docs/engineering-knowledge-base/cyber-security/refusal-matrix.md
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-cyber-recipes-promotion-runbook

graph_title: Atlas AI Cyber Recipes Promotion Runbook

graph_world: atlas

graph_layer: module

graph_kind: runbook

graph_parent: atlas-ai-canonical-architecture-index

graph_status: building

graph_source: repo

owner: cyber-security

repo_paths:
  - docs/engineering-knowledge-base/cyber-security/recipes-promotion-runbook.md

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
  - cyber-security

evidence:
  - docs/engineering-knowledge-base/cyber-security/recipes-promotion-runbook.md

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: high

visual_tags:
  - module
  - runbook
  - cyber-security

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
# Atlas AI Cyber Recipes Promotion Runbook

## Promotion Steps

1. Confirm the tool does not already exist:

```bash
atlas tools list | grep <tool>
```

2. Create migration or seeder entry for `atlas_tool_definitions`.
3. Add recipe metadata with dry-run, sandbox, privacy, task type and authority group.
4. Implement wrapper class under the canonical Tool Runtime service area.
5. Add sandbox profile.
6. Add feature/unit tests for argv rendering, refusal/scope gates and evidence emission.
7. Run tool doctor and dry-run.
8. Update docs and registry references.

## Required Gates

| Gate | Requirement |
|---|---|
| Scope proof | Target must match approved scope. |
| Refusal matrix | Unsafe or unauthorized activity blocks before execution. |
| Decision Receipt | Runtime refuses without valid receipt. |
| Sandbox | Offensive recipes require sandbox. |
| Evidence | Run creates normalized evidence and ledger event. |
| Approval | Active exploit, C2, distributed scan and external MCP require extra approval. |

## Validation Commands

```bash
php artisan migrate
php artisan test tests/Feature/Ai/Tools
atlas tools doctor
atlas tools run-recipe <tool> --recipe=<name> --dry-run
php artisan atlas:ai:architecture-validate --json
atlas engineering knowledge docs-health --json
```

## Definition Of Done

- Registry entry exists.
- Wrapper renders argv safely.
- Scope/refusal tests pass.
- Sandbox profile exists.
- Evidence is normalized.
- Docs link to the recipe owner.
- No direct provider or raw MCP bypass exists.

## Resumo

Operational checklist for promoting an individual cyber recipe into the canonical Super Tool Runtime.

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
