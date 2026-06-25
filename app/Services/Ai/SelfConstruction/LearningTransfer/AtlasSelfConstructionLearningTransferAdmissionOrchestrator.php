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
        $candidate = [
            'class' => (string) ($classification['class'] ?? ''),
            'observations' => $this->synthesizeObservations($classification, $giveBackFact),
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
        );
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
    ): array {
        $envelope = [
            'schema_version' => self::SCHEMA,
            'mode' => $mode,
            'outcome' => $outcome,
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
