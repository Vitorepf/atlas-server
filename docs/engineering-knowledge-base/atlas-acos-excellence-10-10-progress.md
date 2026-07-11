# ACOS Excellence 10/10 — Progresso da obra

Estado durável dos 98 slices. Atualizar a cada land.

## Baseline de partida (2026-07-11, head `e9246cf510`)

| Dimensão | Leitura |
|---|---|
| Scorecard overall / pipeline | 9.92 / 9.77 (674/690) · hash `sha256:dc211175…f160bf` |
| memory:quality | score=63, status=needs_review · feedback 8539 all-neutral · usage 46290 |
| long-horizon gate | certified=false · blockers: series_day_count_below_floor, calendar_span_below_floor · series_day_count=7 |
| AEMOR readiness | 9/9 pass |
| evolution-score | 9.09 overall (exec 9.77 / intel 10 / autonomia 7.5) |

Receipt: `storage/app/atlas/evidence/acos-excellence-10-10-ledger.jsonl`

## Adendo 11/07 — autonomia (charter 06/07)

Relido do disco: plano v1 + implementation-prompt v1 pós-correção. Floor pétreo =
aplicação autônoma + registro completo + revisão-depois (NUNCA aprovação-antes).
Slices landados até aqui (EVI-01, EVI-02) = cadência/scheduler — **zero** write-path
de memória/promoção; nada a refatorar do modelo antigo.

## Checklist

### Onda 0 — Fundação e higiene (14)

| ID | Status | Commit | Aceite |
|---|---|---|---|
| EVI-01 | ✅ | 29801e9cd | suite 8/8 + launchctl exit=0 runs=2 |
| EVI-02 | ✅ | a100e5bf74 | suite 3/3: exit≠0 + fatal → heal; healthy sem heal |
| EVI-04 | ✅ | 89391f7e20 | suite 7/7 + schedule:list fable:delta-series=2 |
| FEE-02 | ✅ | 83036ee77 | fake-green removido; metrics_verified |
| TAXO-01 | ✅ | 6cc56ff1b | taxonomia única + dual-read |
| PIP-01 | ✅ | 81468f962 | freshness v2 FQN-anchored + --explain + dual-read |
| PIP-03 | ✅ | 388e340bd | ambiguous_test_ref não persiste receipt |
| ENG-02 | ✅ | 8e47c0082d | chave forge_execution_gate_enforcing default OFF + ledger ABERTO-até-ENG-02 |
| RAG-09 | ✅ | 2f7cf187b | linker_evidence via ledger vivo |
| COM-09 | ✅ | ec46e9dfbd | deletar emissor órfão ACOP→ACRS |
| FEE-12 | ✅ | bf2c53485 | auto-apply 3 filas + digest held |
| OPE-02 | ✅ | 03b3c75cf | dual-write registry+compounding |
| MED-01 | ✅ | c37ecb353b | atlas:measure:dual-read + ledger schema |
| VOL-01 | ✅ | 69417bae2 | volume check + janela_faminta |

### Onda 1 — Seams + medidores (19)

| ID | Status | Commit | Aceite |
|---|---|---|---|
| COM-01 | ✅ | 0177b45a5d | ledger pack + namespace canônico refs |
| ENG-04 | ✅ | 872591fe9 | certify no landing Autônomos |
| EVI-09 | ✅ | 248cb34a13 | rename fable→acos delta-series |
| EVI-05 | ✅ | 9fdbe5560 | contiguidade + anti-backfill [MEDIDOR] |
| EVI-06 | ✅ | ebc7b91ae | series_day_below_floor [MEDIDOR] |
| EVI-03 | ✅ | c2586c0166 | boot-smoke pregate + landing |
| PIP-02 | ✅ | 8bd1856a26 | post-mint seal freshness |
| MEM-04 | ✅ | 83f1b48 | ALL_RECALLED (5 decisões pétreas via atlas:memory:add) |
| RAG-01 | ✅ | e3730223d | usage no ponto de entrega [MEDIDOR] |
| RAG-04 | ✅ | 88b3fbc112 | multi-item memory no pack |
| CPT-01 | ✅ | 5962afd83 | must_keep extractor always-merge |
| FEE-06 | ✅ | aa963e5130 | PipelineMemoryRefsNoiseTest 3/3 |
| ENG-01 | ✅ | f6eae43f42 | AWIS gate no PipelineRunExecutor |
| ENG-10 | ✅ | be4d2954b | would-have-blocked telemetry [MEDIDOR] |
| OPE-05 | ✅ | 85a64991fa | MCP tool-usage telemetry |
| OPE-03 | ✅ | fe0b338fe8 | compounding lift served≠used |
| OPE-06 | ✅ | f60c7e36ab | evolution-score lift real [MEDIDOR] |
| SUB-01 | ✅ | 84a8532e03 | phpunit + ledger receipt com dump hash |
| ROL-01 | ✅ | 1d7506b5e | 6 gatilhos rollback pré-declarados |

### Onda 2 — Produtores reais (22)

| ID | Status | Commit | Aceite |
|---|---|---|---|
| OUTC-01 | ✅ | d3dbb77412 | 4 suites outcome spine + LIVE auto-promote |
| ENG-05 | ✅ | 047221c35c | single main-merge.lock + close evidence fallback |
| ENG-06 | ✅ | 356685355e | AWIS gate on AtlasTaskServingService::next |
| COM-02 | ✅ | e1cc31d44 | FeedbackDemotion measured-only |
| COM-03 | ✅ | 8ac45a7cf | ContextFeedbackAutoCommandTest 5/5 |
| COM-04 | ✅ | c093a5fb5 | DeliveredAttribution 3/3 + suite ARFL 10/10 [MEDIDOR] |
| COM-06 | ✅ | 0c2a0c241 | ContextAttribution cited vs diffed |
| MEM-05 | ✅ | f3b5cc192 | rehydrate title/summary + evidence_refs |
| MEM-02 | ✅ | 2cdea34c1f | relation verbs related/compatible/scoped/supersedes |
| RAG-02 | ✅ | 363b4ba41 | window_days=30 + all_time_* [MEDIDOR] |
| RAG-11 | ✅ | 41da0f98e | MemoryNegativeFeedbackPathTest |
| RAG-07 | ✅ | b712c4eb1 | MemoryMetadataBackfillTest |
| RAG-08 | ✅ | 1d98fcc57 | AurgDocsSourceTest |
| CPT-02 | ✅ | d9d93dfdc | ConversationCompactionReceiptTest [MEDIDOR] |
| CPT-05 | ✅ | 24b481ba9 | ProviderHandoffReceiptTest 3/3 |
| CPT-08 | ✅ | dda11bb60 | TokenEconomyShadowParityTest |
| FEE-03 | ✅ | 41da0f98e | AtlasMemoryFeedbackImplicitNegativeTest |
| PIP-04 | ✅ | 05e7028d6 | RemintTouchedCommandTest 3/3 |
| PIP-05 | ✅ | 0632d056b | mint dry-run + serviceClass binding (05b pending) |
| PIP-06 | ✅ | 6d5e27aac | verify-claims drift gate + --write |
| OPE-04 | ✅ | f2d2b9f91 | HybridRecallCompoundingArmDefaultOnTest 1/1 |
| EVI-07 | ✅ | 6258f3c12 | near-floor warnings + watchdog echo |

### Onda 3 — Consumidores (24)

| ID | Status | Commit | Aceite |
|---|---|---|---|
| FEE-04 | ✅ | 26a4a2c22 | feedback_ranking default OFF |
| FEE-05 | ✅ | 71a4f3267 | composer health/priority |
| FEE-07 | ✅ | 239fdb6a2 | lift memory spine |
| FEE-10 | ✅ | 5f8b4504d | session.auto delivered refs |
| FEE-11 | ✅ | f96d116aa | ranking-hints before/after |
| RAG-03 | ✅ | 89df3f5aa | anti-dominância top-K |
| RAG-05 | ✅ | f39af178b | golden 25 + recall@K |
| CORP-01 | ✅ | f111f9ce4 | capture+auto-admit+growth |
| MEM-03 | ✅ | 3c0e02a5b | 45d window [MEDIDOR] |
| MEM-06 | ✅ | c2a920cbc | curate scope |
| MEM-07 | ✅ | 15106351c | recall relations consumer |
| MEM-08 | ✅ | acc67fb50 | rationale policy |
| COM-05 | ✅ | eac190025 | measured-only policy |
| COM-07 | ✅ | eac190025 | policy-trend |
| COM-08 | ✅ | eac190025 | missed resolve |
| COM-11 | ✅ | be0eec499 | utility formula_version |
| CPT-03 | ✅ | 3a3a81957 | importance summary |
| CPT-04 | ✅ | 3a3a81957 | needs_review block overwrite |
| CPT-06 | ✅ | f706e88ba | recovery executor |
| CPT-07 | ✅ | 3a3a81957 | retention score |
| ENG-07 | ✅ | 0e25a55b4 | AWIS mutative invariant [MEDIDOR] |
| ENG-08 | ✅ | 0e25a55b4 | proven_real writers |
| ENG-09 | ✅ | dc8e6a0c0 | Dev gate parity |
| OPE-07 | ✅ | 477ce0dc8 | surface-review |

## Notas de leitura dupla pendentes de land

- **MEM-03** (2026-07-11, sem commit nesta sessão): medidor antigo lia `feedback.usage_total`/`feedback.feedback_total` e `retrieval_eval.recall_usage_total` com denominadores de vida-inteira ou janela RAG-02 de 30d; medidor novo lê `feedback.usage_window_total`/`feedback.feedback_window_total` e `retrieval_eval.recall_usage_window_total` na janela viva de 45d (`recall_concentration_window_days`) com piso `recall_concentration_min_recalls` retornando no-signal, preservando os contadores vida-inteira para auditoria. Testes: `AtlasMemoryQualityWindowingTest` + `MemoryQualityRetrievalWindowTest`.

### Onda 4 — Watchdog unificado + agregadores (13)

| ID | Status | Commit | Aceite |
|---|---|---|---|
| WDG-01 | ✅ | e756aaad9 | framework checks-plugin |
| MEM-09 | ✅ | ce6a9adc4f | quality --check + plugin |
| FEE-13 | ✅ | ce6a9adc4f | learning cadence check |
| RAG-10 | ✅ | ce6a9adc4f | AURG coverage gate |
| RAG-12 | ✅ | ce6a9adc4f | RAG anti-masking watchdog |
| COM-10 | ✅ | ce6a9adc4f | feedback-health |
| CPT-09 | ✅ | ce6a9adc4f | compaction soak-watch |
| PIP-08 | ✅ | ce6a9adc4f | scorecard stability check |
| OPE-08 | ✅ | ce6a9adc4f | lift cycle closure |
| OPE-10 | ✅ | ce6a9adc4f | pipeline diagnosis |
| ENG-11 | ✅ | ce6a9adc4f | enforce-readiness [MEDIDOR] |
| ENG-12 | ✅ | 099725c66 | end-to-end scorecard [MEDIDOR] |
| EVI-08 | ✅ | 099725c66 | longitudinal evidence gate doc |

### Onda 5 — Flips + certificação + re-prova (6)

| ID | Status | Commit | Aceite |
|---|---|---|---|
| ENG-13 | ⬜ | — | — |
| ENG-14 | ⬜ | — | — |
| ENG-15 | ⬜ | — | — |
| PIP-07 | ⬜ | — | — |
| CPT-10 | ⬜ | — | — |
| ADV-01 | ⬜ | — | — |

**Feitas: 92/98 · Faltam: 6 · Próximo: onda 5 (PIP-07 CPT-10 ADV-01; ENG flips PRECISAM OK)**
