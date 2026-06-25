<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\RuntimeDaemon;

/**
 * Deterministic virtual 24/7 scenario builder for Self-Construction runtime daemon proof.
 *
 * Generates a bounded list of `virtual_ticks` covering green cycles, empty-queue replenish,
 * give_back repair, failed-gate hold, stale heartbeat recovery, pause/resume, safety stop and
 * scope expansion hold/admit. Pure — NO real time, NO file/provider/process/git/queue side
 * effects, NO scheduler interaction. Output is byte-stable for identical input.
 */
final class AtlasSelfConstructionRuntimeSoakScenarioBuilder
{
    public const SCHEMA = 'atlas.self_construction.runtime_soak_scenario.v1';

    public const DEFAULT_MAX_TICKS = 32;

    public const DEFAULT_MAX_VIRTUAL_SECONDS = 86400;

    /** @var list<array<string,mixed>> Canonical tick templates, in execution order. */
    private const TICK_TEMPLATES = [
        [
            'kind' => 'green_cycle',
            'expected_outcome' => 'success',
            'required_evidence' => ['tests_or_gates_result'],
            'forbidden_dependency_flags' => ['requires_operator', 'requires_human', 'requires_external_provider'],
        ],
        [
            'kind' => 'empty_queue_replenish',
            'expected_outcome' => 'replenished_dry_run',
            'required_evidence' => ['replenisher_dry_run_receipt'],
            'forbidden_dependency_flags' => ['requires_operator', 'requires_human', 'requires_external_provider'],
        ],
        [
            'kind' => 'give_back_repair',
            'expected_outcome' => 'give_back',
            'required_evidence' => ['give_back_diagnostic'],
            'forbidden_dependency_flags' => ['requires_operator', 'requires_human', 'requires_external_provider'],
        ],
        [
            'kind' => 'failed_gate_hold',
            'expected_outcome' => 'failed',
            'required_evidence' => ['gate_failure_log'],
            'forbidden_dependency_flags' => ['requires_operator', 'requires_human', 'requires_external_provider'],
        ],
        [
            'kind' => 'stale_heartbeat_recovery',
            'expected_outcome' => 'recover_expired_leases',
            'required_evidence' => ['heartbeat_recovery_receipt'],
            'forbidden_dependency_flags' => ['requires_operator', 'requires_human', 'requires_external_provider'],
        ],
        [
            'kind' => 'pause_resume',
            'expected_outcome' => 'paused_then_resumed',
            'required_evidence' => ['pause_receipt', 'resume_receipt'],
            'forbidden_dependency_flags' => ['requires_operator', 'requires_human', 'requires_external_provider'],
        ],
        [
            'kind' => 'safety_stop',
            'expected_outcome' => 'safety_stop_observed',
            'required_evidence' => ['safety_stop_receipt'],
            'forbidden_dependency_flags' => ['requires_operator', 'requires_human', 'requires_external_provider'],
        ],
        [
            'kind' => 'replenisher_recovery',
            'expected_outcome' => 'replenisher_recovered',
            'required_evidence' => ['replenisher_recovery_receipt'],
            'forbidden_dependency_flags' => ['requires_operator', 'requires_human', 'requires_external_provider'],
        ],
        [
            'kind' => 'scope_expansion_hold',
            'expected_outcome' => 'scope_expansion_held',
            'required_evidence' => ['scope_expansion_governor_receipt'],
            'forbidden_dependency_flags' => ['requires_operator', 'requires_human', 'requires_external_provider'],
        ],
        [
            'kind' => 'scope_expansion_admit',
            'expected_outcome' => 'scope_expansion_admitted',
            'required_evidence' => ['scope_expansion_governor_receipt'],
            'forbidden_dependency_flags' => ['requires_operator', 'requires_human', 'requires_external_provider'],
        ],
    ];

    /**
     * @param  array<string,mixed>  $options  {max_ticks?:int, max_virtual_seconds?:int, virtual_start_unix?:int, tick_step_seconds?:int}
     * @return array<string,mixed>
     */
    public function build(array $options = []): array
    {
        $maxTicks = max(1, (int) ($options['max_ticks'] ?? self::DEFAULT_MAX_TICKS));
        $maxVirtualSeconds = max(1, (int) ($options['max_virtual_seconds'] ?? self::DEFAULT_MAX_VIRTUAL_SECONDS));
        $virtualStart = (int) ($options['virtual_start_unix'] ?? 1700000000);
        $tickStep = max(1, (int) ($options['tick_step_seconds'] ?? 60));

        $virtualNow = $virtualStart;
        $templates = self::TICK_TEMPLATES;
        $ticks = [];
        $tickIndex = 0;

        // Cycle through templates until we hit the tick/virtual-time bound.
        while ($tickIndex < $maxTicks && ($virtualNow - $virtualStart) < $maxVirtualSeconds) {
            $template = $templates[$tickIndex % count($templates)];
            $ticks[] = [
                'index' => $tickIndex,
                'virtual_unix' => $virtualNow,
                'kind' => (string) $template['kind'],
                'expected_outcome' => (string) $template['expected_outcome'],
                'required_evidence' => array_values((array) $template['required_evidence']),
                'forbidden_dependency_flags' => array_values((array) $template['forbidden_dependency_flags']),
            ];
            $virtualNow += $tickStep;
            $tickIndex++;
        }

        $payload = [
            'schema' => self::SCHEMA,
            'schema_version' => self::SCHEMA,
            'max_ticks' => $maxTicks,
            'max_virtual_seconds' => $maxVirtualSeconds,
            'virtual_start_unix' => $virtualStart,
            'tick_step_seconds' => $tickStep,
            'tick_count' => count($ticks),
            'virtual_ticks' => $ticks,
        ];
        $payload['scenario_hash'] = $this->scenarioHash($payload);

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function scenarioHash(array $payload): string
    {
        $canonical = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return 'scenario_'.substr(hash('sha256', (string) $canonical), 0, 32);
    }
}
