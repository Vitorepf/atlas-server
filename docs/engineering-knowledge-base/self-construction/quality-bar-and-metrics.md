---
id: atlas-ai-self-construction-quality-bar-and-metrics
type: engineering_knowledge
title: Atlas Self-Construction Quality Bar And Metrics
status: active
category: architecture
priority: 99
summary: Measurable quality bar for absurd-level Atlas self-construction.
tags:
  - atlas-ai
  - self-construction
  - quality
  - metrics
capabilities:
  - self_construction_quality_bar_and_metrics
  - quality_metrics
decisions:
  - "Absurd level" must be measured as behavior, not declared as ambition.
  - Atlas quality is judged by continuity, correctness, evidence, autonomy safety and compounding improvement.
maintenance:
  - Update when adding dashboards, eval harnesses or maturity scorecards.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-self-construction-os.md
  - docs/engineering-knowledge-base/cognitive-runtime/enterprise-excellence-checklist.md
owner: atlas-ai
layer: 0.8-self-construction
line_limit: 220
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-self-construction-quality-bar-and-metrics

graph_title: Atlas Self-Construction Quality Bar And Metrics

graph_world: atlas

graph_layer: gear

graph_kind: module

graph_parent: atlas-ai-self-construction-os

graph_status: active

graph_source: repo
human_name: Atlas Self-Construction Quality Bar And Metrics
canonical_name: Atlas Self-Construction Quality Bar And Metrics
technical_name: atlas-ai-self-construction-quality-bar-and-metrics
cartography_type: module
canonical_source: docs/engineering-knowledge-base/self-construction/quality-bar-and-metrics.md

repo_paths:
  - docs/engineering-knowledge-base/self-construction/quality-bar-and-metrics.md

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
  - docs/engineering-knowledge-base/self-construction/quality-bar-and-metrics.md
evidence_refs:
  - symbol: AtlasQualityBarAndMetricsService
  - command: atlas:aaeos:quality-bar-and-metrics
  - test: AtlasQualityBarAndMetricsTest

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
# Atlas Self-Construction Quality Bar And Metrics

"Most powerful" must become measurable.

## Absurd-Level Quality Bar

Atlas self-construction should:

- choose high-leverage work;
- preserve canonical law;
- avoid repeated work;
- remember active decisions;
- retrieve the right context;
- ask only when ambiguity matters;
- implement small safe slices;
- validate before claiming success;
- record evidence;
- detect drift;
- learn through proposals;
- resume after compaction without losing invariants.

## Metrics

| Dimension | Metric |
|---|---|
| Context | relevant context precision, stale context rate, missing-doc rate |
| SDD | spec completeness, assumption quality, acceptance coverage |
| Execution | gate pass rate, repair count, rollback readiness |
| Evidence | requirements with proof, citation/evidence health |
| Drift | spec/code/test mismatch count |
| Continuity | handoff success, repeated decision rate, long-session degradation |
| Safety | blocked unsafe actions, forbidden-scope attempts |
| Priority | high-leverage task selection accuracy |
| Learning | proposals accepted, proposals rejected, unsafe proposal rate |

## Quality Gates

Self-construction gates:

```text
docs-health
architecture-validate
knowledge sync
code index
focused tests
static scans
receipt scope check
traceability check
drift check
diff check
```

## Definition Of Done

A self-construction block is done when:

- docs/AP/spec exist;
- code change, if any, matches spec;
- focused tests pass;
- architecture/doc gates pass;
- evidence is append-only;
- drift is checked;
- maturity delta is stated;
- residual risk is explicit.

## Score Bands

| Score | Meaning |
|---|---|
| 0-3 | ad hoc coding |
| 4-5 | documented but weakly validated |
| 6-7 | governed and testable |
| 8-9 | agent-executable with strong evidence |
| 9+ | self-improving, drift-aware and strategically prioritized |

No score above 9 is valid without repeated autonomous runs and low drift.

## Resumo

Measurable quality bar for absurd-level Atlas self-construction.

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
