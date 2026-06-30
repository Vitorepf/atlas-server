<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Composes queue state, maturity gaps, worker outcomes, give_back risk,
 * simplification pressure and amplifier health into one honest control-plane
 * snapshot. Pure, deterministic, no I/O.
 *
 * Status (worst-case):
 *   red    — give_back_rate>0.30 OR worker_success_rate<0.50 OR amplifier=rollback_candidate
 *   yellow — queue/simplification pressure high OR value/muscle degrading OR amplifier=watch
 *            OR give_back>0.15 OR success_rate<0.70
 *   green  — none of the above AND NOT task_value_degrading AND NOT muscle_outcomes_degrading
 *
 * next_decision (first match):
 *   consolidate_existing_tasks — queue_pressure=high OR simplification_pressure=high
 *   create_more_tasks          — maturity_gap_count>0
 *   monitor                    — otherwise
 *
 * stop_go_verdict: stop=red, watch=yellow, go=green
 *
 * AC3: refuses green when value or muscle is degrading, even if queue health looks ok.
 * AC4: pure PHP, no I/O, no provider calls, no queue mutation.
 */
final class AtlasExternalBrainUnifiedControlPlaneSnapshot
{
    public const SCHEMA = 'atlas.external_brain.unified_control_plane_snapshot.v1';

    public const STATUS_RED    = 'red';
    public const STATUS_YELLOW = 'yellow';
    public const STATUS_GREEN  = 'green';

    public const VERDICT_STOP  = 'stop';
    public const VERDICT_WATCH = 'watch';
    public const VERDICT_GO    = 'go';

    public const DECISION_CREATE      = 'create_more_tasks';
    public const DECISION_CONSOLIDATE = 'consolidate_existing_tasks';
    public const DECISION_MONITOR     = 'monitor';

    private const GIVE_BACK_RED_FLOOR        = 0.30;
    private const GIVE_BACK_YELLOW_FLOOR     = 0.15;
    private const SUCCESS_RATE_RED_CEILING   = 0.50;
    private const SUCCESS_RATE_YELLOW_CEILING = 0.70;

    /**
     * @param  array<string,mixed>  $input
     * @return array{schema:string, status:string, top_risks:list<string>, next_decision:string, recommended_batch_theme:string, stop_go_verdict:string}
     */
    public function compose(array $input): array
    {
        $queuePressure        = (string) ($input['queue_pressure']          ?? 'low');
        $simplPressure        = (string) ($input['simplification_pressure'] ?? 'low');
        $amplifierStatus      = (string) ($input['model_amplifier_status']  ?? 'healthy');
        $maturityGapCount     = max(0,   (int)   ($input['maturity_gap_count']      ?? 0));
        $workerSuccessRate    = max(0.0, min(1.0, (float) ($input['worker_success_rate']   ?? 1.0)));
        $giveBackRate         = max(0.0, min(1.0, (float) ($input['give_back_rate']        ?? 0.0)));
        $taskValueDegrading   = (bool)   ($input['task_value_degrading']    ?? false);
        $muscleOutcomeDegrading = (bool) ($input['muscle_outcomes_degrading'] ?? false);

        [$status, $topRisks] = $this->resolveStatus(
            $queuePressure, $simplPressure, $amplifierStatus,
            $workerSuccessRate, $giveBackRate, $taskValueDegrading, $muscleOutcomeDegrading,
        );

        $nextDecision = $this->resolveDecision($queuePressure, $simplPressure, $maturityGapCount);
        $batchTheme   = $this->resolveBatchTheme($status, $nextDecision, $topRisks);

        return [
            'schema'                   => self::SCHEMA,
            'status'                   => $status,
            'top_risks'                => array_values($topRisks),
            'next_decision'            => $nextDecision,
            'recommended_batch_theme'  => $batchTheme,
            'stop_go_verdict'          => match ($status) {
                self::STATUS_RED    => self::VERDICT_STOP,
                self::STATUS_YELLOW => self::VERDICT_WATCH,
                default             => self::VERDICT_GO,
            },
        ];
    }

    /** @return array{string, list<string>} */
    private function resolveStatus(
        string $queuePressure,
        string $simplPressure,
        string $amplifierStatus,
        float  $workerSuccessRate,
        float  $giveBackRate,
        bool   $taskValueDegrading,
        bool   $muscleOutcomeDegrading,
    ): array {
        $risks = [];

        // RED conditions
        if ($giveBackRate > self::GIVE_BACK_RED_FLOOR) {
            $risks[] = sprintf('give_back_rate:%.4f>%.2f', $giveBackRate, self::GIVE_BACK_RED_FLOOR);
        }
        if ($workerSuccessRate < self::SUCCESS_RATE_RED_CEILING) {
            $risks[] = sprintf('worker_success_rate:%.4f<%.2f', $workerSuccessRate, self::SUCCESS_RATE_RED_CEILING);
        }
        if ($amplifierStatus === 'rollback_candidate') {
            $risks[] = 'model_amplifier_status:rollback_candidate';
        }

        if ($risks !== []) {
            return [self::STATUS_RED, $risks];
        }

        // YELLOW conditions
        $yellowRisks = [];
        if ($queuePressure === 'high') {
            $yellowRisks[] = 'queue_pressure:high';
        }
        if ($simplPressure === 'high') {
            $yellowRisks[] = 'simplification_pressure:high';
        }
        if ($taskValueDegrading) {
            $yellowRisks[] = 'task_value_degrading:true';
        }
        if ($muscleOutcomeDegrading) {
            $yellowRisks[] = 'muscle_outcomes_degrading:true';
        }
        if ($amplifierStatus === 'watch') {
            $yellowRisks[] = 'model_amplifier_status:watch';
        }
        if ($giveBackRate > self::GIVE_BACK_YELLOW_FLOOR) {
            $yellowRisks[] = sprintf('give_back_rate:%.4f>%.2f', $giveBackRate, self::GIVE_BACK_YELLOW_FLOOR);
        }
        if ($workerSuccessRate < self::SUCCESS_RATE_YELLOW_CEILING) {
            $yellowRisks[] = sprintf('worker_success_rate:%.4f<%.2f', $workerSuccessRate, self::SUCCESS_RATE_YELLOW_CEILING);
        }

        if ($yellowRisks !== []) {
            return [self::STATUS_YELLOW, $yellowRisks];
        }

        return [self::STATUS_GREEN, []];
    }

    private function resolveDecision(string $queuePressure, string $simplPressure, int $maturityGapCount): string
    {
        if ($queuePressure === 'high' || $simplPressure === 'high') {
            return self::DECISION_CONSOLIDATE;
        }
        if ($maturityGapCount > 0) {
            return self::DECISION_CREATE;
        }
        return self::DECISION_MONITOR;
    }

    /** @param list<string> $topRisks */
    private function resolveBatchTheme(string $status, string $decision, array $topRisks): string
    {
        if ($status === self::STATUS_RED) {
            return 'stabilize:address_critical_risks_before_new_origination';
        }
        if ($decision === self::DECISION_CONSOLIDATE) {
            return 'consolidate:reduce_sprawl_and_pressure_before_expanding';
        }
        if ($decision === self::DECISION_CREATE) {
            return 'expand:fill_maturity_gaps_with_high_leverage_tasks';
        }
        return 'monitor:observe_system_stability_before_next_batch';
    }
}
