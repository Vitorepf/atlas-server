---
id: atlas-ai-evidence-lake-citation-health
type: engineering_knowledge
title: Atlas AI Evidence Lake And Citation Health
status: active
category: evidence
priority: 100
summary: Raw evidence, snapshots, atomic claims and citation health contract for reliable Atlas research.
tags:
  - atlas-ai
  - evidence-lake
  - citation-health
  - claims
capabilities:
  - evidence_lake
  - citation_health
  - research_claim_verification
decisions:
  - Raw evidence is the source of truth; reports and summaries are derived artifacts.
  - Every critical claim needs source support and citation health.
  - Dead, changed or unsupported citations block promotion.
maintenance:
  - Update before creating evidence tables, object storage paths, claim verifier or citation auditor.
related_paths:
  - docs/engineering-knowledge-base/research-self-improvement/research-operating-system.md
  - docs/engineering-knowledge-base/research-self-improvement/schemas-and-packets.md
  - docs/engineering-knowledge-base/research-self-improvement/metrics-and-evals.md
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-evidence-lake-citation-health

graph_title: Atlas AI Evidence Lake And Citation Health

graph_world: atlas

graph_layer: module

graph_kind: module

graph_parent: atlas-ai-canonical-architecture-index

graph_status: active

graph_source: repo
human_name: Atlas AI Evidence Lake And Citation Health
canonical_name: Atlas AI Evidence Lake And Citation Health
technical_name: atlas-ai-evidence-lake-citation-health
cartography_type: module
canonical_source: docs/engineering-knowledge-base/research-self-improvement/evidence-lake-and-citation-health.md

owner: research-self-improvement

repo_paths:
  - docs/engineering-knowledge-base/research-self-improvement/evidence-lake-and-citation-health.md

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
  - docs/engineering-knowledge-base/research-self-improvement/evidence-lake-and-citation-health.md

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
# Atlas AI Evidence Lake And Citation Health

Evidence Lake stores raw research material before synthesis.

## Raw Evidence Object

```json
{
  "schema_version": "atlas.evidence_source.v1",
  "source_id": "uuid",
  "url": "string",
  "canonical_url": "string",
  "source_type": "docs|paper|github|benchmark|news|video|social|dataset|repo|internal",
  "authority_level": "primary|secondary|lead|unknown",
  "title": "string",
  "author": "string|null",
  "publisher": "string|null",
  "published_at": "datetime|null",
  "modified_at": "datetime|null",
  "retrieved_at": "datetime",
  "raw_object_path": "string|null",
  "text_object_path": "string|null",
  "screenshot_path": "string|null",
  "content_hash": "sha256",
  "archive_url": "string|null",
  "license": "string|null",
  "trust_score": 0.0,
  "notes": []
}
```

## Atomic Claim Object

```json
{
  "schema_version": "atlas.atomic_claim.v1",
  "claim_id": "uuid",
  "claim_text": "string",
  "topic": "string",
  "source_ids": [],
  "status": "supported|contradicted|insufficient_evidence|outdated|unverifiable",
  "confidence": 0.0,
  "created_at": "datetime",
  "last_verified_at": "datetime"
}
```

## Claim Evidence Link

```json
{
  "schema_version": "atlas.claim_evidence.v1",
  "claim_id": "uuid",
  "source_id": "uuid",
  "quote_or_pointer": "string",
  "support_label": "supports|contradicts|mentions|insufficient",
  "verifier_score": 0.0,
  "verified_at": "datetime"
}
```

## Citation Health Checks

Every promoted citation must answer:

- URL or repo path exists?
- canonical URL is stable?
- source hash changed since retrieval?
- archive/snapshot exists when web content is volatile?
- quoted/pointer evidence supports the claim?
- claim is fact, inference or recommendation?
- newer source supersedes this source?
- contradiction exists?

## Blocking Conditions

- citation URL is invented;
- source cannot be retrieved and no local snapshot exists;
- quote does not support the claim;
- claim is stronger than source;
- source is stale for a time-sensitive claim;
- social/community source is used as final proof;
- content changed and no snapshot exists.

## Storage Principle

Summaries can be regenerated. Raw evidence must be preserved or the claim loses
promotion eligibility.

## Resumo

Raw evidence, snapshots, atomic claims and citation health contract for reliable Atlas research.

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
