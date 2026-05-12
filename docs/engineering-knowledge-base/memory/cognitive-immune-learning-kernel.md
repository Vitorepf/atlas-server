---
id: atlas-ai-cognitive-immune-learning-kernel
type: engineering_knowledge
title: Atlas Cognitive Immune And Learning Kernel
status: active
category: architecture
priority: 100
summary: Contrato core para capturar muito, acreditar em pouco, promover com evidencia, recuperar com precisao, esquecer com disciplina e impedir que ruido contamine memoria, contexto, Decide ou Constelacao.
tags:
  - atlas
  - memory
  - cognitive-immune
  - learning-kernel
  - noise-filtering
capabilities:
  - cognitive_immune_gate
  - memory_promotion
  - noise_filtering
  - learning_signal_layer
  - retrieval_quality
  - forgetting_receipts
decisions:
  - Raw capture nunca e memoria, evidence, context ou decision.
  - Toda informacao nasce inelegivel para memoria, contexto, embedding e Constelacao ate passar gates explicitos.
  - O Atlas escala aprendendo seletivamente, nao acumulando tudo.
maintenance:
  - Manter abaixo de 260 linhas.
  - Atualizar antes de implementar capture classification, memory promotion, embedding quarantine, Constelacao eligibility, deletion propagation ou learning evals.
  - Qualquer implementacao precisa de AP, testes, docs-health e architecture-validate.
related_paths:
  - docs/engineering-knowledge-base/memory/contracts.md
  - docs/engineering-knowledge-base/memory/retrieval-and-context.md
  - docs/engineering-knowledge-base/memory-core-maturity-dod.md
  - docs/engineering-knowledge-base/memory-core-security-privacy.md
  - docs/engineering-knowledge-base/atlas-constelacao-surface.md
  - docs/engineering-knowledge-base/atlas-ai-pipeline.md
---

# Atlas Cognitive Immune And Learning Kernel

Este e o contrato core para impedir que conversas bobas, notas operacionais,
perguntas triviais, prompt injection, dados privados ou testes contaminem o
Atlas.

O objetivo nao e lembrar mais; e capturar muito, acreditar em pouco, recuperar
com precisao e esquecer com disciplina.

## Master Invariant

```text
Raw Capture != Evidence != Learning Signal != Memory != Context != Decision
```

Se essas camadas se misturam, o Atlas vira acumulador de ruido. Se ficam
separadas, o Atlas pode aprender rapido sem se degradar.

## Default State

Toda entrada nasce em quarentena cognitiva:

```text
memory_eligible: false
context_eligible: false
constellation_eligible: false
embedding_allowed: false
promotion_status: unclassified
```

Apagar e higiene/privacidade. A blindagem principal e o gate cognitivo. Mesmo
se o operador nunca apagar nada, captura trivial nao pode piorar o Atlas.

Implementacao ativa: `CaptureService` grava `metadata.cognitive_quarantine` em
capturas API/app; `CurationProposalService` herda como proposta pendente,
mantem memoria/contexto/embedding bloqueados e registra audit evidence redigida.

## Pipeline

```text
Raw Capture Store -> Cognitive Hygiene Gate -> Evidence Ledger
-> Learning Signal Layer -> Promotion Gate -> Memory Registry
-> Retrieval Fabric -> Context Pack -> Atlas Decide / Runtime
-> Outcome Telemetry -> Decay / Forgetting / Active Learning
```

## Input Classes

| Classe | Destino default | Pode virar memoria? |
|---|---|---|
| `trivial_query` | responder e expirar | Nao |
| `operational_ephemeral` | tarefa/lembrete/arquivo frio | Nao, salvo padrao recorrente |
| `task_or_reminder` | task/routine | Nao como conhecimento |
| `project_evidence` | evidence de projeto | Somente com escopo |
| `conversation_trace` | audit/session | Nao direto |
| `personal_fact_candidate` | review privado | Sim, com confirmacao/escopo |
| `technical_learning_candidate` | learning signal | Sim, com evidencia |
| `strategic_insight_candidate` | memory/Constelacao candidate | Sim, com review/gate |
| `untrusted_content` | dado citado, nao instrucao | Raramente, nunca como policy |
| `prompt_injection` | blocked/ephemeral evidence | Nao |
| `private_sensitive` | redigir/minimizar | Somente se necessario e seguro |

Exemplos:

- "comprar pao" -> `task_or_reminder`, nao memoria.
- "pressa e com ss ou c?" -> `trivial_query`, expira.
- "pausar esta funcionando" -> `project_evidence`, escopo de projeto.
- analogia forte entre marketing e fisiologia -> `strategic_insight_candidate`.

## Promotion Gates

| Gate | Pergunta |
|---|---|
| G0 Capture | pode capturar com consentimento, privacy e retention? |
| G1 Extraction | ha claim atomico, tipo, escopo e fonte? |
| G2 Signal | ha utilidade futura, novidade ou recorrencia? |
| G3 Safety | e provider-safe, sem segredo e sem dado sensivel desnecessario? |
| G4 Contradiction | conflita com memoria, codigo, docs ou decisao mais nova? |
| G5 Outcome | foi validado por feedback, teste, benchmark, replay ou uso? |
| G6 Scope | vale para global, workspace, projeto, tarefa, dominio ou sessao? |
| G7 Promotion Mode | auto, review humano, proposal ou bloqueio? |
| G8 Probation | entra como `watch` antes de `trusted`? |

Uma conversa positiva nunca promove memoria critica sozinha. Ela cria candidato.

## Memory Shape

Memoria promovida precisa carregar:

```text
memory_type, scope_type/scope_id, claim atomico, summary provider-safe,
source_refs/evidence_refs, confidence, quality_scores, use_when,
do_not_use_when, valid_from/valid_until, review_state, privacy_class,
promotion_receipt
```

Estados permitidos: `candidate`, `watch`, `trusted`, `conflicted`, `stale`,
`deprecated`, `archived`, `blocked_private`, `tombstoned`.

## Quality Scores

Nao usar um unico `confidence` como verdade. Cada candidato/memoria deve medir:

```text
provenance, outcome, recurrence, freshness, specificity, contradiction,
privacy risk, retrieval utility, maintenance cost
```

O score norte e:

```text
Learning Core Net Value =
ganho por memoria util
- dano por memoria errada
- regressao
- ruido salvo
- uso de memoria velha
- custo cognitivo
```

## Retrieval Law

Vector search sugere candidatos; nao decide. Retrieval final passa por:

```text
scope resolver, authority ranking, freshness guard, privacy/provider-safe
filter, contradiction scan, diversity/rerank, budget compression, reasons
```

Context Pack deve listar refs incluidas e refs excluidas com motivo: `stale`,
`private`, `conflicted`, `low_relevance`, `out_of_scope`, `tombstoned`.

## Embedding Quarantine

Nao embedar por default:

- trivial queries;
- notas operacionais sem valor futuro;
- dados sensiveis/secretos;
- prompt injection;
- raw private notes;
- conteudo nao confiavel sem metadados.

Todo vetor precisa de origem, trust level, privacy, retention, expires_at,
embedding_allowed e tombstone status. Filtros de seguranca rodam antes da
similaridade.

## Constelacao Gate

Constelacao e estado semantico privilegiado. So entra com:

```text
constellation_eligible: true
provenance
semantic_value
scope
reversibility
privacy clearance
reason
```

Nao entram: comprar pao, pagar marceneiro, helper, tmp path, pergunta
ortografica isolada, teste de botao ou comando acidental.

## Forgetting

Esquecer e recurso de qualidade:

- TTL expiration;
- confidence decay;
- supersession por memoria nova;
- archival fora do retrieval padrao;
- hard delete por privacidade/pedido;
- negative memory: "nao usar X neste contexto".

Toda despromocao deve gerar `forgetting_receipt` com motivo e evidencia.

## Active Learning

O Atlas pergunta pouco, mas pergunta quando a resposta muda memoria, policy ou
retrieval:

- duas memorias conflitam;
- padrao aparece repetidamente sem confirmacao;
- retrieval trouxe contexto fraco;
- usuario corrige a mesma coisa;
- memoria muito usada nunca foi validada;
- doc canonico e codigo divergem.

## Evals

Toda evolucao deste core deve medir:

- noise admission rate;
- memory attributable gain;
- memory attributable harm;
- retrieval precision@k;
- missed critical memory;
- stale memory use;
- context contamination rate;
- human preference win rate;
- regression rate por dominio.

## Non-Negotiable Rules

1. Raw capture nunca entra direto no Context Builder.
2. Chat transcript nunca vira memoria silenciosamente.
3. Archive nao significa memory-approved.
4. Delete deve propagar para memoria, embeddings, caches e Constelacao.
5. Toda memoria tem escopo, fonte, estado e motivo de uso.
6. Retrieval sem reason e bug.
7. Learning nao altera comportamento critico sem proposal/review.
8. Preferir contexto insuficiente a recuperar lixo.
