---
id: atlas-programming-tool-backlog
type: engineering_knowledge
title: Atlas Programming Tool Backlog
status: active
category: tool_runtime
priority: 85
summary: Implementation backlog for making programming tools fully usable in Atlas automation.
tags:
  - atlas
  - tools
  - backlog
capabilities:
  - programming_power_tools_catalog
decisions:
  - Tool backlog is promoted through recipes, normalizers, gates and UX, never by ad hoc command execution.
maintenance:
  - Update after a tool recipe, normalizer or gate becomes implemented.
related_paths:
  - docs/engineering-knowledge-base/programming-power-tools-catalog.md
  - docs/engineering-knowledge-base/tool-runtime/evidence-gates.md
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-programming-tool-backlog

graph_title: Atlas Programming Tool Backlog

graph_world: atlas

graph_layer: module

graph_kind: module

graph_parent: atlas-ai-canonical-architecture-index

graph_status: active

graph_source: repo

owner: tool-runtime

repo_paths:
  - docs/engineering-knowledge-base/tool-runtime/programming-tool-backlog.md

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
  - tool-runtime

evidence:
  - docs/engineering-knowledge-base/tool-runtime/programming-tool-backlog.md

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: high

visual_tags:
  - module
  - module
  - tool-runtime

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
# Atlas Programming Tool Backlog

## P0 - Real Cheap Recipes

Priority is local, cheap, high-signal recipes that improve programming feedback:

- lint/typecheck recipes;
- secret/dependency scan recipes;
- SBOM/release recipes;
- Docker/IaC recipes;
- fast code search and context recipes.

## P1 - Specific Normalizers

Promote generic output into structured findings for high-volume tools:

- severity;
- file/line;
- fingerprint;
- rule id;
- authority group;
- repair hint;
- artifact refs.

## P2 - Gates By Authority

Gates should use the primary tool per authority group and suppress duplicate
findings from complementary tools when appropriate.

## P3 - Operational UX

App/CLI should expose:

- findings by authority group;
- stale tool evidence;
- missing tool evidence;
- repair hints;
- waiver workflow;
- links from evidence to code and docs.

## Maintenance Commands

```bash
atlas tools authority --json
atlas tools commands <tool> --json
atlas tools evidence --recipe=<recipe>
atlas tools doctor --workspace=/Users/vitorepf/develop/Atlas/atlas-server
```

## Resumo

Implementation backlog for making programming tools fully usable in Atlas automation.

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
