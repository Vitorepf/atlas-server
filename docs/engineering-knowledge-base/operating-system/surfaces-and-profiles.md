---
id: atlas-ai-os-surfaces-and-profiles
type: engineering_knowledge
title: Atlas AI OS - Surfaces And Profiles
status: active
category: architecture
priority: 100
summary: Surface aliases, canonical Domain/Flow Profiles and Atlas Decide authority for the Atlas AI operating system.
tags:
  - atlas-ai
  - surfaces
  - profiles
capabilities:
  - domain_flow_registry
  - surface_adapter_registry
  - model_selection_contract
decisions:
  - Commands and screens are surfaces only.
  - Domain Profile and Flow Profile prevent commands from becoming separate products.
  - Atlas Decide owns provider/model selection unless the operator makes an audited override.
maintenance:
  - Update when surface aliases, model selection modes or domain catalog fields change.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-operating-system.md
  - app/Services/Ai/Kernel/Surface/SurfaceAdapterRegistry.php
  - app/Services/Ai/Kernel/Domain/AtlasAiDomainCatalogService.php
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-os-surfaces-and-profiles

graph_title: Atlas AI OS - Surfaces And Profiles

graph_world: atlas

graph_layer: module

graph_kind: surface

graph_parent: atlas-ai-canonical-architecture-index

graph_status: active

graph_source: repo

owner: operating-system

repo_paths:
  - docs/engineering-knowledge-base/operating-system/surfaces-and-profiles.md

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
  - docs/engineering-knowledge-base/operating-system/surfaces-and-profiles.md

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: medium

visual_tags:
  - module
  - surface
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
# Atlas AI OS - Surfaces And Profiles

## Surface Rule

Surfaces collect input and render output. They do not own domain logic, provider
selection, memory, gates or repair.

| Surface | Canonical entry |
|---|---|
| `atlas dev` | `atlas_cli_dev` -> `programming.dev` |
| `atlas forge` | `atlas_cli_forge` -> `programming.forge` |
| `atlas fix` | alias to `atlas_cli_dev` -> `programming.repair` |
| `atlas continue` | alias to `atlas_cli_dev` with resume contract |
| `atlas ask/chat` | `atlas_cli_chat` |
| API/app/mobile/voice/MCP | Surface Adapter -> Kernel pipeline |

Aliases preserve UX origin for audit while declaring canonical surface.

## Profiles

```txt
Domain Profile = vertical operational world
Flow Profile   = work mode inside that world
```

Examples:

- `programming.dev`, `programming.forge`, `programming.qa`, `programming.security`;
- `finance.market_research`, `finance.risk_review`;
- `personal_development.daily_review`, `personal_development.focus_plan`;
- `self_improvement.docs_drift_review`, `self_improvement.proposal_generation`.

Catalog authority is `atlas:ai:domains`, `GET /ai/domains` and the MCP/Open
Brain domain catalog tool.

## Atlas Decide

Atlas Decide is not a product flow. It compiles the operational decision:

- domain/flow/profile;
- provider/model and fallback;
- budget/risk/autonomy;
- gates and evidence contract;
- manual override audit.

Provider/model selection modes are closed vocabulary:

- `auto_best_allowed`;
- `auto_best_available`;
- `manual_override`.

Surfaces may pass operator preference; they do not decide model authority.

## Resumo

Surface aliases, canonical Domain/Flow Profiles and Atlas Decide authority for the Atlas AI operating system.

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
