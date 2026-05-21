---
id: atlas-ai-self-construction-autonomous-implementation-loop
type: engineering_knowledge
title: Atlas Self-Construction Autonomous Implementation Loop
status: active
category: architecture
priority: 100
summary: Governed loop for Atlas researching, documenting, specifying, implementing, validating and improving itself.
tags:
  - atlas-ai
  - self-construction
  - autonomous-loop
capabilities:
  - autonomous_implementation_loop
  - self_construction_autonomous_implementation_loop
decisions:
  - Autonomous construction is a loop with gates, not an open-ended coding session.
  - Every loop stage must emit an artifact or evidence.
maintenance:
  - Update before implementing the runtime loop or adding autonomous scheduler behavior.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-self-construction-os.md
  - docs/engineering-knowledge-base/self-construction/self-programming-safety-contract.md
owner: atlas-ai
layer: 0.8-self-construction
line_limit: 220
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-self-construction-autonomous-implementation-loop

graph_title: Atlas Self-Construction Autonomous Implementation Loop

graph_world: atlas

graph_layer: gear

graph_kind: module

graph_parent: atlas-ai-self-construction-os

graph_status: active

graph_source: repo
human_name: Atlas Self-Construction Autonomous Implementation Loop
canonical_name: Atlas Self-Construction Autonomous Implementation Loop
technical_name: atlas-ai-self-construction-autonomous-implementation-loop
cartography_type: module
canonical_source: docs/engineering-knowledge-base/self-construction/autonomous-implementation-loop.md

repo_paths:
  - docs/engineering-knowledge-base/self-construction/autonomous-implementation-loop.md

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
  - self-construction

evidence:
  - docs/engineering-knowledge-base/self-construction/autonomous-implementation-loop.md

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: high

visual_tags:
  - gear
  - module
  - self-construction

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
# Atlas Self-Construction Autonomous Implementation Loop

The loop is the operational heart of governed self-programming.

## Loop Stages

```text
1. Observe
2. Diagnose
3. Research
4. Document
5. Specify
6. Plan
7. Decide
8. Execute
9. Validate
10. Evidence
11. Drift Check
12. Learn
13. Report
```

## Stage Contracts

| Stage | Output |
|---|---|
| Observe | gap, metric, failure, user request or roadmap item |
| Diagnose | layer, capability, maturity and risk |
| Research | source-backed findings or "research not needed" reason |
| Document | canonical doc/AP update when durable |
| Specify | Meta-SDD spec and acceptance criteria |
| Plan | technical plan and task list |
| Decide | Decision Receipt with scope, gates and rollback |
| Execute | patch or no-op with reason |
| Validate | tests, docs-health, architecture validation, scans |
| Evidence | diff, command output, traceability, residual risk |
| Drift Check | spec/code/doc mismatch report |
| Learn | proposal, not silent mutation |
| Report | concise human-readable closeout |

## Stop Conditions

Stop and ask/review when:

- target context is missing;
- risk is high and no human gate exists;
- gates are unavailable;
- rollback is impossible;
- spec conflicts with canonical docs;
- implementation touches forbidden files;
- validation fails outside receipt scope;
- research sources conflict.

## Small Slice Rule

The loop must prefer the smallest block that improves maturity.

Bad:

```text
Implement all self-programming runtime.
```

Good:

```text
Implement read-only self-construction gap report with tests and docs.
```

## Loop Receipt Requirements

Each autonomous loop execution needs:

```yaml
loop_receipt:
  operation_id:
  target_capability:
  maturity_delta:
  allowed_files:
  allowed_commands:
  required_gates:
  rollback:
  evidence:
  max_scope:
```

## Learning Rule

The loop may generate learning proposals such as:

- update SDD template;
- add a new gate;
- improve source scoring;
- update priority weights;
- add common failure pattern.

It may not apply critical learning automatically.

## Resumo

Governed loop for Atlas researching, documenting, specifying, implementing, validating and improving itself.

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
