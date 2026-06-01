---
id: atlas-ai-cognitive-runtime-schemas-and-packets
type: engineering_knowledge
title: Atlas AI Cognitive Runtime Schemas And Packets
status: active
category: architecture
priority: 99
summary: Schemas conceituais para long-session snapshot, compaction packet, cognitive audit packet e retrieval benchmark packet.
tags:
  - atlas-ai
  - cognitive-runtime
  - schemas
  - packets
  - long-session
capabilities:
  - long_session_snapshot
  - compaction_packet
  - cognitive_audit_packet
  - retrieval_benchmark_packet
decisions:
  - Packets cognitivos sao evidence read-only; nao executam, nao promovem memoria e nao alteram policy.
  - Todo packet precisa de schema_version, refs, hashes e status explicito.
  - Qualquer packet incompleto bloqueia promocao de maturidade, nao necessariamente a conversa humana.
maintenance:
  - Atualizar antes de criar migrations, DTOs, resources ou commands para Cognitive Runtime.
  - Manter schemas conceituais aqui; implementacao fica em codigo e testes.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-cognitive-runtime.md
  - docs/engineering-knowledge-base/atlas-ai-continuity-session-state.md
  - docs/engineering-knowledge-base/cognitive-runtime/runbook.md
  - docs/ap/AP-688-cognitive-runtime-72h-contract.md
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-cognitive-runtime-schemas-and-packets

graph_title: Atlas AI Cognitive Runtime Schemas And Packets

graph_world: atlas

graph_layer: system

graph_kind: module

graph_parent: atlas-ai-canonical-architecture-index

graph_status: active

graph_source: repo
human_name: Atlas AI Cognitive Runtime Schemas And Packets
canonical_name: Atlas AI Cognitive Runtime Schemas And Packets
technical_name: atlas-ai-cognitive-runtime-schemas-and-packets
cartography_type: module
canonical_source: docs/engineering-knowledge-base/cognitive-runtime/schemas-and-packets.md

owner: cognitive-runtime

repo_paths:
  - docs/engineering-knowledge-base/cognitive-runtime/schemas-and-packets.md

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
  - cognitive-runtime

evidence:
  - docs/engineering-knowledge-base/cognitive-runtime/schemas-and-packets.md
evidence_refs:
  - symbol: AtlasCognitiveRuntimeSchemasAndPacketsService
  - command: atlas:aaeos:cognitive-runtime-schemas-and-packets
  - test: AtlasCognitiveRuntimeSchemasAndPacketsTest

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: high

visual_tags:
  - system
  - module
  - cognitive-runtime

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
# Atlas AI Cognitive Runtime Schemas And Packets

## Packet Rules

- Packets sao provider-safe por default.
- Packets citam refs, hashes e summaries; nao carregam chat bruto.
- Packets read-only podem alimentar Self-Improvement proposal-only.
- Packets nao podem promover memoria, alterar policy ou aplicar patch sozinhos.

## Long Session Snapshot

```json
{
  "schema_version": "atlas.cognitive_runtime.long_session_snapshot.v1",
  "session_id": "uuid",
  "parent_session_id": "uuid|null",
  "status": "active|paused|compacted|handoff|closed|blocked",
  "objective": "short objective",
  "elapsed_minutes": 0,
  "target_minutes": 4320,
  "surface_id": "cli|app|voice_realtime|mobile",
  "domain_id": "programming",
  "flow_id": "programming.review",
  "current_phase": "planning|implementation|validation|review|handoff",
  "hot_files": ["path"],
  "ownership_constraints": ["string"],
  "decision_receipt_refs": ["receipt-id"],
  "evidence_refs": ["ledger/ref"],
  "context_pack_hash": "sha256",
  "compaction_refs": ["packet-id"],
  "handoff_refs": ["packet-id"],
  "open_questions": ["string"],
  "pending_risks": ["string"],
  "quality_metrics": {
    "decision_quality": 0.0,
    "drift_rate": 0.0,
    "repeated_work_rate": 0.0,
    "missed_invariant_count": 0,
    "context_precision_at_k": 0.0,
    "missed_critical_context_count": 0,
    "context_contamination_count": 0,
    "stale_context_use_count": 0,
    "cost_per_useful_hour": 0.0
  }
}
```

## Compaction Packet

Required fields:

| Field | Rule |
|---|---|
| `schema_version` | `atlas.cognitive_runtime.compaction_packet.v1` |
| `source_session_id` | Session being compacted. |
| `summary_hash` | Hash of provider-safe summary. |
| `preserved_decisions` | Decisions and rejected alternatives. |
| `preserved_invariants` | Policy, hot files, ownership and operator rules. |
| `evidence_refs` | Ledger, trace, test, file or AP refs. |
| `blocked_reasons` | Non-empty when continuity is unsafe. |
| `next_action` | Concrete next step if status is ready. |

Status values: `ready`, `blocked_missing_evidence`, `blocked_hot_files_ambiguous`,
`blocked_policy_gap`, `blocked_privacy_gap`, `blocked_canonical_conflict`.

## Cognitive Audit Packet

```json
{
  "schema_version": "atlas.cognitive_runtime.audit_packet.v1",
  "subject_type": "long_session|compaction|handoff|retrieval",
  "subject_id": "id",
  "status": "ready|watch|critical",
  "net_value": 0.0,
  "gain": {
    "useful_context": 0,
    "repeated_work_avoided": 0,
    "decision_reuse": 0
  },
  "harm": {
    "wrong_context": 0,
    "stale_context": 0,
    "context_contamination": 0,
    "lost_decision": 0,
    "policy_violation": 0
  },
  "recommendations": ["proposal-only next action"],
  "evidence_refs": ["ref"]
}
```

## Retrieval Benchmark Packet

Required measures:

- `precision_at_3`;
- `precision_at_5`;
- `missed_critical_context_count`;
- `stale_context_use_count`;
- `context_contamination_count`;
- `reason_coverage`;
- `budget_truncation_count`;
- `provider_safe_violation_count`.

## Promotion Rule

Uma sessao 72h so pode virar evidence de maturidade quando snapshot, compaction
packet e audit packet estiverem `ready` ou `watch` com riscos explicitamente
aceitos por operador humano.

## Resumo

Schemas conceituais para long-session snapshot, compaction packet, cognitive audit packet e retrieval benchmark packet.

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
