---
id: atlas-ai-research-self-improvement-metrics-and-evals
type: engineering_knowledge
title: Atlas AI Research Self-Improvement Metrics And Evals
status: active
category: evaluation
priority: 99
summary: Metrics and evals for proving research quality, evolution velocity, long-session impact and self-improvement safety.
tags:
  - atlas-ai
  - metrics
  - evals
  - research-quality
capabilities:
  - research_quality_eval
  - evolution_velocity_metrics
  - self_improvement_eval
decisions:
  - Atlas cannot claim better evolution without metrics.
  - Quality metrics must include source quality, recall, safety, cost and rework.
maintenance:
  - Update when Observability, Evidence Ledger or Self-Improvement exposes these metrics as read models.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-research-self-improvement-runtime.md
  - docs/engineering-knowledge-base/research-self-improvement/schemas-and-packets.md
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-research-self-improvement-metrics-and-evals

graph_title: Atlas AI Research Self-Improvement Metrics And Evals

graph_world: atlas

graph_layer: module

graph_kind: module

graph_parent: atlas-ai-canonical-architecture-index

graph_status: active

graph_source: repo
human_name: Atlas AI Research Self-Improvement Metrics And Evals
canonical_name: Atlas AI Research Self-Improvement Metrics And Evals
technical_name: atlas-ai-research-self-improvement-metrics-and-evals
cartography_type: module
canonical_source: docs/engineering-knowledge-base/research-self-improvement/metrics-and-evals.md

owner: research-self-improvement

repo_paths:
  - docs/engineering-knowledge-base/research-self-improvement/metrics-and-evals.md

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
  - docs/engineering-knowledge-base/research-self-improvement/metrics-and-evals.md

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: low

visual_tags:
  - module
  - module
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
# Atlas AI Research Self-Improvement Metrics And Evals

## Research Quality Metrics

| Metric | Target |
|---|---|
| Primary-source ratio | >= 80% for critical claims |
| Citation coverage | 100% for factual claims |
| Hallucinated-source rate | 0 |
| Stale-source rate | Explicit and trending down |
| Contradiction detection | Conflicts recorded before promotion |
| Uncertainty preservation | Unknowns remain visible |
| Scope coverage | Required subquestions addressed or marked unknown |
| Abstention correctness | No unsupported answer when evidence is insufficient |

## Evolution Velocity Metrics

| Metric | Meaning |
|---|---|
| research_to_doc_latency | Time from strong finding to canonical doc/AP |
| doc_to_plan_latency | Time from doc law to scoped block plan |
| plan_to_validated_block_latency | Time from plan to passing validation |
| weak_research_rework_rate | Rework caused by poor source quality |
| promotion_throughput | Validated improvements promoted per week |

## Self-Improvement Safety Metrics

- proposal acceptance rate;
- false-positive proposal rate;
- auto-apply attempt blocks;
- rollback rate;
- post-promotion regression count;
- policy/runtime bypass attempts blocked;
- memory contamination findings.

## Efficiency And Robustness Metrics

- cost per report;
- latency per report;
- sources read per minute;
- tokens per verified claim;
- cache hit rate;
- duplicate source rate;
- crawler/API failure rate;
- dead URL rate;
- changed page rate;
- prompt injection detection rate;
- agent divergence rate.

## External Eval References

Atlas should model internal evals after:

- browsing/retrieval persistence benchmarks such as BrowseComp-like tasks;
- deep research report benchmarks such as DeepResearch-Bench-like tasks;
- atomic factuality scoring inspired by FActScore-like evaluation;
- research-and-revise loops inspired by RARR-like correction;
- citation accuracy and URL liveness evals;
- internal Atlas research tasks tied to docs/AP/code outcomes.

## Long-Session Impact Metrics

Research/self-improvement must improve Cognitive Runtime:

- fewer repeated decisions;
- lower context drift;
- higher recall of active decisions;
- lower compaction loss;
- faster recovery after handoff;
- stable quality across 72h target sessions.

## Minimum Eval Suite

1. Source hallucination eval: every cited source must resolve or map to repo.
2. Claim support eval: each critical claim must map to source evidence.
3. Conflict eval: contradictory sources must be surfaced.
4. Promotion eval: docs/AP target must match authority map.
5. Implementation eval: hot files and forbidden changes must be honored.
6. Self-improvement eval: proposal must remain proposal-only until gate.
7. Regression eval: promoted changes must not reduce docs-health or architecture validation.

## Reporting Shape

```json
{
  "schema_version": "atlas.research_eval_report.v1",
  "status": "pass|warn|fail",
  "research_quality": {},
  "evolution_velocity": {},
  "self_improvement_safety": {},
  "long_session_impact": {},
  "blocking_findings": []
}
```

## Resumo

Metrics and evals for proving research quality, evolution velocity, long-session impact and self-improvement safety.

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
