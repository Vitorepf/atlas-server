---
id: atlas-ai-research-self-improvement-failure-modes
type: engineering_knowledge
title: Atlas AI Research Self-Improvement Failure Modes
status: active
category: safety
priority: 98
summary: Failure taxonomy for research, documentation promotion and self-improvement automation.
tags:
  - atlas-ai
  - research
  - failure-modes
  - safety
capabilities:
  - research_failure_detection
  - self_improvement_safety
decisions:
  - Research and self-improvement failures must fail closed.
  - Hallucinated source is a stop-the-line condition.
maintenance:
  - Update when source verifier, crawler, evaluator or Curator promotion changes.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-research-self-improvement-runtime.md
  - docs/engineering-knowledge-base/research-self-improvement/source-quality-and-trust-ladder.md
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-research-self-improvement-failure-modes

graph_title: Atlas AI Research Self-Improvement Failure Modes

graph_world: atlas

graph_layer: module

graph_kind: module

graph_parent: atlas-ai-canonical-architecture-index

graph_status: active

graph_source: repo
human_name: Atlas AI Research Self-Improvement Failure Modes
canonical_name: Atlas AI Research Self-Improvement Failure Modes
technical_name: atlas-ai-research-self-improvement-failure-modes
cartography_type: module
canonical_source: docs/engineering-knowledge-base/research-self-improvement/failure-modes.md

owner: research-self-improvement

repo_paths:
  - docs/engineering-knowledge-base/research-self-improvement/failure-modes.md

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
  - docs/engineering-knowledge-base/research-self-improvement/failure-modes.md

evidence_refs:
  - symbol: AtlasResearchFailureModesService
  - command: atlas:aaeos:atlas-research-failure-modes
  - test: AtlasResearchFailureModesTest

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
# Atlas AI Research Self-Improvement Failure Modes

## Failure Table

| Failure | Risk | Required response |
|---|---|---|
| Hallucinated source | False truth enters Atlas | Stop, mark packet invalid, require source verification. |
| Hype-driven release | Provider marketing becomes roadmap | Route through Provider Evolution and benchmark. |
| Secondary source treated primary | Weak evidence | Downgrade tier, require primary source. |
| Research without docs | Context lost | Promote to owner doc or archive. |
| Docs without AP/plan | Unbounded implementation | Create AP/plan before code. |
| Proposal auto-applied | Unsafe autonomy | Block, require review gate. |
| Memory polluted by weak claim | Long-term degradation | Revoke memory, trace source, add guardrail. |
| Benchmark missing | Improvement unproven | Hold promotion. |
| Contradiction ignored | Bad decisions | Add conflict record and research more. |
| Automation has no rollback | Enterprise risk | Refuse promotion. |

## Stop-The-Line Conditions

- invented citation;
- source unavailable and claim is critical;
- runtime/policy change requested by research output only;
- memory write from Tier 4 or Tier 5 source;
- implementation touches Kernel/Policy/Receipt/Ledger without AP;
- validation cannot be run and risk is medium/high.

## Recovery

1. Preserve raw evidence.
2. Mark invalid packet or proposal.
3. Identify contaminated docs/memory/code.
4. Roll back or supersede.
5. Add guardrail/test.
6. Record Self-Improvement finding.

## Resumo

Failure taxonomy for research, documentation promotion and self-improvement automation.

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
