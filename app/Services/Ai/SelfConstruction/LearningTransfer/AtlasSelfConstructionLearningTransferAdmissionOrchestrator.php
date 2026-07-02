<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\LearningTransfer;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainMuscleOutcomeLearningMatrix;

/**
 * Closed-loop lesson-admission orchestrator (OBSERVE-only by default).
 *
 *   give_back fact → Classifier → LessonCandidateGate → ContextUpdatePlan → PacketTemplateUpdater → Ledger
 *
 * Pure: no I/O outside the injected ledger. Deterministic: same input → byte-identical JSON.
 * Default-organs: missing collaborators are constructed lazily so the orchestrator can be
 * used standalone in tests or wired with custom organs in production.
 *
 * OBSERVE mode is the default; template_after is the INTENDED result of applying the plan
 * with the supplied template snapshot — no real file/template is mutated.
 */
final class AtlasSelfConstructionLearningTransferAdmissionOrchestrator
{
    public const SCHEMA = 'atlas.learning_transfer.admission_orchestrator.v1';

    public const MODE_OBSERVE = 'observe';

    public const MODE_APPLY = 'apply';

    private const STALE_EVIDENCE_MAX_AGE_DAYS = 90;

    /** give_back_rate at/above this (with enough rows) reads as poison-like recurrence, not noise. */
    private const DEFAULT_FAMILY_GIVE_BACK_POISON_FLOOR = 0.50;

    /** Minimum recent-outcome rows for a family before its give_back_rate is trusted. */
    private const DEFAULT_FAMILY_MIN_ROWS_FOR_SUPPRESSION = 3;

    private AtlasSelfConstructionLearningTransferGiveBackClassifier $classifier;

    private AtlasSelfConstructionLearningTransferLessonCandidateGate $gate;

    private AtlasSelfConstructionLearningTransferContextUpdatePlan $planner;

    private AtlasSelfConstructionLearningTransferPacketTemplateUpdater $updater;

    private AtlasSelfConstructionLearningTransferAdmissionLedger $ledger;

    private AtlasSelfConstructionLearningTransferObservationStore $observations;

    public function __construct(
        ?AtlasSelfConstructionLearningTransferGiveBackClassifier $classifier = null,
        ?AtlasSelfConstructionLearningTransferLessonCandidateGate $gate = null,
        ?AtlasSelfConstructionLearningTransferContextUpdatePlan $planner = null,
        ?AtlasSelfConstructionLearningTransferPacketTemplateUpdater $updater = null,
        ?AtlasSelfConstructionLearningTransferAdmissionLedger $ledger = null,
        ?AtlasSelfConstructionLearningTransferObservationStore $observations = null,
    ) {
        $this->classifier = $classifier ?? new AtlasSelfConstructionLearningTransferGiveBackClassifier();
        $this->gate = $gate ?? new AtlasSelfConstructionLearningTransferLessonCandidateGate();
        $this->planner = $planner ?? new AtlasSelfConstructionLearningTransferContextUpdatePlan();
        $this->updater = $updater ?? new AtlasSelfConstructionLearningTransferPacketTemplateUpdater();
        $this->ledger = $ledger ?? new AtlasSelfConstructionLearningTransferAdmissionLedger(
            AtlasSelfConstructionLearningTransferAdmissionLedger::defaultPath()
        );
        $this->observations = $observations ?? new AtlasSelfConstructionLearningTransferObservationStore(
            AtlasSelfConstructionLearningTransferObservationStore::defaultPath()
        );
    }

    /**
     * @param  array<string,mixed>  $giveBackFact
     * @param  array<string,mixed>  $template       optional packet template snapshot (observe mode)
     * @param  array<string,mixed>  $thresholds     gate thresholds override
     * @return array<string,mixed>
     */
    public function admit(array $giveBackFact, array $template = [], array $thresholds = []): array
    {
        $mode = self::MODE_OBSERVE;
        $classification = $this->classifier->classify($giveBackFact);

        $factOutcome = trim((string) ($giveBackFact['outcome'] ?? ''));
        if ($factOutcome === '') {
            $factOutcome = trim((string) data_get($giveBackFact, 'muscle_outcome.status', '')) ?: 'give_back';
        }
        $scopeDirs = $this->scopeDirsOf($classification);

        // Closure-by-resolution class adoption: a resolved/success fact is
        // classless (the classifier derives classes from give_back reasons),
        // so a real resolution in a scope with accumulated give_back history
        // adopts the dominant observed class — the lesson becomes "class F in
        // this scope, observed N times, CLOSED by a real resolution". The
        // muscle_outcome handed to the pétreo success-only floor is always
        // the trigger's real outcome, never forged.
        try {
            if (($classification['class'] ?? '') === AtlasSelfConstructionLearningTransferGiveBackClassifier::CLASS_UNKNOWN
                && in_array($factOutcome, ['success', 'resolved', 'green_commit'], true)
            ) {
                $dominantClass = array_key_first($this->observations->giveBackClassesForScope($scopeDirs));
                if (is_string($dominantClass) && $dominantClass !== '') {
                    $classification['class'] = $dominantClass;
                    $classification['root_cause'] = $dominantClass;
                }
            }
        } catch (\Throwable) {
            // Observation store trouble must never break an admit (fail-open
            // to the stateless pre-accumulator behavior).
        }

        $lessonKey = $this->computeLessonKey($classification);

        [$giveBackFact, $template] = $this->applyObservationAccumulator(
            $giveBackFact,
            $template,
            $classification,
            $lessonKey,
            $factOutcome,
            $scopeDirs,
        );

        $familyOutcomeSignal = $this->computeFamilyOutcomeSignal((string) ($classification['class'] ?? ''), $template, $thresholds);

        // Deduplicate against a caller-supplied snapshot of already-known lesson keys.
        $knownKeys = array_values((array) ($template['known_lesson_keys'] ?? []));
        if ($knownKeys !== [] && in_array($lessonKey, $knownKeys, true)) {
            return $this->envelope(
                mode: $mode,
                classification: $classification,
                gateDecision: [],
                plan: null,
                templateAfter: null,
                ledger: null,
                outcome: 'duplicate_observed',
                lessonKey: $lessonKey,
                familyOutcomeSignal: $familyOutcomeSignal,
            );
        }

        $candidate = [
            'class' => (string) ($classification['class'] ?? ''),
            'observations' => $this->synthesizeObservations($classification, $giveBackFact),
            // A give_back lesson's most direct future effect is changing whether a similar
            // packet gets admitted next time; callers may override with a more specific
            // decision_impact (blocked_repair, worker_feed_replenishment) via $giveBackFact.
            'decision_impact' => array_values((array) ($giveBackFact['decision_impact'] ?? ['packet_admission'])),
        ];
        $gateDecision = $this->gate->admit($candidate, $thresholds);
        $decision = (string) ($gateDecision['decision'] ?? '');

        if ($decision !== AtlasSelfConstructionLearningTransferLessonCandidateGate::DECISION_ADMIT) {
            return $this->envelope(
                mode: $mode,
                classification: $classification,
                gateDecision: $gateDecision,
                plan: null,
                templateAfter: null,
                ledger: null,
                outcome: 'short_circuited_at_gate',
                lessonKey: $lessonKey,
                familyOutcomeSignal: $familyOutcomeSignal,
            );
        }

        // Repeated give_back outcomes for this same family read as poison-like recurrence:
        // admitting the gate's decision again would keep rewriting the packet template on a
        // pattern that already failed to stick. Suppress the template-changing side effects
        // (plan/template_after/ledger) while still surfacing the classification + signal.
        if ($familyOutcomeSignal['suppress_template_update']) {
            return $this->envelope(
                mode: $mode,
                classification: $classification,
                gateDecision: $gateDecision,
                plan: null,
                templateAfter: null,
                ledger: null,
                outcome: 'suppressed_by_family_give_back_recurrence',
                lessonKey: $lessonKey,
                familyOutcomeSignal: $familyOutcomeSignal,
            );
        }

        $admittedLesson = [
            'decision' => $decision,
            'class' => $classification['class'],
            'packet_id' => $classification['packet_id'] ?? '',
            'allowed_files' => $classification['allowed_files'] ?? [],
            'blocking_facts' => $classification['blocking_facts'] ?? [],
            'evidence_refs' => $classification['evidence_refs'] ?? [],
        ];
        $plan = $this->planner->plan($admittedLesson);
        // Propagate evidence refs so the ledger admission guard (requires source_evidence_refs) passes.
        if (! isset($plan['source_evidence_refs']) || $plan['source_evidence_refs'] === []) {
            $plan['source_evidence_refs'] = array_values(array_map('strval', (array) ($admittedLesson['evidence_refs'] ?? [])));
        }
        // r125 outcome-proof floor passthroughs: the caller carries the proof (impact_class,
        // design_path_refs, muscle_outcome) in the give-back fact; the orchestrator never invents it.
        if (! isset($plan['impact_class']) || trim((string) $plan['impact_class']) === '') {
            $plan['impact_class'] = (string) ($giveBackFact['impact_class'] ?? '');
        }
        if (! isset($plan['design_path_refs']) || $plan['design_path_refs'] === []) {
            $plan['design_path_refs'] = array_values(array_map('strval', (array) ($giveBackFact['design_path_refs'] ?? [])));
        }

        $intendedTemplateAfter = $template === []
            ? ['observe_mode_no_template_provided' => true]
            : $this->updater->apply($plan, $template);

        try {
            $ledgerResult = $this->ledger->append($plan, [
                'mode' => $mode,
                'intended_action' => 'observe_apply_plan',
                'classification' => $classification,
                'gate_decision' => $gateDecision,
                'muscle_outcome' => is_array($giveBackFact['muscle_outcome'] ?? null) ? $giveBackFact['muscle_outcome'] : [],
            ]);
        } catch (\InvalidArgumentException $refusal) {
            // The admission floor refusing is a governed outcome, not a crash: surface it so the
            // caller learns what proof is missing instead of the whole observe cycle dying.
            return $this->envelope(
                mode: $mode,
                classification: $classification,
                gateDecision: $gateDecision,
                plan: $plan,
                templateAfter: null,
                ledger: ['schema_version' => AtlasSelfConstructionLearningTransferAdmissionLedger::SCHEMA, 'status' => 'refused', 'reason' => $refusal->getMessage()],
                outcome: 'refused_by_admission_floor',
                lessonKey: $lessonKey,
                familyOutcomeSignal: $familyOutcomeSignal,
            );
        }

        // Retire the accumulated history: the same observations never
        // re-admit or re-conflict (the ledger append is idempotent by
        // plan_hash anyway — this keeps the accumulator honest too).
        try {
            $this->observations->retire($lessonKey);
        } catch (\Throwable) {
            // fail-open
        }

        return $this->envelope(
            mode: $mode,
            classification: $classification,
            gateDecision: $gateDecision,
            plan: $plan,
            templateAfter: $intendedTemplateAfter,
            ledger: $ledgerResult,
            outcome: 'admitted_and_recorded',
            lessonKey: $lessonKey,
            familyOutcomeSignal: $familyOutcomeSignal,
        );
    }

    /**
     * Reduces recent muscle outcomes for this lesson's family (reusing the shared outcome
     * matrix — no bespoke rate math here) into a deterministic per-family signal. Callers
     * supply history via $template['family_outcome_rows'] (list of {outcome}); with no history
     * the signal is neutral and never suppresses.
     *
     * @param  array<string,mixed>  $template
     * @param  array<string,mixed>  $thresholds
     * @return array{family:string, success_rate:float, give_back_rate:float, total:int, signal:string, suppress_template_update:bool}
     */
    private function computeFamilyOutcomeSignal(string $family, array $template, array $thresholds): array
    {
        $rawRows = array_values((array) ($template['family_outcome_rows'] ?? []));
        $outcomeRows = [];
        foreach ($rawRows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $outcomeRows[] = ['task_family' => $family, 'outcome' => (string) ($row['outcome'] ?? '')];
        }

        $analysis = (new AtlasExternalBrainMuscleOutcomeLearningMatrix())->analyze(['outcome_rows' => $outcomeRows]);
        $familyRow = $analysis['family_matrix'][$family] ?? [
            'success_rate' => 0.0,
            'give_back_rate' => 0.0,
            'total' => 0,
            'signal' => 'normal',
        ];

        $giveBackFloor = (float) ($thresholds['family_give_back_poison_floor'] ?? self::DEFAULT_FAMILY_GIVE_BACK_POISON_FLOOR);
        $minRows = (int) ($thresholds['family_min_rows_for_suppression'] ?? self::DEFAULT_FAMILY_MIN_ROWS_FOR_SUPPRESSION);

        $poisonLikeRecurrence = $familyRow['total'] >= $minRows && $familyRow['give_back_rate'] >= $giveBackFloor;

        return [
            'family' => $family,
            'success_rate' => $familyRow['success_rate'],
            'give_back_rate' => $familyRow['give_back_rate'],
            'total' => $familyRow['total'],
            'signal' => $poisonLikeRecurrence ? 'poison_like_give_back_recurrence' : $familyRow['signal'],
            'suppress_template_update' => $poisonLikeRecurrence,
        ];
    }

    /**
     * Admits a pattern learned inside Atlas for transfer into ANOTHER project. Distinct from
     * admit() (intra-Atlas give_back lessons): this gates on cross-project cargo-cult risk —
     * a pattern that worked here can still be blindly copy-pasted somewhere it does not fit.
     *
     * transfer_allowed=true only when ALL FOUR proofs are present AND the target project has
     * real (non-contradicted) evidence — a pattern is never transferred on Atlas-side success
     * alone.
     *
     * @param  array{
     *   source_proof?:string, target_fit?:string, risk_analysis?:string, rollback_path?:string,
     *   target_evidence?:list<mixed>, target_evidence_contradicted?:bool,
     *   target_evidence_age_days?:int, source_scope?:string, target_scope?:string,
     *   hidden_assumptions?:list<mixed>,
     * }  $input
     * @return array{schema_version:string, transfer_decision:string, transfer_allowed:bool, missing_evidence:list<string>, safe_first_task:?string, rollback_ref:?string}
     */
    public function admitCrossProjectTransfer(array $input): array
    {
        $sourceProof = trim((string) ($input['source_proof'] ?? ''));
        $targetFit = trim((string) ($input['target_fit'] ?? ''));
        $riskAnalysis = trim((string) ($input['risk_analysis'] ?? ''));
        $rollbackPath = trim((string) ($input['rollback_path'] ?? ''));
        $targetEvidence = is_array($input['target_evidence'] ?? null) ? array_filter($input['target_evidence']) : [];
        $targetEvidenceContradicted = (bool) ($input['target_evidence_contradicted'] ?? false);
        $targetEvidenceAgeDays = (int) ($input['target_evidence_age_days'] ?? 0);
        $sourceScope = trim((string) ($input['source_scope'] ?? ''));
        $targetScope = trim((string) ($input['target_scope'] ?? ''));
        $hiddenAssumptions = is_array($input['hidden_assumptions'] ?? null) ? array_filter($input['hidden_assumptions']) : [];

        $missingEvidence = [];
        if ($sourceProof === '') {
            $missingEvidence[] = 'source_proof';
        }
        if ($targetFit === '') {
            $missingEvidence[] = 'target_fit';
        }
        if ($riskAnalysis === '') {
            $missingEvidence[] = 'risk_analysis';
        }
        if ($rollbackPath === '') {
            $missingEvidence[] = 'rollback_path';
        }

        // Cargo-cult guard: even with all four proofs present, transfer is never allowed when the
        // TARGET project's own evidence is missing, explicitly contradicted, or stale — proof that
        // a pattern worked in Atlas is never proof it fits somewhere else, and old target evidence
        // may no longer reflect the target project's current state.
        if ($targetEvidence === []) {
            $missingEvidence[] = 'target_evidence';
        } elseif ($targetEvidenceContradicted) {
            $missingEvidence[] = 'target_evidence_contradicted';
        } elseif ($targetEvidenceAgeDays > self::STALE_EVIDENCE_MAX_AGE_DAYS) {
            $missingEvidence[] = 'target_evidence_stale';
        }

        // Scope-fit guard: a pattern proven under one scope is never blindly transferred into a
        // mismatched scope, even with all other proofs present.
        if ($sourceScope !== '' && $targetScope !== '' && strcasecmp($sourceScope, $targetScope) !== 0) {
            $missingEvidence[] = 'target_scope_mismatch';
        }

        // Hidden-assumption guard: a transfer that depends on project-specific assumptions the
        // target has not verified is a cargo-cult transfer regardless of how strong the other
        // proofs look.
        if ($hiddenAssumptions !== []) {
            $missingEvidence[] = 'hidden_assumptions_present';
        }

        $missingEvidence = array_values(array_unique($missingEvidence));
        $transferAllowed = $missingEvidence === [];

        return [
            'schema_version' => self::SCHEMA,
            'transfer_decision' => $transferAllowed ? 'allow' : 'block',
            'transfer_allowed' => $transferAllowed,
            'missing_evidence' => $missingEvidence,
            'safe_first_task' => $transferAllowed
                ? 'implement the smallest scoped adaptation named in target_fit, proven by the rollback_path before wider rollout'
                : null,
            'rollback_ref' => $transferAllowed ? $rollbackPath : null,
        ];
    }

    /**
     * Observation accumulator (the piece that makes the gate's independent-
     * repetition threshold reachable): record the incoming observation, feed
     * accumulated cross-packet history to the gate when the caller brought no
     * observations of its own, and give family suppression live fuel on
     * give_back triggers.
     *
     * O-1 no-noise guard: a classless ('unknown') fact has no lesson — it
     * never records and never aggregates, otherwise every unclassified fact
     * in a scope would share one lesson_key and could mint a meaningless
     * lesson. Independence guard: >=2 distinct agents before aggregating — a
     * single hijacked agent never mints a lesson alone. Fail-open: any store
     * failure degrades to the stateless pre-accumulator behavior.
     *
     * @param  array<string,mixed>  $giveBackFact
     * @param  array<string,mixed>  $template
     * @param  array<string,mixed>  $classification
     * @param  list<string>  $scopeDirs
     * @return array{0: array<string,mixed>, 1: array<string,mixed>} [$giveBackFact, $template]
     */
    private function applyObservationAccumulator(
        array $giveBackFact,
        array $template,
        array $classification,
        string $lessonKey,
        string $factOutcome,
        array $scopeDirs,
    ): array {
        $class = trim((string) ($classification['class'] ?? ''));
        if ($class === '' || $class === AtlasSelfConstructionLearningTransferGiveBackClassifier::CLASS_UNKNOWN) {
            return [$giveBackFact, $template];
        }

        try {
            // Recording a fact that really happened is not fabricating a
            // signal. Dedupe + retirement live in the store.
            $this->observations->record([
                'lesson_key' => $lessonKey,
                'class' => $class,
                'scope_dirs' => $scopeDirs,
                'task_packet_id' => (string) ($classification['packet_id'] ?? ''),
                'agent_id' => (string) ($giveBackFact['agent_id'] ?? ''),
                'outcome' => $factOutcome,
                'evidence_refs' => array_values(array_map('strval', (array) ($classification['evidence_refs'] ?? []))),
                'blocking_facts' => array_values(array_map('strval', (array) ($classification['blocking_facts'] ?? []))),
            ]);

            if (array_values(array_filter((array) ($giveBackFact['observations'] ?? []), 'is_array')) === []) {
                $accumulated = $this->observations->observationsFor($lessonKey, self::STALE_EVIDENCE_MAX_AGE_DAYS);
                $distinctAgents = array_unique(array_filter(array_map(
                    static fn (array $row): string => (string) ($row['agent_id'] ?? ''),
                    $accumulated,
                )));
                if (count($accumulated) >= 2 && count($distinctAgents) >= 2) {
                    $giveBackFact['observations'] = array_map(static fn (array $row): array => [
                        'outcome' => (string) ($row['outcome'] ?? 'unknown'),
                        'evidence_refs' => array_values(array_map('strval', (array) ($row['evidence_refs'] ?? []))),
                        'source' => 'task_packet:'.((string) ($row['task_packet_id'] ?? '')),
                    ], $accumulated);
                }
            }

            // Suppression governs failure-driven template churn; a success
            // closure is not recurrence — hence give_back triggers only.
            if (! array_key_exists('family_outcome_rows', $template) && $factOutcome === 'give_back') {
                $template['family_outcome_rows'] = $this->observations->outcomesForClass(
                    $class,
                    self::STALE_EVIDENCE_MAX_AGE_DAYS,
                );
            }
        } catch (\Throwable) {
            // fail-open
        }

        return [$giveBackFact, $template];
    }

    private function computeLessonKey(array $classification): string
    {
        // Lesson identity must AGGREGATE across packets to ever reach the
        // gate's independent-repetition threshold: the previous payload
        // included evidence_refs (which carry the per-run task_packet id) and
        // the literal allowed_files, so every run minted a fresh key and the
        // circuit could not accumulate by construction. Identity is now
        // class + scope DIRECTORIES + volatile-stripped blocking kinds —
        // stable across packets, still distinct across genuinely different
        // lessons (same class, structurally different blocking facts or
        // different area => different key).
        $scopeDirs = $this->scopeDirsOf($classification);
        $blockingKinds = array_values(array_unique(array_filter(array_map(
            static function (string $fact): string {
                // ponytail: regex strip of volatile tokens (hashes, packet
                // ids, dates); sharpen if real collisions show up.
                $kind = (string) preg_replace(
                    '/\b[0-9a-f]{8,}\b|task_packet:[^\s"]+|\d{4}-\d{2}-\d{2}\S*/i',
                    '',
                    $fact,
                );

                return trim((string) preg_replace('/\s+/', ' ', $kind));
            },
            array_map('strval', (array) ($classification['blocking_facts'] ?? [])),
        ))));
        sort($blockingKinds);

        $payload = [
            'blocking_kinds' => $blockingKinds,
            'class' => (string) ($classification['class'] ?? ''),
            'scope_dirs' => $scopeDirs,
        ];

        return hash('sha256', (string) json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Normalized scope directories of a classification's allowed_files —
     * the cross-packet half of the lesson identity.
     *
     * @param  array<string,mixed>  $classification
     * @return list<string>
     */
    private function scopeDirsOf(array $classification): array
    {
        $dirs = array_values(array_unique(array_map(
            static fn (string $file): string => dirname($file),
            array_filter(array_map('strval', (array) ($classification['allowed_files'] ?? []))),
        )));
        sort($dirs, SORT_STRING);

        return $dirs;
    }

    /**
     * @param  array<string,mixed>  $classification
     * @param  array<string,mixed>  $giveBackFact
     * @return list<array<string,mixed>>
     */
    private function synthesizeObservations(array $classification, array $giveBackFact): array
    {
        $observations = (array) ($giveBackFact['observations'] ?? []);
        if ($observations !== []) {
            return array_values(array_filter($observations, 'is_array'));
        }
        // Default: derive one observation from the classification metadata so the gate has a
        // signal-bearing input. The caller can override by passing 'observations' in the fact.
        $evidence = array_values(array_map('strval', (array) ($classification['evidence_refs'] ?? [])));
        $blocking = array_values(array_map('strval', (array) ($classification['blocking_facts'] ?? [])));

        return [
            [
                'outcome' => $blocking === [] ? 'unknown' : 'give_back',
                'evidence_refs' => $evidence,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $classification
     * @param  array<string,mixed>  $gateDecision
     * @param  array<string,mixed>|null  $plan
     * @param  array<string,mixed>|null  $templateAfter
     * @param  array<string,mixed>|null  $ledger
     * @param  array<string,mixed>  $familyOutcomeSignal
     * @return array<string,mixed>
     */
    private function envelope(
        string $mode,
        array $classification,
        array $gateDecision,
        ?array $plan,
        ?array $templateAfter,
        ?array $ledger,
        string $outcome,
        string $lessonKey = '',
        array $familyOutcomeSignal = [],
    ): array {
        $envelope = [
            'schema_version' => self::SCHEMA,
            'mode' => $mode,
            'outcome' => $outcome,
            'lesson_key' => $lessonKey,
            'classification' => $classification,
            'gate_decision' => $gateDecision,
            'plan' => $plan,
            'template_after' => $templateAfter,
            'ledger' => $ledger,
            'family_outcome_signal' => $familyOutcomeSignal,
        ];
        ksort($envelope, SORT_STRING);

        return $envelope;
    }
}
