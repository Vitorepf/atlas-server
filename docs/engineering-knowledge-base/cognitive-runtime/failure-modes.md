---
id: atlas-ai-cognitive-runtime-failure-modes
type: engineering_knowledge
title: Atlas AI Cognitive Runtime Failure Modes
status: active
category: architecture
priority: 98
summary: Matriz de falhas para memoria, retrieval, sessoes longas, compactacao, handoff e auditoria cognitiva.
tags:
  - atlas-ai
  - cognitive-runtime
  - failure-modes
  - quality
capabilities:
  - cognitive_failure_detection
  - compaction_quality_gate
  - retrieval_quality
  - long_session_audit
decisions:
  - Falhas cognitivas devem degradar para read/plan/watch antes de afetar runtime critico.
  - Falha de contexto seguro e melhor que continuidade contaminada.
maintenance:
  - Atualizar quando novas falhas aparecerem em dogfood, replay ou auditoria.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-cognitive-runtime.md
  - docs/engineering-knowledge-base/cognitive-runtime/runbook.md
  - docs/engineering-knowledge-base/memory-core-failure-modes.md
---

# Atlas AI Cognitive Runtime Failure Modes

## Failure Matrix

| Failure | Signal | Required response |
|---|---|---|
| `lost_objective` | resumo muda objetivo sem decision | bloquear continuidade; pedir snapshot novo |
| `hot_file_ambiguity` | arquivo quente ausente ou incerto | read-only ate ownership ser refeito |
| `missing_evidence_refs` | compaction sem refs | bloquear handoff |
| `stale_canonical_doc` | doc antigo vence doc ativo | refresh context pack |
| `raw_capture_admitted` | raw/unclassified vira context | critical; remover e auditar |
| `provider_memory_merge` | provider file vira fonte primaria | bloquear; usar Atlas memory source |
| `retrieval_without_reason` | ref sem motivo | bug de retrieval |
| `context_overstuffing` | budget dominado por logs/raw docs | compactar; rerank |
| `critical_context_missed` | AP/test/hot file ausente | critical benchmark failure |
| `compaction_requires_chat` | executor precisa chat bruto | compaction rejected |
| `handoff_without_receipt` | provider/surface mudou sem evidence | emitir receipt antes de agir |
| `privacy_leak` | secret/private em provider-safe | critical; redact and audit |
| `memory_harm` | memoria piora decisao | rebaixar/tombstone candidate |
| `repeat_work_loop` | mesma investigacao reaparece | audit packet watch |
| `cost_without_gain` | custo cresce sem refs uteis | reduzir budget/retrieval |

## Severity

| Severity | Meaning | Runtime posture |
|---|---|---|
| `info` | Sem impacto decisorio | registrar |
| `watch` | Pode degradar qualidade | continuar com aviso |
| `blocked` | Continuidade insegura | parar antes de execucao |
| `critical` | Privacy, policy ou context contamination | parar, auditar, reparar |

## Recovery Rules

- Missing context: refresh Open Brain and rerun retrieval.
- Stale context: prefer canonical active doc and record stale exclusion.
- Lost decisions: use ledger/receipt refs, not chat memory.
- Privacy issue: redact, tombstone unsafe packet, emit audit.
- Repetition loop: produce handoff summary and narrow next action.
- Hot file ambiguity: require `git status --short` and owner report.

## Anti-Patterns

- “Resumo bonito” sem refs.
- Context pack que inclui tudo.
- Vector result treated as authority.
- Provider projection used as memory.
- Session duration used as quality proof.
- Compactacao que apaga negative constraints.
- Audit score that can auto-apply behavior changes.
