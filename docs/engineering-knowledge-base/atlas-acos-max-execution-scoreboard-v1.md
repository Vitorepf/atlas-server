# ACOS Max — Scoreboard de Execução v1 (estado durável; a memória entre sessões e entre IAs)

> **Como usar:** este arquivo é ESTADO, não spec. Cada slice muda de estado NO MESMO commit que o implementa. Estados válidos: `pending` · `in_progress` · `landed(<sha>)` · `suspended(ELEV-28)` · `refutado(<motivo>)` · `blocked_by:<dep>` · `pending_window:<janela>`. Anotação opcional de paralelismo (TETO-06): `claimed_by:<engine>` por item (claim = FAMÍLIA×lote via `AcosMaxParallelExecutionProtocol`; hot-files = ELEV-22). Uma IA nova retoma lendo APENAS: plano (`atlas-acos-max-frontier-plan-v1.md`) + playbook (`atlas-acos-max-implementation-playbook-v1.md`) + este scoreboard. Marque `[x]` só em estado terminal (`landed`/`suspended`/`refutado`). Slices de 2 partes (freeze/série etc.) só fecham com as duas. NUNCA reordenar lotes nem editar aceites aqui.
>
> **100% =** 255/255 em estado terminal + gates L0–L12 verdes + MARCO ESP-V1 + critérios finais NO MESMO CORTE (a lista completa está no LOTE 12 abaixo e no cabeçalho do playbook: §vii itens 1-10 + §viii itens 11-17 + pétreos §xiii + §xv itens 18-21 do plano + M>1 e R>0).

## LOTE 0 — Higiene imediata (M0) — GATE: hooks 1×/evento · baseline latência carimbada · receipts vivos · refs impressos · ESP-00 publicado
- [x] ESP-00 — landed(bb15641a19) · Evidence Ledger `EVIDENCE_PACKED` event_id=`01KXA1JWR53YAYT915GTGS0RXB` · content_hash=`e71448b0b81b65da3e16aa86927f9c0c632234efb7cae13b2fad789c3efd4b87` · unexplained=0 · JSONL runtime=`storage/app/atlas/evidence/acos-max-esp-00-ground-truth.jsonl`
- [x] TETO-06 — landed(a5d17690568f) · protocolo multi-engine: claim por FAMÍLIA×lote; scoreboard `claimed_by:<engine>`; hot-files ELEV-22 serializados; caso negativo = pula com registro
- [x] TETO-09 — landed(8e8b54d42c) · Evidence Ledger event_id=`01KXA1SZ0TYE2VZ9HAG46YDY0D` · content_hash=`b6f672b050b12282540eeb797bedfec7ca70b7546fa7efa306c7c5da757c509d` · opção b endurecimento áreas 2/11/14 · gatilhos MULTX-02/incidente
- [x] MAXG-01 (mínimo) — landed(f1f75d5dda) · freeze `aobg.latency_ledger.v1` content_hash=`9f1338cdd9d78e3a1b8e360455a8684fbdf22e8b0364877fb2704b2c5fe23fd3` · JSONL `storage/app/atlas/evidence/acos-measure-freeze.jsonl` · ledger `storage/atlas/aobg/latency-ledger/` · cmd `atlas:context:latency` · WDG `wdg-01.aobg_latency` · aceite pleno p95-de-1d = pending_window
- [x] MAXE-02 — landed · removed absolute duplicate AOBG hooks in .claude/settings.json (1×/event)
- [x] MAXE-03 — landed · atlas-ctx.sh activate TTL 6h + hard timeout/perl-alarm on pack
- [x] MAXF-01 — landed · compactForScope failed_open + repair migration receipts; live Schema@5433 pending_window (pgsql hang)
- [x] MAXE-01 — landed(8b30866a95bd) · renderMarkdown prints ref=<canonical> per item + citation footer; deliveredFromPack == rendered refs; ARFL share>0 = pending_window
- [x] ELEV-22 — landed(fda87090b3) · hot-list acos-max-elev-22.v1 + PreToolUse advisory claim check + release on scoped commit; fail-open; TTL 900s

## LOTE 1 — Freios (F0) — GATE F0: porta única observe · ledgers imunes · event_hash+cadeia+âncora · captura operador viva · restore drill · attempts terminais
- [x] ASI-05 — landed · live ledger testing guard for AtlasDecide outcomes + brain heartbeat · cleanup receipt `storage/app/atlas/evidence/acos-max-asi-05-ledger-cleanup.jsonl` hash=`sha256:10bedba4feaa78658219c341fbfa42ec5834a7c8fe5477a28d7275913f8e3c2e` · removed live_outcomes=7 heartbeat=2 · focused hash acceptance identical
- [x] ASI-01 — landed · ConstitutionGate live caller via `AtlasTaskScopedCommitter` → `AtlasLoopMergeActuator::commitWithConstitutionToken`; presence sentinel + negative forbidden-self-target receipt green
- [x] ASI-02 (+ELEV-08) — landed · observe-only Registry memory admission chokepoint runs G0-G8 on record/upsert/curate; CandidateGate/WriteBack delegate same evaluator; MCP/verbatim/TEOS direct admissions routed through Registry; DB-level direct admission scan=0; >=50 real writes window=pending_window(ELEV-14)
- [x] MAXI-01 — landed · real G3/G4 detectors wired at ASI-02 admission chokepoint: G3 derives `contains_secret`/sensitive from CodeGraphSecretScanner + LocalAgentSecretScanner; G4 derives `contradicts_newer` from newer same-scope memory via FactPairPolarityContradictionDetector + NumericRangeOverlapContradictionDetector; caller G3/G4 flags cannot override derived source signals; observe window ≥50 remains pending_window(ELEV-14)
- [x] ASI-03 — landed · Postgres 16 @5433 memory tuning live SHOW passed (`shared_buffers=6GB`, `effective_cache_size=24GB`, `maintenance_work_mem=2GB`) · conf `docker/postgres/16/acos-max-asi-03.conf` · runbook `docs/engineering-knowledge-base/atlas-acos-max-asi-03-postgres-tuning-runbook.md` · Evidence Ledger `storage/app/atlas/evidence/acos-max-asi-03-postgres-tuning.jsonl` hash=`sha256:1c0bbcee6c6174659d77ffd25c759a67eab5c615a3289b36053012914ab325cf`
- [x] ASI-04 — landed · hook coalescing/cap/load-shed shell backpressure · bancada `scripts/verify-asi04-hooks.sh`: coalescing runs=1 coalesced=5; global_cap runs=1 cap_skips=2; forced_load PostToolUse runs=0 shed=1; UserPromptSubmit no-shed runs=1
- [ ] ESP-01 — blocked_by:ELEV-12,MULTX-03 (deps L2/L7; mecanismo aguarda freezes)
- [x] MAXK-07 (+ELEV-09) — landed(a04b26a861) · GovernanceFloorRegistry + GovernanceAmendmentLedger; floors migrated; completeness gate; `atlas:governance:amendments --json`; ELEV-09 sentinels
- [x] MAXL-01 — landed · additive ledger repair migration restores `event_hash`/`scope_type`/`scope_id`, backfills stored rows, and guards scoped lookups
- [x] MAXL-02 (+ELEV-11) — landed · Evidence Ledger `prev_event_hash` scoped hash-chain (`scope_type:scope_id`, fallback `correlation_id`); legacy rows labelled `chain_basis=legacy_unchained`; verifier detects gap/tamper; daily chain-head anchor writes git-tracked JSONL with declared threat model
- [x] MAXN-01 — landed · operator_* schema reconciled via idempotent 2026_06_08_130000..150000 migrations; sqlite proof `operator_learning_signals` + live runtime capture writes `chat_explicit_operator_signal`; WDG `maxn-01.operator_learning_capture_schema` alerts on flag-ON+missing table; capture-fail counter exposed via cache report (`persistence=cache_counter_no_jsonl`, ASI-05 no new JSONL); phpunit guard proves no pgsql@5433; live migrate @5433 pending_window(SUB-01 snapshot not verified: ELEV-17 manifest missing `memory-substrate.sql`)
- [x] ELEV-17 — landed · `atlas:substrate:restore-drill --json` restores SUB-01 snapshots only into disposable target (guard refuses canonical @5433); fixture acceptance `restored_ok`; corrupted snapshot returns `restore_failed` with named diff; WDG `wdg-01.substrate_restore_drill` alerts when last success >45d; live local snapshot restore pending_window(real SUB-01 manifest points to missing `memory-substrate.sql`)
- [x] TETO-05 — landed · `atlas:acos:obra-retro --lote=0 --json` emite outcomes OUTC-01 com série `obra:acos-max` + candidatos pela fila normal CaptureQualityGate/ASI-02; aceite sqlite/fake-ledger cobre Lote 0 e rejeição de boilerplate

## LOTE 2 — Réguas v2 congeladas (M1) — GATE M1: todo freeze com hash+judge≠author · golden v2 targets_available==cases · registry de séries populado
- [x] ELEV-02 (ASI-METRIC) — landed · freeze `asi.metric.m.v1` content_hash=`edaa1866b556b356eeff8cf5f685bc4b7e8a56ba556ecd91ed4e2846b1977b6b` · formula_version=`asi_metric_m.v1` · denominator_min=8 · cmd `atlas:acos:m-series --json` · caso negativo `insufficient_signal` · registry=registered(ELEV-20s)
- [x] ELEV-12 — landed · freeze `acos.verified_share.v1` content_hash=`4d097e24e6c81ff6d5a1e991c153179a81194c6ae52a164c343e9c98352cfe07` · `judge_engine_id=codex-elev12-judge` ≠ `author_engine_id=cursor-acos-max-elev12` · cmd `atlas:acos:verified-share --json` · live window 14d: status=`below_threshold`, verified=0/65, share=0.0, threshold=0.80, min=50 · registry=registered(ELEV-20s)
- [x] ELEV-20s — landed · registry `AcosMaxMeasureSeriesRegistry` backfills live series `{series,path/table,ttl_days}` for landed Max medidores (MAXG-01, ELEV-02, ELEV-12, MAXL-02) plus ASI-05/ESP-00 receipts; WDG `elev-20s.dead_series_registry` alerts on `age(last_append)>ttl`; architectural guard fails any new landed `[MEDIDOR]` missing registry registration
- [x] ELEV-25 — landed · freeze `acos.operator_review_debt.v1` content_hash=`2884a7af09a225e02e444ee43f4b34391a793a1ef7dcb18dfcc5b6e0a41dcd40` · digest `atlas:ai:weekly-memory-digest --json` publica 4 métricas `{itens_auto_aplicados_nao_revisados, idade_max_da_fila, itens_revisados_na_janela, tempo_medio_inspecao}` · WDG `elev-25.operator_review_debt` alerta quando `idade_max_da_fila > 7d` · auto-apply slow-cap efêmero `50→10` sem fila de aprovação · registry=registered(ELEV-20s)
- [x] MAXG-01 (completo) — landed · reader/trend completo em `atlas:context:latency --json` com `window_1d`, `.ops.*.p50_ms/.p95_ms` e `trend.ops.*`; ELEV-20s registry confirmado para `aobg.latency_ledger.v1`; janela real 2026-07-12 status=`ok`, samples=2712, pack_p95_ms=15356.586, recall_p95_ms=3081.782, hook_p95_ms=15180; caso negativo `insufficient_signal` sem samples; tests: `php artisan test tests/Unit/Ai/AcosMax/Maxg01LatencyLedgerTest.php`
- [x] MAXG-02 — landed · ARLCG consumes MAXG-01 latency-ledger pack p95 as `basis=observed`; empty ledger falls back to `basis=estimated`; receipt schema bumped to `atlas.aucri.retrieval_cost_latency_receipt.v2`; tests: `php artisan test tests/Unit/Ai/Context/AtlasRetrievalCostLatencyGovernorServiceTest.php tests/Feature/Ai/Context/RetrievalCostLatencyGovernorTest.php`
- [x] MAXA-03 — landed · embedding provenance columns on 4 vector tables (`embedding_model`, `embedded_content_hash`); indexers stamp provider/model + exact embedded text hash; vector reads exclude known cross-model rows while legacy NULLs survive until backfill; `atlas:memory:embed-backfill --stale --json` reembedded entries=106 verbatim=1 notes=169 attachments=0 skipped=0; psql null-provenance counts=0/0/0/0; tests: `php artisan test tests/Feature/Ai/Memory/AtlasMemoryVectorSearchServiceTest.php tests/Unit/Semantic/EmbeddingProvenanceTest.php`
- [x] MAXB-02 — landed(e41f8e19c) · GÊMEO fechado pelo landing do MAXG-04 (mesmo sha); `memory_recall_golden_v2` cases=25 targets_available=25 r5=0.56 fd=6 judged=true; frozen_set_hash=`3525f39713ba1e5339233330a5b0b4a0e4f3314560cda424902919cd27455e77`
- [x] MAXG-04 — landed(e41f8e19c) · golden v2 live-anchored por `content_hash` de entradas ativas reais; `v1.json` intocado; ledger judge_event_hash=`76a5026a1211aeafdd9e234471d4125060d2798bc65d422f407b6d835fd08c58`; live recall status=`attention` por fd=6 (medido, não mascarado); tests: `php artisan test tests/Feature/Ai/AtlasAiLocalRagBenchmarkCommandTest.php`
- [x] MAXH-01 — landed · freeze `atlas.memory.temporal_truth.v2` content_hash=`36dcc27f7ccc5006d5f1a6cde2ebd4d5fd719d4e6a6c0781dc9f3e50fc84a23a` · cmd `atlas:memory:temporal-quality --json` · live status=`attention`, temporal_provenance=0/90, truth_density_v2=0/86, supersession_maintained=0/0 · registry=registered(ELEV-20s); MAXI-02 registry gap closed with MAXI-03 · tests: `php artisan test tests/Feature/Ai/Memory/AtlasMemoryTemporalQualityCommandTest.php`
- [x] MAXI-02 — landed · CaptureService keeps `atlas.capture.cognitive_immune_audit.v1` byte-stable and emits real evaluator shadow verdict under `immune_audit_v2` (`atlas.capture.cognitive_immune_audit.v2`); `AtlasMemoryCognitiveImmuneLearningKernelService::evaluatePromotion()` adapts legacy envelope from `CognitiveImmunePromotionGateEvaluator`; audit v2 hash changes with verdict; registry=registered(ELEV-20s); tests: `php artisan test tests/Feature/Ai/Cognition/ImmuneVerdictSingleAuthorityTest.php`
- [x] MAXI-03 — landed · freeze `atlas.immune.calibration.v1` content_hash=`bf89655974a7c1721a1f11a3bf5d30851ac41c380ff7731bb2bcf16f41838b14` · append-only table `immune_verdict_ledger` records capture-pipeline verdicts with labels `true_block|false_block|missed_poison`; cmd `atlas:immune:calibration --json` read-only exposes FP/FN bands by gate+writer and seed `known_miss_seed` lower_bound_known_miss=1/1 with `insufficient_sample` until n≥10; digest publishes `immune_calibration`; registry=registered(ELEV-20s); tests: `php artisan test tests/Feature/Ai/Cognition/ImmuneCalibrationCommandTest.php tests/Feature/Ai/AcosMax/Elev20sDeadSeriesRegistryTest.php`
- [x] MAXJ-01 — landed · freeze `atlas.ai.lesson_quality.v2` content_hash=`411b23244dd0603539f5914cfe3e8101e928bca1ad2bcc7580e111bc971fb26b` · formula_version=`atlas_ai_lesson_quality_v2` · denominator_min=1 · cmd `atlas:ai:lesson-quality --json` · live status=`insufficient_signal`, candidates=23, measured=0/23, groups=1 · registry=registered(ELEV-20s) · v1 RecallUseLift aggregate unchanged/dual-read · tests: `php artisan test tests/Unit/Ai/Compounding/AtlasLessonQualityServiceTest.php`
- [x] MAXJ-05 — landed · freeze `atlas.ai.lesson_type_yield.v2` content_hash=`ce32254a7098e9b2846a0bdc6ab66b704cee6d9b86f57cb2da59f7f80dff8ca3` · formula_version=`atlas_ai_lesson_type_yield_v2` · denominator_min=8 hard floor (`max(8, requested_min_cases)`) · cmd `atlas:ai:lesson-type-yield --json` · live status=`insufficient_signal`, memory_types=0, measured_types=0, feedback_events=153, baseline=153 · aggregate v1 `min_cases_per_arm` unchanged/dual-read · registry=registered(ELEV-20s) · tests: `php artisan test tests/Unit/Ai/Compounding/AtlasLessonQualityServiceTest.php`
- [x] MAXK-01 — landed · freeze `atlas.decide.route_regret.v2` content_hash=`864072845d2c5b3b6d7a4050cac0fed51885530004ed717c2c76f84ddc0d5ffc` · formula_version=`route_regret_v2.gross_scope` · denominator_min=5/min_evidence=3 · cmd `atlas:atlas-decide:live-feedback --regret --json` · live status=`insufficient_signal` (honesto até ASI-06 encher escopo grosso) · contrafactual de exploração usa `would_have_been_greedy_provider`; registry=registered(ELEV-20s) · tests: `php artisan test tests/Feature/Ai/AtlasDecide/AtlasDecideLiveFeedbackRegretCommandTest.php`
- [x] MAXK-04 — landed · `AtlasDecideGatewayConsultationService::consult()` emite Decision Receipt v2 via `DecisionReceiptIssuer` + OperationEnvelope adapter; JSONL `gateway_consultations.jsonl` preservado/aditivo com `decision_id`, `routing_basis`, `evidence_refs`; consumidor `atlas:ai:decision-receipt-report --envelope=<id> --json` lista basis/evidence; tests: `php artisan test tests/Unit/Ai/AtlasDecide/AtlasDecideGatewayConsultationServiceTest.php tests/Feature/Ai/AtlasAiDecisionReceiptReportCommandTest.php tests/Feature/Ai/AtlasDecide/AtlasDecideGatewayDecisionReceiptTest.php`
- [x] MAXL-06 — landed · freeze/report-only `atlas.evidence.delta_attribution.v1` content_hash=`ca7ea0e3b1757578a1ec961689ae3dd2b4726f1528e6fd0ae439cb91ea3585d3` · cmd `atlas:acos:delta-attribution --json` · status=`pending_window`, basis=`unavailable`, deps `ASI-11/MAXL-04` absent; labels causal language as `correlational_attribution`; registry=registered(ELEV-20s)
- [x] MULTK-01 — landed(febc26dd6a) · freeze `atlas.decide.cost_outcome_uncertainty.v1` · `costOutcomeCandidates()` publishes Beta posterior `{lower_bound,upper_bound,n}` from evidence only; n<3 ⇒ `insufficient_n`; selection byte-identical; registry=registered(ELEV-20s)
- [x] MULTN15-01 — landed(c1a1f27bd4) · `atlas:operator-profile audit --json` lists all operator profile items with denominator, provider-blocked flag and correction handles; `correct --verdict=reject` pauses item + records `operator_correction`, removing it from context
- [x] MULTN15-02 — landed(f525fa7db6) · freeze `operator.approval_history.v1` · cmd `atlas:operator-approval-history --json` read-only over `ai_operator_approvals`; cells expose asks/approved/denied/expired/reused/n; n<10 ⇒ `insufficient_n`; registry=registered(ELEV-20s)
- [x] MULTN17-04 (freeze) — landed · freeze `atlas.originator.predicted_impact_calibration.v1` content_hash=`3bd042bcd6853955bf5ec576926d6b20c772459ac62eba760cf549ed1a075525` · cmd `atlas:brain:predicted-impact --json` · status=`insufficient_signal`, originations unresolved until ≥20 real outcomes; registry=registered(ELEV-20s)
- [x] MULTX-01 (freeze) — landed · freeze `acos.flywheel.loops.v1` content_hash=`c4dc17d145906fee637daec8cbe703c227fd551ab88ab6e7725183048a81ff0f` · cmd `atlas:flywheel:loops --json` · status=`insufficient_signal`, valid loop definition requires `proven_real=true`; registry=registered(ELEV-20s)
- [x] MULTX-06 (freeze) — landed · freeze `acos.learning_latency.v1` content_hash=`81f72b0377d282d00e23700dd07969ecb2cfc95ddb720992900a4aa0612ae114` · cmd `atlas:flywheel:learning-latency --json` · status=`insufficient_signal`, `never_delivered` remains in denominator; registry=registered(ELEV-20s)
- [x] MULTJ-01 — landed · freeze `atlas.ai.lesson_half_life.v2` content_hash=`724dcabe1e868b9c396127a76ffe6eab3160d7ab7936cc3daafbf64f2dd97b56` · cmd `atlas:ai:lesson-half-life --json` · status=`insufficient_signal`, bucket_width=2w, bucket n<8 ⇒ insufficient; registry=registered(ELEV-20s)
- [x] MULTJ-02 — landed · freeze `atlas.ai.lesson_semantic_dedup.v1` content_hash=`4208f95df65c2effc27a9a777da85e20148ee0bf55269f0e38999f9bcbc49ad2` · cmd `atlas:ai:lesson-dedup-calibration --json` · status=`pending_window`, observe mode actual_merge_count=0, threshold=0.88, no enforce
- [x] MULTJ-03 — landed · freeze `atlas.ai.counterfactual_lift.v2` content_hash=`63253b0a1341dcb15d8418f89e80b268d0a0aae8854c4a6356e43caeb845c588` · cmd `atlas:ai:counterfactual-lift --json` · status=`insufficient_signal`, n_pairs=0, sample_rate=0.05, peek `record_usage=false`; registry=registered(ELEV-20s)
- [x] TETO-02 — landed · freeze `mission_e2e.v1` content_hash=`3a80721e1d35580ba1fb78d7900dfa92dd3b85f2ab4b4b69b057fc16cf672aac` · cmd `atlas:mission:e2e --json` · status=`insufficient_signal`, denominator_min=20 operator requests, abandoned missions count as not completed; registry=registered(ELEV-20s)

## LOTE 3 — Eficiência estrutural + protocolo (M2) — GATE M2: floor intermediário ELEV-16 · toda flag no PromotionProtocol · :356 wirado · daemon com manifest
- [x] ELEV-26s — landed
- [x] MULTX-09 — landed · `atlas:windows --json` publica DAG read-only do PromotionProtocol com caminho crítico/dias restantes, grupos paralelizáveis e watchdog `dead_window`; janelas `not_started` ficam sem ETA fabricada; série `acos.windows_orchestrator.v1` registrada (ELEV-20s); test: `php artisan test tests/Feature/Console/AtlasAcosWindowsCommandTest.php`
- [x] MAXA-02 — landed(abf571a8f) · memo por request + cache persistente por hash(provider/model/text); teste prova 2 chamadas iguais no processo = 1 embed e novo serviço = 0 embeds; troca de modelo reembeda
- [x] MAXA-07 — landed · vector indexes now build/repair/convert as HNSW `m=16, ef_construction=64` across the 4 pgvector tables; live conversion migration drops old ivfflat indexes; sqlite remains no-op; test: `php artisan test tests/Unit/Ai/AcosMax/Maxa07HnswVectorIndexMigrationTest.php`
- [x] MAXA-01 — landed(abf571a8f) · daemon residente `semantic_rag` via Unix socket JSONL + manifest sha256; `SemanticRagRuntimeClient` usa daemon e cai para spawn se socket/daemon ausente; probe warm_embed_ms=3.324
- [x] MAXA-10 — landed · semantic spine external fallback default-OFF; OpenAI requires explicit provider or opt-in fallback flag (key alone is ignored); `atlas:semantic:embedding-info --json` reports `external_fallback=opt_in_off`; test: `php artisan test tests/Unit/Semantic/EmbeddingServiceTest.php`
- [x] MAXB-01 — landed(abf571a8f) · GÊMEO fechado pelo landing MAXA-01+MAXA-02 (mesmo sha); ranking intocado, cache/daemon apenas removem overhead; fallback spawn negativo coberto
- [x] MAXE-04 — landed · `packFor()` records `timings_ms` for code_graph/reality_graph/memory/total; COM-01 delivered-pack ledger schema v2 appends rows and prunes on read; MAXG-01 latency ledger mirrors section ops `pack.section.*` for trend via `atlas:context:latency --json`; refs/hash payload unchanged; tests: `php artisan test tests/Feature/Ai/AtlasOpenBrainContextPackServiceTest.php --filter=DeliveredPackLedger` + `php artisan test tests/Unit/Ai/AcosMax/Maxg01LatencyLedgerTest.php`
- [x] MAXE-05 — landed · AOBG pack cache keyed by workspace + normalized query_hash + corpus_fingerprint; fingerprint covers code-symbol/module freshness and memory-entry freshness; cache hit preserves `context_pack_hash`, emits hit/miss telemetry to COM-01, and invalidates when memory corpus changes; test: `php artisan test tests/Feature/Ai/AtlasOpenBrainContextPackServiceTest.php --filter=pack_cache`
- [ ] MAXB-09 — pending
- [ ] MAXG-09 — pending_window(MAXG-01 p95 pós-land) · mecanismo landed: `AtlasRetrievalEvaluationBenchmarkArenaService` scoped + memo `risk:golden_set_hash`; 3 avaliações idênticas persistem 1 run-summary e duplicatas retornam `request_scope_duplicate`; arena tests verdes; MAXG-01 atual tem direction=`unknown` (sem baseline anterior suficiente)
- [ ] MAXG-10 (+ELEV-16) — blocked_by:MAXG-09-p95-window · ELEV-16 floor intermediário exige queda p95 UserPromptSubmit ≥30% vs baseline semanal sem queda golden/counts; MAXG-09 ainda `pending_window`
- [ ] MAXH-03 — pending
- [ ] MAXK-05 — pending
- [ ] MAXK-06 — blocked_by:MAXK-05
- [x] MAXM-05 — landed · `surface_contract.v1.1` + `annotations.atlasContract` por tool (`stability/since/provider_bound/side_effect/cost_tier`); guard independente inspeciona handler read e recusa write-path rotulado read; `atlas_capabilities` expõe contrato por-tool
- [x] MAXM-08 — landed · `tools/list` default expõe 9 primárias + `atlas_tool_search` (≤10); `atlas_tool_search` resolve por intenção e retorna contratos por-tool; 64 tools legadas seguem chamáveis por nome; surface-review `removed_tools=0`, coverage=65/65
- [x] MAXN-03 (scaffold) — landed · `OperatorProfileFeedbackService` calcula ajuste puro por comportamento com n<10 ⇒ `insufficient_sample`, apply gera reverse-handle e revert restaura confidence/status; digest expõe `behavior_confidence_curve`; fecho M4 continua pendente em `MAXN-03 (fecho)` até MAXN-02/outcomes reais
- [ ] MULTN15-06 — pending
- [x] ESP-02 — landed · `AtlasNativeWorkerCommandPlanRunner` valida comandos estruturados por `argv/cwd/timeout_s/env_allowlist`; `sh|bash|zsh -c` em argv é recusado com `shell_escape_argv`; shell-string legado é contado em observe e recusado com `structured_command_enforce`; test: `php artisan test tests/Unit/Ai/SelfConstruction/NativeWorker/AtlasNativeWorkerCommandPlanRunnerTest.php`
- [x] ESP-03 — landed · `AtlasTestAttestationService` sela `{runner,suite,n_tests,n_assertions,exit_code,tree_hash}`; `AtlasTaskCommitVerificationGate` emite attestation para task tests e `AtlasTaskScopedCommitter` recusa stale/unrecognized antes do commit; `n_tests=0` permanece vacuous/false-green, nunca verde; tests: `php artisan test tests/Unit/Ai/SelfConstruction/AtlasTestAttestationServiceTest.php tests/Feature/Ai/SelfConstruction/AtlasTaskLandingCertifyTest.php`
- [x] ESP-05 — landed · live outcomes agora carregam `verified_basis` + `certified_receipt_id`; routing/cost-outcome só certifica `server_verified|gates_passed` com receipt, `claimed|absent` tem peso zero e contador exposto (`zero_weight_outcome_count`/`zero_weight_success_rate`); série `atlas.decide.zero_weight_outcomes.v1` registrada (ELEV-20s); tests: `php artisan test tests/Unit/Ai/AtlasDecide/AtlasDecideCostOutcomeRouterTest.php tests/Unit/Ai/AtlasDecide/AtlasDecideLiveOutcomeFeedbackServiceTest.php tests/Feature/Ai/AtlasDecide/LiveOutcomeProvenRealWritersTest.php`
- [ ] ELEV-19 — pending
- [ ] ELEV-24 — pending
- [ ] ELEV-27 — pending
- [ ] ELEV-29s — pending
- [x] TETO-08 — landed · `atlas:acos:cockpit --json` agrega read-only M, R, loops/funil, janelas/caminho crítico, flips pendentes, review-debt, freios e lote corrente do scoreboard; cada seção cita fonte, fontes futuras ficam `unavailable` (ex.: MULTX-02/funnel) e o comando declara `mutates_state=false`; test/smoke: `php artisan test tests/Feature/Console/AtlasAcosCockpitCommandTest.php` + `php artisan atlas:acos:cockpit --json`

## LOTE 4 — Ligar o fluxo (F1; FLIPS = OPERADOR) — GATE F1-fluxo: ≥5 outcomes/dia 7d · decision_id 100% · reflection/pattern com dado real · distiller shadow dual-read
- [ ] MAXC-01 — pending
- [ ] MAXC-02 — pending
- [ ] MAXC-06 — pending
- [ ] ASI-06 (preflight; FLIP = operador) — pending
- [ ] ASI-07 (floor E2E; FLIP = operador) — pending
- [ ] ASI-08 — pending
- [ ] ASI-09 — pending
- [ ] (CHECK v1 onda 5: ENG-13/14/15, PIP-07, CPT-10, ADV-01 — só verificar estado; dono = executor v1) — pending
- [ ] MULTV-10 (seam default-OFF) — pending
- [ ] ASI-10 (flip via janela ELEV-26s) — pending

## LOTE 5 — Corpus e cobertura (M3) — GATE M3: corpus qualificado ≥300 (taxa gated, nunca contagem-aceite) · provenance 100% · golden v2 medindo vivo
- [ ] ELEV-21 — pending
- [ ] MAXA-05 — pending (dep externa: MEM-05 v1)
- [ ] MAXA-06 (fase 1) — pending
- [ ] MAXD-01 — pending
- [ ] MAXD-09 — pending
- [ ] MAXD-06 — pending
- [ ] MAXD-08 — pending
- [ ] MAXD-02 — pending
- [ ] MAXH-02 — pending
- [ ] MAXI-04 — pending
- [ ] MAXM-01 — pending
- [ ] RAGX-01 — pending
- [ ] TETO-01 — pending (N-Capture Drill — 1ª execução)

## LOTE 6 — VERTICAL 1 (subset mínimo + MARCO; gate F1→F2)
- [ ] MAXN-04 — pending
- [ ] MULTN17-03 — pending
- [ ] ESP-09 — pending
- [ ] ESP-04 — pending
- [ ] ESP-07 — pending
- [ ] MAXJ-02 — pending
- [ ] MAXH-04 — pending
- [ ] MAXH-05 — pending
- [ ] ESP-08 — pending
- [ ] MULTX-01 (série cheia) — pending
- [ ] **MARCO ESP-V1** (loops_complete ≥ 1, proven_real, ids encadeados, zero fixture) — pending

## LOTE 7 — M4 completo — GATE M4: measured_share subindo · funil com 3 executores den>0 · receipt MULTV-01 acumulando · zero atuador sem shadow
- [ ] MAXA-04 — pending
- [ ] MAXA-08 — pending (GÊMEO — fecha no landing do MAXB-03; ver plano vi-b)
- [ ] MAXB-03 — pending
- [ ] MAXB-04 — pending
- [ ] MAXB-05 — pending
- [ ] MAXB-06 — pending
- [ ] MAXB-07 — pending
- [ ] MAXB-10 — pending (pode ser absorvido pelo MAXB-03 — registrar decisão)
- [ ] MAXC-03 — pending
- [ ] MAXC-04 — pending
- [ ] MAXC-05 — pending
- [ ] MAXD-03 — pending
- [ ] MAXD-04 — pending
- [ ] MAXD-07 — pending
- [ ] MAXE-08 — pending
- [ ] MAXE-06 — pending
- [ ] MAXE-07 — pending
- [ ] MAXF-02 — pending
- [ ] MAXF-03 — pending
- [ ] MAXF-08 — pending
- [ ] MAXF-10 — pending
- [ ] MAXF-04 — pending
- [ ] MAXF-06 — pending
- [ ] MAXF-07 — pending
- [ ] MAXF-05 — pending
- [ ] MAXH-06 — pending
- [ ] MAXI-05 — pending
- [ ] MAXI-06 — pending
- [ ] MAXI-07 — pending
- [ ] MAXI-08 — pending
- [ ] MAXJ-03 — pending
- [ ] MAXJ-04 — pending
- [ ] MAXJ-06 — pending
- [ ] MAXK-02 — pending
- [ ] MAXK-03 — pending
- [ ] MAXL-03 — pending
- [ ] MAXL-04 — pending
- [ ] MAXL-05 — pending
- [ ] MAXL-07 — pending
- [ ] MAXL-08 — pending
- [ ] MAXM-02 — pending
- [ ] MAXM-03 — pending
- [ ] MAXM-04 — pending
- [ ] MAXM-06 — pending
- [ ] MAXM-07 — pending
- [ ] MAXN-02 — pending
- [ ] MAXN-03 (fecho) — pending
- [ ] MAXN-05 — pending
- [ ] MAXN-06 — pending
- [ ] RAGX-08 — pending
- [ ] MULTK-03 — pending
- [ ] MULTK-04 — pending
- [ ] MULTK-02 — pending
- [ ] MULTN15-03 — pending
- [ ] MULTN15-04 — pending
- [ ] MULTN15-07 — pending
- [ ] MULTN17-07 — pending
- [ ] MULTN17-01 — pending
- [ ] MULTN17-08 — pending
- [ ] MULTN17-04 (curva) — pending
- [ ] MULTH-01 — pending
- [ ] MULTH-02 — pending
- [ ] MULTH-07 — pending
- [ ] MULTH-03 — pending
- [ ] MULTV-01 — pending
- [ ] MULTV-07 — pending
- [ ] MULTV-05 — pending
- [ ] MULTX-03 — pending
- [ ] MULTX-02 — pending
- [ ] MULTX-04 — pending
- [ ] MULTX-06 (série) — pending
- [ ] ESP-06 — pending
- [ ] TETO-03 — pending (Trajectory Vault — liga assim que ESP-04 landar)

## LOTE 8 — Eixo reflexivo (F2) — GATE F2: cascata ≥50 com estados formais + SLA · séries multi-ator · receipt com self_model + banda
- [ ] ASI-11 (completo; ELEV-10/23) — pending
- [ ] ASI-12 — pending
- [ ] ASI-13 — pending
- [ ] ASI-14 — pending
- [ ] ASI-15 — pending
- [ ] MAXK-08 — pending

## LOTE 9 — Certificação + espinhas (M5) — GATE M5: ADV-Max sem refutação pendente · pétreos §xiii verdes · cascata MULTV-02 advisory ≥30
- [ ] MAXG-03 — pending
- [ ] MAXG-05 — pending
- [ ] MAXG-06 — pending
- [ ] MAXG-07 — pending
- [ ] MAXB-08 — pending
- [ ] MAXH-07 — pending
- [ ] MAXH-08 — pending
- [ ] MAXH-09 — pending
- [ ] MAXH-10 — pending
- [ ] MAXI-09 — pending
- [ ] MAXJ-07 — pending
- [ ] MAXJ-08 — pending
- [ ] MAXK-09 — pending
- [ ] MAXL-09 — pending
- [ ] MULTV-02 — pending
- [ ] MULTV-03 — pending
- [ ] MULTV-09 — pending
- [ ] MULTK-05 — pending
- [ ] MULTK-07 — pending
- [ ] MULTN15-05 — pending
- [ ] MULTN17-02 — pending
- [ ] MULTH-04 — pending
- [ ] MULTH-05 — pending
- [ ] MULTH-06 — pending
- [ ] MULTX-05 — pending
- [ ] ESP-10 — pending
- [ ] ESP-11 — pending
- [ ] ESP-12 — pending
- [ ] REC-01 — pending
- [ ] REC-03 — pending
- [ ] REC-05 — pending
- [ ] REC-02 — pending
- [ ] TETO-10 — pending (digest como produto de revisão)

## LOTE 10 — Fronteira condicional (M6) — GATE M6: todo condicional com A/B REGISTRADO (live OU suspenso/refutado — ambos são sucesso) · REC-04 em shadow com hipóteses de funil
- [ ] RAGX-07 — pending
- [ ] RAGX-06 — pending
- [ ] RAGX-03 — pending
- [ ] RAGX-11 — pending
- [ ] RAGX-05 — pending
- [ ] RAGX-02 — pending
- [ ] RAGX-10 — pending
- [ ] RAGX-09 — pending
- [ ] RAGX-04 — pending (candidato-a-corte declarado)
- [ ] MAXA-09 — pending
- [ ] MAXC-07 — pending (condicional)
- [ ] MAXD-05 — pending (gated MAXA-06)
- [ ] MAXF-09 — pending
- [ ] MAXF-11 — pending (SÓ pós-enforce CPT-09)
- [ ] MULTJ-04 — pending
- [ ] MULTJ-05 — pending
- [ ] MULTJ-06 — pending
- [ ] MULTJ-07 — pending
- [ ] MULTJ-08 — pending
- [ ] MULTJ-09 — suspended(ELEV-28) por construção — destrava com ≥5 skills vivas
- [ ] MULTV-04 — pending
- [ ] MULTV-06 — pending
- [ ] MULTV-08 — pending
- [ ] MULTK-06 — pending
- [ ] MULTK-08 — pending
- [ ] MULTN15-08 — pending
- [ ] MULTN17-05 — pending
- [ ] MULTN17-06 — pending
- [ ] MULTH-08 — pending
- [ ] MULTX-07 — pending
- [ ] MULTX-08 — pending
- [ ] REC-04 (shadow) — pending
- [ ] REC-06 — pending
- [ ] TETO-04 — pending (2º domínio; gated MARCO ESP-V1)
- [ ] TETO-07 — pending (model-refresh drill)

## LOTE 11 — Substrato 10-100× (F3) — GATE F3: pack ≤2s / recall ≤1s / hooks ≤5s sustentados 14d · bancada 12 clientes provada
- [ ] ASI-16 — pending
- [ ] ASI-17 — pending
- [ ] ASI-18 — pending
- [ ] MAXA-06 (fase 2) — pending

## LOTE 12 — Fecho (o mesmo corte)
- [ ] Critérios §vii 1-10 + §viii 11-17 + pétreos §xiii + §xv 18-21 (mission_e2e ≥ alvo · N-Capture Drill executado · ≥1 volta proven_real fora de engenharia · Trajectory Vault com privacy provada) — verdes NO MESMO CORTE — pending
- [ ] MAXG-08 (Marco Zero v2; gatilho ADV-01) — pending
- [ ] MAXL-10 — pending
- [ ] REC-04 flip shadow→atuar — **GATILHO EXCLUSIVO DO OPERADOR** (M>1, R>0, freios verdes) — pending

---
*Criado em 12/07/2026; atualizado no mesmo dia para 255/255 slices em `pending` (família TETO, seção xv). Fonte de atribuição: inventário 5.5 do playbook (autoridade). Atualizar SEMPRE no mesmo commit do slice.*

## ESP-00 — Tabela de ground-truth (corte 2026-07-11; unexplained=0)

| número | valor medido agora | fonte/comando | explicação da divergência |
|---|---|---|---|
| memory entries (77 vs 106) | total=106 · active=90 · archived=16 · with_embedding=106 | `SELECT status, count(*) FROM atlas_memory_entries GROUP BY status` (via artisan/tinker @5433) | **escopo+data**: 106=total rows (Codex); 77=foto Max (plan:282 “77 com embedding”) em corpus menor; vivo canônico=106/90 |
| origination targets (1173 vs 369) | done-set autonomous=1173 · queued-targets live=369 | `wc -l storage/app/atlas/brain/done-set/autonomous.jsonl` · `php artisan atlas:brain:queued-targets --scope=autonomous --json` | **escopo**: 1173=histórico done-set; 369=fila live serving (não são o mesmo denominador) |
| “AWEOS 69 execuções” | atlas_aweos_executions=69 | `DB::table('atlas_aweos_executions')->count()` · canon `atlas-autonomous-work-execution-os.md` | **órgão real** (Mission Control); não é erro de leitor — só fora do mapa MAX* |
| Decide proven_real (52/3481) | 52 proven_real=true / 3481 linhas | `rg --no-ignore` + parse `storage/atlas/atlas_decide/live_outcomes.jsonl` | **confirmado** — sem divergência |
| 87 successes não-provados no ranking? | success∩quality∩¬proven_real=87 · **SIM influenciam** | count no JSONL + `AtlasDecideCostOutcomeRouter.php:254` e `:386-390` | **confirmado comportamento**: router ignora `proven_real`; remédio=ESP-05 |

Receipt: Evidence Ledger `EVIDENCE_PACKED` event_id=`01KXA1JWR53YAYT915GTGS0RXB` · content_hash=`e71448b0b81b65da3e16aa86927f9c0c632234efb7cae13b2fad789c3efd4b87`. Caso negativo do protocolo: unexplained⇒gap; observado unexplained=0 ⇒ zero issues abertas no gaps ledger.
