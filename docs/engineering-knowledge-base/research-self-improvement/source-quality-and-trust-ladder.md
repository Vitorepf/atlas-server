---
id: atlas-ai-research-source-quality-trust-ladder
type: engineering_knowledge
title: Atlas AI Research Source Quality And Trust Ladder
status: active
category: research
priority: 99
summary: Trust ladder for turning external or internal information into Atlas research evidence without hallucination or hype.
tags:
  - atlas-ai
  - research
  - source-quality
  - anti-hallucination
capabilities:
  - source_quality_gate
  - research_evidence
  - anti_hallucination
decisions:
  - Source tier must be explicit before a claim can influence docs, APs, memory or code.
  - LLM output is hypothesis-only unless backed by source evidence or repo evidence.
  - Community content can point to a lead, but cannot become canonical proof alone.
maintenance:
  - Update when Atlas adds source registry, crawler, citation verifier or source reputation scoring.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-research-self-improvement-runtime.md
  - docs/engineering-knowledge-base/atlas-ai-content-intelligence-curation.md
  - docs/engineering-knowledge-base/atlas-ai-provider-evolution-intelligence.md
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-research-source-quality-trust-ladder

graph_title: Atlas AI Research Source Quality And Trust Ladder

graph_world: atlas

graph_layer: module

graph_kind: module

graph_parent: atlas-ai-canonical-architecture-index

graph_status: active

graph_source: repo

owner: research-self-improvement

repo_paths:
  - docs/engineering-knowledge-base/research-self-improvement/source-quality-and-trust-ladder.md

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
  - docs/engineering-knowledge-base/research-self-improvement/source-quality-and-trust-ladder.md

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
# Atlas AI Research Source Quality And Trust Ladder

Every research claim must carry a source tier.

## Trust Tiers

| Tier | Source class | Allowed use |
|---|---|---|
| 0 | Local Atlas code, tests, canonical docs, Evidence Ledger, receipts | Can define current truth. |
| 1 | Official provider docs, standards, specs, release notes, API docs | Can define external capability truth. |
| 2 | Peer-reviewed papers, serious benchmarks, reproducible evals | Can guide architecture and metrics. |
| 3 | High-quality engineering blogs, postmortems, open-source code | Can guide design when evidence is clear. |
| 4 | Community threads, social posts, videos, newsletters | Lead only; require promotion evidence. |
| 5 | LLM answer, uncited summary, rumor, marketing claim | Hypothesis only; never canonical alone. |

## Required Source Fields

- `source_id`
- `url_or_repo_path`
- `title`
- `publisher_or_owner`
- `retrieved_at`
- `tier`
- `claim_supported`
- `evidence_excerpt_or_pointer`
- `known_limits`
- `staleness_risk`
- `atlas_impact`

## Promotion Rules

- Tier 0 can update docs when the repo evidence is inspected.
- Tier 1 can create provider release envelope or official capability entry.
- Tier 2 can create architecture guidance or benchmark requirement.
- Tier 3 can create design candidate or AP proposal.
- Tier 4 can create research task only.
- Tier 5 can create question only.

## Source Scoring Formula

Source score must be explicit and reproducible:

```text
source_score =
  authority
+ primary_source_proximity
+ recency
+ reliability_history
+ methodological_transparency
+ reproducibility
+ data_or_code_presence
+ cross_source_consistency
- conflict_of_interest
- missing_evidence
- promotional_language
- missing_date
- dead_link
```

Default ranking:

| Source | Weight |
|---|---|
| Official docs, changelog or standard | very_high |
| Paper with code and reproducible benchmark | very_high |
| Official GitHub release/advisory | very_high |
| Creator technical post with data | high |
| Company engineering blog with method/data | medium_high |
| News article with primary documents | medium |
| Official conference/talk video | medium |
| Researcher social post with primary link | low_medium |
| Unsourced thread | low |
| SEO/generic content without source | very_low |

## Recent News Rule

Recent news requires stronger confirmation:

1. Find original source.
2. Find independent confirmation.
3. Verify date and timezone.
4. Check update/correction history.
5. Check whether primary documents are linked.
6. Avoid strong conclusion before primary source.

## Anti-Hallucination Gate

A research packet fails if:

- a source URL is invented;
- source type is unknown;
- claim has no source pointer;
- a secondary source is treated as primary;
- freshness matters but timestamp is missing;
- source conflict is ignored;
- LLM text is treated as factual proof.

Failing packets may be archived as leads. They cannot become memory truth,
policy, routing, docs law or implementation.

## Resumo

Trust ladder for turning external or internal information into Atlas research evidence without hallucination or hype.

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
