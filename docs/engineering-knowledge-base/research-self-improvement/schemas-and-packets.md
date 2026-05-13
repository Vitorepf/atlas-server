---
id: atlas-ai-research-self-improvement-schemas-and-packets
type: engineering_knowledge
title: Atlas AI Research Self-Improvement Schemas And Packets
status: active
category: contracts
priority: 99
summary: Executable packet contracts for research, source judgment, documentation promotion, implementation plan and self-improvement proposal.
tags:
  - atlas-ai
  - research
  - schemas
  - packets
capabilities:
  - research_packet_schema
  - source_judgment_schema
  - improvement_proposal_schema
decisions:
  - Runtime implementation must use typed packets before background automation.
  - Packets are evidence carriers, not permission to mutate Atlas.
maintenance:
  - Update before adding migrations, DTOs, API resources, commands or queue jobs for this runtime.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-research-self-improvement-runtime.md
  - docs/ap/AP-689-research-self-improvement-runtime-contract.md
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-research-self-improvement-schemas-and-packets

graph_title: Atlas AI Research Self-Improvement Schemas And Packets

graph_world: atlas

graph_layer: module

graph_kind: contract

graph_parent: atlas-ai-canonical-architecture-index

graph_status: active

graph_source: repo

owner: research-self-improvement

repo_paths:
  - docs/engineering-knowledge-base/research-self-improvement/schemas-and-packets.md

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
  - docs/engineering-knowledge-base/research-self-improvement/schemas-and-packets.md

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: low

visual_tags:
  - module
  - contract
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
# Atlas AI Research Self-Improvement Schemas And Packets

## Research Packet

```json
{
  "schema_version": "atlas.research_packet.v1",
  "packet_id": "uuid",
  "objective": "string",
  "question": "string",
  "source_ids": [],
  "claim_ids": [],
  "conflict_ids": [],
  "uncertainty_ids": [],
  "atlas_impact": [],
  "recommended_action": "promote_to_doc|create_ap|benchmark|archive|research_more",
  "forbidden_actions": [],
  "created_at": "datetime"
}
```

## Source Judgment

```json
{
  "schema_version": "atlas.source_judgment.v1",
  "source_id": "uuid",
  "url_or_repo_path": "string",
  "title": "string",
  "publisher_or_owner": "string",
  "retrieved_at": "datetime",
  "tier": 0,
  "freshness": "fresh|aging|stale|unknown",
  "primary_source": true,
  "supports_claims": [],
  "known_limits": [],
  "trust_score": 0.0,
  "promotion_allowed": false
}
```

## Claim

```json
{
  "schema_version": "atlas.research_claim.v1",
  "claim_id": "uuid",
  "content": "string",
  "source_ids": [],
  "confidence": 0.0,
  "scope": "atlas_internal|external_provider|architecture|benchmark|security",
  "status": "supported|conflicted|uncertain|retracted",
  "must_preserve_exact_text": false
}
```

## Documentation Promotion Packet

```json
{
  "schema_version": "atlas.docs_promotion_packet.v1",
  "packet_id": "uuid",
  "research_packet_id": "uuid",
  "target_doc": "path",
  "change_type": "new_doc|update_doc|ap|archive",
  "authority_reason": "string",
  "source_trace": [],
  "validation_commands": [],
  "promotion_allowed": false
}
```

## Implementation Plan Packet

```json
{
  "schema_version": "atlas.implementation_plan_packet.v1",
  "packet_id": "uuid",
  "docs_promotion_packet_id": "uuid",
  "owner_files": [],
  "hot_files": [],
  "allowed_files": [],
  "forbidden_changes": [],
  "test_commands": [],
  "rollback_plan": [],
  "risk": "low|medium|high",
  "implementation_allowed": false
}
```

## Self-Improvement Proposal Packet

```json
{
  "schema_version": "atlas.self_improvement_proposal.v1",
  "proposal_id": "uuid",
  "evidence_refs": [],
  "affected_docs": [],
  "affected_code": [],
  "expected_gain": "string",
  "risk": "low|medium|high",
  "autonomy_level": "read_only|proposal_only|approved_apply",
  "review_required": true,
  "promotion_gate": [],
  "rollback_plan": []
}
```

## Fail-Closed Rules

- Missing source judgment blocks promotion.
- Tier 4 or Tier 5 blocks memory truth and policy.
- `promotion_allowed=false` blocks docs law.
- `implementation_allowed=false` blocks code.
- `review_required=true` blocks auto-apply.

## Resumo

Executable packet contracts for research, source judgment, documentation promotion, implementation plan and self-improvement proposal.

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
