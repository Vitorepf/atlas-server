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
  - retrieval_failure_detection
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
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-cognitive-runtime-failure-modes

graph_title: Atlas AI Cognitive Runtime Failure Modes

graph_world: atlas

graph_layer: system

graph_kind: module

graph_parent: atlas-ai-canonical-architecture-index

graph_status: active

graph_source: repo
human_name: Atlas AI Cognitive Runtime Failure Modes
canonical_name: Atlas AI Cognitive Runtime Failure Modes
technical_name: atlas-ai-cognitive-runtime-failure-modes
cartography_type: module
canonical_source: docs/engineering-knowledge-base/cognitive-runtime/failure-modes.md

owner: cognitive-runtime

repo_paths:
  - docs/engineering-knowledge-base/cognitive-runtime/failure-modes.md

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
  - docs/engineering-knowledge-base/cognitive-runtime/failure-modes.md

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

## Resumo

Matriz de falhas para memoria, retrieval, sessoes longas, compactacao, handoff e auditoria cognitiva.

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
