# CODEMAP — app/Console/Commands

<!-- ATLAS-CODEMAP: GENERATED — do not hand-edit.
     Rebuild: php artisan atlas:codemap --write
     Verify:  php artisan atlas:codemap --verify -->

Every artisan command in this tree, keyed by the name you type.
Laravel discovers these by scanning the directory, so no class names
this file — which is why the façade index cannot see them.

| Artisan name | What it does | File |
| --- | --- | --- |
| `atlas:aael` | Operate AAEL, the Atlas Autonomous Evolution Loop. | `app/Console/Commands/AtlasAaelCommand.php` |
| `atlas:aael:certify` | Certify AAEL, the Atlas Autonomous Evolution Loop. | `app/Console/Commands/AtlasAaelCertifyCommand.php` |
| `atlas:aael:debug` | Operator CLI for the AAEL stepwise debugger (pause \| inspect \| step \| continue). | `app/Console/Commands/AtlasAaelExecutionDebuggerCommand.php` |
| `atlas:aael:depth` | AAEL execution-depth CLI: prove \| invariants \| abort \| recover (FACT-only). | `app/Console/Commands/AtlasAaelExecutionDepthCommand.php` |
| `atlas:aael:inflight` | AAEL in-flight FACT surface (validate \| drift \| history). | `app/Console/Commands/AtlasAaelInFlightCommand.php` |
| `atlas:aael:inspect` | Read-only deep inspect of one AAEL execution receipt. | `app/Console/Commands/AtlasAaelDeepInspectCommand.php` |
| `atlas:aael:parallel` | AAEL parallel CLI: schedule \| lock \| history (observability-only by default). | `app/Console/Commands/AtlasAaelParallelCommand.php` |
| `atlas:aael:rollback` | AAEL execution rollback operator surface (inspect\|execute\|history). | `app/Console/Commands/AtlasAaelExecutionRollbackCommand.php` |
| `atlas:aael:trace` | Operator CLI for AAEL execution trace record/replay/history. | `app/Console/Commands/AtlasAaelTraceCommand.php` |
| `atlas:aaeos` | AAEOS router: daily use atlas:aaeos:run\|certify; advanced observe → atlas:aeos:observe | `app/Console/Commands/AtlasAaeosRouterCommand.php` |
| `atlas:aaeos-acos:simplify-cycle` | Alias of `atlas:acos:simplify-cycle`. AAEOS+ACOS elite simplify lane — plan one Autônomos tick (brain→seed→prompts). [was atlas:aaeos:*; TRI-HYGIENE rename] | `app/Console/Commands/AtlasAaeosAcosSimplifyCycleCommand.php` |
| `atlas:aaeos:certify` | Certify AAEOS GOD/SOTA composite and structural invariants. | `app/Console/Commands/AtlasAaeosCertifyCommand.php` |
| `atlas:aaeos:choreography-status` | Alias of `atlas:aeos:choreography-status`. Inspect and evaluate the AAEOS cross-department choreography (veto/repair/handoff) runtime. [was atlas:aaeos:*; TRI-HYGIENE rename] | `app/Console/Commands/AtlasAaeosChoreographyStatusCommand.php` |
| `atlas:aaeos:codex-review-chain-contract` | Alias of `atlas:review:codex-chain-contract`. Atlas self-construction · codex review chain contract — read-only review/signature/merge-action chain that approves, signs and merges nothing. [was atlas:aae... | `app/Console/Commands/AtlasCodexReviewChainContractCommand.php` |
| `atlas:aaeos:cycle` | Run one AAEOS control cycle: intent → mode → admission → dispatch (Dev\|Forge\|Autonomos). | `app/Console/Commands/AtlasAaeosCycleCommand.php` |
| `atlas:aaeos:deferred-worker` | Alias of `atlas:aeos:deferred-worker`. Drain AAEOS deferred phase queue produced by the HTTP facade (AP-696..AP-699). [was atlas:aaeos:*; TRI-HYGIENE rename] | `app/Console/Commands/AtlasAaeosDeferredWorkerCommand.php` |
| `atlas:aaeos:department-registry` | Alias of `atlas:aeos:department-registry`. Inspect and validate the AAEOS department registry (atlas.aaeos.department.v1). [was atlas:aaeos:*; TRI-HYGIENE rename] | `app/Console/Commands/AtlasAaeosDepartmentRegistryCommand.php` |
| `atlas:aaeos:department-status` | Alias of `atlas:aeos:department-status`. Show AAEOS per-department maturity (L0..L7) and numeric quality bar. [was atlas:aaeos:*; TRI-HYGIENE rename] | `app/Console/Commands/AtlasAaeosDepartmentStatusCommand.php` |
| `atlas:aaeos:learning-proposals` | Alias of `atlas:learning:proposals-decision`. Decide learning-proposal rules: evidence-gated admission, weak-signal hold, justification/risk/action output, and the critical-change no-auto-apply review ga... | `app/Console/Commands/AtlasLearningProposalsCommand.php` |
| `atlas:aaeos:maturity` | Alias of `atlas:aeos:maturity`. Compute machine-verified implementation_state for docs declaring evidence_refs, from the code intelligence index. [was atlas:aaeos:*; TRI-HYGIENE rename] | `app/Console/Commands/AtlasAaeosMaturityCommand.php` |
| `atlas:aaeos:memory-cognitive-immune-learning-kernel` | Alias of `atlas:memory:cognitive-immune-kernel`. Atlas Memory · cognitive immune gate (Input Class classify, G0-G8 promotion ladder, quarantine default, non-negotiable rules). [was atlas:aaeos:*; TRI-HYGIEN... | `app/Console/Commands/AtlasMemoryCognitiveImmuneLearningKernelCommand.php` |
| `atlas:aaeos:run` | AAEOS daily port (intent-first): Dev · Forge · Autônomos same bar. No productive technical dials. | `app/Console/Commands/AtlasAaeosRunCommand.php` |
| `atlas:aaeos:scorecard` | Project AAEOS GOD/SOTA scorecard (read-only). | `app/Console/Commands/AtlasAaeosScorecardCommand.php` |
| `atlas:aaeos:verify-tests` | Alias of `atlas:aeos:verify-tests`. Run the named test(s) of capabilities for REAL and record GREEN-RUN RECEIPTS that gate the verified tier. [was atlas:aaeos:*; TRI-HYGIENE rename] | `app/Console/Commands/AtlasAaeosVerifyTestsCommand.php` |
| `atlas:aars` | Operate AARS, the Atlas Autonomous Reality Sandbox. | `app/Console/Commands/AtlasAarsCommand.php` |
| `atlas:aars:certify` | Certify AARS, the Atlas Autonomous Reality Sandbox. | `app/Console/Commands/AtlasAarsCertifyCommand.php` |
| `atlas:acmf:schema-evolution` | Atlas ACMF Schema Evolution — propose v+1 of existing schemas (operator-driven or pressure-triggered). | `app/Console/Commands/AtlasAcmfSchemaEvolutionCommand.php` |
| `atlas:acos:adv-reproof` | ADV-01 - summarize external adversarial re-proof verdicts from JSONL evidence. | `app/Console/Commands/AtlasAcosAdvReproofCommand.php` |
| `atlas:acos:cockpit` | TETO-08 read-only ACOS Max program cockpit aggregator. | `app/Console/Commands/AtlasAcosCockpitCommand.php` |
| `atlas:acos:consolidation-guard` | T4-S7 — precision@k non-regression guard for nightly re-ranker consolidation. | `app/Console/Commands/AtlasConsolidationGuardCommand.php` |
| `atlas:acos:delta` | Compara o estado atual com o Marco Zero (deltas resolvidos por evidência viva). | `app/Console/Commands/AtlasAcosDeltaCommand.php` |
| `atlas:acos:delta-attribution` | MAXL-06 report-only attributed delta reader. | `app/Console/Commands/AtlasAcosDeltaAttributionCommand.php` |
| `atlas:acos:delta-series` | Mantém a série temporal de deltas do ACOS (append-only, idempotente por data) e emite o relatório N×M de tendência por evidência resolvida. | `app/Console/Commands/AtlasAcosDeltaSeriesCommand.php` |
| `atlas:acos:delta-series-v2` | MAXL-04 — appenda a linha v2 do dia com breakdown POR-ÁREA do scorecard ACOS (arquivo separado, v1 intocada). | `app/Console/Commands/AtlasAcosDeltaSeriesV2Command.php` |
| `atlas:acos:freeze` | Record an ACOS measure_freeze in the Evidence Ledger with independent judge assertion. | `app/Console/Commands/AtlasAcosFreezeCommand.php` |
| `atlas:acos:frontier-status` | ACOS #20 frontier wave ladder — etiqueta os 5 sistemas + ativação por eventos externos reais (E). | `app/Console/Commands/AtlasFrontierStatusCommand.php` |
| `atlas:acos:m-series` | ELEV-02 ASI-METRIC — read-only M series report with frozen formula and raw denominators. | `app/Console/Commands/AtlasAcosMSeriesCommand.php` |
| `atlas:acos:obra-retro` | Emit ACOS Max Obra-Retro lote-close outcomes and normal learning candidates. | `app/Console/Commands/AtlasAcosObraRetroCommand.php` |
| `atlas:acos:operational-volume` | ACOS VOL-01 — volume-real check with pinned thresholds and janela faminta alert. | `app/Console/Commands/AtlasOperationalVolumeCheckCommand.php` |
| `atlas:acos:rec06-breakers` | REC-06 meta-loop breakers; disarmed unless real measured series are present. | `app/Console/Commands/AtlasAcosRec06BreakersCommand.php` |
| `atlas:acos:rollback-triggers` | ACOS ROL-01 — pre-declared rollback trigger check with watchdog alert surface. | `app/Console/Commands/AtlasAcosRollbackTriggerCheckCommand.php` |
| `atlas:acos:simplify-cycle` | AAEOS+ACOS elite simplify lane — plan one Autônomos tick (brain→seed→prompts). [was atlas:aaeos:*; TRI-HYGIENE rename] | `app/Console/Commands/AtlasAaeosAcosSimplifyCycleCommand.php` |
| `atlas:acos:teto10-review-digest` | TETO-10 predicted-revert review digest (markdown/CLI only). | `app/Console/Commands/AtlasAcosTeto10ReviewDigestCommand.php` |
| `atlas:acos:verified-share` | Report ACOS Max ELEV-12 verified_share from OUTC-01 outcomes and verification receipts. | `app/Console/Commands/AtlasAcosVerifiedShareCommand.php` |
| `atlas:acos:window-gates` | ACOS live-window gate panel (D3/D4/D5 + long-horizon receipts) — measured or aguardando-janela, honesto. | `app/Console/Commands/AtlasAcosWindowGatesCommand.php` |
| `atlas:acp:reap-leases` | Reap expired Agent Control Plane leases and return their stranded tasks to claimable (R2 dead-agent recovery). | `app/Console/Commands/AtlasAgentControlPlaneReapLeasesCommand.php` |
| `atlas:aedpds:certify` | Certifies AEDPDS doctrine, selector, gate, Dev, Forge, receipts, outcome, commands, and tests. | `app/Console/Commands/Ai/Product/AtlasAedpdsCertifyCommand.php` |
| `atlas:aedpds:gate` | Runs AEDPDS execution gate for a task. | `app/Console/Commands/Ai/Product/AtlasAedpdsGateCommand.php` |
| `atlas:aedpds:inspect` | Shows AEDPDS docs, services, wiring, tests, and runtime status. | `app/Console/Commands/Ai/Product/AtlasAedpdsInspectCommand.php` |
| `atlas:aedpds:select` | Selects AEDPDS delivery drivers for a task. | `app/Console/Commands/Ai/Product/AtlasAedpdsSelectCommand.php` |
| `atlas:aemor` | Operate Atlas Execution Memory & Outcome Runtime. | `app/Console/Commands/AtlasAemorCommand.php` |
| `atlas:aemor:certify` | Certify AEMOR local runtime. No providers or benchmarks. | `app/Console/Commands/AtlasAemorCertifyCommand.php` |
| `atlas:aemor:close-outcome` | Close an AEMOR outcome. | `app/Console/Commands/AtlasAemorCloseOutcomeCommand.php` |
| `atlas:aemor:control-plane` | Emit AEMOR control-plane JSON. | `app/Console/Commands/AtlasAemorControlPlaneCommand.php` |
| `atlas:aemor:distill` | Distill an AEMOR outcome into learning candidates. | `app/Console/Commands/AtlasAemorDistillCommand.php` |
| `atlas:aemor:episode-open` | Open an AEMOR execution episode. | `app/Console/Commands/AtlasAemorEpisodeOpenCommand.php` |
| `atlas:aemor:judgment` | Run AEMOR Judgment & Learning Guard for an episode. | `app/Console/Commands/AtlasAemorJudgmentCommand.php` |
| `atlas:aemor:judgment-certify` | Certify AEMOR Judgment & Learning Guard. | `app/Console/Commands/AtlasAemorJudgmentCertifyCommand.php` |
| `atlas:aemor:memory-audit` | Audit AEMOR memory candidates. | `app/Console/Commands/AtlasAemorMemoryAuditCommand.php` |
| `atlas:aemor:memory-conflicts` | Audit AEMOR memory candidate conflicts. | `app/Console/Commands/AtlasAemorMemoryConflictsCommand.php` |
| `atlas:aemor:observe` | Append an AEMOR execution event. | `app/Console/Commands/AtlasAemorObserveCommand.php` |
| `atlas:aemor:readiness` | Readiness report for AEMOR. | `app/Console/Commands/AtlasAemorReadinessCommand.php` |
| `atlas:aemor:replay` | Emit an AEMOR replay manifest. | `app/Console/Commands/AtlasAemorReplayCommand.php` |
| `atlas:aemor:risk-predict` | Predict execution risks from AEMOR outcome memory. | `app/Console/Commands/AtlasAemorRiskPredictCommand.php` |
| `atlas:aeos:choreography-status` | Inspect and evaluate the AAEOS cross-department choreography (veto/repair/handoff) runtime. [was atlas:aaeos:*; TRI-HYGIENE rename] | `app/Console/Commands/AtlasAaeosChoreographyStatusCommand.php` |
| `atlas:aeos:deferred-worker` | Drain AAEOS deferred phase queue produced by the HTTP facade (AP-696..AP-699). [was atlas:aaeos:*; TRI-HYGIENE rename] | `app/Console/Commands/AtlasAaeosDeferredWorkerCommand.php` |
| `atlas:aeos:department-registry` | Inspect and validate the AAEOS department registry (atlas.aaeos.department.v1). [was atlas:aaeos:*; TRI-HYGIENE rename] | `app/Console/Commands/AtlasAaeosDepartmentRegistryCommand.php` |
| `atlas:aeos:department-status` | Show AAEOS per-department maturity (L0..L7) and numeric quality bar. [was atlas:aaeos:*; TRI-HYGIENE rename] | `app/Console/Commands/AtlasAaeosDepartmentStatusCommand.php` |
| `atlas:aeos:maturity` | Compute machine-verified implementation_state for docs declaring evidence_refs, from the code intelligence index. [was atlas:aaeos:*; TRI-HYGIENE rename] | `app/Console/Commands/AtlasAaeosMaturityCommand.php` |
| `atlas:aeos:observe` | [ADVANCED observe floors — prefer atlas:aaeos:run for daily; see atlas-cli-daily-map] Atlas Agentic Engineering OS — operator CLI for the 17-phase runbook. | `app/Console/Commands/AtlasAaeosCommand.php` |
| `atlas:aeos:verify-tests` | Run the named test(s) of capabilities for REAL and record GREEN-RUN RECEIPTS that gate the verified tier. [was atlas:aaeos:*; TRI-HYGIENE rename] | `app/Console/Commands/AtlasAaeosVerifyTestsCommand.php` |
| `atlas:agent-execution:lane-provider-plan` | AP-804 · inspect the provider plan routed per multi-agent lane (read-only, no provider call). | `app/Console/Commands/AtlasAgentExecutionLaneProviderPlanCommand.php` |
| `atlas:agentic-workcell` | Operate AAWR, the Atlas Agentic Workcell Runtime. Planning only: no provider calls, no agent spawning. | `app/Console/Commands/AtlasAgenticWorkcellCommand.php` |
| `atlas:agentic-workcell:certify` | Certify AAWR, the Atlas Agentic Workcell Runtime. | `app/Console/Commands/AtlasAgenticWorkcellCertifyCommand.php` |
| `atlas:agents:off` | Operator: declare an Atlas agent desired-OFF (the babá stops it); --all is the global panic kill. | `app/Console/Commands/AtlasAgentsOffCommand.php` |
| `atlas:agents:on` | Operator: declare an Atlas agent desired-ON (+ TTL/budget FREIO). The babá then runs and watches it. | `app/Console/Commands/AtlasAgentsOnCommand.php` |
| `atlas:agents:reconcile` | The babá: converge the running fleet toward the operator desired-state (start desired, stop unsanctioned). | `app/Console/Commands/AtlasAgentsReconcileCommand.php` |
| `atlas:agents:status` | Show every autonomous Atlas agent: running/desired/off, which account it spends, uptime, TTL. | `app/Console/Commands/AtlasAgentsStatusCommand.php` |
| `atlas:ai:abstraction-ladder` | MULTJ-06 — abstraction ladder tactical→pattern→principle with case_count per level; same ASI-02 queue when enqueue flag ON. | `app/Console/Commands/AtlasAiAbstractionLadderCommand.php` |
| `atlas:ai:agent-behavior-report` | Summarize Atlas AI agent behavior gate findings for a recent time window. | `app/Console/Commands/AtlasAiAgentBehaviorReportCommand.php` |
| `atlas:ai:apply-learning` | Apply or reverse an approved learning proposal to runtime behaviour (closes the compounding flywheel). | `app/Console/Commands/AtlasApplyLearningCommand.php` |
| `atlas:ai:approval` | Atlas AI Operator Approval Gate · list/show/decide/expire/control-plane. | `app/Console/Commands/AtlasAiApprovalCommand.php` |
| `atlas:ai:architecture-operations` | List canonical Atlas AI mother-architecture operations. | `app/Console/Commands/AtlasAiArchitectureOperationsCommand.php` |
| `atlas:ai:architecture-readiness` | Show Atlas AI mother-architecture readiness across validation, docs, projections and architecture operations. | `app/Console/Commands/AtlasAiArchitectureReadinessCommand.php` |
| `atlas:ai:architecture-validate` | Validate Atlas AI executable architecture contracts for capabilities and domain profiles. | `app/Console/Commands/AtlasAiArchitectureValidateCommand.php` |
| `atlas:ai:auto-apply-safe` | Autonomously apply the SAFE reversible learnings (no approval); everything else queues for Sunday. Default-OFF. | `app/Console/Commands/AtlasAiAutoApplySafeCommand.php` |
| `atlas:ai:automation-domain` | Atlas Automation / Tool Factory Runtime (Meta 8 · Automation): readiness, seed-manifest, smoke and control-plane projection. | `app/Console/Commands/AtlasAiAutomationDomainCommand.php` |
| `atlas:ai:autonomous-engineering` | Run Atlas Autonomous Engineering OS readiness, execution, control plane and certification commands. | `app/Console/Commands/AtlasAiAutonomousEngineeringCommand.php` |
| `atlas:ai:bootstrap-skills` | Create Atlas master prompt and default skill files inside the AtlasVault. | `app/Console/Commands/AiBootstrapSkillsCommand.php` |
| `atlas:ai:capture-inbox-pipeline-backfill-contracts` | Backfill conservative Capture/Inbox contracts for legacy captures; dry-run by default. | `app/Console/Commands/AtlasAiCaptureInboxPipelineBackfillContractsCommand.php` |
| `atlas:ai:capture-inbox-pipeline-report` | Summarize Capture/Inbox pipeline integrity without mutating memory, context or curation state. | `app/Console/Commands/AtlasAiCaptureInboxPipelineReportCommand.php` |
| `atlas:ai:capture-quality-audit` | Dry-run the capture quality gate over recent learnings: how much is noise (by reason) + the content-dedup ratio (read-only). | `app/Console/Commands/AtlasAiCaptureQualityAuditCommand.php` |
| `atlas:ai:chat` | Use Atlas directly from the Mac while preserving Atlas threads, sessions, memory and provider handoffs. | `app/Console/Commands/AiChatCommand.php` |
| `atlas:ai:co-recall-composition` | MAXJ-07 · detect co-recalled memory pairs in measured passing outcomes (shadow-first). | `app/Console/Commands/AtlasAiCoRecallCompositionCommand.php` |
| `atlas:ai:compounding` | Run Atlas Compounding Engineering Intelligence readiness, certification and deterministic smoke simulation. | `app/Console/Commands/AtlasAiCompoundingCommand.php` |
| `atlas:ai:control-plane` | Atlas AI Control Plane: aggregate read-model snapshots, readiness, blockers, next actions, plus trace-level runtime observability. | `app/Console/Commands/AtlasAiControlPlaneCommand.php` |
| `atlas:ai:cost-calibrate` | Calibrate the provider cost ceiling from observed telemetry (percentiles + suggested hard-gate). | `app/Console/Commands/AtlasCostCalibrateCommand.php` |
| `atlas:ai:counterfactual-lift` | MULTJ-03 paired counterfactual lift read-only series. | `app/Console/Commands/AtlasAiCounterfactualLiftCommand.php` |
| `atlas:ai:cyber-domain` | Atlas Cyber Security Company Runtime: defensive review, AppSec, GRC, remediation, authorized bug bounty intake. NEVER executes offensive actions. | `app/Console/Commands/AtlasAiCyberDomainCommand.php` |
| `atlas:ai:decide` | Preview the Atlas Decide routing decision without running a provider. | `app/Console/Commands/AtlasAiDecideCommand.php` |
| `atlas:ai:decision-receipt-report` | Replay and verify DecisionReceipt hash/chain integrity for an Atlas AI envelope. | `app/Console/Commands/AtlasAiDecisionReceiptReportCommand.php` |
| `atlas:ai:docs-authority-audit` | Audit canonical docs for duplicate authority, runtime names and capability overlap. | `app/Console/Commands/AtlasDocumentationAuthorityAuditCommand.php` |
| `atlas:ai:docs-split-plan` | Return the canonical split plan for oversized Atlas AI documentation. | `app/Console/Commands/AtlasAiDocsSplitPlanCommand.php` |
| `atlas:ai:doctor` | Summarize Atlas operational health, quality scores and pending remediation actions. | `app/Console/Commands/AiDoctorCommand.php` |
| `atlas:ai:domain-runtime` | Atlas Domain Runtime: register/inspect manifests, capabilities, selection, handoff, maturity and control-plane snapshot. | `app/Console/Commands/AtlasAiDomainRuntimeCommand.php` |
| `atlas:ai:domains` | Inspect Atlas AI domain, flow, and orchestrator contracts. | `app/Console/Commands/AtlasAiDomainsCommand.php` |
| `atlas:ai:dynamic-compute-market` | Explain Dynamic Compute Market advice for a selected provider without changing routing. | `app/Console/Commands/AtlasAiDynamicComputeMarketCommand.php` |
| `atlas:ai:engine:backfill` | Backfill Atlas report engine runs over a local date range. | `app/Console/Commands/AiReportEngineBackfillCommand.php` |
| `atlas:ai:engine:run` | Run, replay or inspect the Atlas report engine for an explicit window. | `app/Console/Commands/AiReportEngineRunCommand.php` |
| `atlas:ai:enqueue` | Enqueue a manual Atlas interaction. | `app/Console/Commands/AiEnqueueCommand.php` |
| `atlas:ai:evidence` | Atlas Evidence/Certification Runtime: readiness, evidence packs, receipts, certification and control-plane. | `app/Console/Commands/AtlasAiEvidenceCommand.php` |
| `atlas:ai:external-graph-harness` | Publish the AP-684 External Graph Harness contract and validate graph candidates without writes. | `app/Console/Commands/AtlasAiExternalGraphHarnessCommand.php` |
| `atlas:ai:finance-domain` | Atlas Finance / Investment Company Runtime: research/valuation/portfolio/risk/compliance/reporting/paper-trading. Live trading is hard-blocked. | `app/Console/Commands/AtlasAiFinanceDomainCommand.php` |
| `atlas:ai:health` | Check local Atlas providers and persist operational health snapshots. | `app/Console/Commands/AiHealthCommand.php` |
| `atlas:ai:hyperflow` | Run Atlas Hyperflow backend certification gates without calling external providers. | `app/Console/Commands/AtlasAiHyperflowCommand.php` |
| `atlas:ai:hyperflow-specialists` | Etapa 1 readiness gate for Atlas AI Hyperflow + Specialist Flows. Never runs a benchmark. | `app/Console/Commands/AtlasAiHyperflowSpecialistsCommand.php` |
| `atlas:ai:inbox-action-report` | Summarize Atlas AI Inbox action evidence for a recent time window. | `app/Console/Commands/AtlasAiInboxActionReportCommand.php` |
| `atlas:ai:kernel-pipeline-report` | Summarize Atlas AI Kernel Pipeline contract evidence for a recent time window. | `app/Console/Commands/AtlasAiKernelPipelineReportCommand.php` |
| `atlas:ai:learn-project` | Learn a project fast (stack, purpose, how-to-work) from its real files — every fact cited, nothing invented. | `app/Console/Commands/AtlasLearnProjectCommand.php` |
| `atlas:ai:learning` | Atlas AI Memory & Learning loop: collect signals, list inbox, review proposals. | `app/Console/Commands/AtlasAiLearningCommand.php` |
| `atlas:ai:learning-curriculum` | MAXJ-06 — read-only learning curriculum from lesson-type yield and causal denominators. | `app/Console/Commands/AtlasAiLearningCurriculumCommand.php` |
| `atlas:ai:learning-generalization` | MAXJ-03 — group ai_learning_candidates by {memory_type, primary_cause, scope}, dedupe by candidate_hash, and propose abstract lessons for signatures with cas... | `app/Console/Commands/AtlasAiLearningGeneralizationCommand.php` |
| `atlas:ai:learning-recall-lift` | Measure learn→recall→use lift from existing compounding/RAG feedback rows. | `app/Console/Commands/AtlasAiLearningRecallLiftCommand.php` |
| `atlas:ai:ledger` | Replay Atlas AI kernel evidence events for one operation envelope. | `app/Console/Commands/AtlasAiLedgerCommand.php` |
| `atlas:ai:ledger-backfill-traces` | Backfill provider performance ledger events from existing ai_traces without raw prompt or response text. | `app/Console/Commands/AtlasAiLedgerBackfillTracesCommand.php` |
| `atlas:ai:ledger-project` | Project Evidence Ledger events into operational Atlas AI read models. | `app/Console/Commands/AtlasAiLedgerProjectionCommand.php` |
| `atlas:ai:lesson-dedup-calibration` | MULTJ-02 semantic lesson dedup calibration freeze. | `app/Console/Commands/AtlasAiLessonDedupCalibrationCommand.php` |
| `atlas:ai:lesson-half-life` | MULTJ-01 lesson half-life read-only series. | `app/Console/Commands/AtlasAiLessonHalfLifeCommand.php` |
| `atlas:ai:lesson-quality` | Measure lesson quality by memory_type, flow_id, and scope without writing learning state. | `app/Console/Commands/AtlasAiLessonQualityCommand.php` |
| `atlas:ai:lesson-type-yield` | Measure A/B lift by lesson memory_type with the MAXJ-05 n>=8 floor. | `app/Console/Commands/AtlasAiLessonTypeYieldCommand.php` |
| `atlas:ai:local-rag-benchmark` | Run a controlled Local RAG router benchmark before Graph RAG/Python runtime promotion. | `app/Console/Commands/AtlasAiLocalRagBenchmarkCommand.php` |
| `atlas:ai:local-rag-readiness` | Report Local RAG readiness without creating a parallel memory/runtime brain. | `app/Console/Commands/AtlasAiLocalRagReadinessCommand.php` |
| `atlas:ai:long-running-work-declare-baseline` | Declare disabled governed Structure Mother long-running work baseline schedules without dispatching jobs. | `app/Console/Commands/AtlasAiLongRunningWorkDeclareBaselineCommand.php` |
| `atlas:ai:long-running-work-report` | Summarize Atlas scheduled long-running work and autonomy receipts without dispatching jobs. | `app/Console/Commands/AtlasAiLongRunningWorkReportCommand.php` |
| `atlas:ai:marketing-domain` | Atlas Marketing / Growth Company Runtime: ICP, positioning, campaign, copy, creative, funnel, analytics, experiments and approval gates. No auto-publish, no ... | `app/Console/Commands/AtlasAiMarketingDomainCommand.php` |
| `atlas:ai:marketing:advise` | Atlas Marketing: motor de decisão Stage-1 — sintoma do funil → ação + alavanca (determinístico). | `app/Console/Commands/AtlasAiMarketingAdviseCommand.php` |
| `atlas:ai:marketing:amplify` | Amplifica uma bridge (mede → injeta o que falta → re-mede) até atingir o grade alvo. | `app/Console/Commands/AtlasAiMarketingAmplifyCommand.php` |
| `atlas:ai:marketing:audit` | Audita uma página em 10 dimensões de conversão (Conversion Pattern OS). | `app/Console/Commands/AtlasAiMarketingAuditCommand.php` |
| `atlas:ai:marketing:audit-vsl` | Atlas Marketing: audita uma VSL real (anatomia + Cialdini + awareness) → score + correções. | `app/Console/Commands/AtlasAiMarketingAuditVslCommand.php` |
| `atlas:ai:marketing:battle` | Gera N variantes ortogonais pra split-test (matriz angle × hook × awareness). | `app/Console/Commands/AtlasAiMarketingBattleCommand.php` |
| `atlas:ai:marketing:bridge-page` | Compose an aggressive, policy-durable bridge page from a dissected VSL + winning patterns + skills. | `app/Console/Commands/AtlasAiMarketingBridgePageCommand.php` |
| `atlas:ai:marketing:brief` | Atlas Marketing: emite o blueprint determinístico de uma skill (copy/funnel/icp/campaign/…). | `app/Console/Commands/AtlasAiMarketingBriefCommand.php` |
| `atlas:ai:marketing:campaign` | Atlas Marketing: deterministic Google-Search launch blueprint from a VSL asset. | `app/Console/Commands/AtlasAiMarketingCampaignCommand.php` |
| `atlas:ai:marketing:compose-funnel` | Gera um funil estruturalmente são (ad→bridge→página→checkout) a partir de um VSL asset e auto-verifica. | `app/Console/Commands/AtlasAiMarketingComposeFunnelCommand.php` |
| `atlas:ai:marketing:continuity` | Audita a CONTINUIDADE de promessa do funil (ad→página→checkout) — quebra de scent + bait-and-switch. | `app/Console/Commands/AtlasAiMarketingContinuityCommand.php` |
| `atlas:ai:marketing:diagnose` | Diagnóstico 1→25 de uma página: gargalo #1 + veredito de audiência + onde a aba fecha + kill/scale. | `app/Console/Commands/AtlasAiMarketingDiagnoseCommand.php` |
| `atlas:ai:marketing:dossier` | Atlas Marketing: dossiê completo de uma VSL (economia+lance+auditoria+briefs+readiness+1ª jogada). | `app/Console/Commands/AtlasAiMarketingDossierCommand.php` |
| `atlas:ai:marketing:funnel` | Raio-X estrutural do funil inteiro (congruência por hop + continuidade + vazamentos). | `app/Console/Commands/AtlasAiMarketingFunnelCommand.php` |
| `atlas:ai:marketing:import-outcomes` | Importa em lote outcomes de campanhas (CSV/JSON exportado do dashboard) para o ledger. | `app/Console/Commands/AtlasAiMarketingImportOutcomesCommand.php` |
| `atlas:ai:marketing:keyword-moat` | Atlas Keyword Moat: ingrediente → token coinável pra plantar + a campanha que pré-possui a busca fabricada. | `app/Console/Commands/AtlasAiMarketingKeywordMoatCommand.php` |
| `atlas:ai:marketing:keyword-os` | Atlas Keyword OS: oferta → dossiê completo (descoberta+precisão+exclusão da venda real), determinístico. | `app/Console/Commands/AtlasAiMarketingKeywordOsCommand.php` |
| `atlas:ai:marketing:keyword-scale` | Decide escalar/segurar/recuar budget por SINAL (KeywordScalingDiagnostic) com os números reais do painel. | `app/Console/Commands/AtlasAiMarketingKeywordScaleCommand.php` |
| `atlas:ai:marketing:keyword-verdict` | Veredito investimento-vs-gasto de uma keyword (3 portas: significância × atribuição × lag) com os números reais. | `app/Console/Commands/AtlasAiMarketingKeywordVerdictCommand.php` |
| `atlas:ai:marketing:keywords` | Gera + PONTUA as keywords QUALIFICADAS de rede de pesquisa (memory-recall arbitrage + quality index) a partir de um asset de VSL. | `app/Console/Commands/AtlasAiMarketingKeywordsCommand.php` |
| `atlas:ai:marketing:mine-patterns` | Mine the Nivor/Blackink tracker (read-only) for winning patterns per niche: real CVR, converting keywords, winning pages. | `app/Console/Commands/AtlasAiMarketingMinePatternsCommand.php` |
| `atlas:ai:marketing:orchestrate` | Pipeline end-to-end: asset → bridge amplificada → HTML pronta pra subir. | `app/Console/Commands/AtlasAiMarketingOrchestrateCommand.php` |
| `atlas:ai:marketing:record-outcome` | Registra o outcome de uma campanha real no ledger (alimenta o flywheel de pesos aprendidos). | `app/Console/Commands/AtlasAiMarketingRecordOutcomeCommand.php` |
| `atlas:ai:marketing:scout` | Escaneia uma página vencedora real → fingerprint, signal density e candidatos a aprender. | `app/Console/Commands/AtlasAiMarketingScoutCommand.php` |
| `atlas:ai:marketing:spy` | Analisa a SAFRA (N páginas vencedoras) → trend patterns + candidatos + density por library. | `app/Console/Commands/AtlasAiMarketingSpyCommand.php` |
| `atlas:ai:marketing:vsl` | Atlas Marketing VSL intelligence: ingest a VSL file, transcribe it in full, and store the offer asset. | `app/Console/Commands/AtlasAiMarketingVslCommand.php` |
| `atlas:ai:marketing:vsl-page` | Assemble a VSL sales page (video + delayed offer/checkout) from a dissected VSL asset. | `app/Console/Commands/AtlasAiMarketingVslPageCommand.php` |
| `atlas:ai:marketing:weights` | Mostra os pesos APRENDIDOS por padrão dentro de um nicho (alimentação do flywheel). | `app/Console/Commands/AtlasAiMarketingWeightsCommand.php` |
| `atlas:ai:memory-forget` | Archive (or --restore) an Atlas memory entry — non-destructive and reversible (the Sunday-digest prune handle). | `app/Console/Commands/AtlasAiMemoryForgetCommand.php` |
| `atlas:ai:metrics:snapshot-refresh` | Materialize daily Atlas metric snapshots used by the statistical report engine. | `app/Console/Commands/AiMetricSnapshotRefreshCommand.php` |
| `atlas:ai:mine-held-evidence` | Mine held governed evidence into propose-only learning proposals (never promotes). | `app/Console/Commands/AtlasMineHeldEvidenceCommand.php` |
| `atlas:ai:mission` | Atlas AI Mission Mode · create/show/certify/list/detect missions persistent above Hyperflow. | `app/Console/Commands/AtlasAiMissionCommand.php` |
| `atlas:ai:mission-foundation` | Atlas Kernel Mission Foundation: readiness, mission/objective/work_order lifecycle, evidence and certification. | `app/Console/Commands/AtlasAiMissionFoundationCommand.php` |
| `atlas:ai:operations-domain` | Atlas Operations Company Runtime: diagnostic, runbook, incident review and readiness review with no infra mutation. | `app/Console/Commands/AtlasAiOperationsDomainCommand.php` |
| `atlas:ai:operator-comprehend` | Learn the operator profile by comprehension: run the LLM extractor over recent turns into the governed review queue (capture-only). | `app/Console/Commands/AtlasOperatorComprehendCommand.php` |
| `atlas:ai:operator-patterns` | Detect recurring operator patterns and prepare governed skill/mission proposals (propose-only, Sunday review). | `app/Console/Commands/AtlasOperatorPatternsCommand.php` |
| `atlas:ai:operator-profile` | Inspect and reversibly archive/restore what Atlas learned about you (operator profile items). | `app/Console/Commands/AtlasOperatorProfileCommand.php` |
| `atlas:ai:operator-skill` | Review and promote (or reject) skills Atlas auto-built from your recurring patterns. Promotion needs --confirm. | `app/Console/Commands/AtlasOperatorSkillCommand.php` |
| `atlas:ai:performance:smoke` | Run a safe Atlas performance pipeline smoke check without emitting reports or mutating engine state. | `app/Console/Commands/AiPerformanceSmokeCommand.php` |
| `atlas:ai:personal-development-domain` | Atlas Personal Development Company Runtime: non-clinical goals, habits, focus, learning, energy and review loops. | `app/Console/Commands/AtlasAiPersonalDevelopmentDomainCommand.php` |
| `atlas:ai:pipeline` | Inspect the Atlas AI kernel pipeline contract without executing providers or runtime. | `app/Console/Commands/AtlasAiPipelineCommand.php` |
| `atlas:ai:place-feature` | Place a feature in the canonical Atlas AI architecture before implementation. | `app/Console/Commands/AtlasAiPlaceFeatureCommand.php` |
| `atlas:ai:policy` | Atlas Policy / Permission / Budget plane: readiness, seed-defaults, evaluate, request-approval, control-plane. | `app/Console/Commands/AtlasAiPolicyCommand.php` |
| `atlas:ai:proactive-layer-report` | Summarize Atlas proactive insight/watch and notification evidence for a recent time window. | `app/Console/Commands/AtlasAiProactiveLayerReportCommand.php` |
| `atlas:ai:procedural-skill-promoter` | MULTJ-04 — procedural playbook to skill.v1 promoter, default-OFF and held under ASI-02. | `app/Console/Commands/AtlasAiProceduralSkillPromoterCommand.php` |
| `atlas:ai:product-certify` | Certifies Atlas AI as an internal product (Mobile + Desktop + Server + Forge) without invoking providers or rivals. | `app/Console/Commands/Ai/Product/AtlasAiProductCertifyCommand.php` |
| `atlas:ai:programming-adapter` | Atlas Programming Domain Adapter: bridges Atlas Dev/Forge into Kernel (Mission, Domain Runtime, Policy, Evidence, Tool Runtime). | `app/Console/Commands/AtlasAiProgrammingAdapterCommand.php` |
| `atlas:ai:programming-runtime` | Honest readiness/certification for the Atlas AI Programming Runtime (atlas.programming.runtime_readiness.v1). | `app/Console/Commands/AtlasAiProgrammingRuntimeCommand.php` |
| `atlas:ai:programming-runtime-control-plane` | Programming Runtime control plane: aggregated read model (missions, Dev runs, Forge Obras, work packets, RAG gates, repair loops, telemetry, blockers, eviden... | `app/Console/Commands/AtlasAiProgrammingRuntimeControlPlaneCommand.php` |
| `atlas:ai:programming-runtime-telemetry` | Programming Runtime telemetry — record an internal event or emit the aggregate read model. Never runs a benchmark. | `app/Console/Commands/AtlasAiProgrammingRuntimeTelemetryCommand.php` |
| `atlas:ai:provider-performance` | Summarize Atlas AI provider usage and performance evidence for a recent time window. | `app/Console/Commands/AtlasAiProviderPerformanceCommand.php` |
| `atlas:ai:provider-release-review` | Classify a provider release into Atlas AI envelopes, owner docs, APs, Rivals and Decide signals. | `app/Console/Commands/AtlasAiProviderReleaseReviewCommand.php` |
| `atlas:ai:provider-release-sources` | List governed provider release sources or classify a detected URL into a read-only candidate. | `app/Console/Commands/AtlasAiProviderReleaseSourcesCommand.php` |
| `atlas:ai:qualitative-levels` | Report Atlas AI qualitative level maturity without changing behavior. | `app/Console/Commands/AtlasAiQualitativeLevelsCommand.php` |
| `atlas:ai:quality:backfill` | Backfill heuristic quality evaluations for terminal traces missing one. | `app/Console/Commands/AiQualityBackfillCommand.php` |
| `atlas:ai:real-engineering-kernel` | Run Atlas Real Engineering Execution Kernel readiness, execution, control plane and certification commands. | `app/Console/Commands/AtlasAiRealEngineeringKernelCommand.php` |
| `atlas:ai:recommendations:measure` | Measure applied Atlas performance recommendations and close effective ones. | `app/Console/Commands/AiRecommendationMeasureCommand.php` |
| `atlas:ai:repair` | Inspect the Atlas AI repair loop contract without executing repair actions. | `app/Console/Commands/AtlasAiRepairCommand.php` |
| `atlas:ai:repair-report` | Summarize Atlas AI Repair Loop evidence for a recent time window. | `app/Console/Commands/AtlasAiRepairReportCommand.php` |
| `atlas:ai:research-domain` | Atlas Research Company Runtime (Meta 8A): source plan, source quality, claims, contradiction check, synthesis, evidence pack, certification. | `app/Console/Commands/AtlasAiResearchDomainCommand.php` |
| `atlas:ai:router-runtime` | Atlas Router / Runtime Dispatch (Meta 6): intent kernel, domain router, flow router, runtime dispatch, decision receipts. | `app/Console/Commands/AtlasAiRouterRuntimeCommand.php` |
| `atlas:ai:runtime-boundary` | Report Atlas AI runtime language boundary status for Laravel, Python, Go and Swift. | `app/Console/Commands/AtlasAiRuntimeBoundaryCommand.php` |
| `atlas:ai:runtime-readiness` | Atlas AI Runtime Readiness & Release Gate · agrega Product Cert + Control Plane + Router + Mission Foundation + Mission Mode + Follow-Through + Approval Gate... | `app/Console/Commands/AtlasAiRuntimeReadinessCommand.php` |
| `atlas:ai:runtime-release-gate` | Atlas AI Runtime Release Gate · agrega evidência dos macros Hyperflow / Mission / Follow-Through / Approval / Learning / UX em uma decisão única ready\|partia... | `app/Console/Commands/AtlasAiRuntimeReleaseGateCommand.php` |
| `atlas:ai:runtime-ux-certify` | Certifies the Atlas AI Runtime UX layer (Mobile + Desktop status pill + view-model wiring). | `app/Console/Commands/Ai/Product/AtlasAiRuntimeUxCertifyCommand.php` |
| `atlas:ai:self-construction` | Atlas Self-Construction OS — read-only advisory projections (Phase 2 gap report). | `app/Console/Commands/AtlasAiSelfConstructionMotherCommand.php` |
| `atlas:ai:self-construction:codex` | Atlas Self-Construction OS — AgentCodex family (60 services). | `app/Console/Commands/AtlasAiSelfConstructionCodexCommand.php` |
| `atlas:ai:self-construction:control-plane` | Atlas Self-Construction OS — AgentControlPlane family (56 services). | `app/Console/Commands/AtlasAiSelfConstructionControlPlaneCommand.php` |
| `atlas:ai:self-construction:core` | Atlas Self-Construction OS — AtlasSelfConstructionCore family (69 services). | `app/Console/Commands/AtlasAiSelfConstructionCoreCommand.php` |
| `atlas:ai:self-construction:dispatch` | Atlas Self-Construction OS — AgentAutomaticDispatch family (64 services). | `app/Console/Commands/AtlasAiSelfConstructionDispatchCommand.php` |
| `atlas:ai:self-construction:dispatch-planner` | Atlas Self-Construction OS — AgentDispatchPlanner family (17 services). | `app/Console/Commands/AtlasAiSelfConstructionDispatchPlannerCommand.php` |
| `atlas:ai:self-construction:merge-review` | Atlas Self-Construction OS — AgentMergeReview family (7 services). | `app/Console/Commands/AtlasAiSelfConstructionMergeReviewCommand.php` |
| `atlas:ai:self-construction:provider` | Atlas Self-Construction OS — AgentProviderAdapter family (2 services). | `app/Console/Commands/AtlasAiSelfConstructionProviderCommand.php` |
| `atlas:ai:self-construction:runtime` | Atlas Self-Construction OS — AgentRuntime family (28 services). | `app/Console/Commands/AtlasAiSelfConstructionRuntimeCommand.php` |
| `atlas:ai:self-construction:shell-placeholder` | Atlas Self-Construction OS — unassigned placeholder shell (no family wired). | `app/Console/Commands/AtlasAiSelfConstructionCommand.php` |
| `atlas:ai:self-construction:status` | Atlas Self-Construction OS — read-only status snapshot (readiness + naming policy baseline). | `app/Console/Commands/AtlasAiSelfConstructionStatusCommand.php` |
| `atlas:ai:self-construction:validation` | Atlas Self-Construction OS — AgentValidationGate family (7 services). | `app/Console/Commands/AtlasAiSelfConstructionValidationCommand.php` |
| `atlas:ai:self-improve` | Run the Atlas AI self-improvement review over Evidence Ledger events. | `app/Console/Commands/AtlasAiSelfImproveCommand.php` |
| `atlas:ai:self-improvement-schedule-report` | Summarize replay evidence for Atlas AI Self-Improvement recurring schedule observations. | `app/Console/Commands/AtlasAiSelfImprovementScheduleReportCommand.php` |
| `atlas:ai:session-bootstrap` | Return the canonical Atlas AI session bootstrap package for a task. | `app/Console/Commands/AtlasAiSessionBootstrapCommand.php` |
| `atlas:ai:slo` | Summarize Atlas AI kernel SLO observations for a recent time window. | `app/Console/Commands/AtlasAiSloCommand.php` |
| `atlas:ai:strategic-decision` | Generate a plan-only Strategic Decision review packet without executing the decision. | `app/Console/Commands/AtlasAiStrategicDecisionCommand.php` |
| `atlas:ai:strategy-domain` | Atlas Corporate Strategy / Venture Studio Runtime: readiness, smoke and control-plane projection. | `app/Console/Commands/AtlasAiStrategyDomainCommand.php` |
| `atlas:ai:structure-mother-audit` | Audit the eight Atlas AI structure-mother modules without mutating runtime, memory or provider state. | `app/Console/Commands/AtlasAiStructureMotherAuditCommand.php` |
| `atlas:ai:task-orchestration-backfill-receipts` | Backfill local Atlas task orchestration receipts and hash chains without executing providers, runtimes or agents. | `app/Console/Commands/AtlasAiTaskOrchestrationBackfillReceiptsCommand.php` |
| `atlas:ai:task-orchestration-report` | Summarize Atlas task orchestration lifecycle receipts without executing providers, runtimes or agents. | `app/Console/Commands/AtlasAiTaskOrchestrationReportCommand.php` |
| `atlas:ai:telemetry:cost-rates` | List or upsert Atlas provider cost rates used by telemetry efficiency scoring. | `app/Console/Commands/AiTelemetryCostRatesCommand.php` |
| `atlas:ai:telemetry:health` | Evaluate Atlas telemetry health and optionally emit an operational insight. | `app/Console/Commands/AiTelemetryHealthCommand.php` |
| `atlas:ai:telemetry:performance-report` | Build and optionally emit Atlas daily and multi-window performance reports. | `app/Console/Commands/AiTelemetryPerformanceReportCommand.php` |
| `atlas:ai:telemetry:rollup` | Recompute Atlas trace metric summaries and print the current telemetry scorecard. | `app/Console/Commands/AiTelemetryRollupCommand.php` |
| `atlas:ai:tool-action-runtime-report` | Summarize Atlas Tool/Action Runtime readiness and evidence without executing tools. | `app/Console/Commands/AtlasAiToolActionRuntimeReportCommand.php` |
| `atlas:ai:tool-runtime` | Atlas Tool Runtime: registry, capability catalog, planning, policy bridge, invocation, receipts, health and validation. | `app/Console/Commands/AtlasAiToolRuntimeCommand.php` |
| `atlas:ai:trust-ladder` | Inspect or accrue the Self-Construction trust ladder for a change class (governed, bounded by the risk cap). | `app/Console/Commands/AtlasTrustLadderCommand.php` |
| `atlas:ai:voice` | Inspect the Atlas AI Voice Realtime surface contract. | `app/Console/Commands/AtlasAiVoiceRealtimeCommand.php` |
| `atlas:ai:weekly-memory-digest` | Sunday digest of everything saved to Atlas memory + auto-applied learnings, each with a reverse handle (read-only). | `app/Console/Commands/AtlasAiWeeklyMemoryDigestCommand.php` |
| `atlas:ai:work` | Run a local Atlas AI provider worker for desktop jobs. | `app/Console/Commands/AiWorkCommand.php` |
| `atlas:akif:ocr` | Atlas AKIF OCR Confidence-Scored Ingestion — wrap OCR/transcription content with confidence so downstream promotion can gate. | `app/Console/Commands/AtlasAkifOcrCommand.php` |
| `atlas:aobg:capture-session` | AOBG N2.F3: STRUCTURAL capture — distil a session transcript (touched files + outcome + explicit learnings) and feed it into the brain via the governed write... | `app/Console/Commands/AtlasAobgCaptureSessionCommand.php` |
| `atlas:aobg:file-context` | AOBG N2.F1: the brain-delta about a file the engine just touched — decisions/missions/memories/code-neighbors that govern it. Provider-bound, read-only, cost... | `app/Console/Commands/AtlasAobgFileContextCommand.php` |
| `atlas:aobg:guard` | AOBG N2.F2: the sentinel — evaluate a PROPOSED edit against the brain BEFORE it lands. Returns allow\|warn\|block (warn by default; block opt-in + highest-conf... | `app/Console/Commands/AtlasAobgGuardCommand.php` |
| `atlas:aobg:guard-hitrate` | T0.2: measure the decision-guard hit-rate (blind vs query-aware candidate set + real guard) and publish it to the Evolution Diary. | `app/Console/Commands/AtlasAobgGuardHitRateCommand.php` |
| `atlas:aobg:mark-session-echo` | Backfill do marker origin=aobg_session_capture nos mission nodes minted por session capture (anti-echo do reality graph). | `app/Console/Commands/AtlasAobgMarkSessionEchoCommand.php` |
| `atlas:aobg:propose-learning` | AOBG N1.F2: propose a learning from an external session — lands as pending_review (quality-gated, provider-safe, NEVER auto-applied). | `app/Console/Commands/AtlasAobgProposeLearningCommand.php` |
| `atlas:aobg:record-outcome` | AOBG N1.F2: record what an external session did back into the brain (provider-safe mission/evidence node, never a merge, fail-open). | `app/Console/Commands/AtlasAobgRecordOutcomeCommand.php` |
| `atlas:aobg:semantic-lift` | Measure AOBG semantic retrieval lift over 10 provider-safe context queries. | `app/Console/Commands/AtlasAobgSemanticRetrievalLiftCommand.php` |
| `atlas:aobg:workspace` | AOBG N1.F3: multi-project workspace status/onboarding/activation — does the brain know THIS project? | `app/Console/Commands/AtlasAobgWorkspaceCommand.php` |
| `atlas:api:describe` | Catálogo machine-readable da superfície de produto do terminal (comandos-núcleo, invocação JSON, schemas) para apps irmãos. | `app/Console/Commands/AtlasApiDescribeCommand.php` |
| `atlas:api:perf` | Mede p50/p95 de latência dos comandos-núcleo do catálogo (processo fresco por run; orçamento p50 3s). | `app/Console/Commands/AtlasApiPerfCommand.php` |
| `atlas:arena:drain` | Drena a fila de medições da Arena executando o pipeline Rivals real | `app/Console/Commands/AtlasArenaDrainCommand.php` |
| `atlas:atlas-decide:gateway-consult` | Atlas Decide → Gateway Consultation hook. Query learned routing under Kernel + Admission gates before letting any provider call go through. | `app/Console/Commands/AtlasDecideGatewayConsultCommand.php` |
| `atlas:atlas-decide:live-feedback` | Atlas Decide Live Outcome Feedback — record provider call outcomes, query stats/signals, sweep degraded routes. | `app/Console/Commands/AtlasDecideLiveFeedbackCommand.php` |
| `atlas:atlas-decide:lookahead` | Atlas Decide × TEOS-I4 Lookahead — explore counterfactual routing alternatives before adopting. | `app/Console/Commands/AtlasDecideLookaheadCommand.php` |
| `atlas:atlas-decide:meta-learning` | Atlas Decide · meta-learning recommendations · derives read-only routing recommendations/advisory maps from the Provider Performance Ledger. | `app/Console/Commands/AtlasDecideMetaLearningCommand.php` |
| `atlas:atlas-decide:meta-learning:activate` | Atlas Decide · activate a meta-learning routing recommendation as authoritative for (task_category, role[, framework]). | `app/Console/Commands/AtlasDecideMetaLearningActivateCommand.php` |
| `atlas:atlas-decide:meta-learning:deactivate` | Atlas Decide · deactivate a routing recommendation (return scope to native Atlas Decide policy). | `app/Console/Commands/AtlasDecideMetaLearningDeactivateCommand.php` |
| `atlas:atlas-decide:meta-learning:reset` | Atlas Decide · reset routing table back to native Atlas Decide policy (append-only reset receipt). | `app/Console/Commands/AtlasDecideMetaLearningResetCommand.php` |
| `atlas:atlas-decide:routing-table` | Atlas Decide · show the active routing table (read-only fold of activation receipts). | `app/Console/Commands/AtlasDecideRoutingTableCommand.php` |
| `atlas:aucri:optimize-audit` | Audit AUCRI optimization contracts for token reduction without quality loss. | `app/Console/Commands/AtlasAucriOptimizeAuditCommand.php` |
| `atlas:aucri:token-quality-canaries` | Emit AUCRI token/quality regression canary set. | `app/Console/Commands/AtlasAucriTokenQualityCanarySetCommand.php` |
| `atlas:aurg:backfill-temporal` | AURG · backfill bi-temporal ticks from git history (valid-time producer, T4-S1). | `app/Console/Commands/AtlasAurgBackfillTemporalCommand.php` |
| `atlas:aurg:edges-asof` | AURG edges as-of a valid-time instant (SIS4 SQL as-of). | `app/Console/Commands/AtlasAurgEdgesAsOfCommand.php` |
| `atlas:aurg:ingest` | AURG F1: ingest bounded provider-safe projections of the 5 read-models into the fused reality-graph store (atlas_aurg_nodes/atlas_aurg_edges) + deterministic... | `app/Console/Commands/AtlasAurgIngestCommand.php` |
| `atlas:aurg:query` | AURG F2: query the fused reality-graph brain with provenance (hybrid seeds + bounded traversal + cross-layer paths + honest ranking). | `app/Console/Commands/AtlasAurgQueryCommand.php` |
| `atlas:aurg:status` | AURG F4: honest health surface of the fused reality-graph brain (live store counts, temporal chain integrity, growth deltas, flag states). | `app/Console/Commands/AtlasAurgStatusCommand.php` |
| `atlas:aurg:temporal` | AURG · temporal timeline · state-at (transaction) / state-as-of-valid (T4-S1) / range query (read-only). | `app/Console/Commands/AtlasAurgTemporalCommand.php` |
| `atlas:aurg:temporal:record` | AURG · record a temporal tick (append-only, Doctor 3-Tier). | `app/Console/Commands/AtlasAurgTemporalRecordCommand.php` |
| `atlas:aurg:temporal:verify` | AURG · verify temporal chain integrity (walks ticks, reports break if any). | `app/Console/Commands/AtlasAurgTemporalVerifyCommand.php` |
| `atlas:autonomos:auto-apply-preflight` | Preflight for the auto-apply floor (operator-only flip). | `app/Console/Commands/AtlasAutonomosAutoApplyPreflightCommand.php` |
| `atlas:autonomos:preflight` | Read-only preflight for the Autonomos muscle (8 checks; the master flip is operator-only). | `app/Console/Commands/AtlasAutonomosPreflightCommand.php` |
| `atlas:autonomous-change-orchestrator` | Compose contract-first autonomous change orchestration over Impact GraphRAG, AVEOR and AVER. | `app/Console/Commands/AtlasAutonomousChangeOrchestratorCommand.php` |
| `atlas:autonomy:admit` | Atlas Autonomy Admission — compose Constitutional Kernel + risk + autonomy into a single admission envelope. | `app/Console/Commands/AtlasAutonomyAdmissionCommand.php` |
| `atlas:autonomy:ladder` | Inspect and evaluate the Atlas Autonomy Ladder (L0..L7) promotion/demote runtime. | `app/Console/Commands/AtlasAutonomyLadderCommand.php` |
| `atlas:autonomy:promote` | Atlas Autônomos autonomy tier promotion (S49): evaluate an operator-signed receipt and persist the promotion. No receipt = honest block. Never invokes a prov... | `app/Console/Commands/AtlasAutonomyPromoteCommand.php` |
| `atlas:autonomy:status` | Atlas Autônomos autonomy tier status: active tier per registered area + promotion chain readiness. Read-only. | `app/Console/Commands/AtlasAutonomyStatusCommand.php` |
| `atlas:aver` | Operate AVER, the Atlas Verified Execution Runtime. | `app/Console/Commands/AtlasAverCommand.php` |
| `atlas:aver:certify` | Certify AVER, the Atlas Verified Execution Runtime. | `app/Console/Commands/AtlasAverCertifyCommand.php` |
| `atlas:aweos` | Operate AWEOS, the Atlas Autonomous Work Execution OS. Orchestrates existing runtimes; no direct provider calls. | `app/Console/Commands/AtlasAweosCommand.php` |
| `atlas:aweos:certify` | Certify AWEOS, the Atlas Autonomous Work Execution OS. | `app/Console/Commands/AtlasAweosCertifyCommand.php` |
| `atlas:bdd` | Atlas BDD Acceptance Runtime — compile Gherkin scenarios, execute with registered step definitions, report honest pass/fail/pending_definition. | `app/Console/Commands/AtlasBddCommand.php` |
| `atlas:blast-radius` | Deterministic blast radius (affected call graph + covering tests + decisions touched) for a change, or a coverage measurement over real slices. | `app/Console/Commands/AtlasBlastRadiusCommand.php` |
| `atlas:blog:editorial-plan` | Read the public blog backlog and report the next governed editorial step. | `app/Console/Commands/AtlasBlogEditorialPlanCommand.php` |
| `atlas:brain:audit` | One-shot consolidated brain observability: state + health-doctor findings + raw adversarial audits. | `app/Console/Commands/AtlasBrainAuditCommand.php` |
| `atlas:brain:catalog` | Dump the portfolio path catalog (config(atlas.brain.paths)) with optional intent/kind filters. | `app/Console/Commands/AtlasBrainCatalogCommand.php` |
| `atlas:brain:contract-gaps` | List interfaces in a scope with ZERO concrete implementers (declared-but-unfulfilled contracts the brain can originate against). | `app/Console/Commands/AtlasBrainContractGapsCommand.php` |
| `atlas:brain:cycle-capsule` | Record external cycles as replayable capsules and internalize them into gated capability candidates. | `app/Console/Commands/AtlasBrainCycleCapsuleCommand.php` |
| `atlas:brain:findings` | Dump the adviser code→path mapping for every known doctor finding. | `app/Console/Commands/AtlasBrainFindingsCommand.php` |
| `atlas:brain:frontier-ingest` | Append frontier rows, optionally from the default-gated governed discover/read/ground fetcher. | `app/Console/Commands/AtlasBrainFrontierIngestCommand.php` |
| `atlas:brain:health-doctor` | First-aid checks over brain state — emits actionable findings (gate holes, dormant flags, dead memory, etc). | `app/Console/Commands/AtlasBrainHealthDoctorCommand.php` |
| `atlas:brain:history` | Tail of the brain reflection stream for a scope — compact operator-readable rows. | `app/Console/Commands/AtlasBrainHistoryCommand.php` |
| `atlas:brain:metrics` | Flat key=value metrics export (Prometheus textfile format). | `app/Console/Commands/AtlasBrainMetricsCommand.php` |
| `atlas:brain:next` | Brain DECIDE: originate + design the next grounded evolution spec for a scope (author≠judge — writes only docs/ + ledger). | `app/Console/Commands/AtlasBrainNextCommand.php` |
| `atlas:brain:path-yield` | Read-only PathYieldEwma report over the pattern-learning ledger + reflection stream. | `app/Console/Commands/AtlasBrainPathYieldCommand.php` |
| `atlas:brain:perception` | Dump the consolidated perception bundle (all read-only perception organs at once). | `app/Console/Commands/AtlasBrainPerceptionCommand.php` |
| `atlas:brain:plan` | Single-purpose: prints the recommended next action from the doctor adviser. | `app/Console/Commands/AtlasBrainPlanCommand.php` |
| `atlas:brain:predicted-impact` | MULTN17-04 predicted-impact calibration freeze reader. | `app/Console/Commands/AtlasBrainPredictedImpactCommand.php` |
| `atlas:brain:provenance` | Tail the per-seed provenance ledger (cycle_id + lineage signals). | `app/Console/Commands/AtlasBrainProvenanceCommand.php` |
| `atlas:brain:queued-targets` | List target files in a scope that already have a LIVE task packet (so the brain never re-proposes queued work). | `app/Console/Commands/AtlasBrainQueuedTargetsCommand.php` |
| `atlas:brain:rehydrate-memory` | Re-hydrate stub memories to real bodies (o quê+porquê+evidência), reversibly (D1). | `app/Console/Commands/AtlasBrainRehydrateMemoryCommand.php` |
| `atlas:brain:replay` | Rebuild brain memory rows from the hash-chained journal (SIS8 reversibility). | `app/Console/Commands/AtlasBrainReplayCommand.php` |
| `atlas:brain:scopes` | Dump the configured brain scope cohort (slug, label, meta_harness, roots_count). | `app/Console/Commands/AtlasBrainScopesCommand.php` |
| `atlas:brain:seed` | Brain SEED: gate originated packet specs and enqueue survivors onto the dedicated serving disk (BRAIN switch-gated). | `app/Console/Commands/AtlasBrainSeedCommand.php` |
| `atlas:brain:snapshot` | Compute current health_score and append one row to the brain health-score ledger (cron-friendly). | `app/Console/Commands/AtlasBrainSnapshotCommand.php` |
| `atlas:brain:state` | READ-ONLY brain state snapshot: master switch, scope, done-set tail, reflection tail (no origination). | `app/Console/Commands/AtlasBrainStateCommand.php` |
| `atlas:brain:summary` | Single-line plain-text brain summary (gates, ratio, finding counts) — terminal/status-line friendly. | `app/Console/Commands/AtlasBrainSummaryCommand.php` |
| `atlas:brain:trend` | Summarize the brain health-score ledger tail: first/last/min/max/delta. | `app/Console/Commands/AtlasBrainTrendCommand.php` |
| `atlas:brain:worker-prompt` | Print a ready-to-paste prompt that turns any AI session into the external-brain decide-loop (author≠judge). | `app/Console/Commands/AtlasBrainWorkerPromptCommand.php` |
| `atlas:brief` | Generate or show the deterministic brief (churn + invariants + refutations + HEAD) for a scope. | `app/Console/Commands/AtlasBriefCommand.php` |
| `atlas:cartography:truth-guard` | Atlas Cartography Truth Guard — detect drift between Cartografia render and canonical .md docs. | `app/Console/Commands/AtlasCartographyTruthGuardCommand.php` |
| `atlas:cli:bootstrap` | Run the professional Atlas CLI configuration and installation bootstrap. | `app/Console/Commands/AtlasCliBootstrapCommand.php` |
| `atlas:cli:checkpoint` | List, inspect and restore Atlas runtime checkpoints. | `app/Console/Commands/AtlasCliCheckpointCommand.php` |
| `atlas:cli:cockpit` | Cockpit único do motor vivo: cérebro + fila + landings recentes + review pendente + Dev/Forge. | `app/Console/Commands/AtlasCliCockpitCommand.php` |
| `atlas:cli:compare` | Run explicit Claude + Codex dual-review through Atlas. | `app/Console/Commands/AtlasCliCompareCommand.php` |
| `atlas:cli:completion` | Print or install Atlas CLI shell completion (bash/zsh). | `app/Console/Commands/AtlasCliCompletionCommand.php` |
| `atlas:cli:continue` | Resume the most recent unfinished Atlas dev workflow in this workspace. | `app/Console/Commands/AtlasCliContinueCommand.php` |
| `atlas:cli:dashboard` | Show the Atlas CLI/TUI operational dashboard for the current Mac workspace. | `app/Console/Commands/AtlasCliDashboardCommand.php` |
| `atlas:cli:dev` | Run the native Atlas CLI dev workflow with preflight, provider strategy and completion gate. | `app/Console/Commands/AtlasCliDevCommand.php` |
| `atlas:cli:dev:plan` | Atlas Dev A2 — project, approve, reject or inspect a Plan Visible for a work item. | `app/Console/Commands/AtlasCliDevPlanCommand.php` |
| `atlas:cli:doctor` | Run the Atlas CLI terminal readiness doctor. | `app/Console/Commands/AtlasCliDoctorCommand.php` |
| `atlas:cli:dogfood` | Track real Atlas CLI dogfooding sessions and final-product usage coverage. | `app/Console/Commands/AtlasCliDogfoodCommand.php` |
| `atlas:cli:final` | Verify Atlas CLI final-product readiness against the B0-B8 implementation plan. | `app/Console/Commands/AtlasCliFinalCommand.php` |
| `atlas:cli:fix` | Run Atlas dev repair loop for a known failing test, bug or quality gate. | `app/Console/Commands/AtlasCliFixCommand.php` |
| `atlas:cli:help` | Show the Atlas CLI product command map. | `app/Console/Commands/AtlasCliHelpCommand.php` |
| `atlas:cli:inbox` | Read and act on the Atlas operational inbox. | `app/Console/Commands/AtlasCliInboxCommand.php` |
| `atlas:cli:install` | Install the Atlas CLI launcher into the local shell PATH. | `app/Console/Commands/AtlasCliInstallCommand.php` |
| `atlas:cli:interrupt` | Cancel the active Atlas trace running for this workspace. | `app/Console/Commands/AtlasCliInterruptCommand.php` |
| `atlas:cli:memory` | Review and apply typed Atlas memory deltas. | `app/Console/Commands/AtlasCliMemoryCommand.php` |
| `atlas:cli:mobile` | Manage Atlas mobile devices, pairing codes and maintenance tasks. | `app/Console/Commands/AtlasCliMobileCommand.php` |
| `atlas:cli:permissions` | Manage Atlas CLI permission sessions for tool runtime. | `app/Console/Commands/AtlasCliPermissionsCommand.php` |
| `atlas:cli:providers` | Show Atlas CLI provider health and recommendation strategy. | `app/Console/Commands/AtlasCliProvidersCommand.php` |
| `atlas:cli:quality` | Run Atlas native quality/completion gate for the current workspace. | `app/Console/Commands/AtlasCliQualityCommand.php` |
| `atlas:cli:release` | Run the Atlas CLI release readiness gate and optionally create a version tag. | `app/Console/Commands/AtlasCliReleaseCommand.php` |
| `atlas:cli:rollback` | Rollback local Atlas CLI checkout with dry-run and migration safety. | `app/Console/Commands/AtlasCliRollbackCommand.php` |
| `atlas:cli:schedule` | Manage Atlas scheduled AI tasks. | `app/Console/Commands/AtlasCliScheduleCommand.php` |
| `atlas:cli:setup` | Diagnose and configure Atlas CLI provider binaries for terminal use. | `app/Console/Commands/AtlasCliSetupCommand.php` |
| `atlas:cli:skills` | List, inspect and validate Atlas agentskills bundles. | `app/Console/Commands/AtlasCliSkillsCommand.php` |
| `atlas:cli:start` | Briefing focado para comecar o dia: contexto + UMA proxima acao concreta. | `app/Console/Commands/AtlasCliStartCommand.php` |
| `atlas:cli:state` | Inspect and update Atlas CLI long-session state. | `app/Console/Commands/AtlasCliStateCommand.php` |
| `atlas:cli:trace` | Inspect Atlas traces, tool events, quality gates and replay-safe command summaries. | `app/Console/Commands/AtlasCliTraceCommand.php` |
| `atlas:cli:tui` | Open the interactive Atlas CLI terminal cockpit. | `app/Console/Commands/AtlasCliTuiCommand.php` |
| `atlas:cli:update` | Update local Atlas CLI checkout safely. | `app/Console/Commands/AtlasCliUpdateCommand.php` |
| `atlas:cli:version` | Show Atlas CLI version, commit and branch. | `app/Console/Commands/AtlasCliVersionCommand.php` |
| `atlas:code-graph:build` | Build & populate the real AP-811 code graph from Code Intelligence (gated by ATLAS_CODE_GRAPH_REAL_EDGES). --symbols for symbol-level granularity. | `app/Console/Commands/AtlasCodeGraphBuildCommand.php` |
| `atlas:code-graph:index-all` | AP-815: discover git repos under a root and index each as an isolated workspace (G-5 gated). | `app/Console/Commands/AtlasCodeGraphIndexAllCommand.php` |
| `atlas:code-graph:lsp` | Serve the Atlas code graph as a minimal LSP (JSON-RPC: initialize, textDocument/definition, textDocument/references). | `app/Console/Commands/AtlasCodeGraphLspCommand.php` |
| `atlas:code-graph:pipeline` | Run the per-workspace AWIS code-graph pipeline (index Code Intelligence -> build symbol graph) as one governed flow. Symbol build is gated by ATLAS_CODE_GRAP... | `app/Console/Commands/AtlasCodeGraphPipelineCommand.php` |
| `atlas:code-reality` | Read-only ACRUI operational reality classifier, reachability and anti-duplication gate. | `app/Console/Commands/AtlasCodeRealityCommand.php` |
| `atlas:code:deadcode-check` | Detect dead PRIVATE members (zero in-class references) via AST. Per-file frozen acceptance or repo-wide discovery. Exit 0 iff clean. | `app/Console/Commands/AtlasCodeDeadCodeCheckCommand.php` |
| `atlas:code:enterprise-certify` | Certifica o produto Atlas Code enterprise pesado: Obra, WorkItem, Forge Live, review, promotion, rollback, checkpoint e history. | `app/Console/Commands/AtlasCodeEnterpriseCertifyCommand.php` |
| `atlas:code:forge-fast-path` | Atlas Code Forge Operator Fast Path v1 — orquestra Obra → WorkItem → Spec/Plan/Tasks → Forge Live Execution. | `app/Console/Commands/AtlasCodeForgeFastPathCommand.php` |
| `atlas:code:forge-fast-path-status` | Status/resume canonico de um Atlas Code Forge Fast Path run. | `app/Console/Commands/AtlasCodeForgeFastPathStatusCommand.php` |
| `atlas:code:forge-intake` | Atlas Code Forge Work Intake & Spec Governance v1 · CLI canonica. | `app/Console/Commands/AtlasCodeForgeWorkIntakeCommand.php` |
| `atlas:code:forge-review` | Atlas Code Forge Review & Completion Gate v1 CLI (approve/reject/rollback ou show packet). | `app/Console/Commands/AtlasCodeForgeReviewCommand.php` |
| `atlas:code:forge-ux` | Atlas Code Forge UX Orchestrator · imprime o estado humano da Obra (camada acima do runtime). | `app/Console/Commands/AtlasCodeForgeUxOrchestratorCommand.php` |
| `atlas:code:obra-command-center` | Atlas Code Obra Command Center · resumo humano canonico da Obra (lifecycle, decision inbox, trust). | `app/Console/Commands/AtlasCodeObraCommandCenterCommand.php` |
| `atlas:code:scan` | Scan Atlas Código rules from a registered repository | `app/Console/Commands/AtlasCodeScanCommand.php` |
| `atlas:codemap` | Derive per-zone CODEMAPs for app/Services/Ai (public façade → navigation target). | `app/Console/Commands/AtlasCodemapCommand.php` |
| `atlas:cognition:acos-long-horizon-gate` | L6-9 honest ACOS long-horizon gate: requires score floors plus >=30 days of resolved-evidence delta series. | `app/Console/Commands/AtlasAcosLongHorizonGateCommand.php` |
| `atlas:cognition:evolution-score` | As 3 notas da evolução do ACOS (execução provada / inteligência entregue / autonomia), função de evidência resolvida — nunca literais. | `app/Console/Commands/AtlasCognitionEvolutionScoreCommand.php` |
| `atlas:cognition:mint-pipeline-receipts` | Cunha green-run receipts reais para subir a dimensão pipeline do scorecard ACOS (mede o lift antes/depois). | `app/Console/Commands/AtlasCognitionMintPipelineReceiptsCommand.php` |
| `atlas:cognition:predictive-code-intelligence-gate` | L6-11 gate: certify predictive code intelligence only when code-gate and resolved failure outcomes agree. | `app/Console/Commands/AtlasPredictiveCodeIntelligenceGateCommand.php` |
| `atlas:cognition:remint-touched` | Re-mint ACOS pipeline receipts for owner docs affected by touched paths. | `app/Console/Commands/AtlasCognitionRemintTouchedCommand.php` |
| `atlas:cognition:scorecard` | Atlas Cognition Operating System (ACOS) scorecard · 73 subsistemas em 3 dimensões estruturais: code (service existe) + doc (canon resolvido por ownership FQN... | `app/Console/Commands/AtlasCognitionScorecardCommand.php` |
| `atlas:cognition:scorecard:verify-claims` | Verify ACOS doc scorecard claim stamps against live runtime. --write refreshes stamps; exit=0 immediately after --write is tautological, the real guarantee i... | `app/Console/Commands/AtlasCognitionVerifyClaimsCommand.php` |
| `atlas:cognition:scorecard:watchdog` | PIP-08 — record ACOS scorecard strict stability evidence. | `app/Console/Commands/AtlasCognitionScorecardWatchdogCommand.php` |
| `atlas:cognitive-function` | Atlas Cognitive Function Atlas — read-model lens over ACOS scorecard projecting groups, gaps, shape, ownership. | `app/Console/Commands/AtlasCognitiveFunctionAtlasCommand.php` |
| `atlas:cognitive-function:decompose` | Decompose a natural-language pedido into the canonical 6-axis cognitive function tuple. | `app/Console/Commands/AtlasCognitiveFunctionDecomposeCommand.php` |
| `atlas:compaction:certify` | CPT-10 — certify compaction 10/10 from real source events and receipts. | `app/Console/Commands/AtlasCompactionCertifyCommand.php` |
| `atlas:compaction:recovery-sample` | MAXF-02 — sample compaction receipts and measure read-only recovery fidelity. | `app/Console/Commands/AtlasCompactionRecoverySampleCommand.php` |
| `atlas:compaction:soak-watch` | CPT-09 — read-only compaction soak readiness watchdog. | `app/Console/Commands/AtlasCompactionSoakWatchCommand.php` |
| `atlas:compounding:antifragility-metric` | Measure the Atlas antifragility composition metric. | `app/Console/Commands/AtlasAntifragilityMetricCommand.php` |
| `atlas:compounding:fixed-n-capability-dollar-gate` | L6-13: fixed-N capability-per-dollar series gate with measured cost and positive trend. | `app/Console/Commands/AtlasFixedNCapabilityDollarGateCommand.php` |
| `atlas:compounding:harvest-obra-lessons` | Colhe refutações/NÃO-FAZER de um doc de obra e cria learning candidates governados em quarentena (nunca auto-promove). | `app/Console/Commands/AtlasCompoundingHarvestObraLessonsCommand.php` |
| `atlas:compounding:level8` | Atlas Compounding L7→L8→L9 distillation — read runtime evidence and project current compounding level. | `app/Console/Commands/AtlasCompoundingLevel8Command.php` |
| `atlas:compounding:review-lessons` | Revisa a quarentena de lições de obra: lista candidates em hold; --promote/--reject é decisão explícita do operador (nunca auto-promove). | `app/Console/Commands/AtlasCompoundingReviewLessonsCommand.php` |
| `atlas:constitutional:kernel` | Atlas Constitutional Kernel — read pétreo invariants and validate proposed autonomous changes. | `app/Console/Commands/AtlasConstitutionalKernelCommand.php` |
| `atlas:constitutional:vault` | Atlas Constitutional Vault — separately-signed file outside source for kernel invariants verification. | `app/Console/Commands/AtlasConstitutionalVaultCommand.php` |
| `atlas:context-intelligence:certify` | Certify Atlas Context Intelligence Engine local wiring. No providers, rivals or benchmarks. | `app/Console/Commands/AtlasContextIntelligenceCertifyCommand.php` |
| `atlas:context-pack` | AOBG N1.F1: the unified provider-bound context-pack front door — fuses code-graph + AURG + semantic memory into one budgeted brief. Read-only, cost-free, fai... | `app/Console/Commands/AtlasContextPackCommand.php` |
| `atlas:context:agentic-rag` | Build the AUCRI AARF agentic RAG plan with AHRI, gap critic and sufficiency gate. | `app/Console/Commands/AtlasAgenticRagFrameworkCommand.php` |
| `atlas:context:cache-warm` | Build ACCCR Merkle context cache pack, prompt warmup receipt, and delta request. No provider calls, no writes. | `app/Console/Commands/AtlasContextCacheCompilerCommand.php` |
| `atlas:context:cognitive-memory` | Plan AUCRI ACMF cognitive working memory budget, delta and spillover receipts. | `app/Console/Commands/AtlasCognitiveMemoryFabricCommand.php` |
| `atlas:context:compile` | Compile AUCRI context into provider-aware final context pack with loss accounting. | `app/Console/Commands/AtlasContextCompilerRuntimeCommand.php` |
| `atlas:context:evaluate-retrieval` | Run AUCRI AREBA internal retrieval evaluation arena without external rivals. | `app/Console/Commands/AtlasRetrievalEvaluationBenchmarkArenaCommand.php` |
| `atlas:context:execution-cooccurrence` | MAXL-08 report-only delivered refs x measured used refs co-occurrence. | `app/Console/Commands/AtlasExecutionContextCooccurrenceCommand.php` |
| `atlas:context:feedback-auto` | Close the ARFL->ACRS loop: record one aggregated retrieval-feedback event from the context_pack_hash markers in a session transcript (fail-open). | `app/Console/Commands/AtlasContextFeedbackAutoCommand.php` |
| `atlas:context:feedback-health` | COM-10 — read-only context feedback signal health watchdog. | `app/Console/Commands/AtlasContextFeedbackHealthCommand.php` |
| `atlas:context:freshness-quality` | Evaluate the AUCRI ACFQ freshness and quality gate over ranked context. | `app/Console/Commands/AtlasContextFreshnessQualityGateCommand.php` |
| `atlas:context:golden-counterfactual` | MAXL-07 read-only paired golden counterfactual replay report. | `app/Console/Commands/AtlasGoldenCounterfactualReplayCommand.php` |
| `atlas:context:graph-retrieval` | Run AUCRI AGRN bounded graph retrieval over the Codebase World Model. | `app/Console/Commands/AtlasGraphRetrievalNetworkCommand.php` |
| `atlas:context:hybrid-retrieval` | Build the AUCRI AHRI hybrid retrieval report without provider calls or writes. | `app/Console/Commands/AtlasHybridRetrievalInfrastructureCommand.php` |
| `atlas:context:knowledge-ingestion` | Normalize AUCRI AKIF source packet with lineage, privacy gate and receipt hashes. | `app/Console/Commands/AtlasKnowledgeIngestionFabricCommand.php` |
| `atlas:context:latency` | Report AOBG context latency p50/p95 by op/day from the local JSONL ledger. | `app/Console/Commands/AtlasContextLatencyCommand.php` |
| `atlas:context:observability` | Show AUCRI ACOP context observability snapshot without exposing raw text. | `app/Console/Commands/AtlasContextObservabilityPlaneCommand.php` |
| `atlas:context:pareto-frontier` | Emit AUCRI context Pareto frontier shadow report. | `app/Console/Commands/AtlasContextParetoFrontierCommand.php` |
| `atlas:context:policy-trend` | Read-only ARFL policy-to-ROI trend over measured feedback windows. | `app/Console/Commands/AtlasContextPolicyTrendCommand.php` |
| `atlas:context:privacy-trust` | Evaluate AUCRI ARPTL provider-safe privacy/trust gate without exposing raw text. | `app/Console/Commands/AtlasRetrievalPrivacyTrustLayerCommand.php` |
| `atlas:context:purge-spurious-memory-noise` | FEE-06: purge historical spurious memory demote votes from ai_rag_feedback_events. | `app/Console/Commands/AtlasContextPurgeSpuriousMemoryNoiseCommand.php` |
| `atlas:context:python-data` | Run AUCRI APDR governed Python/data retrieval runtime contract and optional execution. | `app/Console/Commands/AtlasPythonDataRetrievalRuntimeCommand.php` |
| `atlas:context:quality-certify` | Run Atlas context/memory quality certification gate without providers or external rivals. | `app/Console/Commands/AtlasContextQualityCertifyCommand.php` |
| `atlas:context:rank` | Build the AUCRI ACRS context ranking report with explainable scores. | `app/Console/Commands/AtlasContextRankingSystemCommand.php` |
| `atlas:context:ranking-hints` | List provider-safe ACRS feedback-hint before/after snapshots from the Evidence Ledger (read-only). | `app/Console/Commands/AtlasContextRankingHintsCommand.php` |
| `atlas:context:reality-graph` | Run AUCRI AURG reality graph snapshot over governed Atlas reality entities and relationships. | `app/Console/Commands/AtlasUnifiedRealityGraphCommand.php` |
| `atlas:context:retrieval-budget` | Govern AUCRI ARCLG retrieval cost, latency, cache and safe degraded mode. | `app/Console/Commands/AtlasRetrievalCostLatencyGovernorCommand.php` |
| `atlas:context:retrieval-feedback` | Capture AUCRI ARFL retrieval feedback and proposal-only learning candidates. | `app/Console/Commands/AtlasRetrievalFeedbackLoopCommand.php` |
| `atlas:context:semantic-foundation` | Build the AUCRI ASEF manifest/readiness without external embeddings or vector-store writes. | `app/Console/Commands/AtlasSemanticEmbeddingFoundationCommand.php` |
| `atlas:context:sufficiency-calibration` | Report used-rate/utility split by declared sufficiency (calibrate or open a gap issue). | `app/Console/Commands/AtlasContextSufficiencyCalibrationCommand.php` |
| `atlas:context:token-economy` | Run the Atlas Token Economy local-prereasoning / token-optimization pass. | `app/Console/Commands/AtlasTokenEconomyRuntimeCommand.php` |
| `atlas:context:token-economy:input` | Compatibility alias for Atlas Token Economy JSON-input optimization. | `app/Console/Commands/AtlasTokenEconomyCommand.php` |
| `atlas:conversation-ops:certify` | Certify Atlas Conversation Operations Layer local wiring. No providers, rivals or benchmarks. | `app/Console/Commands/AtlasConversationOpsCertifyCommand.php` |
| `atlas:cross-domain:analytics` | AP-814 M-8 Fase-3: run the existing domain-agnostic python graph algorithms (betweenness/communities) over the cross-domain edge set via the governed venv bo... | `app/Console/Commands/AtlasCrossDomainAnalyticsCommand.php` |
| `atlas:cross-domain:bridge` | Atlas Cross-Domain · request a bridge through ARPTL (Doctor 3-Tier). | `app/Console/Commands/AtlasCrossDomainBridgeCommand.php` |
| `atlas:cross-domain:graph-build` | AP-814 M-8: assemble the cross-domain entity graph (domains + handoffs + mesh edges + entities), run god-node analytics, and optionally persist into the cros... | `app/Console/Commands/AtlasCrossDomainGraphBuildCommand.php` |
| `atlas:cross-domain:list-decisions` | Atlas Cross-Domain · list recent ARPTL veto decisions (read-only). | `app/Console/Commands/AtlasCrossDomainListDecisionsCommand.php` |
| `atlas:cross-domain:topology` | Atlas Cross-Domain · view mesh topology (allowed edges per privacy class). | `app/Console/Commands/AtlasCrossDomainTopologyCommand.php` |
| `atlas:ctx` | AP-815 I-2: keyword context retrieval over the code-graph symbol read-model → a budgeted E-3 context pack. Fail-safe (empty pack, exit 0). | `app/Console/Commands/AtlasCodeGraphContextCommand.php` |
| `atlas:db:explain` | Record Postgres query-plan evidence for a task. | `app/Console/Commands/AtlasDbExplainCommand.php` |
| `atlas:db:review` | Run deterministic Postgres engineering review for a task. | `app/Console/Commands/AtlasDbReviewCommand.php` |
| `atlas:decide:replay-divergence` | MULTK-03 — read-only decision replay divergence over gateway consultations. | `app/Console/Commands/AtlasDecideReplayDivergenceCommand.php` |
| `atlas:decide:self-model` | ASI-13 self-model read model consumed by the Decide layer. | `app/Console/Commands/AtlasDecideSelfModelCommand.php` |
| `atlas:dev:beat-test` | Score supplied Atlas Dev beat-test evidence without dispatching providers; optionally enqueue concrete gaps. | `app/Console/Commands/AtlasDevBeatTestReportCommand.php` |
| `atlas:dev:capsule-backfill` | Re-hydrate atlas_dev_failure_capsules from the real failure_capsule receipts (idempotent). | `app/Console/Commands/AtlasDevCapsuleBackfillCommand.php` |
| `atlas:dev:debug:smoke` | Smoke for Atlas Dev plan-only, with optional confirmed provider execution. | `app/Console/Commands/AtlasDevSmokeCommand.php` |
| `atlas:dev:desktop:acceptance` | Audit persisted Atlas Dev Desktop real-smoke acceptance evidence without calling a provider. | `app/Console/Commands/AtlasDevDesktopAcceptanceCommand.php` |
| `atlas:dev:desktop:certify` | Certify Atlas Dev Desktop operational readiness without model/provider calls. | `app/Console/Commands/AtlasDevDesktopCertifyCommand.php` |
| `atlas:dev:desktop:efficiency-evidence` | Compute Atlas Dev Desktop comparative efficiency evidence from operator-supplied cases. | `app/Console/Commands/AtlasDevDesktopEfficiencyEvidenceCommand.php` |
| `atlas:dev:desktop:enable` | Enable the local Atlas Dev Desktop runtime flags and report readiness. | `app/Console/Commands/AtlasDevDesktopEnableCommand.php` |
| `atlas:dev:desktop:goal-audit` | Audit whether Atlas Dev Desktop satisfies the full operator goal, including comparative efficiency evidence. | `app/Console/Commands/AtlasDevDesktopGoalAuditCommand.php` |
| `atlas:dev:desktop:real-smoke` | Run a confirmed Atlas Dev Desktop smoke with the real provider/runtime path. | `app/Console/Commands/AtlasDevDesktopRealSmokeCommand.php` |
| `atlas:dev:exemplar-index` | Backfill the exemplar index (exemplar_index.jsonl) over the whole Dev receipts store. | `app/Console/Commands/AtlasDevExemplarIndexCommand.php` |
| `atlas:dev:minimax-worker:run` | Run the Codex→MiniMax implementation worker for one bounded Atlas task | `app/Console/Commands/AtlasDevMinimaxWorkerRunCommand.php` |
| `atlas:dev:readiness` | Check Atlas Dev Desktop runtime readiness. | `app/Console/Commands/AtlasDevReadinessCommand.php` |
| `atlas:dev:run-certify` | Emit the latest Atlas Dev run certification for a run/task. | `app/Console/Commands/AtlasDevRunCertifyCommand.php` |
| `atlas:dev:run-snapshot` | Read-only control snapshot for a Dev run. | `app/Console/Commands/AtlasDevRunSnapshotCommand.php` |
| `atlas:dev:run-worker` | Execute an accepted Atlas Dev run from persisted plan artifacts. | `app/Console/Commands/AtlasDevRunWorkerCommand.php` |
| `atlas:dev:runtime-flows` | List the supported flows of the dev runtime intelligence service. | `app/Console/Commands/AtlasDevRuntimeFlowsCommand.php` |
| `atlas:dev:senior-loop:audit` | Audit the Atlas Dev Senior Engineer Loop capability projection. | `app/Console/Commands/AtlasDevSeniorLoopAuditCommand.php` |
| `atlas:dev:senior-loop:run` | Run the Atlas Dev Senior Engineer Loop operational path end-to-end. | `app/Console/Commands/AtlasDevSeniorLoopRunCommand.php` |
| `atlas:docs:lint-file` | Lint ONE canonical-module markdown doc against the structural rules docs-health enforces (frozen, per-file). Exit 0 iff clean. | `app/Console/Commands/AtlasDocsLintFileCommand.php` |
| `atlas:docs:locate` | Resolve the canonical owner doc for a needle from the docs authority graph (R1). | `app/Console/Commands/AtlasDocsLocateCommand.php` |
| `atlas:docs:reality-check-file` | Detect fake-implemented docs: App\\ classes / artisan commands a doc claims but the code lacks. Per-file frozen acceptance or docs-tree discovery. Exit 0 iff... | `app/Console/Commands/AtlasDocsRealityCheckFileCommand.php` |
| `atlas:documentation-reality` | Read-only ADRS source registry, block readiness and documentation reality score. | `app/Console/Commands/AtlasDocumentationRealityCommand.php` |
| `atlas:documentation-reality-antibody-proposals` | Read-only P3 antibody proposer: from escaped failures, propose the detector + reproducing-test outline that makes each un-repeatable. Never creates a gate, n... | `app/Console/Commands/AtlasDocumentationRealityAntibodyProposalsCommand.php` |
| `atlas:documentation-reality-auto-heal` | Commit-boundary auto-heal: downgrade each over-claiming staged canonical doc to its honest computed implementation_state (dry-run by default). | `app/Console/Commands/AtlasDocumentationRealityAutoHealCommand.php` |
| `atlas:documentation-reality-bidirectional-reconcile` | Read-only P2 bidirectional doc<->code reconciliation: over-claim repairs (delegated) PLUS under-claim doc upgrades, both doc-side. Never applies, never gener... | `app/Console/Commands/AtlasDocumentationRealityBidirectionalReconcileCommand.php` |
| `atlas:documentation-reality-causal-self-model` | Read-only L-inf fragment (R1 causal self-model): explains a capability as intent->truth->result->why with calibrated uncertainty on every causal link, distin... | `app/Console/Commands/AtlasDocumentationRealityCausalSelfModelCommand.php` |
| `atlas:documentation-reality-code-contract-proposals` | Read-only P2 doc-ahead-of-code contract proposer: when a doc NAMED code (evidence_refs) the index cannot resolve, propose the contract (signature + test outl... | `app/Console/Commands/AtlasDocumentationRealityCodeContractProposalsCommand.php` |
| `atlas:documentation-reality-completeness` | Read-only ADRS runtime-completeness check. Reports THREE separate axes honestly: (a) runtime_completeness (the buildable mechanisms — the achievable 10/10), ... | `app/Console/Commands/AtlasDocumentationRealityCompletenessCommand.php` |
| `atlas:documentation-reality-flow` | Read-only demonstration of the documented ADRS "Fluxo alvo para IA": compose P1 predict + O2 advise + L0 write-boundary verdict + P2 reconcile + P3 immunise ... | `app/Console/Commands/AtlasDocumentationRealityFlowCommand.php` |
| `atlas:documentation-reality-intent-advisory` | Read-only L2-O2 intent advisory: before building, surface considerations + leverage questions about a proposed spec (duplication, drift, owner, fit-to-object... | `app/Console/Commands/AtlasDocumentationRealityIntentAdvisoryCommand.php` |
| `atlas:documentation-reality-multi-estate` | Read-only L2-O3 cross-estate immunity propagation proposer: given an antibody + target estates, propose immunising the operator\'s OTHER estates with the SAM... | `app/Console/Commands/AtlasDocumentationRealityMultiEstateCompoundingCommand.php` |
| `atlas:documentation-reality-outcome-grounding` | Read-only L2-O1 outcome-grounding scorer: grade implemented docs by whether a REAL outcome signal links to them. No signal => implemented_no_outcome_signal (... | `app/Console/Commands/AtlasDocumentationRealityOutcomeGroundingCommand.php` |
| `atlas:documentation-reality-reflective-status` | Read-only L-inf fragment (R2 epistemic humility): the ADRS reflective self-status. Answers "is the ADRS 10/10?" with calibrated uncertainty + declared blind-... | `app/Console/Commands/AtlasDocumentationRealityReflectiveStatusCommand.php` |
| `atlas:documentation-reality-repair-proposals` | Read-only P2 reconciliation repair proposer: conservative doc-side repair proposals for detected doc<->code drift. Never applies, never touches code. | `app/Console/Commands/AtlasDocumentationRealityRepairProposalsCommand.php` |
| `atlas:documentation-reality-self-improvement-modeling` | Read-only, proposal-only L-inf fragment (R3 self-improving modeling): watches R1/R2 declared limits and PROPOSES the next measurable self-model improvement f... | `app/Console/Commands/AtlasDocumentationRealitySelfImprovementModelingCommand.php` |
| `atlas:documentation-reality-write-gate` | Write-bound ADRS gate: one fail-closed verdict over a proposed change at the commit boundary. | `app/Console/Commands/AtlasDocumentationRealityWriteGateCommand.php` |
| `atlas:documentation:enforce` | Unified pre-implementation documentation enforcement gate for AI/provider sessions. | `app/Console/Commands/AtlasDocumentationEnforcementCommand.php` |
| `atlas:domain:analyze` | Adjudicate a structured cross-domain analysis through the G6 lens panel and honesty gate (deterministic, decision-only). | `app/Console/Commands/AtlasDomainAnalyzeCommand.php` |
| `atlas:dreyfus` | Inspect or update Atlas Cognitive Dreyfus overlays. | `app/Console/Commands/AtlasDreyfusCommand.php` |
| `atlas:efficiency` | Operate AQPES quality-preserving efficiency certification, shadow, and resource policy. No providers, no writes. | `app/Console/Commands/AtlasQualityPreservingEfficiencyCommand.php` |
| `atlas:elite:compaction` | Elite compaction obra — baseline, inventory, loop deprecation, prune, proof battery. | `app/Console/Commands/AtlasEliteCompactionCommand.php` |
| `atlas:engineering:api-contract` | Validate API contracts through the Atlas Super Tool Runtime evidence layer. | `app/Console/Commands/AtlasEngineeringApiContractCommand.php` |
| `atlas:engineering:benchmark` | Run an Atlas Engineering benchmark suite and persist case-level runner quality results. | `app/Console/Commands/AtlasEngineeringBenchmarkCommand.php` |
| `atlas:engineering:benchmark:calibrate` | Calibrate Atlas-Bench rollout policy and corpus health from recorded benchmark outcomes. | `app/Console/Commands/AtlasEngineeringBenchmarkCalibrateCommand.php` |
| `atlas:engineering:benchmark:claude-fair` | Run the official opt-in Fair Claude benchmark workflow against Claude Code CLI. | `app/Console/Commands/AtlasEngineeringBenchmarkFairCommand.php` |
| `atlas:engineering:benchmark:replay-manifest` | Read a persisted benchmark replay manifest after integrity verification. | `app/Console/Commands/AtlasEngineeringBenchmarkReplayManifestCommand.php` |
| `atlas:engineering:benchmark:report` | Summarize persisted Fair Claude paired benchmark scorecards. | `app/Console/Commands/AtlasEngineeringBenchmarkReportCommand.php` |
| `atlas:engineering:benchmark:seed` | Create the default Atlas-Bench suite and promote real Harness runs into reusable benchmark cases. | `app/Console/Commands/AtlasEngineeringBenchmarkSeedCommand.php` |
| `atlas:engineering:deliver` | Real code delivery: a provider generates a syntax-verified artifact in an isolated sandbox, certified for review (never merged). | `app/Console/Commands/AtlasEngineeringDeliverCommand.php` |
| `atlas:engineering:docker-cleanup` | Audit or delete old Atlas Engineering Docker caches and exported test artifacts. | `app/Console/Commands/AtlasEngineeringDockerCleanupCommand.php` |
| `atlas:engineering:end-to-end-scorecard` | Read-only ENG-12 scorecard over the engineering end-to-end primary sources. | `app/Console/Commands/AtlasEngineeringEndToEndScorecardCommand.php` |
| `atlas:engineering:enforce-readiness` | ENG-11 — read-only readiness verdict for engineering enforcement flips. | `app/Console/Commands/AtlasEngineeringEnforceReadinessCommand.php` |
| `atlas:engineering:harnessability:calibrate` | Calibrate Atlas Engineering harnessability autonomy thresholds from historical runs and outcomes. | `app/Console/Commands/AtlasEngineeringHarnessabilityCalibrateCommand.php` |
| `atlas:engineering:knowledge` | Sync and inspect the Atlas Engineering Knowledge Base. | `app/Console/Commands/AtlasEngineeringKnowledgeCommand.php` |
| `atlas:engineering:m-scorecard` | Honest engineering-multiplier scorecard: real Dev run outcomes, repair conversion, task-serving flow and learning-memory liveness. | `app/Console/Commands/AtlasEngineeringMScorecardCommand.php` |
| `atlas:engineering:quality-foundry-manifests` | Execute mode-scoped evidence tests and emit read-only Quality Foundry live manifests. | `app/Console/Commands/AtlasEngineeringQualityFoundryManifestsCommand.php` |
| `atlas:engineering:quality-foundry-readiness` | Read-only checklist manifest for the Atlas Quality Foundry master plan. | `app/Console/Commands/AtlasEngineeringQualityFoundryReadinessCommand.php` |
| `atlas:engineering:quality-scan` | Run an Atlas Engineering quality/security scan with audited tool detection, artifacts and normalized findings. | `app/Console/Commands/AtlasEngineeringQualityScanCommand.php` |
| `atlas:engineering:refactor-census` | Shape-census de métodos por hash + conta líquida honesta (órgão Refactor Intelligence, read-only) | `app/Console/Commands/AtlasEngineeringRefactorCensusCommand.php` |
| `atlas:engineering:replay` | Replay an existing Atlas Engineering Harness run with a controlled, auditable strategy. | `app/Console/Commands/AtlasEngineeringReplayCommand.php` |
| `atlas:engineering:run` | Run the Atlas Engineering Harness Runner for a task with controls, patch artifacts, tests and score. | `app/Console/Commands/AtlasEngineeringRunCommand.php` |
| `atlas:engineering:sbom` | Generate a local SBOM evidence run through the generic Atlas tool runtime. | `app/Console/Commands/AtlasEngineeringSbomCommand.php` |
| `atlas:engineering:security-scan` | Run Atlas Engineering security and vulnerability scanners through the generic tool runtime. | `app/Console/Commands/AtlasEngineeringSecurityScanCommand.php` |
| `atlas:engineering:visual-baseline` | List or promote Atlas Engineering visual smoke DOM and screenshot baselines. | `app/Console/Commands/AtlasEngineeringVisualBaselineCommand.php` |
| `atlas:engineering:visual-driver` | Inspect or bootstrap the Atlas-managed Playwright runtime used by engineering visual smoke. | `app/Console/Commands/AtlasEngineeringVisualDriverCommand.php` |
| `atlas:engineering:visual-smoke` | Run Atlas-managed local visual smoke checks and persist route snapshots. | `app/Console/Commands/AtlasEngineeringVisualSmokeCommand.php` |
| `atlas:evolucao` | Navigate and revert the Evolution Diary (Carta Regra 3). | `app/Console/Commands/AtlasEvolucaoCommand.php` |
| `atlas:external-brain:amplifier-economics` | Read-only model-amplifier economics report: telemetry health, escalation policy, shadow-to-live promotion gate, and value-gate backtest replay. | `app/Console/Commands/AtlasExternalBrainAmplifierEconomicsCommand.php` |
| `atlas:external-brain:autonomy-governor` | Read-only autonomy-governor report: run policy compliance, queue saturation, task-family yield, and enqueue value throttle. | `app/Console/Commands/AtlasExternalBrainAutonomyGovernorCommand.php` |
| `atlas:external-brain:autonomy-replay` | Read-only end-to-end autonomy cycle replay + cycle-causality verifier + control-plane snapshot (fails closed, never claims fake 24/7 readiness). | `app/Console/Commands/AtlasExternalBrainAutonomyReplayCommand.php` |
| `atlas:external-brain:capability-proof-map` | Read-only: combined capability integration + evidence-provenance + debt-ledger proof map. | `app/Console/Commands/AtlasExternalBrainCapabilityProofMapCommand.php` |
| `atlas:external-brain:control-plane` | Read-only external-brain control plane inspector (inspect\|plan\|audit\|certify). | `app/Console/Commands/AtlasExternalBrainControlPlaneCommand.php` |
| `atlas:external-brain:control-plane-convergence` | Read-only control-plane convergence audit (organ integration gate feeds the stop-go bridge with real integrated proof). | `app/Console/Commands/AtlasExternalBrainControlPlaneConvergenceCommand.php` |
| `atlas:external-brain:domain-map` | Read-only: fuse maturity gaps, capability-map drift, evidence backfill, and breakthrough planning into one domain-map verdict. | `app/Console/Commands/AtlasExternalBrainDomainMapCommand.php` |
| `atlas:external-brain:final-readiness` | Read-only final-95 readiness composer (readiness map + gap burn-down + certification gate + finality evidence bundle), fail-closed on any sub-verdict. | `app/Console/Commands/AtlasExternalBrainFinalReadinessCommand.php` |
| `atlas:external-brain:gap-closure` | Read-only External Brain gap-closure snapshot (closed-loop learning + autonomous spine + queue repair + outcome feedback). | `app/Console/Commands/AtlasExternalBrainGapClosureCommand.php` |
| `atlas:external-brain:knowledge-freshness` | Read-only knowledge-freshness runtime (artifact map + docs drift gate + code-index readiness + post-merge plan). | `app/Console/Commands/AtlasExternalBrainKnowledgeFreshnessCommand.php` |
| `atlas:external-brain:learning-completeness` | Read-only: closed-loop learning completeness audit (cycle verifier + worker feedback inbox + compounding outcome router). | `app/Console/Commands/AtlasExternalBrainLearningCompletenessCommand.php` |
| `atlas:external-brain:maturity-gap` | Read-only final-95 blocker + capability-map drift report (combines maturity gap index + drift detector). | `app/Console/Commands/AtlasExternalBrainMaturityGapCommand.php` |
| `atlas:external-brain:model-amplifier` | Read-only model-capability amplification + scaffold-overfit + model-tier-governance report: proves when a smaller model can safely operate with scaffold, and... | `app/Console/Commands/AtlasExternalBrainModelAmplifierCommand.php` |
| `atlas:external-brain:originator-coverage` | Read-only: combine roadmap coverage, surface saturation, backlog aging, and impact diversity into one governance verdict. | `app/Console/Commands/AtlasExternalBrainOriginatorCoverageCommand.php` |
| `atlas:external-brain:originator-quality` | Read-only originator-quality runtime: critique → leverage-rank → coverage-audit → bounded high-value batch. | `app/Console/Commands/AtlasExternalBrainOriginatorQualityCommand.php` |
| `atlas:external-brain:originator-stop-pivot` | Read-only: compose stop/pivot advisor, theme saturation, batch value, and spec novelty into one origination verdict. | `app/Console/Commands/AtlasExternalBrainOriginatorStopPivotCommand.php` |
| `atlas:external-brain:post-commit-learning` | Read-only: post-commit learning loop (feedback router + green-lift evaluator + roadmap delta + capability transfer). | `app/Console/Commands/AtlasExternalBrainPostCommitLearningCommand.php` |
| `atlas:external-brain:proposal-arena` | Read-only proposal replay-court + arena runner: returns the single winner with loser reasons before any enqueue step. | `app/Console/Commands/AtlasExternalBrainProposalArenaCommand.php` |
| `atlas:external-brain:provider-independence` | Read-only provider-pool capability + cost/quality routing + independence-gate + local-client-governance verdict: optional accelerators stay optional, hidden ... | `app/Console/Commands/AtlasExternalBrainProviderIndependenceCommand.php` |
| `atlas:external-brain:queue-pressure` | Read-only: combine drain forecast, adaptive batch size, and worker-floor pressure into one control surface. | `app/Console/Commands/AtlasExternalBrainQueuePressureCommand.php` |
| `atlas:external-brain:regression-repair` | Read-only gate-regression + give-back root-cause + queue-repair-ranking + repair-task-synthesis report. | `app/Console/Commands/AtlasExternalBrainRegressionRepairCommand.php` |
| `atlas:external-brain:research-to-task` | Read-only research/frontier-digest to task-candidate converter (trust-rank + triage + digest + ground, blocks hype/provider-dependent/non-runnable ideas). | `app/Console/Commands/AtlasExternalBrainResearchToTaskCommand.php` |
| `atlas:external-brain:simplification-burndown` | Read-only first-safe retire/merge/simplify batch plan with preserved task-feed yield. | `app/Console/Commands/AtlasExternalBrainSimplificationBurnDownCommand.php` |
| `atlas:external-brain:simplification-governor` | Read-only: simplification governor (architecture compression + complexity burn-down + organ sprawl reduction + ROI ledger). | `app/Console/Commands/AtlasExternalBrainSimplificationGovernorCommand.php` |
| `atlas:external-brain:strategy-loop` | Read-only strategy-loop runtime: filter → leverage-rank → ambition-budget → durable decision ledger. | `app/Console/Commands/AtlasExternalBrainStrategyLoopCommand.php` |
| `atlas:external-brain:task-graph-orchestrator` | Read-only task-graph orchestration runtime (critical path + staleness audit + prerequisite unlock + release gate). | `app/Console/Commands/AtlasExternalBrainTaskGraphOrchestratorCommand.php` |
| `atlas:external-brain:task-graph-wave` | Read-only critical-path execution wave manifest (ROI scheduler + prerequisite unlock + wave manifest + release gate). | `app/Console/Commands/AtlasExternalBrainTaskGraphWaveCommand.php` |
| `atlas:external-brain:unified-control-plane` | Read-only external-brain unified control-plane snapshot (fail-closed stop/go). | `app/Console/Commands/AtlasExternalBrainUnifiedControlPlaneCommand.php` |
| `atlas:fable:delta` | Alias of `atlas:acos:delta`. Compara o estado atual com o Marco Zero (deltas resolvidos por evidência viva). | `app/Console/Commands/AtlasAcosDeltaCommand.php` |
| `atlas:fable:delta-series` | Alias of `atlas:acos:delta-series`. Mantém a série temporal de deltas do ACOS (append-only, idempotente por data) e emite o relatório N×M de tendência por evidência resolvida. | `app/Console/Commands/AtlasAcosDeltaSeriesCommand.php` |
| `atlas:fable:final-capture` | Build the Fable L4-14 final M capture receipt from canonical docs, frozen tests, KB/index/projection receipts and a cold-session context pack. | `app/Console/Commands/AtlasFableFinalCaptureCommand.php` |
| `atlas:fable:final-report` | Build the Fable L4-13 final N x M report and tested cold-session handoff packet from resolved sources. | `app/Console/Commands/AtlasFableFinalReportCommand.php` |
| `atlas:fable:weekly-report` | Build the weekly Atlas engineering report and L5-1 agenda feed from resolved sources. | `app/Console/Commands/AtlasFableWeeklyReportCommand.php` |
| `atlas:failure` | Classify, inspect and review Atlas cognitive failure signatures. | `app/Console/Commands/AtlasFailureCommand.php` |
| `atlas:failure:auto-feed` | AP-819 F1 — auto-feed do cérebro de falhas: ai_job_attempts failed/timeout + ledger OPERATION_FAILED → failure_signatures. | `app/Console/Commands/AtlasFailureAutoFeedCommand.php` |
| `atlas:failure:weekly-red-snapshot` | L5-3 — grava o snapshot semanal do número REAL de testes vermelhos a partir do último relatório real. | `app/Console/Commands/AtlasFailureWeeklyRedSnapshotCommand.php` |
| `atlas:finance:paper-trade` | Paper-trading ao vivo na Binance spot: valida o cano de execução de ponta a ponta sem dinheiro. | `app/Console/Commands/AtlasFinancePaperTradeCommand.php` |
| `atlas:finance:poly-arb` | Scan Polymarket multi-outcome events for sum-of-legs arbitrage inconsistency (shadow only, no orders). | `app/Console/Commands/AtlasFinancePolyArbCommand.php` |
| `atlas:finance:poly-exec` | Run Polymarket sum-of-legs arbitrage in shadow/sim — long (buy all legs) and short (mint+sell simulation); live is blocked by Finance policy. | `app/Console/Commands/AtlasFinancePolyExecCommand.php` |
| `atlas:finance:poly-implication` | Scan Polymarket for cross-market implication violations P(A) <= P(B) (shadow only, no orders). | `app/Console/Commands/AtlasFinancePolyImplicationCommand.php` |
| `atlas:finance:poly-shadow` | Shadow-trade the Polymarket 5-minute BTC Up/Down market (no orders, calibration evidence only). | `app/Console/Commands/AtlasFinancePolyShadowCommand.php` |
| `atlas:finance:spot-exec` | Executor live Binance spot fail-closed: preflight/status sempre; ordens só com o gate completo. | `app/Console/Commands/AtlasFinanceSpotExecCommand.php` |
| `atlas:finance:strategy-adversarial-audit` | Dry adversarial audit for finance strategy-loop honesty and no-execution invariants. | `app/Console/Commands/AtlasFinanceStrategyAdversarialAuditCommand.php` |
| `atlas:finance:strategy-backtest` | Backtest a candidate strategy over real history and emit the frozen ATLAS_METRIC (propose-only; no live trading). | `app/Console/Commands/AtlasFinanceStrategyBacktestCommand.php` |
| `atlas:finance:strategy-campaign-runner` | Run exactly one sequential finance strategy campaign: pending champion confirmation first, otherwise next roadmap scenario. | `app/Console/Commands/AtlasFinanceStrategyCampaignRunnerCommand.php` |
| `atlas:finance:strategy-confirmation-next` | Show the next sequential confirmation campaign for a promoted trading-strategy champion. | `app/Console/Commands/AtlasFinanceStrategyConfirmationNextCommand.php` |
| `atlas:finance:strategy-loop-audit` | Read-only audit for the finance strategy campaign platform invariants. | `app/Console/Commands/AtlasFinanceStrategyLoopAuditCommand.php` |
| `atlas:finance:strategy-plan-completion-audit` | Requirement-by-requirement completion audit for the finance strategy scientific campaign plan. | `app/Console/Commands/AtlasFinanceStrategyPlanCompletionAuditCommand.php` |
| `atlas:finance:strategy-scientific-readiness-audit` | End-to-end readiness audit for the finance strategy scientific campaign platform. | `app/Console/Commands/AtlasFinanceStrategyScientificReadinessAuditCommand.php` |
| `atlas:finance:strategy-search` | Fast in-process evolutionary search for a trading strategy under the audited honesty gate (propose-only, no live trading). | `app/Console/Commands/AtlasFinanceStrategySearchCommand.php` |
| `atlas:flywheel:funnel` | MULTX-02 — read-only M leakage funnel by executor and window. | `app/Console/Commands/AtlasFlywheelFunnelCommand.php` |
| `atlas:flywheel:learning-latency` | MULTX-06 learning latency read-only series. | `app/Console/Commands/AtlasFlywheelLearningLatencyCommand.php` |
| `atlas:flywheel:loops` | MULTX-01 read-only flywheel loop assembler. | `app/Console/Commands/AtlasFlywheelLoopsCommand.php` |
| `atlas:forge:continuum-certify` | Atlas Forge Continuum OS certification (Provider Topology + Governed Fallback). Read-model; nunca chama provider externo. | `app/Console/Commands/AtlasForgeContinuumCertifyCommand.php` |
| `atlas:forge:kanban-dispatch` | Dispatch a decomposed Forge obra as a durable Hermes Kanban swarm (fail-closed: kanban.policy=atlas_adapter AND kanban.dispatch_for_forge=true AND --confirm). | `app/Console/Commands/AtlasForgeKanbanDispatchCommand.php` |
| `atlas:forge:l4-10-proof` | Plans the L4-10 multi-node Forge Obra and certifies only explicit real kill/resume evidence. | `app/Console/Commands/AtlasForgeMultiNodeL410ProofCommand.php` |
| `atlas:forge:live-execute` | Prova executavel da cadeia Forge real: sandbox, patch fixture, harness fixture, repair loop, Evidence Ledger. Falha fechado sem --obra. | `app/Console/Commands/AtlasForgeLiveExecuteCommand.php` |
| `atlas:forge:parallel-durable` | Propose a Forge parallel-durable assignment from JSON inputs (AP-704). | `app/Console/Commands/AtlasForgeParallelDurableCommand.php` |
| `atlas:forge:provider-capacity` | Atlas Forge Provider Capacity (read-model). Local-only; nunca chama provider externo. | `app/Console/Commands/AtlasForgeProviderCapacityCommand.php` |
| `atlas:forge:provider-failure-record` | Registra um evento de falha de provider em uma Obra (failure memory canonica). Read-only contra provider externo. | `app/Console/Commands/AtlasForgeProviderFailureRecordCommand.php` |
| `atlas:forge:provider-invoke` | Atlas Forge Governed Provider Invocation · plan-only por padrao; execute exige aprovacao explicita. | `app/Console/Commands/AtlasForgeProviderInvokeCommand.php` |
| `atlas:forge:provider-topology` | Show the Forge adaptive provider topology read model. | `app/Console/Commands/AtlasForgeProviderTopologyCommand.php` |
| `atlas:forge:reap-leases` | Reap expired Forge scope leases through the canonical reservation owner. | `app/Console/Commands/AtlasForgeLeaseReapCommand.php` |
| `atlas:forge:runtime-certify` | Prova replayable da cadeia Atlas Code -> Obra -> Forge Workspace -> programming.forge -> Decide -> Receipt -> Governance -> Evidence. | `app/Console/Commands/AtlasForgeRuntimeCertifyCommand.php` |
| `atlas:forge:runtime-dispatch` | Atlas Forge Runtime Dispatcher · prepara dispatch plan governado sem chamar provider externo. | `app/Console/Commands/AtlasForgeRuntimeDispatchCommand.php` |
| `atlas:forge:supervise` | Reap expired Forge leases and heartbeat every active Obra. | `app/Console/Commands/AtlasForgeSupervisorCommand.php` |
| `atlas:foundry:harvest` | Harvest a read-only Foundry evidence dossier from real anchors (AP-A, generates nothing). | `app/Console/Commands/AtlasFoundryHarvestCommand.php` |
| `atlas:foundry:verify` | Verify a harvested Foundry dossier; confirm/refute anchors with reasons (AP-A, regenerates nothing). | `app/Console/Commands/AtlasFoundryVerifyCommand.php` |
| `atlas:frontend:adapters` | Inspect Atlas Frontend framework HMR/live-mode adapter. | `app/Console/Commands/AtlasFrontendFrameworkAdapterCommand.php` |
| `atlas:frontend:assets` | Inspect Atlas Frontend asset packs or write governed templates. | `app/Console/Commands/AtlasFrontendAssetPackCommand.php` |
| `atlas:frontend:benchmark` | Run the Atlas Frontend competitive benchmark matrix. | `app/Console/Commands/AtlasFrontendBenchmarkCommand.php` |
| `atlas:frontend:blueprint` | Generate or write the Atlas Frontend product/UX/design blueprint for premium company frontend work. | `app/Console/Commands/AtlasFrontendBlueprintCommand.php` |
| `atlas:frontend:bridge` | Emit or inject the Atlas Frontend browser element picker bridge. | `app/Console/Commands/AtlasFrontendBrowserBridgeCommand.php` |
| `atlas:frontend:certify` | Certify the Atlas Frontend design runtime, gates, docs, commands and tests. | `app/Console/Commands/AtlasFrontendDesignRuntimeCertifyCommand.php` |
| `atlas:frontend:company-profile` | Inspect Atlas Frontend company design profiles or write governed templates. | `app/Console/Commands/AtlasFrontendCompanyDesignProfileCommand.php` |
| `atlas:frontend:control-plane` | Inspect Atlas Frontend execution readiness, market claim policy, rival replay and public proof status. | `app/Console/Commands/AtlasFrontendControlPlaneCommand.php` |
| `atlas:frontend:design-dossier` | Inspect or write the company-owned local repo design dossier required by Atlas Frontend. | `app/Console/Commands/AtlasFrontendDesignDossierCommand.php` |
| `atlas:frontend:design-system-drift` | Inspect Atlas Frontend design-system drift reports or write governed templates. | `app/Console/Commands/AtlasFrontendDesignSystemDriftCommand.php` |
| `atlas:frontend:detect` | Run the deterministic Atlas Frontend anti-AI-slop detector. | `app/Console/Commands/AtlasFrontendAntiSlopDetectCommand.php` |
| `atlas:frontend:directions` | Generate governed Atlas Frontend design directions for ambiguous or broad briefs. | `app/Console/Commands/AtlasFrontendDesignDirectionCommand.php` |
| `atlas:frontend:enterprise-bootstrap` | Prepare a company-owned local repo for premium Atlas Frontend execution. | `app/Console/Commands/AtlasFrontendEnterpriseBootstrapCommand.php` |
| `atlas:frontend:evidence` | Verify Atlas Frontend evidence packs or write governed templates. | `app/Console/Commands/AtlasFrontendEvidencePackCommand.php` |
| `atlas:frontend:evidence-kit` | Prepare the Atlas Frontend evidence collection kit for a real company frontend run. | `app/Console/Commands/AtlasFrontendEvidenceKitCommand.php` |
| `atlas:frontend:gate` | Run the Atlas Frontend pre-execution gate. | `app/Console/Commands/AtlasFrontendExecutionGateCommand.php` |
| `atlas:frontend:gauntlet` | Run the Atlas Frontend local company repo gauntlet across spec, gate, dossier, inventory and runtime certification. | `app/Console/Commands/AtlasFrontendGauntletCommand.php` |
| `atlas:frontend:handoff` | Compile an enterprise Atlas Frontend delivery handoff from certified evidence. | `app/Console/Commands/AtlasFrontendDeliveryHandoffCommand.php` |
| `atlas:frontend:intake` | Inspect a local company frontend repository and emit the Atlas Frontend operating map. | `app/Console/Commands/AtlasFrontendRepoIntakeCommand.php` |
| `atlas:frontend:inventory` | Inspect Atlas Frontend design-system tokens, components and libraries. | `app/Console/Commands/AtlasFrontendDesignSystemInventoryCommand.php` |
| `atlas:frontend:live` | Run Atlas Frontend live source patch prepare/accept/discard/recover operations. | `app/Console/Commands/AtlasFrontendLiveSourcePatchCommand.php` |
| `atlas:frontend:onboard` | Install and prepare Atlas Frontend operating runtime for a local company frontend repo. | `app/Console/Commands/AtlasFrontendCompanyRepoOnboardingCommand.php` |
| `atlas:frontend:outcomes` | Record or summarize Atlas Frontend outcome memory. | `app/Console/Commands/AtlasFrontendOutcomeMemoryCommand.php` |
| `atlas:frontend:plan` | Emit the Atlas Frontend deterministic runtime contract for a frontend task. | `app/Console/Commands/AtlasFrontendDesignRuntimePlanCommand.php` |
| `atlas:frontend:portfolio` | Scan a local company repo portfolio for Atlas Frontend readiness. | `app/Console/Commands/AtlasFrontendCompanyPortfolioCommand.php` |
| `atlas:frontend:private-benchmark-plan` | Compile the private Atlas Frontend competitive benchmark proof and improvement plan without public superiority claims. | `app/Console/Commands/AtlasFrontendPrivateBenchmarkProofPlanCommand.php` |
| `atlas:frontend:proof` | Emit Atlas Frontend product proof demo catalog or build/pilot proof dossier. | `app/Console/Commands/AtlasFrontendProductProofCommand.php` |
| `atlas:frontend:provider-packet` | Compile a provider-safe Atlas Frontend execution instruction packet. | `app/Console/Commands/AtlasFrontendProviderInstructionPacketCommand.php` |
| `atlas:frontend:publish` | Verify Atlas Frontend product proof publication readiness. | `app/Console/Commands/AtlasFrontendPublicationCommand.php` |
| `atlas:frontend:quality-budget` | Inspect objective Atlas Frontend quality budgets for accessibility, performance and visual stability. | `app/Console/Commands/AtlasFrontendQualityBudgetCommand.php` |
| `atlas:frontend:relay` | Emit or inject the Atlas Frontend live CSS preview relay. | `app/Console/Commands/AtlasFrontendLivePreviewRelayCommand.php` |
| `atlas:frontend:repair-plan` | Compile a deterministic Atlas Frontend repair plan from failed gates and blockers. | `app/Console/Commands/AtlasFrontendRepairPlanCommand.php` |
| `atlas:frontend:replay` | Inspect, template or operationalize Atlas Frontend external rival replay evidence. | `app/Console/Commands/AtlasFrontendRivalReplayCommand.php` |
| `atlas:frontend:review` | Inspect Atlas Frontend 5D design review reports or write governed templates. | `app/Console/Commands/AtlasFrontendDesignReviewCommand.php` |
| `atlas:frontend:rubric` | Emit the Atlas Frontend competitive scoring rubric. | `app/Console/Commands/AtlasFrontendCompetitiveRubricCommand.php` |
| `atlas:frontend:run-certify` | Certify one Atlas Frontend delivery run from real evidence artifacts. | `app/Console/Commands/AtlasFrontendRunCertifyCommand.php` |
| `atlas:frontend:runbook` | Compile a repo-specific Atlas Frontend execution runbook. | `app/Console/Commands/AtlasFrontendExecutionRunbookCommand.php` |
| `atlas:frontend:scenarios` | Compile the Atlas Frontend route x viewport x state visual verification scenario matrix. | `app/Console/Commands/AtlasFrontendScenarioMatrixCommand.php` |
| `atlas:frontend:selected-workspace` | Validate the operator-selected local company/product frontend repository path as the primary Atlas Frontend workspace. | `app/Console/Commands/AtlasFrontendSelectedWorkspaceCommand.php` |
| `atlas:frontend:skill-pack` | Export or install the provider-safe Atlas Frontend SKILL.md operating pack. | `app/Console/Commands/AtlasFrontendSkillPackCommand.php` |
| `atlas:frontend:spec` | Compile a provider-safe deterministic Atlas Frontend task spec. | `app/Console/Commands/AtlasFrontendTaskSpecCommand.php` |
| `atlas:frontend:visual-quality` | Inspect Atlas Frontend visual quality gate reports or write governed templates. | `app/Console/Commands/AtlasFrontendVisualQualityGateCommand.php` |
| `atlas:frontend:work-order` | Compile an executable Atlas Frontend work order from task, repo intake, gauntlet and control-plane readiness. | `app/Console/Commands/AtlasFrontendWorkOrderCommand.php` |
| `atlas:frontend:world-best-plan` | Legacy alias for atlas:frontend:private-benchmark-plan; public superiority claims stay disabled. | `app/Console/Commands/AtlasFrontendWorldBestProofPlanCommand.php` |
| `atlas:gateway:preflight` | Atlas Gateway Preflight — counterfactual tree before token spend on major decisions. | `app/Console/Commands/AtlasGatewayPreflightCommand.php` |
| `atlas:golden` | P5 · freeze/check a golden manifest of per-case DEEP-canonical hashes (Obra #19). | `app/Console/Commands/AtlasGoldenCommand.php` |
| `atlas:governance:amendments` | List governance floor amendment history and effective registry floors. | `app/Console/Commands/AtlasGovernanceAmendmentsCommand.php` |
| `atlas:governance:change-class-trust-release-gate` | L6-14: certify class-scoped trust release and regression revocation at the Admission boundary. | `app/Console/Commands/AtlasChangeClassTrustReleaseGateCommand.php` |
| `atlas:harness` | AP-819 — Atlas Harness Surface v1: surface, cluster→proposal bridge, frozen suite, reverse, autopilot (math-gated). | `app/Console/Commands/AtlasHarnessCommand.php` |
| `atlas:health:repair` | Audit and repair legacy HealthKit data that can pollute health metrics. | `app/Console/Commands/HealthRepairCommand.php` |
| `atlas:hermes:capabilities` | Read-only Hermes Capability Registry surface: probe the local Hermes CLI into the pinned atlas.hermes.capability_manifest.v1, diff it against the last record... | `app/Console/Commands/AtlasHermesCapabilitiesCommand.php` |
| `atlas:hermes:kanban` | Atlas Hermes Kanban swarm surface: status (config + readiness), plan (compose + seal a governed swarm plan, read-only), dispatch (run a real governed swarm —... | `app/Console/Commands/AtlasHermesKanbanCommand.php` |
| `atlas:hermes:mesh` | Alias of `atlas:hermes:workcell`. Atlas Workcell Adapter surface (Hermes runtime): status (config + readiness), plan (compose + seal a governed many-agent plan, read-only), dispatch (fan out ... | `app/Console/Commands/AtlasHermesMeshCommand.php` |
| `atlas:hermes:ops` | Read-only Hermes ops: preflight (version/doctor/status -> sealed health evidence) and sessions (sessions list -> hash-only evidence candidates; fail-closed u... | `app/Console/Commands/AtlasHermesOpsCommand.php` |
| `atlas:hermes:workcell` | Atlas Workcell Adapter surface (Hermes runtime): status (config + readiness), plan (compose + seal a governed many-agent plan, read-only), dispatch (fan out ... | `app/Console/Commands/AtlasHermesMeshCommand.php` |
| `atlas:host` | Manage Atlas Mac Agent power sessions, status and maintenance wake schedules. | `app/Console/Commands/AtlasHostCommand.php` |
| `atlas:host-agent:work` | Run the Atlas Mac Agent loop for heartbeats, session expiry and maintenance wake scheduling. | `app/Console/Commands/AtlasHostAgentWorkCommand.php` |
| `atlas:immune:calibration` | MAXI-03 read-only immune gate calibration report with FP/FN bands. | `app/Console/Commands/AtlasImmuneCalibrationCommand.php` |
| `atlas:immune:verify-lineage` | MAXI-07 verify tamper-evident HMAC capture lineage chain. | `app/Console/Commands/AtlasImmuneVerifyLineageCommand.php` |
| `atlas:initiatives` | Lista e executa iniciativas autonomas do Atlas com dry-run seguro por padrao. | `app/Console/Commands/AtlasInitiativesCommand.php` |
| `atlas:insight` | Cria um insight contextual no Inbox operacional do Atlas. | `app/Console/Commands/AtlasInsightCommand.php` |
| `atlas:insight:watch` | Roda watchers de saude, foco digital e metricas operacionais para emitir insights contextuais no Mobile Inbox. | `app/Console/Commands/AtlasInsightWatchCommand.php` |
| `atlas:intelligence:rollout-promote` | Promote ACOS intelligence rollout modes with certifier gates and audit receipt. | `app/Console/Commands/AtlasIntelligenceRolloutPromoteCommand.php` |
| `atlas:land` | Land a scoped slice on local main (fail-closed committer) + label it in the Evolution Diary (L1 · Carta Regra 3). | `app/Console/Commands/AtlasLandCommand.php` |
| `atlas:learning:mutation` | Atlas Learning Mutation Runtime — evaluate proposals + apply gated mutations (operator approval required). | `app/Console/Commands/AtlasLearningMutationCommand.php` |
| `atlas:learning:proposals-decision` | Decide learning-proposal rules: evidence-gated admission, weak-signal hold, justification/risk/action output, and the critical-change no-auto-apply review ga... | `app/Console/Commands/AtlasLearningProposalsCommand.php` |
| `atlas:ledger:replay` | Replay Atlas AI Evidence Ledger events for one operation envelope. | `app/Console/Commands/AtlasLedgerReplayCommand.php` |
| `atlas:local-agent:ingest` | Run the Atlas local agent memory ingestion pipeline against configured roots. | `app/Console/Commands/AtlasLocalAgentMemoryIngestCommand.php` |
| `atlas:local-verification:run` | Run ALVE local verification shadow: diff scope, test impact and failure capsule. No commands executed. | `app/Console/Commands/AtlasLocalVerificationEngineCommand.php` |
| `atlas:long-horizon:attention-queue` | TEOS-I3 operator attention queue read model for long-horizon work. | `app/Console/Commands/AtlasLongHorizonAttentionQueueCommand.php` |
| `atlas:long-horizon:causal-graph` | Atlas Long-Horizon · Causal Decision Graph Lite (read-only view sobre Mission Foundation + Router decisions + Forge intake). | `app/Console/Commands/AtlasLongHorizonCausalGraphCommand.php` |
| `atlas:long-horizon:continuity-certify` | Atlas TEOS-I2 · long-horizon continuity certification for a (scope_type, scope_id). | `app/Console/Commands/AtlasLongHorizonContinuityCertifyCommand.php` |
| `atlas:long-horizon:continuity-pack` | L6-12: emit a scoped continuation pack, replay manifest and continuity certification receipt. | `app/Console/Commands/AtlasLongHorizonContinuityPackCommand.php` |
| `atlas:long-horizon:cross-week-recall-lift-gate` | L6-12 keystone: prove cross-week continuity with a measured old-memory recall lift on real elapsed time. | `app/Console/Commands/AtlasLongHorizonCrossWeekRecallLiftGateCommand.php` |
| `atlas:long-horizon:obra-review` | TEOS-I3 weekly/monthly Forge Obra review receipt. Advisory-only; no mutation. | `app/Console/Commands/AtlasLongHorizonObraReviewCommand.php` |
| `atlas:long-horizon:replay-manifest` | TEOS-I2 Replay Manifest CLI · build / show / read / list provider-independent manifests. | `app/Console/Commands/AtlasLongHorizonReplayManifestCommand.php` |
| `atlas:long-horizon:strategic-forgetting` | TEOS-I3 strategic forgetting read-only receipt over Atlas memory. | `app/Console/Commands/AtlasLongHorizonStrategicForgettingCommand.php` |
| `atlas:long-horizon:world-model` | TEOS-I4 time-aware codebase world model read model. | `app/Console/Commands/AtlasLongHorizonWorldModelCommand.php` |
| `atlas:maestro:dialogue` | Operator entry point for the Quaternity dialogue-to-packet pipeline. | `app/Console/Commands/AtlasMaestroDialogueCommand.php` |
| `atlas:measure:dual-read` | ACOS MED-01 — record dual-read meter transition to the Evidence Ledger. | `app/Console/Commands/AtlasMeasureDualReadCommand.php` |
| `atlas:memory:add` | Add an Atlas central memory registry entry. | `app/Console/Commands/AtlasMemoryAddCommand.php` |
| `atlas:memory:audit` | Audit which Atlas memory entries were used by a trace context pack. | `app/Console/Commands/AtlasMemoryAuditCommand.php` |
| `atlas:memory:backfill-metadata` | Backfill memory metadata paths/domains from cited, resolvable content (dry-run by default). | `app/Console/Commands/AtlasMemoryBackfillMetadataCommand.php` |
| `atlas:memory:capture-candidates` | Capture Atlas memory candidates outside atlas_memory_entries and auto-admit those passing quality gates. | `app/Console/Commands/AtlasMemoryCaptureCandidatesCommand.php` |
| `atlas:memory:cognitive-immune-kernel` | Atlas Memory · cognitive immune gate (Input Class classify, G0-G8 promotion ladder, quarantine default, non-negotiable rules). [was atlas:aaeos:*; TRI-HYGIEN... | `app/Console/Commands/AtlasMemoryCognitiveImmuneLearningKernelCommand.php` |
| `atlas:memory:concentration-v2` | ASI-12 v2 multi-actor recall concentration (informative; v1 unchanged). | `app/Console/Commands/AtlasMemoryConcentrationV2Command.php` |
| `atlas:memory:consolidation-scan` | MAXH-03/MAXH-04 memory pair scanner and reversible consolidation actuator. | `app/Console/Commands/AtlasMemoryConsolidationScanCommand.php` |
| `atlas:memory:curate` | Curate an Atlas memory entry summary or archive a low-value entry. | `app/Console/Commands/AtlasMemoryCurateCommand.php` |
| `atlas:memory:embed-backfill` | Backfill real vector embeddings and MAXA-03 provenance for Atlas memory vector tables (pgvector). | `app/Console/Commands/AtlasMemoryEmbedBackfillCommand.php` |
| `atlas:memory:feedback` | Record explicit operator feedback for a recalled Atlas memory entry. | `app/Console/Commands/AtlasMemoryFeedbackCommand.php` |
| `atlas:memory:feedback-implicit` | D4 · mark recalled memories cited ∧ present in the diff as useful_implicit + flag dominant recalls (Obra #18). | `app/Console/Commands/AtlasMemoryFeedbackImplicitCommand.php` |
| `atlas:memory:govern` | Govern Atlas memory registry health, duplicates and conflicts. | `app/Console/Commands/AtlasMemoryGovernanceCommand.php` |
| `atlas:memory:growth-report` | Read-only weekly memory corpus growth and capture-channel health report. | `app/Console/Commands/AtlasMemoryGrowthReportCommand.php` |
| `atlas:memory:judge` | Atlas Cognition: register canonical conflict verdict between two memory entries (Absorcao 2 + 3). | `app/Console/Commands/AtlasMemoryJudgeCommand.php` |
| `atlas:memory:kb-embedding-coverage` | MAXA-06 fase 1 reader: semantic-coverage ratio over atlas_engineering_knowledge_items using MAXA-03 provenance. | `app/Console/Commands/AtlasMemoryKbEmbeddingCoverageCommand.php` |
| `atlas:memory:list` | List Atlas central memory registry entries. | `app/Console/Commands/AtlasMemoryListCommand.php` |
| `atlas:memory:maintain` | Run the Atlas memory maintenance routine: docs sync, code index, provider projection status/apply and Open Brain health. | `app/Console/Commands/AtlasMemoryMaintenanceCommand.php` |
| `atlas:memory:privacy` | Apply and review privacy/redaction policy for Atlas memory registry entries. | `app/Console/Commands/AtlasMemoryPrivacyCommand.php` |
| `atlas:memory:projection` | Generate provider-safe Atlas memory projection files such as CLAUDE.md and AGENTS.md. | `app/Console/Commands/AtlasMemoryProjectionCommand.php` |
| `atlas:memory:quality` | Show Atlas memory quality scorecard and operational recommendations. | `app/Console/Commands/AtlasMemoryQualityCommand.php` |
| `atlas:memory:recall` | Run provider-safe hybrid Atlas memory recall across registry, verbatim and semantic notes (alias: atlas:memory:search). | `app/Console/Commands/AtlasMemoryRecallCommand.php` |
| `atlas:memory:relations` | List and review Atlas memory duplicate/conflict relations. | `app/Console/Commands/AtlasMemoryRelationsCommand.php` |
| `atlas:memory:review-queue` | Show the unified Atlas memory review queue for privacy, verbatim and relation work. | `app/Console/Commands/AtlasMemoryReviewQueueCommand.php` |
| `atlas:memory:search` | Alias of `atlas:memory:recall`. Run provider-safe hybrid Atlas memory recall across registry, verbatim and semantic notes (alias: atlas:memory:search). | `app/Console/Commands/AtlasMemoryRecallCommand.php` |
| `atlas:memory:seed-core` | Seed canonical provider-safe Atlas core memories for provider projections and bootstraps. | `app/Console/Commands/AtlasMemorySeedCoreCommand.php` |
| `atlas:memory:substrate-snapshot` | ACOS SUB-01 — verified snapshot/backup of memory tables + series JSONLs. | `app/Console/Commands/AtlasMemorySubstrateSnapshotCommand.php` |
| `atlas:memory:temporal-backfill` | MAXH-02 — derive default temporal truth fields for Atlas Memory entries. | `app/Console/Commands/AtlasMemoryTemporalBackfillCommand.php` |
| `atlas:memory:temporal-quality` | MAXH-01 — read-only temporal truth v2 quality meter (MAXH-10 --check adds cadence+regression watchdog checks). | `app/Console/Commands/AtlasMemoryTemporalQualityCommand.php` |
| `atlas:memory:verbatim` | Manage exact Atlas verbatim memories with privacy-aware redaction. | `app/Console/Commands/AtlasMemoryVerbatimCommand.php` |
| `atlas:mission:deliver` | Mission e2e: request → brain context → certified code → branch → outcome recorded (Atlas delivers, you merge). | `app/Console/Commands/AtlasMissionDeliverCommand.php` |
| `atlas:mission:e2e` | TETO-02 mission end-to-end rate reader. | `app/Console/Commands/AtlasMissionE2eRateCommand.php` |
| `atlas:native:constitution-heal` | Mechanically heal atlas-native constitution findings (R2 dead symbol v1) | `app/Console/Commands/AtlasNativeConstitutionHealCommand.php` |
| `atlas:native:constitution-scan` | Scan atlas-native constitution rules R1-R5 | `app/Console/Commands/AtlasNativeConstitutionScanCommand.php` |
| `atlas:night-shift:area-focus` | Atlas Night Shift · Area Focus Loop read-only read model (no writes, no provider, no execution, no Dev/Forge dispatch). | `app/Console/Commands/AtlasNightShiftAreaFocusCommand.php` |
| `atlas:night-shift:area-focus-certify` | Atlas Night Shift · Area Focus Loop operational certification (AP-722): composes the slices into one verdict. No repo mutation, no provider, no execution, no... | `app/Console/Commands/AtlasAreaFocusCertifyCommand.php` |
| `atlas:night-shift:area-focus-cycle` | Atlas Night Shift · Area Focus durable cycle + evidence pack (AP-720): record/replay/list/evidence-pack over append-only JSONL. No writes to repo, no provide... | `app/Console/Commands/AtlasNightShiftAreaFocusCycleCommand.php` |
| `atlas:night-shift:area-focus-operate` | Atlas Night Shift · Area Focus Loop operational orchestrator + certification (read-only; composes AP-716..AP-720; no execution, no branch, no dispatch, no me... | `app/Console/Commands/AtlasNightShiftAreaFocusOperateCommand.php` |
| `atlas:nightly:counterfactuals` | Atlas Nightly Counterfactuals — background TEOS-I4 projections of yesterday major decisions. | `app/Console/Commands/AtlasNightlyCounterfactualsCommand.php` |
| `atlas:obra:current` | Show or set the active obra (storage/atlas/obras/current) — the explicit scope of the resumption pack. | `app/Console/Commands/AtlasObraCurrentCommand.php` |
| `atlas:obra:deliver` | AOBG N3.F4: ONE command commissions an obra — decompose an intent, execute it onto ONE branch (Atlas delivers, you merge). Spends per node; halts on a failed... | `app/Console/Commands/AtlasObraDeliverCommand.php` |
| `atlas:obra:enqueue-work-order` | B2a · enfileira uma Ordem (work-order Kit) na fila viva → K2/K3/K4 avaliam de verdade | `app/Console/Commands/AtlasObraEnqueueWorkOrderCommand.php` |
| `atlas:obra:plan` | AOBG N3.F1: decompose an intent into a validated, brain-anchored plan-DAG (PLAN ONLY — no execution, no branch, cost-free by default). | `app/Console/Commands/AtlasObraPlanCommand.php` |
| `atlas:obra:replay` | Replay an Obra event sequence deterministically (AP-705). | `app/Console/Commands/AtlasObraReplayCommand.php` |
| `atlas:obra:run` | AOBG N3.F2: execute a planned obra onto ONE accumulating branch (Atlas delivers, you merge). Spends per node; halts on a failed step. | `app/Console/Commands/AtlasObraRunCommand.php` |
| `atlas:obra:status` | AOBG N3.F4: inspect a commissioned obra — the plan-DAG, per-step status, certification + the one branch (READ ONLY, cost-free). | `app/Console/Commands/AtlasObraStatusCommand.php` |
| `atlas:obra:work-order` | Generate + validate a Kit-conformant work-order from a slice descriptor (K2). | `app/Console/Commands/AtlasObraWorkOrderCommand.php` |
| `atlas:open-brain:context` | Export an audited Atlas Open Brain context pack for local tools and providers. | `app/Console/Commands/AtlasOpenBrainContextCommand.php` |
| `atlas:open-brain:expand-context` | Expand one Open Brain context handle on demand. Read-only, provider-safe, local-only, zero provider spend. | `app/Console/Commands/AtlasOpenBrainExpandContextCommand.php` |
| `atlas:open-brain:mcp` | Serve Atlas Open Brain tools over local MCP stdio. | `app/Console/Commands/AtlasOpenBrainMcpCommand.php` |
| `atlas:open-brain:surface-review` | Review Open Brain MCP tool surface against primary tools and usage telemetry. | `app/Console/Commands/AtlasOpenBrainSurfaceReviewCommand.php` |
| `atlas:open-brain:tool-usage` | Aggregate Open Brain MCP per-tool usage from ai_telemetry_events. | `app/Console/Commands/AtlasOpenBrainToolUsageCommand.php` |
| `atlas:operator-approval-history` | MULTN15-02 read-only ask-vs-act approval history by action class and risk. | `app/Console/Commands/AtlasOperatorApprovalHistoryCommand.php` |
| `atlas:operator-learning` | Operator Intelligence Layer: capture, review, promote, inspect, digest and project operator learning. | `app/Console/Commands/AtlasOperatorLearningCommand.php` |
| `atlas:operator-profile` | Compose provider-safe Operator Intelligence context from active profile items. | `app/Console/Commands/AtlasOperatorProfileContextCommand.php` |
| `atlas:organism:actuate` | AOBG N4.F4: actuate a domain proposal through the hardened propose-only gate — RECORDS + returns requires_operator + writes an audit receipt. NEVER executes ... | `app/Console/Commands/AtlasOrganismActuateCommand.php` |
| `atlas:organism:commission` | AOBG N4.F3: commission a cross-domain mission — decompose an intent, route nodes to domains, propose+validate per domain (PROPOSE-ONLY, requires_operator, co... | `app/Console/Commands/AtlasOrganismCommissionCommand.php` |
| `atlas:organism:status` | AOBG N4.F3/F4: inspect a cross-domain mission OR the actuation audit — routed domains, per-domain honest validation, ARPTL vetoes, the requires_operator gate... | `app/Console/Commands/AtlasOrganismStatusCommand.php` |
| `atlas:patamar4:activate-flags` | Activate the Patamar 4 production flags (F2 resolver + F6 parallel + A4 failover) with audit receipt. | `app/Console/Commands/AtlasPatamar4ActivateFlagsCommand.php` |
| `atlas:patamar4:run-loop-once` | Patamar 4 — fire one full loop iteration end-to-end (Reconciliation + TEOS-I4 + Swarm + TDC), returning all artifacts produced. | `app/Console/Commands/AtlasPatamar4RunLoopOnceCommand.php` |
| `atlas:patamar4:self-construct-f4-gaps` | Propose ASCB scaffolds for the 2 F4 probe gaps (cache pool + AGRN stale fraction). | `app/Console/Commands/AtlasPatamar4SelfConstructF4GapsCommand.php` |
| `atlas:patamar4:status` | Atlas Patamar 4 — read-only operator view of the live autonomous loop (kernel · admission · CFA · reconciliation · TEOS-I4 · swarm · TDC + integration layer). | `app/Console/Commands/AtlasPatamar4StatusCommand.php` |
| `atlas:pattern` | Inspect, author, match and apply Atlas Cognitive Process Patterns. | `app/Console/Commands/AtlasPatternCommand.php` |
| `atlas:persistent-context` | Build and audit Atlas Persistent Context Runtime packs. | `app/Console/Commands/AtlasPersistentContextRuntimeCommand.php` |
| `atlas:persistent-context:certify` | Certify Atlas Persistent Context Runtime local wiring. No providers, rivals or benchmarks. | `app/Console/Commands/AtlasPersistentContextCertifyCommand.php` |
| `atlas:plan-execution:certify` | Atlas Plan Execution (Pilar 1) · certify PLAN-scope delivery (operational gate + tracker + real-cycle cert). No provider, no merge. | `app/Console/Commands/AtlasPlanExecutionCertifyCommand.php` |
| `atlas:plan-execution:decompose` | Atlas Plan Execution (Pilar 1) · decompose a build-plan doc into an executable decomposed plan. No provider, no merge, no deploy, no secrets. | `app/Console/Commands/AtlasPlanExecutionDecomposeCommand.php` |
| `atlas:plan-execution:e2e` | Atlas Plan Execution (Pilar 1) · full E2E pass decompose->track->certify. Real-or-blocked; no provider, no merge, no deploy, no secrets. | `app/Console/Commands/AtlasPlanExecutionE2eCommand.php` |
| `atlas:plan-execution:run` | Atlas Plan Execution (Pilar 1) · drive a decomposed build-plan to honest completion via the loop owner flow. Real-or-blocked; --simulate proves wiring only. | `app/Console/Commands/AtlasPlanExecutionRunCommand.php` |
| `atlas:plan-execution:track` | Atlas Plan Execution (Pilar 1) · project the JSONL completion ledger for a plan/area. Read-only; no provider, no merge. | `app/Console/Commands/AtlasPlanExecutionTrackCommand.php` |
| `atlas:playbook` | Define / retrieve / apply a general procedural playbook and record its proven-real follow outcome by task category (ATLAS BUILD #3). | `app/Console/Commands/AtlasProceduralPlaybookCommand.php` |
| `atlas:power-helper` | Privileged helper for macOS power operations that require root, such as pmset wake scheduling. | `app/Console/Commands/AtlasPowerHelperCommand.php` |
| `atlas:predict` | Run governed cognitive predictive failure insertion and calibration reports. | `app/Console/Commands/AtlasPredictCommand.php` |
| `atlas:pregate` | P1 · ≤3s pre-gate over the given paths: php -l + pint --test + phpstan (Obra #19). | `app/Console/Commands/AtlasPregateCommand.php` |
| `atlas:pressure:guard` | Run a Cognitive Pressure Layer advisory guard; exit non-zero blocks the land; --record feeds the proof-gated outcome ledger. | `app/Console/Commands/AtlasPressureGuardCommand.php` |
| `atlas:pressure:preland` | Pre-land Cognitive Pressure Layer seam: ground the in-progress diff\'s referenced symbols (rich signal) so boundary + cartographer produce PROVEN verdicts be... | `app/Console/Commands/AtlasPressurePreLandCommand.php` |
| `atlas:pressure:status` | Report accumulated Cognitive Pressure Layer guard verdicts (cadence) from the outcome ledger — the signal Goal 4 reads. | `app/Console/Commands/AtlasPressureStatusCommand.php` |
| `atlas:product-delivery:certify` | Certifies AEDPDS/APTC/APDR/APFPR product delivery runtime without invoking providers. | `app/Console/Commands/Ai/Product/AtlasProductDeliveryCertifyCommand.php` |
| `atlas:product-delivery:control-plane` | Aggregates AEDPDS delivery, risk, replay, doctrine fitness, receipts, and certification without providers or writes. | `app/Console/Commands/Ai/Product/AtlasProductDeliveryControlPlaneCommand.php` |
| `atlas:product-delivery:doctrine-fitness` | Evaluates AEDPDS doctrine fitness from persisted outcome memory without providers or writes. | `app/Console/Commands/Ai/Product/AtlasProductDeliveryDoctrineFitnessCommand.php` |
| `atlas:product-delivery:outcome` | Build or persist AEDPDS product delivery outcome memory. | `app/Console/Commands/Ai/Product/AtlasProductDeliveryOutcomeCommand.php` |
| `atlas:product-delivery:patch-request` | Builds a provider-safe AEDPDS patch request contract without invoking providers or writing files. | `app/Console/Commands/Ai/Product/AtlasProductDeliveryPatchRequestCommand.php` |
| `atlas:product-delivery:plan` | Plans a provider-free AEDPDS/APDR delivery envelope from a human request. | `app/Console/Commands/Ai/Product/AtlasProductDeliveryPlanCommand.php` |
| `atlas:product-delivery:policy-optimizer` | Proposes guarded AEDPDS policy improvements from replay, doctrine fitness, and provider memory without applying policy. | `app/Console/Commands/Ai/Product/AtlasProductDeliveryPolicyOptimizerCommand.php` |
| `atlas:product-delivery:primitives` | Builds the AEDPDS product execution primitive envelope: intent, twin, outcome, cartography, gate, and provider strategy. | `app/Console/Commands/Ai/Product/AtlasProductExecutionPrimitivesCommand.php` |
| `atlas:product-delivery:provider-memory` | Aggregates AEDPDS provider, cost, and flake memory for Risk Governor without invoking providers. | `app/Console/Commands/Ai/Product/AtlasProductDeliveryProviderMemoryCommand.php` |
| `atlas:product-delivery:release-gate` | Decides whether AEDPDS can create a release candidate from Control Plane, Risk, Replay, Fitness, Receipts, and Certification. | `app/Console/Commands/Ai/Product/AtlasProductReleaseGateCommand.php` |
| `atlas:product-delivery:repair-execute` | Executes an explicit AEDPDS mutative repair patch with allowlist, rollback snapshot, and proof rerun. | `app/Console/Commands/Ai/Product/AtlasProductDeliveryRepairExecuteCommand.php` |
| `atlas:product-delivery:repair-plan` | Plans a provider-free AEDPDS multi-step repair plan with budgets, rollback gates, and stop conditions. | `app/Console/Commands/Ai/Product/AtlasProductDeliveryRepairPlanCommand.php` |
| `atlas:product-delivery:replay-lab` | Replays canonical AEDPDS delivery scenarios and runtime receipts without providers or writes. | `app/Console/Commands/Ai/Product/AtlasProductDeliveryReplayLabCommand.php` |
| `atlas:product-delivery:risk-govern` | Evaluates AEDPDS delivery risk, approvals, gates, and autonomy budget without providers or writes. | `app/Console/Commands/Ai/Product/AtlasProductDeliveryRiskGovernorCommand.php` |
| `atlas:product-proof:challenge` | Runs APFPR proof challenge against a provider-free delivery plan. | `app/Console/Commands/Ai/Product/AtlasProductProofChallengeCommand.php` |
| `atlas:product-truth:compile` | Compiles a human product request into a Product Truth Contract without invoking providers. | `app/Console/Commands/Ai/Product/AtlasProductTruthCompileCommand.php` |
| `atlas:product-twin:simulate` | Simulates AEDPDS product delivery impact before execution without provider calls or writes. | `app/Console/Commands/Ai/Product/AtlasProductTwinSimulateCommand.php` |
| `atlas:productive-failure` | Run governed Cognitive Productive Failure sessions for calibrated prediction-error learning. | `app/Console/Commands/AtlasProductiveFailureCommand.php` |
| `atlas:programming:adaptive-control-plane` | Evaluate AHCL v2/v3/v4/v5 adaptive control for a programming work item. | `app/Console/Commands/AtlasProgrammingAdaptiveControlPlaneCommand.php` |
| `atlas:programming:benchmark-readiness` | Atlas Programming Benchmark Readiness Harness — prepare suite/manifest/rubric only. NEVER executes benchmark, NEVER calls rival providers. `run` is intention... | `app/Console/Commands/AtlasProgrammingBenchmarkReadinessCommand.php` |
| `atlas:programming:cartography` | Atlas Programming Cartography Publisher — read-only graph builder over work items + specs + tasks + evidence + drift reports. | `app/Console/Commands/AtlasProgrammingCartographyCommand.php` |
| `atlas:programming:complete` | Register a review decision and run the completion gate. | `app/Console/Commands/AtlasProgrammingCompleteCommand.php` |
| `atlas:programming:completion-audit` | Audit whether the professional programming implementation can be marked complete. | `app/Console/Commands/AtlasProgrammingCompletionAuditCommand.php` |
| `atlas:programming:console` | Atlas Programming Console — operate Dev/Forge runtime via a unified canonical JSON envelope. Read-only and safe-write actions only. Never invokes a provider,... | `app/Console/Commands/AtlasProgrammingConsoleCommand.php` |
| `atlas:programming:dev-forge-flow-certify` | Certify Atlas Dev/Forge are wired to the strongest local programming flow. No providers, rivals or benchmarks. | `app/Console/Commands/AtlasProgrammingDevForgeFlowCertifyCommand.php` |
| `atlas:programming:final-certify` | Internal final certification of the Atlas Programming Runtime. NEVER runs an external rivals/benchmark battery. | `app/Console/Commands/AtlasProgrammingFinalCertifyCommand.php` |
| `atlas:programming:hierarchical-control` | Evaluate the H/L control state and halt decision for a programming work item. | `app/Console/Commands/AtlasProgrammingHierarchicalControlCommand.php` |
| `atlas:programming:intake` | Receive a programming intent and create a governed work item. | `app/Console/Commands/AtlasProgrammingIntakeCommand.php` |
| `atlas:programming:patch-verifier-benchmark` | Run the governed Programming Patch Verifier grounded-patch golden-set benchmark. | `app/Console/Commands/AtlasProgrammingPatchVerifierBenchmarkCommand.php` |
| `atlas:programming:plan` | Attach plan and task contracts to a programming work item. | `app/Console/Commands/AtlasProgrammingPlanCommand.php` |
| `atlas:programming:pre-benchmark-readiness` | Final read-only pre-benchmark readiness gate. Does not run providers, rivals or benchmarks. | `app/Console/Commands/AtlasProgrammingPreBenchmarkReadinessCommand.php` |
| `atlas:programming:receipt` | Append a verifiable evidence receipt to a programming work item. | `app/Console/Commands/AtlasProgrammingReceiptCommand.php` |
| `atlas:programming:repair-loop-benchmark` | Run the governed Programming Repair Loop golden-set benchmark. | `app/Console/Commands/AtlasProgrammingRepairLoopBenchmarkCommand.php` |
| `atlas:programming:resume` | Build a programming resume state from persisted stage receipts. | `app/Console/Commands/AtlasProgrammingResumeCommand.php` |
| `atlas:programming:retrieval-benchmark` | Run the governed Programming Agentic RAG retrieval golden-set benchmark. | `app/Console/Commands/AtlasProgrammingRetrievalBenchmarkCommand.php` |
| `atlas:programming:spec` | Attach a canonical spec to a programming work item. | `app/Console/Commands/AtlasProgrammingSpecCommand.php` |
| `atlas:programming:spec-compile` | Synthesize a draft programming spec from intake + placement + code intelligence. | `app/Console/Commands/AtlasProgrammingSpecCompileCommand.php` |
| `atlas:programming:status` | Print the full timeline of a programming work item. | `app/Console/Commands/AtlasProgrammingStatusCommand.php` |
| `atlas:programming:test-impact-benchmark` | Run the governed Programming Test Impact Analysis golden-set benchmark. | `app/Console/Commands/AtlasProgrammingTestImpactBenchmarkCommand.php` |
| `atlas:programming:verify` | Run governance gates against a programming work item. | `app/Console/Commands/AtlasProgrammingVerifyCommand.php` |
| `atlas:project:blueprint:create` | Create a persisted draft project-level engineering blueprint. | `app/Console/Commands/AtlasProjectBlueprintCreateCommand.php` |
| `atlas:project:blueprint:freeze` | Freeze a project-level engineering blueprint after coverage validation. | `app/Console/Commands/AtlasProjectBlueprintFreezeCommand.php` |
| `atlas:project:blueprint:prepare` | Prepare a project-level engineering blueprint draft without persisting it. | `app/Console/Commands/AtlasProjectBlueprintPrepareCommand.php` |
| `atlas:project:blueprint:validate` | Validate project-level engineering blueprint coverage. | `app/Console/Commands/AtlasProjectBlueprintValidateCommand.php` |
| `atlas:project:tasks:generate` | Generate engineering tasks from a frozen project blueprint. | `app/Console/Commands/AtlasProjectTasksGenerateCommand.php` |
| `atlas:promotions` | ELEV-26s - list ACOS Max promotion states or record a governed promotion flip. | `app/Console/Commands/AtlasPromotionsCommand.php` |
| `atlas:proof:status` | Proof Loop · contador de fake-green (Dev+Forge+Product OutcomeMemory), medição real | `app/Console/Commands/AtlasProofStatusCommand.php` |
| `atlas:proposal` | Cria uma proposal segura no Inbox operacional, sem commit ou merge automatico. | `app/Console/Commands/AtlasProposalCommand.php` |
| `atlas:proposal:scan` | Varre repo/workspace e transforma achados de auto-improvement em proposals seguras no Mobile Inbox. | `app/Console/Commands/AtlasProposalScanCommand.php` |
| `atlas:provider:coverage` | Report the real provider-governance bypass rate (covered vs bypass executions). | `app/Console/Commands/AtlasProviderCoverageCommand.php` |
| `atlas:qa` | Record rich manual QA evidence for an Atlas task. | `app/Console/Commands/AtlasQaCommand.php` |
| `atlas:rebalance` | Atlas Subsystem Auto-Rebalance — cache/AGRN/AEMOR/MCP pool rebalance plans + Trust Budget gated apply. | `app/Console/Commands/AtlasAutoRebalanceCommand.php` |
| `atlas:reconciliation` | Atlas Autonomous Reconciliation Runtime — fire ticks, inspect summary/history. Each tick chains: CFA → Admission → AURG-4D → ASCB. | `app/Console/Commands/AtlasReconciliationCommand.php` |
| `atlas:refactor:bench` | Measure a command N times (median wall time) — the before/after evidence an optimize task owes. | `app/Console/Commands/AtlasRefactorBenchCommand.php` |
| `atlas:repair:playbook` | Show the learned repair playbook (resolution rate) for a failure domain (T4-S3). | `app/Console/Commands/AtlasRepairPlaybookCommand.php` |
| `atlas:review:codex-chain-contract` | Atlas self-construction · codex review chain contract — read-only review/signature/merge-action chain that approves, signs and merges nothing. [was atlas:aae... | `app/Console/Commands/AtlasCodexReviewChainContractCommand.php` |
| `atlas:review:deep` | Run a deep engineering review and emit a structured review packet. | `app/Console/Commands/AtlasReviewDeepCommand.php` |
| `atlas:rivals` | Rivals 2.0 — benchmark interno Atlas (model-vs-model + Atlas uplift), fail-closed | `app/Console/Commands/AtlasRivalsCommand.php` |
| `atlas:rivals2` | Alias of `atlas:rivals`. Rivals 2.0 — benchmark interno Atlas (model-vs-model + Atlas uplift), fail-closed | `app/Console/Commands/AtlasRivalsCommand.php` |
| `atlas:rize:inspect` | Inspect the authenticated Rize GraphQL query fields. | `app/Console/Commands/RizeInspectCommand.php` |
| `atlas:rize:sync` | Import Rize digital activity sessions through the Rize GraphQL API. | `app/Console/Commands/RizeSyncCommand.php` |
| `atlas:rollback:cascade` | ASI-11 cascade rollback executor: revert the closure of a decision_id (4 formal states). | `app/Console/Commands/AtlasRollbackCascadeCommand.php` |
| `atlas:rsi:component-value-per-token` | Replay the ComponentValueLedger and print a component value-per-token as {"metric": n} (RSI measure command). | `app/Console/Commands/AtlasRsiComponentValuePerTokenCommand.php` |
| `atlas:runtime` | Run Atlas native tool runtime with permissions, checkpoints, diffs and workspace profiling. | `app/Console/Commands/AtlasRuntimeCommand.php` |
| `atlas:runtime-efficiency` | Operate AREG, the Atlas Runtime Efficiency Governor. No providers, no external execution. | `app/Console/Commands/AtlasRuntimeEfficiencyGovernorCommand.php` |
| `atlas:runtime-efficiency:certify` | Certify AREG local runtime. No providers, no benchmarks, no external execution. | `app/Console/Commands/AtlasRuntimeEfficiencyGovernorCertifyCommand.php` |
| `atlas:scaffold:promote` | Atlas Self-Construction Scaffold Promotion — emit dry-run plan to promote staged scaffold to source tree (operator copies manually). | `app/Console/Commands/AtlasScaffoldPromoteCommand.php` |
| `atlas:scaffold:stage` | Atlas Self-Construction Scaffold Staging Executor — promote an APPROVED proposal into the staging directory (operator promotes from staging to source by hand). | `app/Console/Commands/AtlasScaffoldStageCommand.php` |
| `atlas:scheduler:ensure-launchd` | Verify launchd agent is healthy; reinstall if missing or failing (self-healing). | `app/Console/Commands/AtlasSchedulerEnsureLaunchdCommand.php` |
| `atlas:scheduler:heartbeat` | Record a scheduler heartbeat tick (Patamar 4 Cron OS probe). | `app/Console/Commands/AtlasSchedulerHeartbeatCommand.php` |
| `atlas:scheduler:install-launchd` | Install or remove the Atlas scheduler launchd agent (Mac local cron OS). | `app/Console/Commands/AtlasSchedulerInstallLaunchdCommand.php` |
| `atlas:scheduler:install-watchdog` | Install or remove the external Atlas scheduler watchdog launchd agent (EVI-01). | `app/Console/Commands/AtlasSchedulerInstallWatchdogCommand.php` |
| `atlas:scheduler:status` | Show Atlas scheduler health (silent_alarm probe). | `app/Console/Commands/AtlasSchedulerStatusCommand.php` |
| `atlas:scheduler:tick` | Claim due Atlas scheduled tasks and dispatch their execution jobs. | `app/Console/Commands/AtlasSchedulerTickCommand.php` |
| `atlas:sdd:run` | Run the full Atlas SDD pipeline (intent → spec → plan → tasks → receipt → execute → drift → learning). | `app/Console/Commands/AtlasSddRunCommand.php` |
| `atlas:self-construct` | Self-construction: Atlas detects + delivers its own improvements as branches (you merge). | `app/Console/Commands/AtlasSelfConstructCommand.php` |
| `atlas:self-construction:approve-proposal` | Atlas Self-Construction · approve or reject a proposal (append-only receipt). | `app/Console/Commands/AtlasSelfConstructionApproveProposalCommand.php` |
| `atlas:self-construction:architecture-council` | Read-only Architecture Council surface: inspect / critic / invariants / boundaries / slices. | `app/Console/Commands/AtlasSelfConstructionArchitectureCouncilCommand.php` |
| `atlas:self-construction:atlas-native-completion` | Atlas-native completion CLI: verify \| gate \| dossier. | `app/Console/Commands/AtlasSelfConstructionAtlasNativeCompletionCommand.php` |
| `atlas:self-construction:autonomy-level` | Atlas Self-Construction autonomy ladder CLI: levels, promote, degrade, cycle, history. | `app/Console/Commands/AtlasSelfConstructionAutonomyLevelCommand.php` |
| `atlas:self-construction:autonomy-stop-go` | Read-only autonomy stop/go decision (self_heal\|replenish\|consolidate\|call_muscles\|create\|pause), fail-closed on queue injury or worker-feed starvation. | `app/Console/Commands/AtlasSelfConstructionAutonomyStopGoCommand.php` |
| `atlas:self-construction:autopoiesis` | Read-only Autopoiesis surface: hypothesize / generate / design / gate / interpret. | `app/Console/Commands/AtlasSelfConstructionAutopoiesisCommand.php` |
| `atlas:self-construction:closure-execution-pack` | Build the human completion receipt closure execution pack envelope. | `app/Console/Commands/AtlasSelfConstructionClosureExecutionPackCommand.php` |
| `atlas:self-construction:completion-autonomy` | Read-only final-autonomy completion CLI: audit \| transition-map \| policy \| verdict \| code-index-readiness. | `app/Console/Commands/AtlasSelfConstructionCompletionAutonomyCommand.php` |
| `atlas:self-construction:compounding` | Read-only Self-Construction compounding CLI: outcomes \| velocity \| leverage \| frontier. | `app/Console/Commands/AtlasSelfConstructionCompoundingCommand.php` |
| `atlas:self-construction:continuous-runtime` | Read-only continuous Self-Construction runtime CLI. | `app/Console/Commands/AtlasSelfConstructionContinuousRuntimeCommand.php` |
| `atlas:self-construction:control-plane` | Read-only Control-Plane CLI: inspect \| mode \| scope \| scope-gate \| next. | `app/Console/Commands/AtlasSelfConstructionControlPlaneCommand.php` |
| `atlas:self-construction:cortex` | Read-only Self-Construction Cortex surface: inventory / freshness / risk / snapshot. | `app/Console/Commands/AtlasSelfConstructionCortexCommand.php` |
| `atlas:self-construction:detect-gaps` | Atlas Self-Construction · detect gaps in the live ACOS scorecard (read-only). | `app/Console/Commands/AtlasSelfConstructionDetectGapsCommand.php` |
| `atlas:self-construction:final-completion-gate` | Build the self-construction final completion human-gate endgame envelope. | `app/Console/Commands/AtlasSelfConstructionFinalCompletionGateCommand.php` |
| `atlas:self-construction:final-smoke` | Read-only final-architecture smoke CLI: cycle \| coverage \| stewardship \| queue-repair \| smoke. | `app/Console/Commands/AtlasSelfConstructionFinalArchitectureSmokeCommand.php` |
| `atlas:self-construction:goal-value` | Read-only goal-value CLI: contract \| anti-proxy \| evidence \| decide \| outcome-evidence. | `app/Console/Commands/AtlasSelfConstructionGoalValueCommand.php` |
| `atlas:self-construction:knowledge-sync` | Read-only Knowledge Sync surface: inspect / artifacts / gate / plan. | `app/Console/Commands/AtlasSelfConstructionKnowledgeSyncCommand.php` |
| `atlas:self-construction:learning-transfer` | Read-only Learning Transfer surface: classify / gate / plan / template. | `app/Console/Commands/AtlasSelfConstructionLearningTransferCommand.php` |
| `atlas:self-construction:list-proposals` | Atlas Self-Construction · list subsystem builder proposals and approval receipts. | `app/Console/Commands/AtlasSelfConstructionListProposalsCommand.php` |
| `atlas:self-construction:merge-governor` | Read-only Merge Governor surface: inspect / decide (dry-run) / history. | `app/Console/Commands/AtlasSelfConstructionMergeGovernorCommand.php` |
| `atlas:self-construction:native-implementation` | Read-only native-implementation CLI: templates \| plan \| materialize \| repair \| patch-plan. | `app/Console/Commands/AtlasSelfConstructionNativeImplementationCommand.php` |
| `atlas:self-construction:native-implementation-release` | Native implementation release surface: preflight / apply / verify / rollback. | `app/Console/Commands/AtlasSelfConstructionNativeImplementationReleaseCommand.php` |
| `atlas:self-construction:native-replenisher` | Read-only native-replenisher surface: contract / draft / preflight / top-up / run. | `app/Console/Commands/AtlasSelfConstructionNativeReplenisherCommand.php` |
| `atlas:self-construction:operator-interface` | Read-only Operator Interface CLI: snapshot \| visibility \| emergency \| dependency-gate. | `app/Console/Commands/AtlasSelfConstructionOperatorInterfaceCommand.php` |
| `atlas:self-construction:promote` | Govern-promote a staged self-construction scaffold to a new branch (flag + operator approval; never merges to main). | `app/Console/Commands/AtlasSelfConstructionPromoteCommand.php` |
| `atlas:self-construction:propose-subsystem` | Atlas Self-Construction · propose a new subsystem (Doctor 3-Tier; never auto-merges code). | `app/Console/Commands/AtlasSelfConstructionProposeSubsystemCommand.php` |
| `atlas:self-construction:receipts` | Read-only Self-Construction receipts CLI: index \| bind \| reality \| export-plan. | `app/Console/Commands/AtlasSelfConstructionReceiptsCommand.php` |
| `atlas:self-construction:runtime` | Atlas-native autonomous runtime CLI (read-only / operator-visible). | `app/Console/Commands/AtlasSelfConstructionAutonomousRuntimeCommand.php` |
| `atlas:self-construction:runtime-daemon` | Atlas-native runtime daemon control: status \| plan \| claim \| tick \| run-once \| pause \| resume \| stop. | `app/Console/Commands/AtlasSelfConstructionRuntimeDaemonCommand.php` |
| `atlas:self-construction:runtime-promotion-endgame` | Build the self-construction runtime promotion endgame envelope. | `app/Console/Commands/AtlasSelfConstructionRuntimePromotionEndgameCommand.php` |
| `atlas:self-construction:runtime-soak` | Virtual runtime soak CLI: scenario \| dry-run \| run \| audit. | `app/Console/Commands/AtlasSelfConstructionRuntimeSoakCommand.php` |
| `atlas:self-construction:strategy-council` | Read-only Strategy Council surface: inspect / filter / rank / ambition / history. | `app/Console/Commands/AtlasSelfConstructionStrategyCouncilCommand.php` |
| `atlas:self-construction:tool-gap-bridge` | L5-4 · Detect recurrent capability gaps from the Loop loss-observer and route them into the governed self-construction corridor (parked, human approval requi... | `app/Console/Commands/AtlasSelfConstructionToolGapBridgeCommand.php` |
| `atlas:self-construction:verification-court` | Read-only Verification Court surface: inspect / plan / verdict / history. | `app/Console/Commands/AtlasSelfConstructionVerificationCourtCommand.php` |
| `atlas:self-construction:workers` | Read-only Worker Swarm surface: capability / match / envelope / normalize. | `app/Console/Commands/AtlasSelfConstructionWorkersCommand.php` |
| `atlas:self-diagnostic` | Avalia desempenho recente do Atlas e emite self_diagnostic quando ha regressao confirmada. | `app/Console/Commands/AtlasSelfDiagnosticCommand.php` |
| `atlas:self-directed-evolution` | Atlas Self-Directed Evolution · read-only gap read model, operator curation inbox and proposal-only spec drafts (no writes, no provider, no auto-approval). | `app/Console/Commands/AtlasSelfDirectedEvolutionCommand.php` |
| `atlas:self-improvement:activate-forge` | Atlas Self-Improvement → Forge Activation v1 (read+governed). Never calls provider; never auto-executes Fast Path. | `app/Console/Commands/AtlasSelfImprovementActivateForgeCommand.php` |
| `atlas:self-improvement:activation-cockpit` | Atlas Self-Improvement Activation Cockpit v1 (read-only). Lists activations, summarises power gate and surfaces next safe action for the operator. | `app/Console/Commands/AtlasSelfImprovementActivationCockpitCommand.php` |
| `atlas:self-improvement:before-after` | Atlas Self-Improvement Before/After Delta Scorecard (13 metrics, weights total 100). Read-model. | `app/Console/Commands/AtlasSelfImprovementBeforeAfterCommand.php` |
| `atlas:self-improvement:closed-loop` | Atlas Self-Improvement Closed Loop v1 (Level 7) — read-only projection of proposal lifecycle. | `app/Console/Commands/AtlasSelfImprovementClosedLoopCommand.php` |
| `atlas:self-improvement:invariant-lock` | Atlas Self-Improvement Invariant Lock — protects sacred rules. Read-model. | `app/Console/Commands/AtlasSelfImprovementInvariantLockCommand.php` |
| `atlas:self-improvement:maturity-score` | Atlas Self-Improvement Capability Maturity Score (0..10 ladder). Read-model. | `app/Console/Commands/AtlasSelfImprovementMaturityScoreCommand.php` |
| `atlas:self-improvement:measure-result` | Atlas Self-Improvement Measure Result v1 (Level 7) — records before/after delta + learning packet. | `app/Console/Commands/AtlasSelfImprovementMeasureResultCommand.php` |
| `atlas:self-improvement:next-cycle` | Atlas Self-Improvement Next Cycle Recommendation v1 (Level 7). | `app/Console/Commands/AtlasSelfImprovementNextCycleCommand.php` |
| `atlas:self-improvement:proposal-backlog` | Atlas Self-Improvement Proposal Backlog v1 (Level 7). Manage proposals before they become activations. | `app/Console/Commands/AtlasSelfImprovementProposalBacklogCommand.php` |
| `atlas:self-improvement:proposal-gate` | Atlas Self-Improvement Proposal Power Gate (read-model). Never calls provider; never promotes Forge. | `app/Console/Commands/AtlasSelfImprovementProposalGateCommand.php` |
| `atlas:self-improvement:regression-sentinel` | Atlas Self-Improvement Regression Sentinel — finds hidden regressions. Read-model. | `app/Console/Commands/AtlasSelfImprovementRegressionSentinelCommand.php` |
| `atlas:self-improvement:trust-ledger` | Atlas Self-Improvement Human Trust Ledger (read + record). Read-model. | `app/Console/Commands/AtlasSelfImprovementTrustLedgerCommand.php` |
| `atlas:semantic:activate` | Create contextual semantic memory activations. | `app/Console/Commands/SemanticActivateCommand.php` |
| `atlas:semantic:bootstrap` | Create the Atlas semantic memory vault structure and templates. | `app/Console/Commands/SemanticBootstrapVaultCommand.php` |
| `atlas:semantic:curation-review` | Review a semantic curation proposal and optionally promote it into Memory/Open Brain with receipts. | `app/Console/Commands/SemanticCurationReviewCommand.php` |
| `atlas:semantic:embedding-info` | Show the last embedding info from the semantic embedding foundation. | `app/Console/Commands/AtlasSemanticEmbeddingInfoCommand.php` |
| `atlas:semantic:govern` | Compute the semantic memory vault health snapshot. | `app/Console/Commands/SemanticGovernCommand.php` |
| `atlas:semantic:index` | Index markdown files from the Atlas semantic memory vault. | `app/Console/Commands/SemanticIndexCommand.php` |
| `atlas:semantic:jina-v3-dual-read` | MAXA-04 read-only/default-off jina-v3 dual-read mechanism report. | `app/Console/Commands/AtlasSemanticJinaV3DualReadCommand.php` |
| `atlas:semantic:propose` | Create semantic memory curation proposals from recent captures. | `app/Console/Commands/SemanticProposeCommand.php` |
| `atlas:skills:evolve` | Propose or refactor Atlas skills from verified outcomes without auto-installing them. | `app/Console/Commands/AtlasSkillEvolutionCommand.php` |
| `atlas:software-company-stewardship` | Atlas Software Company Stewardship Stack · read-only/proposal read-models plus append-only review ledgers. No provider, no branch, no merge/deploy/secrets. | `app/Console/Commands/AtlasSoftwareCompanyStewardshipCommand.php` |
| `atlas:software-company-stewardship:ap786-cycle` | AP-786 · certify/replay whether an autonomous evolution session ran real full-owner-flow cycles, rejecting fake direct-provider or incomplete cycles. | `app/Console/Commands/AtlasAp786RealCycleCertificationCommand.php` |
| `atlas:software-company-stewardship:area-focus-certify` | Atlas Software Company Stewardship Stack · Area Focus Loop structural certification (read-only; checks APs, docs, services, command, schemas, gates, receipts... | `app/Console/Commands/AtlasAreaFocusLoopCertifyCommand.php` |
| `atlas:software-company-stewardship:area-focus-decision` | Atlas Software Company Stewardship · Area Focus operator decision receipt (AP-724): accept/reject/defer/request_changes. No auto-approval, no execution, no r... | `app/Console/Commands/AtlasAreaFocusDecisionCommand.php` |
| `atlas:software-company-stewardship:area-focus-handoff` | Atlas Software Company Stewardship Stack · Area Focus branch sandbox preflight + governed Dev/Forge handoff (read-only; branch metadata only, no creation, no... | `app/Console/Commands/AtlasAreaFocusHandoffCommand.php` |
| `atlas:software-company-stewardship:area-focus-materialize-obra` | Atlas Software Company Stewardship · Materialize a real governed Obra from an operator accept receipt + forge handoff (S2). No fabrication, idempotent, never... | `app/Console/Commands/AtlasAreaFocusForgeMaterializeObraCommand.php` |
| `atlas:software-company-stewardship:autonomous-evolution-session` | AP-786 · run an Atlas-owned autonomous evolution session with Cursor CLI, Inbox, governed ff-only merge and loop continuation. | `app/Console/Commands/AtlasSoftwareCompanyAutonomousEvolutionSessionCommand.php` |
| `atlas:software-company-stewardship:certify-24h-loop` | AP-792 · read-only certification harness for the 24h autonomous loop. test_mode (fixtures) never certifies production; runtime_real certifies only with real ... | `app/Console/Commands/AtlasCertify24hLoopCommand.php` |
| `atlas:software-company-stewardship:first-live-branch-proof` | AP-781 · create a real visible stewardship branch/worktree/commit proof without merging. | `app/Console/Commands/AtlasSoftwareCompanyFirstLiveBranchProofCommand.php` |
| `atlas:software-company-stewardship:integration-lane` | AP-782 · advance a safe visible integration lane without mutating main. | `app/Console/Commands/AtlasSoftwareCompanyIntegrationLaneCommand.php` |
| `atlas:software-company-stewardship:integration-lane-promote` | AP-783 · promote a clean integration lane into the base ref via AP-775 + AP-769 ff-only merge. | `app/Console/Commands/AtlasSoftwareCompanyIntegrationLanePromoteCommand.php` |
| `atlas:software-company-stewardship:l7-l10-queue` | Validate + optionally materialize the governed L7-L10 (S83-S165) queue from the broad backlog doc, without auto-index capture. | `app/Console/Commands/AtlasAaeosL7L10QueueCommand.php` |
| `atlas:software-company-stewardship:live-cycle-audit` | AP-784 · read-only audit of real stewardship git branches, integration lanes and promotion readiness. | `app/Console/Commands/AtlasSoftwareCompanyLiveCycleAuditCommand.php` |
| `atlas:software-company-stewardship:priority-engine` | AP-785 · rank stewardship work by largest advancement and robustness. | `app/Console/Commands/AtlasSoftwareCompanyPriorityEngineCommand.php` |
| `atlas:software-company-stewardship:reliable-24h-loop` | AP-790 · reliable 24h autonomous loop runner wrapping AP-786 (locks, budgets, pause/kill, crash recovery, append-only ledger). | `app/Console/Commands/AtlasSoftwareCompanyReliable24hLoopCommand.php` |
| `atlas:software-twin` | Operate ASTR, the Atlas Software Twin Runtime, as a read-only living system twin. | `app/Console/Commands/AtlasSoftwareTwinCommand.php` |
| `atlas:software-twin-verified-evolution:certify` | Certify ASTR and AVEOR, the Atlas Software Twin & Verified Evolution Runtime. | `app/Console/Commands/AtlasSoftwareTwinVerifiedEvolutionCertifyCommand.php` |
| `atlas:spec:critique` | Deterministic adversarial spec critique — flag refutations + governed decisions a spec touches, before implementation. | `app/Console/Commands/AtlasSpecCritiqueCommand.php` |
| `atlas:srl` | Manage Atlas Self-Regulated Learning overlay episodes and preferences. | `app/Console/Commands/AtlasSRLCommand.php` |
| `atlas:strategic-os` | Operate the Atlas Strategic OS: feedback graph, experiments, organization twin, portfolio and governance evolution. | `app/Console/Commands/AtlasStrategicOperatingSystemCommand.php` |
| `atlas:strategic-reality` | Operate ASRE, the Atlas Strategic Reality Engine. Recommendation-only; no provider or external execution. | `app/Console/Commands/AtlasStrategicRealityCommand.php` |
| `atlas:strategic-reality:certify` | Certify ASRE local runtime. No providers, no benchmarks, no external execution. | `app/Console/Commands/AtlasStrategicRealityCertifyCommand.php` |
| `atlas:strategic-reality:control-plane` | Show ASRE aggregate control plane. Read-only and sanitized. | `app/Console/Commands/AtlasStrategicRealityControlPlaneCommand.php` |
| `atlas:study` | Generate a governed Atlas study packet with Dreyfus Dynamic Pedagogy. | `app/Console/Commands/AtlasStudyCommand.php` |
| `atlas:substrate:restore-drill` | ELEV-17 — restore latest SUB-01 snapshot into a disposable DB and diff against manifest. | `app/Console/Commands/AtlasMemorySubstrateRestoreDrillCommand.php` |
| `atlas:surface:constelacao-positions` | Show the Constelação surface positions read model. | `app/Console/Commands/AtlasConstelacaoPositionsCommand.php` |
| `atlas:swarm` | Atlas Swarm Conductor — multi-arm dispatch composer over ADML + Kernel + Admission. | `app/Console/Commands/AtlasSwarmCommand.php` |
| `atlas:swarm:conduct` | Run one governed, provider-agnostic cross-provider engineering swarm: plan/route -> execute -> (verify) -> governed envelope. | `app/Console/Commands/AtlasSwarmConductCommand.php` |
| `atlas:swarm:execute` | Atlas Swarm Executor — fan-out cross-provider arms, fan-in reconciliation, deterministic tie-break. | `app/Console/Commands/AtlasSwarmExecutorCommand.php` |
| `atlas:swarm:execute-arm` | Execute one swarm arm via the Production Resolver and emit canonical outcome JSON on stdout. | `app/Console/Commands/AtlasSwarmExecuteArmCommand.php` |
| `atlas:swarm:topology-auto-compose` | L6-10 shadow auto-composer: select swarm topology by task type and measure plan convergence. | `app/Console/Commands/AtlasSwarmTopologyAutoComposeCommand.php` |
| `atlas:system-structure` | Read-only derived Atlas system structure (areas -> subsystems -> services/commands) from the live code index or filesystem. | `app/Console/Commands/AtlasSystemStructureCommand.php` |
| `atlas:task` | The Atlas task-serving contract: PULL the next task (next) or hand back a result (report). Platform-free, client_id opaque. | `app/Console/Commands/AtlasTaskCommand.php` |
| `atlas:task:authoring-council` | Run the authoring governance chain (Strategy + Architecture councils) over a comprehension snapshot. | `app/Console/Commands/AtlasTaskAuthoringCouncilCommand.php` |
| `atlas:task:blocked-respec-plan` | Read-only: summarise blocked queue packets and emit ordered replacement drafts. | `app/Console/Commands/AtlasTaskBlockedRespecPlanCommand.php` |
| `atlas:task:enqueue` | Enqueue a real task for AIs to pull (atlas:task next). Quality-gated: an underspecified task is rejected. | `app/Console/Commands/AtlasTaskEnqueueCommand.php` |
| `atlas:task:farm-audit` | Audit the claimable queue for template-farm near-duplicate clusters and optionally block the losers. | `app/Console/Commands/AtlasTaskFarmAuditCommand.php` |
| `atlas:task:governance-dossier` | Read-only governance observe-mode dossier: what enforce mode WOULD have blocked, and the arming recommendation per risk level. | `app/Console/Commands/AtlasTaskGovernanceDossierCommand.php` |
| `atlas:task:health` | Coordination health of the task-serving stack (read-only): queue distribution, leases, recoverable backlog, integrity flags, and ranked interventions. | `app/Console/Commands/AtlasTaskHealthCommand.php` |
| `atlas:task:maestro-health` | Read-only Maestro health lenses (histogram\|predict\|urgency): queue/lease age distributions, worker-idle prediction, replenish urgency. | `app/Console/Commands/AtlasTaskHealthHistogramCommand.php` |
| `atlas:task:maestro-multiprovider` | Operator surface for Maestro MultiProvider: providers \| classify \| assign. | `app/Console/Commands/AtlasTaskMaestroMultiProviderCommand.php` |
| `atlas:task:maestro-projection` | Emit FACT-only Maestro workload projections and history. | `app/Console/Commands/AtlasTaskMaestroProjectionCommand.php` |
| `atlas:task:maestro-semantic-audit` | Run the Maestro semantic v+3 gate over one task packet (exit 0=pass, 1=rejected, 2=missing/invalid). | `app/Console/Commands/AtlasTaskMaestroSemanticAuditCommand.php` |
| `atlas:task:maestro:bid` | Maestro provider-bid CLI: propose \| arbitrate \| history. | `app/Console/Commands/AtlasTaskMaestroBidCommand.php` |
| `atlas:task:maestro:cost` | Maestro cost observability CLI: ledger \| aggregate \| budget \| history. | `app/Console/Commands/AtlasTaskMaestroCostCommand.php` |
| `atlas:task:maestro:decay` | Maestro packet-decay CLI (inspect \| propose \| history) — advisory only. | `app/Console/Commands/AtlasTaskMaestroDecayCommand.php` |
| `atlas:task:maestro:fairness` | Maestro fairness CLI: gini \| alerts \| history. | `app/Console/Commands/AtlasTaskMaestroFairnessCommand.php` |
| `atlas:task:maestro:prefs` | Maestro personalization CLI: register \| inspect \| policy. | `app/Console/Commands/AtlasTaskMaestroPrefsCommand.php` |
| `atlas:task:maestro:priority` | Operator surface for the maestro dynamic-priority loop (snapshot \| reshape \| history). | `app/Console/Commands/AtlasTaskMaestroPriorityCommand.php` |
| `atlas:task:maestro:provenance` | Atlas Maestro packet provenance CLI: trace\|verify\|history. | `app/Console/Commands/AtlasTaskMaestroProvenanceCommand.php` |
| `atlas:task:maestro:provider-perf` | Maestro provider-learning CLI: inspect facts \| recommend (advisory only). | `app/Console/Commands/AtlasTaskMaestroProviderPerfCommand.php` |
| `atlas:task:maestro:retry` | Read-only inspector for the maestro give-back retry loop (policy / receipts / mined evidence). | `app/Console/Commands/AtlasTaskMaestroRetryCommand.php` |
| `atlas:task:maestro:schema` | Maestro packet schema CLI: versions \| migrate \| deprecate. | `app/Console/Commands/AtlasTaskMaestroSchemaCommand.php` |
| `atlas:task:maestro:workers` | Read-only Maestro worker coordination CLI: list \| probe \| checkpoint. | `app/Console/Commands/AtlasTaskMaestroWorkersCommand.php` |
| `atlas:task:project-lane-runtime-plan` | Project-lane runtime plan: inspect / plan / decision (read-only). | `app/Console/Commands/AtlasProjectLaneRuntimePlanCommand.php` |
| `atlas:task:project-lanes` | Read-only inspector for project-lane admission and stewardship health. | `app/Console/Commands/AtlasProjectLaneStewardshipCommand.php` |
| `atlas:task:property-gate-preflight` | Classify task file targets and print the required constitution evidence contract. Read-only. | `app/Console/Commands/AtlasTaskPropertyGatePreflightCommand.php` |
| `atlas:task:quality` | Read-only task quality audit: inspect / respec-plan / bulk-draft / lint. | `app/Console/Commands/AtlasTaskQualityCommand.php` |
| `atlas:task:reindex` | Rebuild the task-serving registry index from disk (recovers claimable tasks dropped by the old cap). | `app/Console/Commands/AtlasTaskReindexCommand.php` |
| `atlas:task:repair-blocked` | Repair blocked task-serving packets into claimable, committable packets when safe. | `app/Console/Commands/AtlasTaskRepairBlockedCommand.php` |
| `atlas:task:replenish` | Atlas structures the task list from its comprehension of a scope and keeps the serving queue full (the runtime that never dries). | `app/Console/Commands/AtlasTaskReplenishCommand.php` |
| `atlas:task:retire` | Retire repeated-give-back quarantined blocked packets (blocked→cancelled). | `app/Console/Commands/AtlasTaskRetireCommand.php` |
| `atlas:task:revert` | Revert the commit a task landed (dry-run plan by default; --live to execute). | `app/Console/Commands/AtlasTaskRevertCommand.php` |
| `atlas:task:review:decide` | Veredito em lote (aprovar/rejeitar) de landings do autônomo no inbox de review. | `app/Console/Commands/AtlasTaskReviewDecideCommand.php` |
| `atlas:task:review:deep` | Generate deterministic review findings for a landed atlas:task commit (scope/lint/pétreo/tests; --semantic adiciona IA governada). | `app/Console/Commands/AtlasTaskLandingDeepReviewCommand.php` |
| `atlas:task:review:publish` | Publish landed atlas:task commits as operator review items in the inbox (approve/reject). | `app/Console/Commands/AtlasTaskLandingReviewPublishCommand.php` |
| `atlas:task:seed-gov-lanes` | Idempotently seed reviewed Autonomous-Government lane task packets into the serving queue. | `app/Console/Commands/AtlasTaskSeedGovLanesCommand.php` |
| `atlas:task:self-heal` | Read-only: scan blocked packets for self-healing respec + repair proposals. | `app/Console/Commands/AtlasTaskQueueSelfHealCommand.php` |
| `atlas:task:servable-heartbeat` | Servable-heartbeat: reads servability and auto-fires reap → sweep → repair when queue is jammed. | `app/Console/Commands/AtlasTaskServableHeartbeatCommand.php` |
| `atlas:task:serving` | Turn the task-serving surface (atlas:task next/report) on/off — independent of the Autônomos master switch. | `app/Console/Commands/AtlasTaskServingSwitchCommand.php` |
| `atlas:task:swarm-proof` | Force REAL N-client concurrency on the task-serving contract and emit the conflict-free X-ray (the proof the operator asked for). | `app/Console/Commands/AtlasTaskSwarmProofCommand.php` |
| `atlas:task:sweep-malformed` | Quarantine claimable task-serving packets that are not self-sufficient before workers pull them. | `app/Console/Commands/AtlasTaskSweepMalformedCommand.php` |
| `atlas:task:worker-prompt` | Print a ready-to-paste prompt that turns any AI session into a self-driving Atlas task worker. | `app/Console/Commands/AtlasTaskWorkerPromptCommand.php` |
| `atlas:temporary-domain` | Atlas Temporary Domain Composition — compose K-domain capsules with TTL composer over ACDM + Kernel + Admission. | `app/Console/Commands/AtlasTemporaryDomainCommand.php` |
| `atlas:teos-i4` | Atlas TEOS-I4 Counterfactual Tree — greedy BFS over TEOS-I3 branches with Kernel + Admission gates. | `app/Console/Commands/AtlasTeosI4Command.php` |
| `atlas:teos:counterfactual:branch` | TEOS-I3 · build a counterfactual branch (Doctor 3-Tier). | `app/Console/Commands/AtlasTeosCounterfactualBranchCommand.php` |
| `atlas:teos:counterfactual:recommend` | TEOS-I3 · best replan recommendation for a scope (read-only). | `app/Console/Commands/AtlasTeosCounterfactualRecommendCommand.php` |
| `atlas:teos:final-certify` | TEOS-I5 final local release certification. Does not run benchmarks or rivals. | `app/Console/Commands/AtlasTeosFinalCertifyCommand.php` |
| `atlas:teos:i2-certify` | TEOS-I2 release certification: replay manifest + causal graph + continuity certification. Read-only; no providers/rivals. | `app/Console/Commands/AtlasTeosIncrement2CertifyCommand.php` |
| `atlas:teos:readiness` | Atlas TEOS readiness/certification audit. NEVER calls a provider; NEVER mutates Atlas Decide topology. | `app/Console/Commands/AtlasTeosReadinessCommand.php` |
| `atlas:teos:runtime-smoke` | Materialize local TEOS runtime smoke data and run final certification. No providers, rivals or benchmarks. | `app/Console/Commands/AtlasTeosRuntimeSmokeCommand.php` |
| `atlas:terminal` | Atlas Terminal Dev — multi-turn agent session with tools, skills, Open Brain (Grok-class surface). | `app/Console/Commands/AtlasTerminalCommand.php` |
| `atlas:terminal:clipboard-image` | Capture current macOS clipboard image for Terminal Dev attachments. | `app/Console/Commands/AtlasTerminalClipboardImageCommand.php` |
| `atlas:terminal:doctor` | Terminal Dev doctor: Hermes, AAP, clipboard, TUI binary, theme/truecolor hints. | `app/Console/Commands/AtlasTerminalDoctorCommand.php` |
| `atlas:terminal:scorecard` | Terminal Dev capability scorecard (Grok parity + Atlas superiority). | `app/Console/Commands/AtlasTerminalScorecardCommand.php` |
| `atlas:test:cached` | P3 · skip a test run when a fresh green receipt matches (hash test,impl); else run + record (Obra #19). | `app/Console/Commands/AtlasTestCachedCommand.php` |
| `atlas:test:impacted` | P2 · changed paths → impacted tests + phpunit command (real world-model edges, declared fallback) (Obra #19). | `app/Console/Commands/AtlasTestImpactedCommand.php` |
| `atlas:teto:n-capture-drill` | TETO-01 N-Capture Drill reader: publish the three drill times + denominators per installed non-routed engine. | `app/Console/Commands/AtlasTetoNCaptureDrillCommand.php` |
| `atlas:tools` | Inspect and operate the Atlas Super Tool Runtime registry, policy and evidence store. | `app/Console/Commands/AtlasToolsCommand.php` |
| `atlas:trust-budget` | Atlas Trust Budget — tiered daily budget for mutative actions with rollback + receipt. | `app/Console/Commands/AtlasTrustBudgetCommand.php` |
| `atlas:universal-reality-cartography` | Read-only AURC visual reality map over ADRS and ACRUI. | `app/Console/Commands/AtlasUniversalRealityCartographyCommand.php` |
| `atlas:valor` | B4 · valor: TPE (turns-até-1ª-edição) + perguntas-ao-operador, medidos de transcripts reais | `app/Console/Commands/AtlasValorCommand.php` |
| `atlas:vault` | Inspect AtlasVault and create or update managed vault notes safely. | `app/Console/Commands/AtlasVaultCommand.php` |
| `atlas:venture` | Atlas Venture Foundry: criação e gestão de empresas — ideação, regras de negócio, métricas e estratégia até o alvo de 100M USD. | `app/Console/Commands/AtlasVentureFoundryCommand.php` |
| `atlas:verified-context-execution` | Operate AVCEL verified context execution loop in read-only shadow/certify mode. | `app/Console/Commands/AtlasVerifiedContextExecutionLoopCommand.php` |
| `atlas:verified-evolution` | Operate AVEOR, the Atlas Verified Evolution Runtime, as the change safety kernel above ASTR. | `app/Console/Commands/AtlasVerifiedEvolutionCommand.php` |
| `atlas:vox:doctor` | Aggregate every Vox read-only health surface (readiness, hardening, metrics, dogfood, gate, certification) into a single backend snapshot. Read-only. | `app/Console/Commands/AtlasVoxDoctorCommand.php` |
| `atlas:vox:dogfood-summary` | Resumo PT-BR do uso real do Atlas Vox (dogfood + métricas). READ-ONLY. | `app/Console/Commands/AtlasVoxDogfoodSummaryCommand.php` |
| `atlas:vox:quality-bench` | Atlas Vox V6 quality bench. Mede auto mode, prompt compiler, restrições, perigos. READ-ONLY. | `app/Console/Commands/AtlasVoxQualityBenchCommand.php` |
| `atlas:vox:v5-certify` | Certify the Atlas Vox V5 Symbiotic Interlocutor for real-world dogfood. Read-only. | `app/Console/Commands/AtlasVoxV5CertifyCommand.php` |
| `atlas:vox:v6-8-certify` | Certify Atlas Vox V6.8 Cognitive Flow Governor. Read-only, local-only. | `app/Console/Commands/AtlasVoxV68CertifyCommand.php` |
| `atlas:vox:v6-certify` | Certify Atlas Vox V6 for dogfood. Read-only. Cobre backend, desktop, macOS, UX e safety. | `app/Console/Commands/AtlasVoxV6CertifyCommand.php` |
| `atlas:watchdog:run` | ACOS WDG-01 — run the unified watchdog check registry. | `app/Console/Commands/AtlasWatchdogRunCommand.php` |
| `atlas:windows` | Read-only ACOS window DAG with critical path, parallel windows, and dead-window watchdog. | `app/Console/Commands/AtlasAcosWindowsCommand.php` |
| `atlas:worked-example` | Deliver, list, show, or author governed Atlas Cognitive worked examples. | `app/Console/Commands/AtlasWorkedExampleCommand.php` |
| `atlas:working-set` | SIS1 — read/write the persisted working memory (survives the process). | `app/Console/Commands/AtlasWorkingSetCommand.php` |
| `atlas:workspace-artifacts` | Exposes AWAIR artifact graph, replay, simulation, shadow execution and AWAOL workrooms without returning the full AWIS envelope. | `app/Console/Commands/AtlasWorkspaceArtifactsCommand.php` |
| `atlas:workspace-intelligence` | Builds and certifies the AWIS family runtime envelope (AWIS/AWTR/ACIOS/AWAF/AWAIR/AWCO/AWEF). | `app/Console/Commands/AtlasWorkspaceIntelligenceCommand.php` |
