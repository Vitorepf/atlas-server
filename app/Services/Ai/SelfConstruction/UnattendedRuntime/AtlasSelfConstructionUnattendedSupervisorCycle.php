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
 * NO git, NO subprocess, NO external coding tool calls. Pure orchestration shell.
 */
final class AtlasSelfConstructionUnattendedSupervisorCycle
{
    public const SCHEMA = 'atlas.self_construction.unattended_supervisor_cycle.v1';

    public function __construct(
        private readonly ?AtlasSelfConstructionUnattendedLivenessSnapshot $snapshotComposer = null,
        private readonly ?AtlasSelfConstructionUnattendedStallClassifier $classifier = null,
        private readonly ?AtlasSelfConstructionUnattendedRecoveryActionPlanner $planner = null,
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
        $plan = $plannerSvc->plan($classification, $snapshot);

        $plannedActions = array_values((array) $plan['actions']);
        $blockedActions = array_values((array) $plan['blocked_actions']);
        $isUnsafeStop = (string) $classification['classification'] === AtlasSelfConstructionUnattendedStallClassifier::UNSAFE_STOP;

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
                if (! isset($callbacks[$actionId]) || ! is_callable($callbacks[$actionId])) {
                    $blockedActions[] = ['action' => $actionId, 'reason' => 'callback_missing'];

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
