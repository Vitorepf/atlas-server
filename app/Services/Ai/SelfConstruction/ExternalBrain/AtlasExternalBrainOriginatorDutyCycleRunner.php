<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Composes the three originator duty-cycle organs into a single governance runner:
 *   1. OriginatorCoverageRuntimeBridge::route() — route coverage to gaps/starved paths
 *   2. OriginatorDutyCycleContract::evaluate() — enforce the duty cycle (never stop prematurely)
 *   3. OriginatorStopConditionGate::evaluate() — decide when origination must stop
 *
 * The runner is pure/read-only: it never dispatches, never originates, never
 * mutates state. It produces a governed duty-cycle verdict.
 *
 * OUTPUT:
 *   { schema, status, routing, duty_cycle, stop_condition, should_stop, should_originate,
 *     action, blockers }
 *
 * Pure / deterministic. No I/O, no provider calls.
 */
final class AtlasExternalBrainOriginatorDutyCycleRunner
{
    public const SCHEMA = 'atlas.external_brain.originator_duty_cycle_runner.v1';

    public function __construct(
        private readonly AtlasExternalBrainOriginatorCoverageRuntimeBridge $coverageBridge = new AtlasExternalBrainOriginatorCoverageRuntimeBridge,
        private readonly AtlasExternalBrainOriginatorDutyCycleContract $dutyCycleContract = new AtlasExternalBrainOriginatorDutyCycleContract,
        private readonly AtlasExternalBrainOriginatorStopConditionGate $stopConditionGate = new AtlasExternalBrainOriginatorStopConditionGate,
    ) {}

    /**
     * @param  array<string, mixed>  $coverageVerdict
     * @param  array<string, mixed>  $dutyCycleFacts
     * @param  array<string, mixed>  $stopConditionInput
     * @return array<string, mixed>
     */
    public function govern(array $coverageVerdict, array $dutyCycleFacts, array $stopConditionInput): array
    {
        // 1. Route coverage — where should origination go next?
        $routing = $this->coverageBridge->route($coverageVerdict);

        // 2. Enforce duty cycle — is the mission still active?
        $dutyCycle = $this->dutyCycleContract->evaluate($dutyCycleFacts);

        // 3. Stop condition gate — should origination stop?
        $stopCondition = $this->stopConditionGate->evaluate($stopConditionInput);

        $shouldStop = (bool) ($stopCondition['can_stop'] ?? false)
            || (bool) ($dutyCycle['terminal'] ?? false);
        $shouldOriginate = ! $shouldStop
            && (bool) ($dutyCycle['next_action'] ?? null) !== false
            && ! in_array($routing['action'] ?? '', [AtlasExternalBrainOriginatorCoverageRuntimeBridge::ACTION_SELF_HEAL, AtlasExternalBrainOriginatorCoverageRuntimeBridge::ACTION_RETIRE_OR_REFRESH], true);

        $action = match (true) {
            $shouldStop => 'stop',
            ($routing['action'] ?? '') === AtlasExternalBrainOriginatorCoverageRuntimeBridge::ACTION_SELF_HEAL => 'self_heal',
            ($routing['action'] ?? '') === AtlasExternalBrainOriginatorCoverageRuntimeBridge::ACTION_RETIRE_OR_REFRESH => 'retire_or_refresh',
            ($routing['action'] ?? '') === AtlasExternalBrainOriginatorCoverageRuntimeBridge::ACTION_ORIGINATE => 'originate',
            ($routing['action'] ?? '') === AtlasExternalBrainOriginatorCoverageRuntimeBridge::ACTION_RESEARCH => 'research',
            default => 'consolidate',
        };

        $blockers = [];
        if ($shouldStop) {
            $blockers[] = 'origination_stopped:'.($stopCondition['verdict'] ?? ($dutyCycle['terminal_reason'] ?? 'unknown'));
        }

        return [
            'schema' => self::SCHEMA,
            'status' => $shouldStop ? 'stopped' : 'active',
            'routing' => $routing,
            'duty_cycle' => $dutyCycle,
            'stop_condition' => $stopCondition,
            'should_stop' => $shouldStop,
            'should_originate' => $shouldOriginate,
            'action' => $action,
            'blockers' => $blockers,
        ];
    }
}
