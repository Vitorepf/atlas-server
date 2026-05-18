---
id: atlas-temporal-engineering-operating-system-operations
type: engineering_knowledge
title: Atlas TEOS Operational Protocols
status: planned
category: programming
priority: 97
summary: Focused child spec for TEOS operational protocols: Atlas Dev long-context runtime, Atlas Forge multi-month Obra runtime, monthly review, operator attention, continuity certification, provider-independent continuity, metrics, anti-patterns, DoD, and benchmark relationship. North-star only; benchmark_not_run.
tags:
  - atlas
  - teos
  - temporal
  - atlas-dev
  - atlas-forge
  - operations
capabilities:
  - dev_multi_week_continuity_certified
  - forge_multi_month_obra_continuity_certified
  - operator_attention_queue
  - monthly_obra_review_protocol
  - continuity_certification_design
  - provider_independent_resume
decisions:
  - This child spec preserves detailed TEOS operational design split out of the north-star index.
  - It preserves Dev/Forge boundary and does not authorize benchmark execution.
maintenance:
  - Keep this child spec aligned with TEOS-I1 and pre-benchmark readiness docs.
related_paths:
  - docs/engineering-knowledge-base/atlas-temporal-engineering-operating-system.md
  - docs/engineering-knowledge-base/atlas-temporal-engineering-operating-system-primitives.md
  - docs/engineering-knowledge-base/atlas-temporal-engineering-operating-system-operations.md
  - docs/engineering-knowledge-base/atlas-long-horizon-intelligence-layer.md
  - docs/engineering-knowledge-base/atlas-teos-increment-1-plan.md
  - docs/engineering-knowledge-base/atlas-teos-existing-code-map.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-temporal-engineering-operating-system-operations
graph_title: Atlas TEOS Operational Protocols
graph_world: atlas
graph_layer: system
graph_kind: module
graph_parent: atlas-temporal-engineering-operating-system
graph_status: planned
graph_source: repo
owner: programming
repo_paths:
  - docs/engineering-knowledge-base/atlas-temporal-engineering-operating-system-operations.md
allowed_changes:
  - Promote operational protocols when runtime evidence exists.
forbidden_changes:
  - Fuse Atlas Dev and Atlas Forge.
  - Authorize benchmark execution.
depends_on:
  - atlas-temporal-engineering-operating-system
flows_to:
  - atlas-pre-benchmark-readiness-audit
unlocks:
  - long_horizon_operational_certification
governs:
  - atlas_teos_operational_protocols
evidence:
  - docs/engineering-knowledge-base/atlas-temporal-engineering-operating-system-operations.md
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
requires_evidence: true
risk_level: high
line_limit: 520
next_actions:
  - Usar esta spec como referência para TEOS-I1 M9-M10 e TEOS-I2.
  - Manter Dev/Forge separados e benchmark_not_run até autorização posterior.
---

# Atlas TEOS Operational Protocols
## 17. Atlas Dev Long-Context Runtime


## Resumo

Spec filha do TEOS para protocolos operacionais de longa duração. Mantém Dev multi-semana e Forge multi-mês separados.

## Papel no Atlas

Define como Atlas Dev, Atlas Forge, operador, certification e benchmark-readiness usam os primitivos temporais em operação real.

## Onde Se Encaixa

Fica abaixo do índice TEOS e complementa a spec de primitivos temporais com protocolos de runtime e governança.

## Contratos

Cobre Dev long-context, Forge Obra multi-mês, monthly review, operator attention e continuity certification.

## Fluxo

```text
Dev/Forge scope -> continuation pack -> safe resume mode -> operator/certification/control plane -> next action
```

## Regras para IA

Não fundir Dev e Forge. Não rodar benchmark. Não tratar monthly review como automação ativa em TEOS-I1.

## Escopo de Implementacao

Esta spec é planned/north-star. TEOS-I1 implementa só a fundação; monthly review e attention robusto ficam para incrementos posteriores.

## Dependencias

Depende da spec de primitivos, TEOS-I1, Dual-Core, Forge OS, Evidence Runtime e Pre-Benchmark Readiness.

## Evidencias

Evidência atual é documental. Runtime proof deve vir de E2E long-horizon, continuity certification e control plane.

## Riscos

Riscos principais: operador fatigado, false resume, Dev virando Forge, Forge sem timeline e benchmark prematuro.

## Exemplos

Exemplo: uma Obra de 90 dias só avança marco se milestone ledger, SDD revision, evidence timeline e certification estiverem consistentes.

## Proximas Acoes

Implementar TEOS-I1 antes de automatizar monthly review ou operator attention avançado.
Como Atlas Dev (núcleo rápido, semanas / 1 mês) opera sob TEOS **sem
virar mini-Forge**:

- **Unidade de continuidade:** workstream = união de runs Dev por
  `thread_id` ou `workspace_hash`.
- **Continuation Pack:** emitido ao final de cada run; carrega só o que é
  reusável na próxima run.
- **Compaction:** acionada quando token budget excede; receipt com
  `must_keep_coverage=1.0`.
- **Freshness Gate:** consultado no início de cada run para invalidar
  decisões stale.
- **Escalation a Forge:** quando escopo ultrapassa workstream, Recovery
  Planner mode = `escalate_to_forge`; emite escalation packet canônico.
- **NÃO faz:** spec-mãe, milestones, multi-agent dispatch, certification
  de Obra — esses são responsabilidade do Forge.

Boundary preservada: Dev é rápido, contextual, run-based. TEOS o deixa
**lembrar**, não o transforma em Forge.

## 18. Atlas Forge Multi-Month Obra Runtime

Como Atlas Forge (núcleo pesado, Obras de meses) opera sob TEOS:

- **Unidade de continuidade:** Obra (`forge_intake → milestones[5] →
  work_packets[N] → execution_cycles[]`).
- **Long-Horizon State:** já WIRED (`AiForgeLongHorizonState` +
  `ForgeLongHorizonStateService`); TEOS o consome via timeline.
- **Milestone gates:** já WIRED via `ForgeMilestoneGateRunner`; TEOS
  exige que toda transição emita evento (§6).
- **Work Packet Execution Cycle:** WIRED; TEOS adiciona campos temporais
  e replay.
- **Certification:** `ForgeObraCertificationService` é consumidor de
  Continuity Certification (§21).
- **Compaction de Obra:** quando timeline excede limite, compacta com
  receipt; nenhum milestone, blocker ou evidence é descartável.
- **Monthly review (§19) é obrigatório.**

## 19. Monthly Obra Review Protocol

Obras longas exigem revisão programada para não acumular dívida
silenciosa. TEOS define dois cadências canônicas:

- **Weekly synthesis** (Dev workstream e Forge Obra):
  - Continuation Pack consolidado;
  - resumo de decisões da semana;
  - blockers abertos;
  - próximas ações;
  - itens em `stale_risks`.
- **Monthly architecture review** (Forge Obra ≥ 1 mês):
  - revisão da spec-mãe vs realidade;
  - drift acumulado (§10);
  - decisões superseded e seus impactos;
  - milestones a renegociar;
  - evidence ainda válida vs caducada;
  - decisão explícita: continuar, ajustar escopo, pausar, encerrar.

Ambas as cadências são eventos na timeline (`operator.review.opened` /
`.decided`). Sem o monthly, Obras de 3+ meses se tornam compostos de
suposições mortas.

## 20. Operator Attention Queue

Operador humano é o recurso mais escasso. TEOS define uma fila explícita
de atenção:

- **Severity:** `critical` (Obra parada), `high` (decisão pendente
  bloqueando milestone), `medium` (review programada), `low` (FYI).
- **Aging:** todo item carrega `opened_at`; itens `critical` envelhecem
  rápido e disparam escalation.
- **Deduplicação:** mesma decisão pendente não aparece N vezes; o item
  cresce em severity em vez de duplicar.
- **Closure:** decisão registrada como evento (§6) fecha o item.
- **Anti-fadiga:** itens `low` são agrupados em digest semanal; nunca
  individuais.

Sem essa disciplina, o operador é o gargalo silencioso de qualquer
sistema temporal.

## 21. Continuity Certification

Antes de continuar **qualquer** execução, TEOS exige Continuity
Certification (`atlas.teos.continuity_certification.v1`, GREENFIELD).
Saída: `passed` / `passed_with_warnings` / `failed`.

Checks obrigatórios:

- Continuation Pack presente e `pack_hash` matching.
- Context Manifest presente e válido.
- Freshness + Drift Gate `pass`.
- Replay Manifest produz `expected_state_hash` consistente com runtime.
- Nenhum blocker open sem owner.
- Nenhum `valid_until` aberto para asserção load-bearing.
- Authority chain válida (provider_output não sobrescreveu canônico).
- Compaction receipts da janela com `must_keep_coverage=1.0`.

`failed` ⇒ Recovery Planner emite mode ∈ {`read_only`, `repair`,
`review`, `ask_human`, `blocked`}. Nunca `execute`.

## 22. Provider-Independent Continuity

Qualquer provider (Claude, GPT, Gemini, Codex, modelo local) deve
conseguir continuar uma Obra sem **nenhum chat bruto** de outro provider.
TEOS garante isso por construção:

- Estado vive no Continuation Pack (§7), não em transcript.
- Contexto vive no Context Manifest (§8), com hashes verificáveis.
- Decisões vivem na timeline (§6), não em conversa.
- Replay Manifest (§11) reconstrói o ponto de retomada.
- Provider-safe projection (já contratual em Atlas Dev schemas) garante
  que nenhuma asserção sensível vaza para o provider externo.

Implicação: trocar de provider mid-Obra é uma decisão de custo/qualidade,
não uma operação destrutiva de continuidade.

## 23. Metrics

Métricas que TEOS pretende emitir (todas como receipt no Evidence Ledger;
nenhuma é "Atlas é Nx" — todas são internas e auditáveis):

- **Retention rate**: % de itens `must_keep` retidos por compaction (deve
  ser sempre 1.0).
- **Recovery success rate**: % de retomadas em que Continuity Cert =
  `passed` na primeira tentativa.
- **Stale-block rate**: % de execuções bloqueadas por Freshness Gate.
- **Replay success rate**: % de replays em que `expected_state_hash`
  bateu.
- **False resume rate**: % de retomadas com mode=`execute` que produziram
  outcome `failed` por estado stale não detectado. **Deve tender a zero.**
- **Median operator turnaround**: tempo médio de Attention Queue
  `opened → decided`.
- **Decision supersede latency**: tempo médio entre decisão original e
  evento `decision.superseded` quando aplicável.
- **Drift detection lead time**: tempo médio entre drift introduzido e
  detectado pelo Drift Gate.
- **Compaction loss share**: % de itens descartados por compaction,
  categorizado por `discarded_reason`.
- **Causal graph depth**: profundidade média de cadeias `caused_by` em
  decisões load-bearing.

## 24. Anti-Patterns

TEOS proíbe explicitamente:

- **Resumo sem receipt.** Compactação sem `compaction_receipt.v1` é
  corrupção silenciosa.
- **Event-sourcing universal sem necessidade.** Nem todo dado vira evento
  — só os listados em §6. Eventizar logs internos infla a timeline e
  degrada replay.
- **Schema proliferation.** Antes de inventar `atlas.teos.<algo>.v2`,
  verificar se schema existente cobre. Cita `atlas-canonical-glossary-and-naming.md`.
- **Dev virando Forge.** Atlas Dev TEOS-aware ainda é rápido e
  workstream-scoped; jamais opera milestones de Obra. Se tentar, mode =
  `escalate_to_forge`.
- **Forge sem timeline.** Forge "rápido" sem timeline operacional vira
  Atlas Dev gordo; perde toda a vantagem.
- **Benchmark antes de continuidade.** Comparar contra Claude Code /
  Codex enquanto TEOS está GREENFIELD não mede capacidade; mede prompt
  + sorte. benchmark_not_run.
- **Promoção direta raw → durable_memory.** Memory Promotion Pipeline
  (§16) não tem atalho.
- **Provider output sobrescrevendo canonical doc.** Authority chain (§5)
  proíbe.
- **Continuação por chat bruto.** Toda retomada via Continuation Pack
  certificado.
- **Compactação sem must_keep_coverage=1.0.** Qualquer < 1.0 é rejeição.
- **Estado mutado fora de evento.** Toda mudança gera evento (§6); zero
  side-channels.
- **Decisão sem `superseded_by` quando substituída.** Decisão antiga sem
  ponteiro polui RAG e cria drift.

## 25. Definition of Done

TEOS está implementado **de verdade** quando, todos verificáveis no
Evidence Ledger:

1. `atlas.teos.temporal_truth_record.v1` shipado e adotado por
   decisions, contexts, evidence, blockers, milestones.
2. Event-Sourced Timeline canônica com os tipos do §6 escrevendo via
   um único `AtlasEvidenceLedger` extendido; zero estado escrito sem
   evento.
3. `atlas.long_horizon.continuation_pack.v1` e
   `atlas.long_horizon.compaction_receipt.v1` shipados, com
   `must_keep_coverage=1.0` enforced e usados por Dev e Forge.
4. `atlas.teos.context_manifest.v1` shipado, consumido por compaction +
   freshness gate + recovery planner.
5. Freshness + Drift Gate WIRED para Doc/Runtime/Decision/Source/Stale.
6. `atlas.teos.replay_manifest.v1` WIRED, gerando `expected_state_hash`
   e validado por pelo menos um provider de continuação real (interno).
7. Recovery Planner com os 7 modes do §12 emitindo plano antes de toda
   execução.
8. Causal Decision Graph queryable (no mínimo: `caused_by`,
   `supersedes`, `verifies`, `repairs`).
9. Time-Aware World Model com `at(time)` e `between(t1,t2)` em pelo
   menos um domínio (provavelmente programming).
10. Strategic Forgetting com as 7 políticas + tombstones, integrado a
    Memory Promotion Pipeline.
11. Continuity Certification cross-scope (Dev workstream **e** Forge
    Obra) emitindo receipt antes de cada `execute`.
12. Provider-independent continuity validado em pelo menos 2 providers
    distintos (sem chat bruto).
13. Monthly Obra Review protocol formalizado como receipts da timeline.
14. Operator Attention Queue com aging + dedup + digest.
15. Métricas §23 emitindo continuamente; dashboards internos
    consumindo-as.

Cada item da DoD é "WIRED + receipt", não "design pronto".

## 26. Relationship With Benchmark

TEOS **prepara** benchmark; não executa benchmark. O lugar canônico para
discussão de comparação contra Claude Code / Codex / Cursor é
`atlas-pre-benchmark-readiness-audit.md` (irmão), e a metodologia
auditada vive em `atlas-programming-superiority-architecture.md`
(§Honest Comparison Methodology).

Regras:

- benchmark_not_run nesta doc; nenhum número aparece sem receipt
  externo.
- Comparação só é honesta após DoD §25 atingido (ou explicitamente
  parcial com qualificadores).
- "TEOS pronto" **não** implica "Atlas supera X". Implica que **se**
  alguém medir, a medida tem base auditável.

---

## Top 10 Princípios TEOS

1. **Tempo é primeira classe.** Toda asserção carrega envelope temporal.
2. **Estado é projeção sobre eventos.** Append-only; sem overwrite
   silencioso.
3. **Toda compactação tem receipt com must_keep_coverage=1.0.**
4. **Toda retomada exige Continuity Certification.** Sem certification,
   sem `execute`.
5. **Decisões superseded apontam para o sucessor.** Drift propaga; nada é
   silenciado.
6. **Authority chain é inviolável.** Provider output nunca sobrescreve
   canônico ou operador sem promoção.
7. **Provider é fungível; continuação não depende de chat bruto.**
8. **Dev e Forge permanecem paralelos.** Mesma camada temporal, runtimes
   distintos.
9. **Memória durável só nasce via Promotion Pipeline.** Zero
   auto-promoção.
10. **benchmark depois de continuidade.** TEOS prepara; comparação é
    missão separada.

## Top 10 Componentes TEOS

| # | Componente | Estado-alvo | Cita |
|---|---|---|---|
| 1 | Temporal Truth Layer (§5) | GREENFIELD | `atlas.teos.temporal_truth_record.v1` |
| 2 | Event-Sourced Timeline (§6) | PARTIAL (via `AtlasEvidenceLedger`) | `app/Services/Ai/Kernel/Evidence/AtlasEvidenceLedger.php` |
| 3 | Continuation Pack (§7) | LHIL M1 (proposto) | `atlas.long_horizon.continuation_pack.v1` |
| 4 | Context Manifest (§8) | GREENFIELD | `atlas.teos.context_manifest.v1` |
| 5 | Superior Compaction + Loss Accounting (§9) | PARTIAL (`AiCompactionService`) | `atlas.long_horizon.compaction_receipt.v1` |
| 6 | Freshness + Drift Gate (§10) | GREENFIELD (LHIL §13 component 10) | `LongHorizonContextFreshnessGate` |
| 7 | Replay Manifest (§11) | GREENFIELD | `atlas.teos.replay_manifest.v1` |
| 8 | Recovery Planner + 7 Safe Modes (§12) | GREENFIELD (LHIL §13 component 9) | `LongHorizonRecoveryPlannerService` |
| 9 | Causal Decision Graph (§13) | PARTIAL (eventos existem, grafo não) | `AtlasLedgerEvent` |
| 10 | Continuity Certification (§21) | GREENFIELD | `atlas.teos.continuity_certification.v1` |

(Componentes complementares — Time-Aware World Model §14, Strategic
Forgetting §15, Memory Promotion Pipeline §16, Monthly Review §19,
Operator Attention Queue §20 — listados em §25 DoD.)

## Top Riscos

- **Schema proliferation.** Vários "long_horizon.*", "teos.*",
  "programming.continuation.*" sem consolidação. Mitigação: regra forte
  no `atlas-canonical-glossary-and-naming.md` antes de cada novo schema.
- **Event explosion.** Tudo virar evento; timeline incomporta. Mitigação:
  lista fechada §6; resto vira log, não evento.
- **Compaction silenciosa.** Receipt opcional ou `must_keep_coverage` <
  1.0 aceito. Mitigação: gate canônico recusa.
- **Dev virando Forge.** TEOS dá tanto poder ao Dev que o boundary
  evapora. Mitigação: §17 e §24 explícitos; `escalate_to_forge` mode.
- **Forge sem timeline.** Forge usa Continuation Pack mas pula
  event-sourcing — perde causal graph, replay, drift. Mitigação: §18 +
  Continuity Certification recusa.
- **False resume.** Continuity Cert `passed` mas estado realmente stale.
  Mitigação: métrica `false_resume_rate` §23; toda detecção retroativa
  vira evento + ajuste de regra.
- **Authority creep.** Provider output sendo aceito como canônico ao
  longo do tempo. Mitigação: §5 authority chain; Memory Promotion §16.
- **Operator fadigado.** Attention Queue cresce mais rápido que
  decisões. Mitigação: §20 aging + dedup + digest; severity escalation.
- **Replay determinístico falha em prática.** Provider gera output
  diferente mesmo com mesmo manifest. Mitigação: `expected_state_hash`
  como gate, não como decoração; divergência ⇒ `read_only`.
- **TEOS virar marketing.** Doc cresce, código não. Mitigação: DoD §25 é
  "WIRED + receipt"; status `north_star` é honesto até cada componente
  shipar.
- **Benchmark prematuro.** Pressão por número antes da continuidade
  pronta. Mitigação: §26 + `benchmark_not_run` em toda doc da família.
- **Esquecimento mal calibrado.** Strategic Forgetting (§15) descarta
  algo `must_keep` real. Mitigação: tombstones + auditoria + Compaction
  Receipt rastreável.
