<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\LearningTransfer;

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

    private AtlasSelfConstructionLearningTransferGiveBackClassifier $classifier;

    private AtlasSelfConstructionLearningTransferLessonCandidateGate $gate;

    private AtlasSelfConstructionLearningTransferContextUpdatePlan $planner;

    private AtlasSelfConstructionLearningTransferPacketTemplateUpdater $updater;

    private AtlasSelfConstructionLearningTransferAdmissionLedger $ledger;

    public function __construct(
        ?AtlasSelfConstructionLearningTransferGiveBackClassifier $classifier = null,
        ?AtlasSelfConstructionLearningTransferLessonCandidateGate $gate = null,
        ?AtlasSelfConstructionLearningTransferContextUpdatePlan $planner = null,
        ?AtlasSelfConstructionLearningTransferPacketTemplateUpdater $updater = null,
        ?AtlasSelfConstructionLearningTransferAdmissionLedger $ledger = null,
    ) {
        $this->classifier = $classifier ?? new AtlasSelfConstructionLearningTransferGiveBackClassifier();
        $this->gate = $gate ?? new AtlasSelfConstructionLearningTransferLessonCandidateGate();
        $this->planner = $planner ?? new AtlasSelfConstructionLearningTransferContextUpdatePlan();
        $this->updater = $updater ?? new AtlasSelfConstructionLearningTransferPacketTemplateUpdater();
        $this->ledger = $ledger ?? new AtlasSelfConstructionLearningTransferAdmissionLedger(
            (function_exists('storage_path') ? storage_path('atlas/learning-transfer/admission.jsonl') : sys_get_temp_dir().'/atlas-learning-transfer-admission.jsonl')
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
        $lessonKey = $this->computeLessonKey($classification);

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

        $intendedTemplateAfter = $template === []
            ? ['observe_mode_no_template_provided' => true]
            : $this->updater->apply($plan, $template);

        $ledgerResult = $this->ledger->append($plan, [
            'mode' => $mode,
            'intended_action' => 'observe_apply_plan',
            'classification' => $classification,
            'gate_decision' => $gateDecision,
        ]);

        return $this->envelope(
            mode: $mode,
            classification: $classification,
            gateDecision: $gateDecision,
            plan: $plan,
            templateAfter: $intendedTemplateAfter,
            ledger: $ledgerResult,
            outcome: 'admitted_and_recorded',
            lessonKey: $lessonKey,
        );
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
        // TARGET project's own evidence is missing or explicitly contradicted — proof that a
        // pattern worked in Atlas is never proof it fits somewhere else.
        if ($targetEvidence === []) {
            $missingEvidence[] = 'target_evidence';
        } elseif ($targetEvidenceContradicted) {
            $missingEvidence[] = 'target_evidence_contradicted';
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

    private function computeLessonKey(array $classification): string
    {
        $payload = [
            'allowed_files' => array_values(array_map('strval', (array) ($classification['allowed_files'] ?? []))),
            'blocking_facts' => array_values(array_map('strval', (array) ($classification['blocking_facts'] ?? []))),
            'class' => (string) ($classification['class'] ?? ''),
            'evidence_refs' => array_values(array_map('strval', (array) ($classification['evidence_refs'] ?? []))),
        ];
        sort($payload['allowed_files']);
        sort($payload['blocking_facts']);
        sort($payload['evidence_refs']);

        return hash('sha256', (string) json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
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
        ];
        ksort($envelope, SORT_STRING);

        return $envelope;
    }
}
