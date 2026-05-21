---
id: atlas-ai-research-intelligence-pipeline
type: engineering_knowledge
title: Atlas AI Research Intelligence Pipeline
status: active
category: research
priority: 99
summary: Canonical pipeline for long, high-quality research that feeds Atlas documentation, planning and implementation.
tags:
  - atlas-ai
  - research-pipeline
  - evidence-synthesis
capabilities:
  - research_intelligence_runtime
  - evidence_synthesis
  - research_packet
decisions:
  - Research must be packetized before it changes docs, APs, memory or runtime.
  - The pipeline must preserve uncertainty and conflicting evidence.
maintenance:
  - Update when research scheduler, source registry or evaluator commands exist.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-research-self-improvement-runtime.md
  - docs/engineering-knowledge-base/research-self-improvement/source-quality-and-trust-ladder.md
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-research-intelligence-pipeline

graph_title: Atlas AI Research Intelligence Pipeline

graph_world: atlas

graph_layer: flow

graph_kind: flow

graph_parent: atlas-ai-pipeline

graph_status: active

graph_source: repo
human_name: Atlas AI Research Intelligence Pipeline
canonical_name: Atlas AI Research Intelligence Pipeline
technical_name: atlas-ai-research-intelligence-pipeline
cartography_type: flow
canonical_source: docs/engineering-knowledge-base/research-self-improvement/research-pipeline.md

owner: research-self-improvement

repo_paths:
  - docs/engineering-knowledge-base/research-self-improvement/research-pipeline.md

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
  - docs/engineering-knowledge-base/research-self-improvement/research-pipeline.md

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: low

visual_tags:
  - flow
  - flow
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
# Atlas AI Research Intelligence Pipeline

Research is a production pipeline, not casual browsing.

## Pipeline

```text
1. Define objective
2. Discover source candidates
3. Classify source tier
4. Extract claims and limits
5. Detect conflicts
6. Synthesize Atlas impact
7. Create research packet
8. Decide promote / hold / archive / research more
```

## Research Packet

```json
{
  "schema_version": "atlas.research_packet.v1",
  "objective": "string",
  "question": "string",
  "sources": [],
  "claims": [],
  "conflicts": [],
  "uncertainties": [],
  "atlas_impact": [],
  "recommended_action": "promote_to_doc|create_ap|benchmark|archive|research_more",
  "forbidden_actions": [],
  "created_at": "datetime"
}
```

## Discovery Strategy

Use a balanced source mix:

- repo evidence and existing docs first;
- official docs/specs for external capability;
- papers and benchmarks for state of art;
- engineering postmortems for operational lessons;
- community only as discovery lead.

## Output Quality

Good research output is:

- specific;
- source-backed;
- conflict-aware;
- time-aware;
- actionable;
- bounded by what cannot be concluded.

Bad research output is:

- generic;
- citation-free;
- hype-driven;
- implementation-first;
- blind to uncertainty;
- detached from Atlas docs and code.

## Resumo

Canonical pipeline for long, high-quality research that feeds Atlas documentation, planning and implementation.

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
