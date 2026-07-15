<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\UnattendedRuntime;

/**
 * Bounded orchestration shell for ONE final 24/7 supervisor tick.
 *
 * Composes (in order): liveness snapshot → stall classifier → recovery action planner.
 * Default mode is DRY-RUN: no callback is invoked, only the planned actions are reported.
 * With $options['apply']=true, only callbacks whose action id is explicitly planned AND has a
 * matching entry in $callbacks are invoked. Missing callbacks are surfaced as blocked_actions.
 *
 * SAFETY: When classification is `unsafe_stop`, only the `safety_stop` callback (if provided) may
 * fire — every other planned action is blocked, regardless of apply.
 *
 * `decision` distills stall_class (from the classifier's classifyStallAction()) plus the unsafe_stop
 * gate into ONE of: self_heal, replenish, pause, escalate, or null when healthy. `escalate` is
 * returned — and auto-recovery refused — whenever the stall is unsafe_stop OR the stall class is
 * marked safe_to_auto_recover=false (poison_loop, proof_blocked: operator-only repair). `evidence_refs`
 * carries the stall classifier's evidence_needed; `safety_blockers` names why auto-recovery was refused.
 *
 * NO git, NO subprocess, NO external coding tool calls. Pure orchestration shell.
 */
final class AtlasSelfConstructionUnattendedSupervisorCycle
{
    public const SCHEMA = 'atlas.self_construction.unattended_supervisor_cycle.v1';

    public const DECISION_SELF_HEAL = 'self_heal';

    public const DECISION_REPLENISH = 'replenish';

    public const DECISION_PAUSE = 'pause';

    public const DECISION_ESCALATE = 'escalate';

    private const SELF_HEAL_STALL_CLASSES = [
        AtlasSelfConstructionUnattendedStallClassifier::STALL_MALFORMED_QUEUE,
        AtlasSelfConstructionUnattendedStallClassifier::STALL_STALE_HEARTBEAT,
        AtlasSelfConstructionUnattendedStallClassifier::STALL_WORKER_STARVATION,
        AtlasSelfConstructionUnattendedStallClassifier::STALL_LEARNING_STALE,
    ];

    public function __construct(
        private readonly ?AtlasSelfConstructionUnattendedLivenessSnapshotPort $snapshotComposer = null,
        private readonly ?AtlasSelfConstructionUnattendedStallClassifierPort $classifier = null,
        private readonly ?AtlasSelfConstructionUnattendedRecoveryActionPlannerPort $planner = null,
    ) {}

    /**
     * @param  array<string,mixed>  $facts
     * @param  array<string, callable(array): array<string,mixed>>  $callbacks
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function tick(array $facts, array $callbacks = [], array $options = []): array
    {
        $apply = (bool) ($options['apply'] ?? false);

        $snapshotSvc = $this->snapshotComposer ?? new AtlasSelfConstructionUnattendedLivenessSnapshot();
        $classifierSvc = $this->classifier ?? new AtlasSelfConstructionUnattendedStallClassifier();
        $plannerSvc = $this->planner ?? new AtlasSelfConstructionUnattendedRecoveryActionPlanner();

        $snapshot = $snapshotSvc->compose($facts);
        $classification = $classifierSvc->classify($snapshot);
        // classifyStallAction() reads the raw fact shape (queue.poison_loop_detected,
        // queue.repeated_poison_count, verification.proof_missing, learning.stale) — fields the
        // liveness snapshot's normalizeQueue()/normalizeVerification() do not carry through. The
        // composed $snapshot must not be used here or those signals are silently lost.
        $stallAction = $classifierSvc->classifyStallAction(['facts' => $facts]);
        $plan = $plannerSvc->plan($classification, $snapshot);

        $plannedActions = array_values((array) $plan['actions']);
        $blockedActions = array_values((array) $plan['blocked_actions']);
        $isUnsafeStop = (string) $classification['classification'] === AtlasSelfConstructionUnattendedStallClassifier::UNSAFE_STOP;

        [$decision, $safetyBlockers] = $this->decideRecoveryDecision($classification, $stallAction, $isUnsafeStop);

        $appliedActions = [];
        $receipts = [];

        if ($apply) {
            foreach ($plannedActions as $planned) {
                $actionId = (string) ($planned['action'] ?? '');
                if ($actionId === '') {
                    continue;
                }
                if ($isUnsafeStop && $actionId !== AtlasSelfConstructionUnattendedRecoveryActionPlanner::ACTION_SAFETY_STOP) {
                    $blockedActions[] = ['action' => $actionId, 'reason' => 'unsafe_stop_only_safety_stop_allowed'];

                    continue;
                }
                if (! (bool) ($planned['atlas_native'] ?? false)) {
                    $blockedActions[] = [
                        'action' => $actionId,
                        'planned_action' => $planned,
                        'reason' => 'non_atlas_native_action_refused',
                    ];

                    continue;
                }
                if (! isset($callbacks[$actionId]) || ! is_callable($callbacks[$actionId])) {
                    $blockedActions[] = [
                        'action' => $actionId,
                        'planned_action' => $planned,
                        'reason' => 'callback_missing',
                    ];

                    continue;
                }
                $callbackResult = null;
                $error = null;
                try {
                    $callbackResult = $callbacks[$actionId]($planned);
                } catch (\Throwable $e) {
                    $error = $e->getMessage();
                }
                $appliedActions[] = [
                    'action' => $actionId,
                    'applied' => $error === null,
                    'error' => $error,
                ];
                $receipts[] = [
                    'action' => $actionId,
                    'planned_action' => $planned,
                    'result' => is_array($callbackResult) ? $callbackResult : null,
                ];
            }
        }

        $supervisorCycleHash = $this->cycleHash(
            $classification,
            $plannedActions,
            $appliedActions,
            $blockedActions,
            $apply,
        );

        return [
            'schema' => self::SCHEMA,
            'schema_version' => self::SCHEMA,
            'status' => 'ok',
            'dry_run' => ! $apply,
            'classification' => $classification['classification'],
            'snapshot_hash' => (string) ($snapshot['snapshot_hash'] ?? ''),
            'classifier_hash' => (string) ($classification['classifier_hash'] ?? ''),
            'recovery_plan_hash' => (string) ($plan['plan_hash'] ?? ''),
            'planned_actions' => $plannedActions,
            'applied_actions' => $appliedActions,
            'blocked_actions' => $blockedActions,
            'receipts' => $receipts,
            'supervisor_cycle_hash' => $supervisorCycleHash,
            'recovery_receipt_strength' => $this->buildRecoveryReceiptStrength(
                $plannedActions,
                $blockedActions,
                $receipts,
                $isUnsafeStop,
            ),
            'decision' => $decision,
            'evidence_refs' => array_values((array) $stallAction['evidence_needed']),
            'safety_blockers' => $safetyBlockers,
        ];
    }

    /**
     * Distills stall_class + safe_to_auto_recover + the unsafe_stop gate into ONE decision:
     * self_heal, replenish, pause, escalate, or null when healthy. escalate ALWAYS refuses
     * auto-recovery — it is returned whenever the stall is unsafe_stop or the classifier marked
     * this stall class operator-only (safe_to_auto_recover=false).
     *
     * @param  array<string,mixed>  $classification
     * @param  array<string,mixed>  $stallAction
     * @return array{0:?string, 1:list<string>}
     */
    private function decideRecoveryDecision(array $classification, array $stallAction, bool $isUnsafeStop): array
    {
        $stallClass = (string) ($stallAction['stall_class'] ?? '');
        $safeToAutoRecover = (bool) ($stallAction['safe_to_auto_recover'] ?? true);

        if ($isUnsafeStop) {
            return [self::DECISION_ESCALATE, ['unsafe_stop_requires_operator_review']];
        }

        if (! $safeToAutoRecover) {
            return [self::DECISION_ESCALATE, ["operator_only_repair_required:{$stallClass}"]];
        }

        if ($stallClass === AtlasSelfConstructionUnattendedStallClassifier::STALL_NO_CLAIMABLE) {
            return [self::DECISION_REPLENISH, []];
        }

        if (in_array($stallClass, self::SELF_HEAL_STALL_CLASSES, true)) {
            return [self::DECISION_SELF_HEAL, []];
        }

        if ($stallClass === AtlasSelfConstructionUnattendedStallClassifier::STALL_NONE
            && (string) $classification['classification'] === AtlasSelfConstructionUnattendedStallClassifier::HEALTHY) {
            return [null, []];
        }

        return [self::DECISION_PAUSE, []];
    }

    /**
     * Build a summary of recovery receipt strength for the runtime daemon.
     *
     * Fields:
     *   planned_atlas_native_actions  list<string>  — action ids where atlas_native=true
     *   missing_callbacks             list<string>  — action ids blocked due to callback_missing
     *   unsafe_stop_blockers          list<string>  — action ids blocked due to unsafe_stop guard
     *   applied_receipts_count        int           — number of receipts recorded this tick
     *   safe_to_continue              bool          — no unsafe_stop AND no missing callbacks
     *
     * @param  list<array<string,mixed>>  $plannedActions
     * @param  list<array<string,mixed>>  $blockedActions
     * @param  list<array<string,mixed>>  $receipts
     */
    private function buildRecoveryReceiptStrength(
        array $plannedActions,
        array $blockedActions,
        array $receipts,
        bool $isUnsafeStop,
    ): array {
        $plannedAtlasNative = array_values(array_map(
            static fn (array $a): string => (string) ($a['action'] ?? ''),
            array_filter($plannedActions, static fn (array $a): bool => (bool) ($a['atlas_native'] ?? false)),
        ));

        $missingCallbacks = array_values(array_map(
            static fn (array $b): string => (string) ($b['action'] ?? ''),
            array_filter($blockedActions, static fn (array $b): bool => ($b['reason'] ?? '') === 'callback_missing'),
        ));

        $unsafeStopBlockers = array_values(array_map(
            static fn (array $b): string => (string) ($b['action'] ?? ''),
            array_filter($blockedActions, static fn (array $b): bool => ($b['reason'] ?? '') === 'unsafe_stop_only_safety_stop_allowed'),
        ));

        return [
            'planned_atlas_native_actions' => $plannedAtlasNative,
            'missing_callbacks'            => $missingCallbacks,
            'unsafe_stop_blockers'         => $unsafeStopBlockers,
            'applied_receipts_count'       => count($receipts),
            'safe_to_continue'             => ! $isUnsafeStop && $missingCallbacks === [],
        ];
    }

    /**
     * @param  array<string,mixed>  $classification
     * @param  list<array<string,mixed>>  $planned
     * @param  list<array<string,mixed>>  $applied
     * @param  list<array<string,mixed>>  $blocked
     */
    private function cycleHash(array $classification, array $planned, array $applied, array $blocked, bool $apply): string
    {
        $canonical = json_encode([
            'classification' => $classification['classification'] ?? '',
            'planned' => $planned,
            'applied' => $applied,
            'blocked' => $blocked,
            'apply' => $apply,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return 'supervisor_cycle_'.substr(hash('sha256', (string) $canonical), 0, 32);
    }
}
