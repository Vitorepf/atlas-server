---
id: atlas-temporal-engineering-operating-system-primitives
type: engineering_knowledge
title: Atlas TEOS Temporal Primitives
status: planned
category: programming
priority: 97
implementation_state: planned_design_not_current_runtime
summary: Focused child spec for TEOS temporal primitives: Temporal Truth Layer, Event-Sourced Timeline, Continuation Pack, Context Manifest, Compaction Receipt, Freshness/Drift, Replay, Recovery, Causal Decision Graph, Time-Aware World Model, Strategic Forgetting, and Memory Promotion. North-star only; benchmark_not_run.
tags:
  - atlas
  - teos
  - temporal
  - long-horizon
  - primitives
capabilities:
  - temporal_truth_layer_contract
  - event_sourced_engineering_timeline
  - superior_compaction_with_loss_accounting
  - freshness_and_drift_gate_design
  - provider_independent_replay
  - causal_decision_graph
  - time_aware_world_model
  - strategic_forgetting_policy
decisions:
  - This child spec preserves detailed TEOS primitive design split out of the north-star index.
  - It is planned design, not runtime proof; benchmark_not_run.
maintenance:
  - Keep this child spec aligned with atlas-teos-increment-1-plan.md.
related_paths:
  - docs/engineering-knowledge-base/atlas-temporal-engineering-operating-system.md
  - docs/engineering-knowledge-base/atlas-temporal-engineering-operating-system-primitives.md
  - docs/engineering-knowledge-base/atlas-temporal-engineering-operating-system-operations.md
  - docs/engineering-knowledge-base/atlas-long-horizon-intelligence-layer.md
  - docs/engineering-knowledge-base/atlas-teos-increment-1-plan.md
  - docs/engineering-knowledge-base/atlas-teos-existing-code-map.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-temporal-engineering-operating-system-primitives
graph_title: Atlas TEOS Temporal Primitives
graph_world: atlas
graph_layer: system
graph_kind: module
graph_parent: atlas-temporal-engineering-operating-system
graph_status: planned
graph_source: repo
owner: programming
repo_paths:
  - docs/engineering-knowledge-base/atlas-temporal-engineering-operating-system-primitives.md
allowed_changes:
  - Promote GREENFIELD primitive details when code ships with evidence.
forbidden_changes:
  - Declare primitive runtime-ready without evidence.
  - Add benchmark claims.
depends_on:
  - atlas-temporal-engineering-operating-system
flows_to:
  - atlas-teos-increment-1-plan
unlocks:
  - temporal_primitives_implementation
governs:
  - atlas_teos_temporal_primitives
evidence:
  - docs/engineering-knowledge-base/atlas-temporal-engineering-operating-system-primitives.md
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
requires_evidence: true
risk_level: high
line_limit: 520
next_actions:
  - Usar esta spec como referência para TEOS-I1 M1-M8.
  - Promover campos e contratos somente via TEOS-I1 com tests e evidence.
---

# Atlas TEOS Temporal Primitives
## 5. Temporal Truth Layer


## Resumo

Spec filha do TEOS para os primitivos temporais. Mantém o detalhe técnico fora do índice principal e preserva `benchmark_not_run`.

## Papel no Atlas

Define os blocos de tempo, verdade, compactação, replay, recovery, grafo causal, world model temporal e memória que TEOS-I1/I2 devem implementar incrementalmente.

## Onde Se Encaixa

Fica abaixo do índice `atlas-temporal-engineering-operating-system.md` e acima do plano `atlas-teos-increment-1-plan.md`.

## Contratos

Cobre `continuation_pack`, `context_manifest`, `compaction_receipt`, `freshness_report`, `replay_manifest`, `recovery_plan` e contratos futuros de grafo/memória.

## Fluxo

```text
context refs -> manifest -> compaction receipt -> continuation pack -> freshness/replay/recovery -> certification
```

## Regras para IA

Não criar runtime paralelo; estender serviços existentes conforme TEOS-I1. Não declarar runtime pronto sem evidence.

## Escopo de Implementacao

Esta spec é planned/north-star. A implementação inicial fica limitada às missões TEOS-I1.

## Dependencias

Depende de LHIL, TEOS north-star, TEOS-I1 plan, Evidence Runtime, World Model e Compounding.

## Evidencias

Evidência atual é documental. Componentes WIRED/PARTIAL/GREENFIELD devem ser promovidos somente com arquivo/teste/receipt.

## Riscos

Riscos principais: schema proliferation, event explosion, replay frágil e uso de contexto stale.

## Exemplos

Exemplo: uma compactação que descarta decisão crítica precisa gerar `unresolved_loss` e bloquear execução write.

## Proximas Acoes

Executar M1-M4 do TEOS-I1 antes de implementar freshness/recovery/replay.
Toda asserção operacional do Atlas (decisão, contexto, fato sobre código,
evidence, blocker, milestone) carrega um envelope temporal canônico
(`atlas.teos.temporal_truth_record.v1`):

| Campo | Tipo | Significado |
|---|---|---|
| `valid_from` | ISO8601 | Quando a asserção começou a valer. |
| `valid_until` | ISO8601 ou null | Quando deixa de valer; null = aberto. |
| `observed_at` | ISO8601 | Quando o Atlas viu o fato. |
| `verified_at` | ISO8601 ou null | Última verificação independente. |
| `stale_after` | ISO8601 | TTL antes de exigir re-verificação. |
| `source_hash` | sha256 | Hash da fonte que originou a asserção. |
| `confidence` | 0.0–1.0 | Quão certo o emissor está. |
| `superseded_by` | record_id ou null | Aponta para o registro que substituiu este. |
| `evidence_refs` | list<ref> | Provas que suportam a asserção. |
| `authority_level` | enum | `operator` > `canonical_doc` > `evidence_runtime` > `code_intelligence` > `provider_output`. |

Invariantes:

- `valid_until <= now` ⇒ asserção tratada como **stale** até re-verificar.
- `superseded_by` definido ⇒ asserção **não pode** ser citada como verdade
  atual; consultas devem seguir o ponteiro.
- `confidence < threshold(authority_level)` ⇒ asserção não passa em
  Continuity Certification sem evidence adicional.
- Authority de `provider_output` **nunca** sobrescreve `canonical_doc` ou
  `operator` sem promoção via Memory Promotion Pipeline.

## 6. Event-Sourced Timeline

Estado é projeção sobre uma timeline **append-only** com eventos
canônicos. Nenhum runtime do Atlas escreve estado direto sem registrar o
evento que o produziu.

Tipos de evento (não exaustivo):

- `decision.recorded`, `decision.superseded`
- `context_pack.assembled`, `context_pack.invalidated`
- `compaction.run`, `compaction.loss_accounted`
- `work_packet.proposed`, `work_packet.claimed`, `work_packet.done`,
  `work_packet.blocked`
- `milestone.activated`, `milestone.advanced`, `milestone.blocked`
- `sdd.spec.revised`, `sdd.assumption.resolved`, `sdd.drift.detected`
- `gate.run`, `gate.passed`, `gate.failed`
- `test.executed`, `test.regressed`, `test.fixed`
- `repair.attempted`, `repair.succeeded`, `repair.blocked`
- `blocker.opened`, `blocker.resolved`, `blocker.escalated`
- `operator.review.opened`, `operator.review.decided`
- `certification.run`, `certification.passed`, `certification.failed`,
  `certification.expired`

Cada evento herda do Temporal Truth Record do §5 e referencia o evento
causante via `caused_by[]` (entrada para o §13 Causal Decision Graph).

Por que append-only: **replay** (§11), **auditoria temporal**, e a
garantia de que reduzir o estado sempre rende a mesma projeção dado o
mesmo conjunto de eventos.

## 7. Continuation Pack

O **Continuation Pack** (`atlas.long_horizon.continuation_pack.v1` —
proposto no LHIL §Contratos) é a entrada canônica de qualquer retomada.
TEOS reforça seu papel:

- É a **única** estrutura válida para passar estado entre sessões /
  providers / operadores.
- Carrega `decisions[]`, `open_tasks[]`, `blockers[]`, `evidence_refs[]`,
  `context_pack_hash`, `next_best_action`, `stale_after`, `confidence`.
- Não é resumo textual — é projeção estruturada sobre a timeline (§6).
- `pack_hash` é determinístico: o mesmo conjunto de eventos produz o
  mesmo pack.
- Promoção a pack válido para retomada exige Continuity Certification
  (§21).

**Regra TEOS:** retomar uma Obra ou workstream Dev sem Continuation Pack
válido é um anti-pattern (§24).

## 8. Context Manifest

TEOS separa **estado** (a verdade atual sobre o trabalho) de **contexto**
(a coleção de fontes que sustentam o estado).

Estado vive no Continuation Pack (§7). Contexto vive num
`atlas.teos.context_manifest.v1`:

- lista cada fonte (`canonical_doc`, `code_symbol`, `test_log`,
  `evidence_ledger_entry`, `prior_decision`, `prior_pack`);
- carrega `source_hash`, `observed_at`, `stale_after`, `authority_level`
  (§5) por entrada;
- declara quais fontes são `must_keep` (decisão, blocker, evidence) e
  quais são `discardable` (exploração, redação intermediária).

O Context Manifest é **input** do Compaction Engine, do Freshness Gate e
do Recovery Planner. Sem ele, compactação é cega.

## 9. Superior Compaction + Loss Accounting

Compaction Engine emite **sempre** um `atlas.long_horizon.compaction_receipt.v1`
(definido no LHIL) com extensões TEOS:

| Campo | Regra TEOS |
|---|---|
| `must_keep_coverage` | **deve ser 1.0**. < 1.0 ⇒ compaction rejeitada. |
| `retained_items[]` | cada item carrega `must_keep:true/false` + razão. |
| `discarded_items[]` | nunca contém item com `must_keep:true`. |
| `discarded_reason[]` | `stale`/`low_signal`/`duplicate`/`out_of_scope`/`superseded` — sem categorias livres. |
| `quality_score` | calculado a partir de cobertura semântica vs receita; informativo. |
| `detected_contradictions[]` | inclui pares onde a compaction encontrou claims em conflito; nunca silencia. |
| `stale_risks[]` | itens cuja `stale_after` está perto. |
| `summary_hash` | hash do output; permite verificar que o consumidor leu a versão certificada. |

Princípio: **uma compactação que esconde perda é uma corrupção**. Loss
accounting é o que separa Atlas de "agente faz um resumo".

## 10. Freshness + Drift Gate

Antes de qualquer execução, retomada ou certification, TEOS dispara um
Freshness/Drift Gate. Falhas conhecidas que o gate detecta:

- **Doc drift.** Hash do doc citado por uma decisão mudou; a decisão
  precisa ser re-validada.
- **Runtime drift.** Estado runtime (`AiForgeLongHorizonState`, receipts
  Dev) divergiu da projeção esperada da timeline.
- **Decision drift.** Decisão superseded ainda referenciada como atual em
  algum pack.
- **Source-hash drift.** `source_hash` de uma evidence cited divergiu da
  fonte hoje.
- **Stale ref.** Referência cuja `stale_after` passou.
- **Test drift.** Conjunto de testes verde divergiu da última
  certification.
- **SDD drift.** `AtlasSpec.version` avançou sem propagação aos
  artefatos derivados.

Saídas: `pass`, `pass_with_warnings`, `blocked` (com lista estruturada).
Continuity Certification (§21) **não pode** declarar `pass` se o gate
não passou.

## 11. Replay Manifest

`atlas.teos.replay_manifest.v1` (GREENFIELD) define a provenência
suficiente para que **qualquer provider/modelo** continue uma Obra sem
chat bruto:

- ponteiro para o último Continuation Pack certificado;
- ponteiro para o Context Manifest correspondente;
- ponteiro para o intervalo da timeline (event_first_id, event_last_id);
- `expected_state_hash` — projeção de estado esperada se o replay for
  fiel;
- `expected_evidence_set_hash` — conjunto canônico de evidence ativa;
- `replay_mode_hint` — `read_only` por default; outros modos exigem
  promoção via Recovery Planner (§12).

Provider-independence (§22) opera sobre esse manifest. O receipt do
replay é comparado contra `expected_state_hash`; divergência ⇒ Drift
Gate.

## 12. Recovery Planner + Safe Resume Modes

Antes de continuar, o Recovery Planner emite um plano com **mode**
explícito. Sete modos canônicos:

| Mode | Quando aplicar | O que permite |
|---|---|---|
| `execute` | Freshness+Drift `pass`, Continuity Certification `pass`, nenhum blocker open. | Escrever código, mover packet, avançar milestone. |
| `read_only` | Estado utilizável mas algum sinal stale. | Ler timeline, gerar relatório, sem mutação. |
| `repair` | Falha conhecida com `repair_hook` estruturado; tentativas restantes > 0. | Tentar reparo dentro do escopo do hook; nenhuma expansão. |
| `review` | Pacote ou milestone com `operator.review.opened`. | Apresentar diff/decision para humano; não decidir sozinho. |
| `ask_human` | Decisão fora do mandato; Atlas falta authority. | Emitir pergunta canônica; bloquear até resposta. |
| `blocked` | Drift Gate `blocked` ou blocker open sem owner. | Nenhuma execução; só registrar status. |
| `escalate_to_forge` | Atlas Dev detecta escopo > workstream ou Obra latente. | Emitir `atlas.dev_to_forge.escalation_packet.v1`; Dev encerra responsabilidade. |

O plano carrega: `recommended_mode`, `reason`, `required_evidence[]`,
`expected_next_action`, `stop_conditions[]`. Nenhum runtime do Atlas pode
agir sem um mode atribuído.

## 13. Causal Decision Graph

A timeline (§6) sustenta um grafo dirigido:

```text
decision → work_packet → file → test → repair → decision'
```

Cada nó é um event_id; cada aresta é uma relação `caused_by` /
`supersedes` / `verifies` / `repairs` / `invalidates`.

Por que importa:

- **Auditoria temporal.** Por que essa decisão existe? Qual evento a
  causou? Qual evidence a validou?
- **Supersede propagation.** Quando uma decisão é superseded, o grafo
  marca decisões filhas como possivelmente afetadas; o Drift Gate (§10)
  consome esse aviso.
- **Repair attribution.** Um `test.regressed` que leva a
  `repair.succeeded` cria a aresta que prova que o sintoma foi tratado,
  não silenciado.
- **Compounding learning.** O grafo alimenta o Compounding Memory (cita
  `atlas-compounding-engineering-intelligence.md`) com padrões reais de
  decisão → outcome.

## 14. Time-Aware World Model

O Atlas mantém um modelo de mundo do código + docs + testes + evidence
(parcialmente WIRED via `ProgrammingSemanticCodeGraphService` /
`AtlasSpec` / `AtlasEvidenceLedger`). TEOS reforça que **toda relação no
world model é válida no tempo**:

- "Função X chama função Y" pode ser verdade hoje, falsa depois do
  refactor de amanhã.
- "Doc D explica módulo M" pode estar superseded por D'.
- "Teste T cobre comportamento C" pode ter regressed em commit K.

Cada aresta carrega `valid_from` / `valid_until` / `verified_at`. Queries
ao world model são **temporais por default**: `at(time)` retorna o snapshot
no instante T; `between(t1, t2)` retorna a história.

Implicação operacional: nenhum context_pack assembly assume relações
estáticas. RAG Gate (cita `programming-agentic-rag-professional-spec.md`)
consulta o world model **com timestamp**.

## 15. Strategic Forgetting

Memória não cresce indefinidamente. TEOS define sete políticas explícitas
sobre conhecimento durável:

| Política | Quando aplica | Efeito |
|---|---|---|
| `retain` | `must_keep:true` ou alta `confidence` recente. | Mantido in-loco. |
| `compress` | Médio sinal, idade média. | Vai para compaction com receipt. |
| `archive` | Baixo sinal, idade alta, sem citação ativa. | Movido para tier de armazenamento frio; recuperável. |
| `demote` | Authority degradou (e.g. doc canon foi reescrito). | Permanece, mas perde precedência em RAG. |
| `expire` | `stale_after` passou sem re-verificação. | Marcado stale; não consumido sem refresh. |
| `supersede` | Substituído por registro mais novo. | `superseded_by` aponta; consultas seguem o ponteiro. |
| `forget` | Conteúdo proibido (PII, secret, dado solicitado para exclusão). | Apagado; tombstone fica para auditoria. |

Esquecer NÃO é silenciar: cada transição é evento na timeline (§6) e
gera um receipt auditável.

## 16. Memory Promotion Pipeline

Promoção de raw para memória durável segue uma escada inquebrável:

```text
raw  →  quarantine  →  candidate  →  reviewed_learning
     →  memory_proposal  →  approval  →  execution
     →  evidence  →  durable_memory
```

Pontos canônicos:

- `raw` vem de sessões Dev, sessões Forge, planos Codex, transcripts de
  Claude Code via `atlas-local-agent-memory-ingestion.md` (sempre
  redactada, sempre em quarentena).
- `candidate` exige Temporal Truth envelope (§5) completo.
- `reviewed_learning` exige operador OU policy explícita.
- `memory_proposal` é votado pelo cognitive immune kernel (cita LHIL §11).
- `approval` é registrado como evento (§6).
- `execution` confirma que a memória foi usada em decisão real.
- `evidence` confirma que a decisão produziu outcome verificável.
- `durable_memory` só nasce com a cadeia inteira; nada de auto-promoção.

Anti-pattern §24: promover diretamente de raw para durable.
