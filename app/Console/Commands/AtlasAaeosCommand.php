<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AgenticEngineeringOs\AaeosPhaseHandoffService;
use App\Services\Ai\AgenticEngineeringOs\AtlasAaeosHttpPathFacadeService;
use App\Services\Ai\AgenticEngineeringOs\AtlasMissionControlCockpitService;
use App\Services\Ai\AgenticEngineeringOs\AtlasUniversalGatesEvaluator;
use App\Services\Ai\AgenticEngineeringOs\DepartmentContractRuntime;
use App\Services\Ai\AgenticEngineeringOs\RunbookOrchestrator;
use App\Services\Ai\Support\AiValueNormalizer;
use Illuminate\Console\Command;

/**
 * Atlas Agentic Engineering OS — operator entry point.
 *
 * Implements the canonical AAEOS CLI suite mandated by the runbook doc:
 *
 *   atlas:aaeos:runbook            — show the 17-phase canonical runbook
 *   atlas:aaeos:phase-handoff      — emit and validate an atlas.aaeos.phase.v1 envelope
 *   atlas:aaeos:phase-skip         — record a justified phase skip with receipt
 *   atlas:aaeos:department-status  — show department catalogue + canon-field coverage
 *   atlas:aaeos:cockpit            — render mission-control cockpit snapshot
 *   atlas:aaeos:universal-gates    — evaluate the 15 universal gates from a signals JSON file
 *
 * All sub-commands accept `--json` for machine output. Operator input is
 * limited to identifiers and hashes; raw payloads are rejected by the
 * underlying services (provider-safe by default).
 */
final class AtlasAaeosCommand extends Command
{
    protected $signature = 'atlas:aaeos
        {action : runbook|phase-handoff|phase-skip|department-status|cockpit|universal-gates|http-path-status}
        {--intent= : intent_id used by phase-handoff/cockpit/universal-gates}
        {--phase-in= : phase_in for phase-handoff/phase-skip}
        {--phase-out= : phase_out for phase-handoff}
        {--actor-kind=system : agent|operator|system}
        {--actor-id=aaeos.cli : actor identifier}
        {--evidence=* : sha256:* hashes (repeatable)}
        {--operator-signature= : operator signature for phase-handoff}
        {--autonomy=L1 : L0|L1|L2|L3|L4|L5|L6|L7}
        {--receipt= : receipt_id used by phase-skip}
        {--reason= : human-readable reason for phase-skip}
        {--signals= : JSON file with universal-gate signals}
        {--delivery-pack= : JSON file with delivery-pack composition (derives delivery_pack_completeness_min_0_95)}
        {--spec= : JSON file with compiled-spec shape (observe-only specCompletenessSignal)}
        {--quality-bar= : JSON file with quality-bar telemetry (observe-only M5 contract)}
        {--architect-spec-pack= : JSON file with architect spec-pack gate payload (observe-only M1)}
        {--predicted-impact= : JSON file with predicted-impact candidate (observe-only band classify)}
        {--predicted-impact-calibration= : JSON file with predicted-impact calibration rows (observe-only)}
        {--pre-review= : JSON file with pre-review advisory features (observe-only MULTN15-08)}
        {--reality-compiler-slice= : JSON file with Reality Compiler slice map (observe-only contract)}
        {--esp09-challenger= : JSON file with ESP-09 challenger context (observe-only advisory)}
        {--esp09-promotion-gate= : JSON file with ESP-09 promotion-gate context (observe-only delay)}
        {--esp09-refutation-series= : JSON file with ESP-09 refutation series events (observe-only)}
        {--dogfooding-leads= : JSON file with dogfooding friction events (observe-only mine)}
        {--reactive-saturation= : JSON file with reactive saturation windows (observe-only classify)}
        {--blocker-severity= : JSON file with phase blockers (observe-only severity gate)}
        {--phase-advance= : JSON file with atlas.aaeos.phase.v1 envelope (observe-only verdict)}
        {--required-gate-coverage= : JSON file with required/passed gate lists (observe-only)}
        {--outcome-causality= : JSON file with OutcomeEnvelope map (observe-only causality rank)}
        {--summary-fidelity= : JSON file with required_items + summary_text (observe-only fidelity coverage)}
        {--memory-injection-budget= : JSON file with ranked_items + budget caps (observe-only allocation)}
        {--memory-feedback-decay= : JSON file with memory feedback signals (observe-only decay score)}
        {--segment-importance= : JSON file with segments + token_budget (observe-only ranking)}
        {--context-pareto= : JSON file with variants + objective_direction (observe-only Pareto)}
        {--memory-recall-rank= : JSON file with memory candidate rows (observe-only recall rank)}
        {--portfolio-budget= : JSON file with portfolio allocation input (observe-only MULTK-06)}
        {--ambition-rung= : JSON file with ambition rung candidates + context (observe-only)}
        {--domain-lexical= : JSON file with query + fields (observe-only domain lexical score)}
        {--gated-corpus= : JSON file with corpus sources (observe-only gated candidate mine)}
        {--structured-facts= : JSON file with memory_type + facts (observe-only schema validate)}
        {--citation-grounding= : JSON file with response rows (observe-only citation grounding)}
        {--provenance-weight= : JSON file with evidence_refs + verified_refs (observe-only provenance weight)}
        {--recall-gap= : JSON file with weak-recall events (observe-only recall gap aggregate)}
        {--belief-cascade= : JSON file with origin + graph (observe-only belief cascade plan)}
        {--ledger-rotation= : JSON file with series id (observe-only ledger rotation policy)}
        {--evidence-vision= : JSON file with thesis + optional forbidden (observe-only fence)}
        {--gate-signal-spec-pack= : JSON file with acceptance_criteria (observe-only gate signal)}
        {--gate-signal-intent= : JSON file with intent disambiguation features (observe-only)}
        {--gate-signal-task-pack= : JSON file with tasks (observe-only task-pack atomicity)}
        {--gate-signal-phase= : JSON file with phaseOutputs (observe-only phase gate rollup)}
        {--threshold-ladder= : JSON file with level band ladder (observe-only normalize)}
        {--kb-embedding-coverage= : JSON file (any object) to observe KB embedding coverage ruler}
        {--code-symbol-embedding-coverage= : JSON file (any object) to observe code-symbol coverage}
        {--predicted-revert-digest= : JSON file with review-debt items (observe-only TETO-10 digest)}
        {--jina-dual-read-ledger= : JSON file with optional path (observe-only MAXA-04 ledger)}
        {--resource-budget= : JSON file with optional budget override (observe-only ELEV-27)}
        {--model-capability-spec= : JSON file with function+model (observe-only ELEV-29s)}
        {--measure-series-freshness= : JSON file with registry entry (observe-only ELEV-31)}
        {--verified-share= : JSON file with optional days (observe-only ELEV-12)}
        {--ragx-chain= : JSON file with optional deps (observe-only RAGX stages)}
        {--procedural-skill-promoter= : JSON file with optional floor/enqueue (observe-only MULTJ-04)}
        {--aaeos-phase-router= : JSON file with optional phase override (observe-only HTTP path phase)}
        {--aaeos-quality-bar= : JSON file (any object) to observe AAEOS department quality bar}
        {--aaeos-department-maturity= : JSON file (any object) to observe AAEOS department maturity}
        {--veto-propagation-watchdog= : JSON file with veto events (observe-only choreography)}
        {--repair-loop-guard= : JSON file with current_iteration (observe-only repair guard)}
        {--generated-contract-gate= : JSON file (any object) to observe Generated quarantine gate}
        {--maturity-band-classifier= : JSON file with band ladder + metrics (observe-only)}
        {--promotion-eligibility= : JSON file with department+metrics(+options) (observe-only)}
        {--debug-root-cause= : JSON file with debug context (observe-only root-cause)}
        {--cross-department-choreography= : JSON file with mode+payload (observe-only choreography)}
        {--docs-authority-locate= : JSON file with needle(+limit) (observe-only docs locate)}
        {--department-level-classifier= : JSON file with department+metrics+ladder (observe-only)}
        {--quality-bar-level-classifier= : JSON file with department+metrics+ladder (observe-only)}
        {--implementation-truth-evaluate= : JSON file with claimed_state+resolutions (observe-only)}
        {--phase-handoff-catalogue= : JSON file with optional autonomy_level (observe-only phases)}
        {--golden-counterfactual-replay= : JSON file with optional runs_path (observe-only)}
        {--composed-obra-arc= : JSON file with candidates(+context) (observe-only MULTN17-02)}
        {--exploratory-bets-portfolio= : JSON file with candidates(+context) (observe-only MULTN17-05)}
        {--n-capture-drill= : JSON file with optional days (observe-only N-capture report)}
        {--lote2-counterfactual-lift= : JSON file (any object) to observe MULTJ-03 lift}
        {--string-list-normalize= : JSON file with values list (observe-only string normalize)}
        {--threshold-comparator= : JSON file with comparator+observed+threshold (observe-only)}
        {--evidence-ref-normalize= : JSON file with evidence_refs list (observe-only)}
        {--doc-maturity-classify= : JSON file with sections map (observe-only DOC L0..L4)}
        {--claim-definition-of-done= : JSON file with claim map (observe-only DoD verdict)}
        {--array-field-reader= : JSON file with row+key (observe-only stringField)}
        {--veto-propagation-resolve= : JSON file with origin+veto_kind(+repair_iteration) (observe-only)}
        {--department-registry-validate= : JSON file with department or departments list (observe-only)}
        {--cognitive-immune-classify= : JSON file with text(+metadata) (observe-only immune class)}
        {--department-canonical-list= : JSON file (any object) to observe canonical department ids}
        {--universal-gates-catalogue= : JSON file (any object) to observe the 15-gate catalogue}
        {--outcome-attribution-types= : JSON file (any object) to observe outcome attribution types}
        {--json : Machine-readable JSON output}';

    protected $description = 'Atlas Agentic Engineering OS — operator CLI for the 17-phase runbook.';

    public function handle(
        DepartmentContractRuntime $departments,
        AaeosPhaseHandoffService $phases,
        AtlasUniversalGatesEvaluator $gates,
        RunbookOrchestrator $runbook,
        AtlasMissionControlCockpitService $cockpit,
        AtlasAaeosHttpPathFacadeService $httpPath,
    ): int {
        $action = (string) $this->argument('action');
        $json = (bool) $this->option('json');

        return match ($action) {
            'runbook' => $this->runbook($runbook, $phases, $json),
            'phase-handoff' => $this->phaseHandoff($phases, $json),
            'phase-skip' => $this->phaseSkip($phases, $json),
            'department-status' => $this->departmentStatus($departments, $json),
            'cockpit' => $this->cockpit($cockpit, $json),
            'universal-gates' => $this->universalGates($gates, $json),
            'http-path-status' => $this->httpPathStatus($httpPath, $json),
            default => $this->failWith("unknown action '{$action}'"),
        };
    }

    private function httpPathStatus(AtlasAaeosHttpPathFacadeService $httpPath, bool $json): int
    {
        $configured = (string) config('atlas.aaeos.http_path_phase', 'legacy');
        $payload = $httpPath->telemetrySnapshot($configured);

        if ($json) {
            $this->line(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->info('AAEOS HTTP Path · '.$configured);
        $this->line('  facade_active: '.($payload['facade_active'] ? 'yes' : 'no'));
        foreach ($payload['counters'] as $name => $value) {
            $this->line(sprintf('  counter.%s: %d', $name, $value));
        }
        $samples = (int) ($payload['latency_ms']['samples'] ?? 0);
        if ($samples > 0) {
            $avg = (int) round(((int) $payload['latency_ms']['sum']) / max(1, $samples));
            $this->line(sprintf('  latency_ms.avg: %d (samples=%d, max=%d)', $avg, $samples, (int) $payload['latency_ms']['max']));
        } else {
            $this->line('  latency_ms: no samples yet');
        }

        return self::SUCCESS;
    }

    private function runbook(RunbookOrchestrator $runbook, AaeosPhaseHandoffService $phases, bool $json): int
    {
        $payload = [
            'schema' => 'atlas.aaeos.runbook.v1',
            'phase_count' => count(AaeosPhaseHandoffService::PHASES),
            'phases' => array_map(
                fn (string $p, int $i) => [
                    'index' => $i,
                    'phase' => $p,
                    'gates' => $phases->gatesForPhase($p),
                ],
                AaeosPhaseHandoffService::PHASES,
                array_keys(AaeosPhaseHandoffService::PHASES),
            ),
            'department_flow' => RunbookOrchestrator::DEFAULT_FLOW,
        ];

        $this->emit($payload, $json);

        return self::SUCCESS;
    }

    private function phaseHandoff(AaeosPhaseHandoffService $phases, bool $json): int
    {
        $intent = (string) ($this->option('intent') ?? '');
        $phaseIn = (string) ($this->option('phase-in') ?? '');
        $phaseOut = (string) ($this->option('phase-out') ?? '');
        if ($intent === '' || $phaseIn === '' || $phaseOut === '') {
            return $this->failWith('phase-handoff requires --intent, --phase-in and --phase-out');
        }

        try {
            $envelope = $phases->emit(
                intentId: $intent,
                phaseIn: $phaseIn,
                phaseOut: $phaseOut,
                actor: [
                    'kind' => (string) $this->option('actor-kind'),
                    'id' => (string) $this->option('actor-id'),
                    'provider' => null,
                ],
                inputs: [],
                outputs: [],
                evidenceHashes: array_values(array_filter((array) $this->option('evidence'))),
                operatorSignature: (string) ($this->option('operator-signature') ?? '') ?: null,
                autonomyLevel: (string) $this->option('autonomy'),
            );
        } catch (\Throwable $e) {
            return $this->failWith($e->getMessage());
        }
        $this->emit($envelope, $json);

        return self::SUCCESS;
    }

    private function phaseSkip(AaeosPhaseHandoffService $phases, bool $json): int
    {
        $intent = (string) ($this->option('intent') ?? '');
        $phase = (string) ($this->option('phase-in') ?? '');
        $receipt = (string) ($this->option('receipt') ?? '');
        $reason = (string) ($this->option('reason') ?? '');
        if ($intent === '' || $phase === '' || $receipt === '' || $reason === '') {
            return $this->failWith('phase-skip requires --intent --phase-in --receipt --reason');
        }
        try {
            $envelope = $phases->skip($intent, $phase, $receipt, $reason, (string) $this->option('autonomy'));
        } catch (\Throwable $e) {
            return $this->failWith($e->getMessage());
        }
        $this->emit($envelope, $json);

        return self::SUCCESS;
    }

    private function departmentStatus(DepartmentContractRuntime $departments, bool $json): int
    {
        $catalogue = $departments->catalogue();
        $missing = $departments->missingFieldsByDepartment();
        $this->emit([
            'schema' => $catalogue['schema_version'],
            'department_count' => $catalogue['department_count'],
            'canon_department_count' => $catalogue['canon_department_count'],
            'schema_fields_12_present' => $catalogue['schema_fields_12_present'],
            'departments' => array_keys($catalogue['departments']),
            'missing_fields' => $missing,
        ], $json);

        return $missing === [] ? self::SUCCESS : self::FAILURE;
    }

    private function cockpit(AtlasMissionControlCockpitService $cockpit, bool $json): int
    {
        $intent = (string) ($this->option('intent') ?? '');
        if ($intent === '') {
            return $this->failWith('cockpit requires --intent');
        }
        $signals = $this->loadSignals();
        $snapshot = $cockpit->snapshot(
            intentId: $intent,
            phaseEnvelopes: [],
            gateSignals: $signals,
            autonomyLevel: (string) $this->option('autonomy'),
        );
        $this->emit($snapshot, $json);

        return self::SUCCESS;
    }

    private function universalGates(AtlasUniversalGatesEvaluator $gates, bool $json): int
    {
        $intent = (string) ($this->option('intent') ?? '');
        if ($intent === '') {
            return $this->failWith('universal-gates requires --intent');
        }
        $signals = $this->loadSignals();
        $deliveryPackPath = (string) ($this->option('delivery-pack') ?? '');
        if ($deliveryPackPath !== '') {
            $composition = $this->loadJsonFile($deliveryPackPath);
            if ($composition === null) {
                return $this->failWith('universal-gates --delivery-pack must be a readable JSON object');
            }
            $signals['delivery_pack_completeness_min_0_95'] = $gates->deliveryPackCompletenessSignal($composition);
            $report = $gates->evaluate($intent, $signals);
            $report['observe'] = array_merge(
                AiValueNormalizer::arrayOrEmpty($report['observe'] ?? null),
                ['delivery_pack_completeness' => $gates->deliveryPackCompletenessScoreObserve($composition)],
            );
        } else {
            $report = $gates->evaluate($intent, $signals);
        }

        $specPath = (string) ($this->option('spec') ?? '');
        if ($specPath !== '') {
            $spec = $this->loadJsonFile($specPath);
            if ($spec === null) {
                return $this->failWith('universal-gates --spec must be a readable JSON object');
            }
            $report['observe'] = array_merge(
                AiValueNormalizer::arrayOrEmpty($report['observe'] ?? null),
                [
                    'spec_completeness' => $gates->specCompletenessSignal($spec),
                    'spec_completeness_score' => $gates->specCompletenessScoreObserve($spec),
                ],
            );
        }

        // Observe-only projectors: do not add universal-gate ids (catalogue stays 15).
        foreach ([
            ['quality-bar', 'quality_bar_telemetry', fn (array $p) => $gates->qualityBarTelemetryObserve($p)],
            ['architect-spec-pack', 'architect_spec_pack_gate', fn (array $p) => $gates->architectSpecPackObserve($p)],
            ['predicted-impact', 'predicted_impact_band', fn (array $p) => $gates->predictedImpactBandObserve($p)],
            ['predicted-impact-calibration', 'predicted_impact_calibration', fn (array $p) => $gates->predictedImpactCalibrationObserve($p)],
            ['pre-review', 'pre_review_advisory', fn (array $p) => $gates->preReviewAdvisoryObserve($p)],
            ['reality-compiler-slice', 'reality_compiler_slice', fn (array $p) => $gates->realityCompilerSliceObserve($p)],
            ['esp09-challenger', 'esp09_challenger', fn (array $p) => $gates->esp09ChallengerObserve($p)],
            ['esp09-promotion-gate', 'esp09_promotion_gate', fn (array $p) => $gates->esp09PromotionGateObserve($p)],
            ['esp09-refutation-series', 'esp09_refutation_series', fn (array $p) => $gates->esp09RefutationSeriesObserve($p)],
            ['dogfooding-leads', 'dogfooding_friction_leads', fn (array $p) => $gates->dogfoodingFrictionLeadsObserve($p)],
            ['reactive-saturation', 'reactive_saturation', fn (array $p) => $gates->reactiveSaturationObserve($p)],
            ['blocker-severity', 'blocker_severity', fn (array $p) => $gates->blockerSeverityObserve($p)],
            ['phase-advance', 'phase_advance_verdict', fn (array $p) => $gates->phaseAdvanceVerdictObserve($p)],
            ['required-gate-coverage', 'required_gate_coverage', fn (array $p) => $gates->requiredGateCoverageObserve($p)],
            ['outcome-causality', 'outcome_causality', fn (array $p) => $gates->outcomeCausalityObserve($p)],
            ['summary-fidelity', 'summary_fidelity_coverage', fn (array $p) => $gates->summaryFidelityCoverageObserve($p)],
            ['memory-injection-budget', 'memory_injection_budget', fn (array $p) => $gates->memoryInjectionBudgetObserve($p)],
            ['memory-feedback-decay', 'memory_feedback_decay', fn (array $p) => $gates->memoryFeedbackDecayObserve($p)],
            ['segment-importance', 'segment_importance', fn (array $p) => $gates->segmentImportanceObserve($p)],
            ['context-pareto', 'context_pareto_dominance', fn (array $p) => $gates->contextParetoDominanceObserve($p)],
            ['memory-recall-rank', 'memory_recall_rank', fn (array $p) => $gates->memoryRecallRankObserve($p)],
            ['portfolio-budget', 'portfolio_budget', fn (array $p) => $gates->portfolioBudgetObserve($p)],
            ['ambition-rung', 'ambition_rung', fn (array $p) => $gates->ambitionRungObserve($p)],
            ['domain-lexical', 'domain_lexical', fn (array $p) => $gates->domainLexicalObserve($p)],
            ['gated-corpus', 'gated_corpus_candidates', fn (array $p) => $gates->gatedCorpusCandidatesObserve($p)],
            ['structured-facts', 'structured_fact_schema', fn (array $p) => $gates->structuredFactSchemaObserve($p)],
            ['citation-grounding', 'citation_grounding', fn (array $p) => $gates->citationGroundingObserve($p)],
            ['provenance-weight', 'provenance_weight', fn (array $p) => $gates->provenanceWeightObserve($p)],
            ['recall-gap', 'recall_gap', fn (array $p) => $gates->recallGapObserve($p)],
            ['belief-cascade', 'belief_cascade', fn (array $p) => $gates->beliefCascadeObserve($p)],
            ['ledger-rotation', 'ledger_rotation', fn (array $p) => $gates->ledgerRotationObserve($p)],
            ['evidence-vision', 'evidence_vision', fn (array $p) => $gates->evidenceVisionObserve($p)],
            ['gate-signal-spec-pack', 'gate_signal_spec_pack', fn (array $p) => $gates->gateSignalSpecPackObserve($p)],
            ['gate-signal-intent', 'gate_signal_intent', fn (array $p) => $gates->gateSignalIntentObserve($p)],
            ['gate-signal-task-pack', 'gate_signal_task_pack', fn (array $p) => $gates->gateSignalTaskPackObserve($p)],
            ['gate-signal-phase', 'gate_signal_phase', fn (array $p) => $gates->gateSignalPhaseObserve($p)],
            ['threshold-ladder', 'threshold_ladder', fn (array $p) => $gates->thresholdLadderObserve($p)],
            ['kb-embedding-coverage', 'kb_embedding_coverage', fn (array $p) => $gates->kbEmbeddingCoverageObserve($p)],
            ['code-symbol-embedding-coverage', 'code_symbol_embedding_coverage', fn (array $p) => $gates->codeSymbolEmbeddingCoverageObserve($p)],
            ['predicted-revert-digest', 'predicted_revert_digest', fn (array $p) => $gates->predictedRevertDigestObserve($p)],
            ['jina-dual-read-ledger', 'jina_dual_read_ledger', fn (array $p) => $gates->jinaDualReadLedgerObserve($p)],
            ['resource-budget', 'resource_budget', fn (array $p) => $gates->resourceBudgetObserve($p)],
            ['model-capability-spec', 'model_capability_spec', fn (array $p) => $gates->modelCapabilitySpecObserve($p)],
            ['measure-series-freshness', 'measure_series_freshness', fn (array $p) => $gates->measureSeriesFreshnessObserve($p)],
            ['verified-share', 'verified_share', fn (array $p) => $gates->verifiedShareObserve($p)],
            ['ragx-chain', 'ragx_chain', fn (array $p) => $gates->ragxChainObserve($p)],
            ['procedural-skill-promoter', 'procedural_skill_promoter', fn (array $p) => $gates->proceduralSkillPromoterObserve($p)],
            ['aaeos-phase-router', 'aaeos_phase_router', fn (array $p) => $gates->aaeosPhaseRouterObserve($p)],
            ['aaeos-quality-bar', 'aaeos_quality_bar', fn (array $p) => $gates->aaeosQualityBarObserve($p)],
            ['aaeos-department-maturity', 'aaeos_department_maturity', fn (array $p) => $gates->aaeosDepartmentMaturityObserve($p)],
            ['veto-propagation-watchdog', 'veto_propagation_watchdog', fn (array $p) => $gates->vetoPropagationWatchdogObserve($p)],
            ['repair-loop-guard', 'repair_loop_guard', fn (array $p) => $gates->repairLoopGuardObserve($p)],
            ['generated-contract-gate', 'generated_contract_gate', fn (array $p) => $gates->generatedContractGateObserve($p)],
            ['maturity-band-classifier', 'maturity_band_classifier', fn (array $p) => $gates->maturityBandClassifierObserve($p)],
            ['promotion-eligibility', 'promotion_eligibility', fn (array $p) => $gates->promotionEligibilityObserve($p)],
            ['debug-root-cause', 'debug_root_cause', fn (array $p) => $gates->debugRootCauseObserve($p)],
            ['cross-department-choreography', 'cross_department_choreography', fn (array $p) => $gates->crossDepartmentChoreographyObserve($p)],
            ['docs-authority-locate', 'docs_authority_locate', fn (array $p) => $gates->docsAuthorityLocateObserve($p)],
            ['department-level-classifier', 'department_level_classifier', fn (array $p) => $gates->departmentLevelClassifierObserve($p)],
            ['quality-bar-level-classifier', 'quality_bar_level_classifier', fn (array $p) => $gates->qualityBarLevelClassifierObserve($p)],
            ['implementation-truth-evaluate', 'implementation_truth_evaluate', fn (array $p) => $gates->implementationTruthEvaluateObserve($p)],
            ['phase-handoff-catalogue', 'phase_handoff_catalogue', fn (array $p) => $gates->phaseHandoffCatalogueObserve($p)],
            ['golden-counterfactual-replay', 'golden_counterfactual_replay', fn (array $p) => $gates->goldenCounterfactualReplayObserve($p)],
            ['composed-obra-arc', 'composed_obra_arc', fn (array $p) => $gates->composedObraArcObserve($p)],
            ['exploratory-bets-portfolio', 'exploratory_bets_portfolio', fn (array $p) => $gates->exploratoryBetsPortfolioObserve($p)],
            ['n-capture-drill', 'n_capture_drill', fn (array $p) => $gates->nCaptureDrillObserve($p)],
            ['lote2-counterfactual-lift', 'lote2_counterfactual_lift', fn (array $p) => $gates->lote2CounterfactualLiftObserve($p)],
            ['string-list-normalize', 'string_list_normalize', fn (array $p) => $gates->stringListNormalizeObserve($p)],
            ['threshold-comparator', 'threshold_comparator', fn (array $p) => $gates->thresholdComparatorObserve($p)],
            ['evidence-ref-normalize', 'evidence_ref_normalize', fn (array $p) => $gates->evidenceRefNormalizeObserve($p)],
            ['doc-maturity-classify', 'doc_maturity_classify', fn (array $p) => $gates->docMaturityClassifyObserve($p)],
            ['claim-definition-of-done', 'claim_definition_of_done', fn (array $p) => $gates->claimDefinitionOfDoneObserve($p)],
            ['array-field-reader', 'array_field_reader', fn (array $p) => $gates->arrayFieldReaderObserve($p)],
            ['veto-propagation-resolve', 'veto_propagation_resolve', fn (array $p) => $gates->vetoPropagationResolveObserve($p)],
            ['department-registry-validate', 'department_registry_validate', fn (array $p) => $gates->departmentRegistryValidateObserve($p)],
            ['cognitive-immune-classify', 'cognitive_immune_classify', fn (array $p) => $gates->cognitiveImmuneClassifyObserve($p)],
            ['department-canonical-list', 'department_canonical_list', fn (array $p) => $gates->departmentCanonicalListObserve($p)],
            ['universal-gates-catalogue', 'universal_gates_catalogue', fn (array $p) => $gates->universalGatesCatalogueObserve($p)],
            ['outcome-attribution-types', 'outcome_attribution_types', fn (array $p) => $gates->outcomeAttributionTypesObserve($p)],
        ] as [$option, $observeKey, $projector]) {
            $failed = $this->appendOptionalJsonObserve($report, $option, $observeKey, $projector);
            if ($failed !== null) {
                return $failed;
            }
        }

        $this->emit($report, $json);

        return $report['outcome'] === 'green' || $report['outcome'] === 'exception' ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  array<string,mixed>  $report
     * @param  callable(array<string,mixed>):mixed  $projector
     */
    private function appendOptionalJsonObserve(array &$report, string $option, string $observeKey, callable $projector): ?int
    {
        $path = (string) ($this->option($option) ?? '');
        if ($path === '') {
            return null;
        }
        $payload = $this->loadJsonFile($path);
        if ($payload === null) {
            return $this->failWith('universal-gates --'.$option.' must be a readable JSON object');
        }
        $report['observe'] = array_merge(
            AiValueNormalizer::arrayOrEmpty($report['observe'] ?? null),
            [$observeKey => $projector($payload)],
        );

        return null;
    }

    /** @return array<string,bool|string|null> */
    private function loadSignals(): array
    {
        $path = (string) ($this->option('signals') ?? '');
        if ($path === '') {
            return [];
        }
        $decoded = $this->loadJsonFile($path);

        return AiValueNormalizer::arrayOrEmpty($decoded);
    }

    /** @return array<string,mixed>|null */
    private function loadJsonFile(string $path): ?array
    {
        if ($path === '' || ! is_file($path)) {
            return null;
        }
        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : null;
    }

    private function emit(mixed $payload, bool $json): void
    {
        // --json is currently the same pretty provider-safe encoding; flag kept
        // for callers/docs that already pass it.
        unset($json);
        $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function failWith(string $reason): int
    {
        $this->error($reason);

        return self::FAILURE;
    }
}
