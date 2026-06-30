<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\RuntimeDaemon;

/**
 * Provider-free manifest that tells the existing Atlas scheduler / launchd surfaces how to keep the
 * Self-Construction runtime daemon alive WITHOUT installing anything itself.
 *
 * Pure: no I/O, no process, no provider call. The manifest is a deterministic value-object emitted
 * by `manifest()` so the actual scheduler bridge (the existing launchd / cron tooling) can consume
 * it without operator hand-holding.
 *
 * Self-declared invariants:
 *   - final_runtime_owner = atlas_native
 *   - steady_state_runtime_owner = atlas_server
 *   - operator_dependency_allowed = false
 *   - human_dependency_allowed = false
 *   - external_provider_dependency_allowed = false
 */
final class AtlasSelfConstructionRuntimeSchedulerManifest
{
    public const SCHEMA = 'atlas.self_construction.runtime_scheduler_manifest.v1';

    public const DEFAULT_PHP_BIN = '/opt/homebrew/bin/php';

    public const DEFAULT_CADENCE_SECONDS = 60;

    public const DEFAULT_HEARTBEAT_MAX_AGE_SECONDS = 180;

    public const DEFAULT_MAX_RUNTIME_SECONDS = 21600; // 6 hours

    public const DEFAULT_ENABLED_LANES = [
        'self-recovery', 'lane-governance', 'task-repair',
        'muscle-feedback', 'frontier-import', 'compounding', 'completion-certification',
    ];

    public const DEFAULT_QUEUE_THRESHOLDS = [
        'max_queued'   => 500,
        'max_claimed'  => 20,
        'drain_before_stop' => false,
    ];

    /**
     * @param  array<string,mixed>  $options {php_bin?, cadence_seconds?, heartbeat_max_age_seconds?, max_runtime_seconds?, facts_path?, enabled_lanes?, disabled_lanes?, queue_thresholds?}
     * @return array<string,mixed>
     */
    public function manifest(array $options = []): array
    {
        $phpBin          = (string) ($options['php_bin'] ?? self::DEFAULT_PHP_BIN);
        $cadence         = max(1, (int) ($options['cadence_seconds'] ?? self::DEFAULT_CADENCE_SECONDS));
        $heartbeatMaxAge = max(1, (int) ($options['heartbeat_max_age_seconds'] ?? self::DEFAULT_HEARTBEAT_MAX_AGE_SECONDS));
        $maxRuntime      = max(1, (int) ($options['max_runtime_seconds'] ?? self::DEFAULT_MAX_RUNTIME_SECONDS));
        $factsPath       = (string) ($options['facts_path'] ?? '');

        $enabledLanes  = array_values((array) ($options['enabled_lanes']  ?? self::DEFAULT_ENABLED_LANES));
        $disabledLanes = array_values((array) ($options['disabled_lanes'] ?? []));
        $enabledLanes  = array_values(array_diff($enabledLanes, $disabledLanes));

        $queueThresholds = array_replace(
            self::DEFAULT_QUEUE_THRESHOLDS,
            array_filter((array) ($options['queue_thresholds'] ?? []), static fn ($v): bool => $v !== null),
        );

        $tickCommand = $phpBin.' artisan atlas:self-construction:runtime-daemon tick --apply --json';
        $statusCommand = $phpBin.' artisan atlas:self-construction:runtime-daemon status --json';
        $planCommand = $phpBin.' artisan atlas:self-construction:runtime-daemon plan --json';
        if ($factsPath !== '') {
            $tickCommand .= ' --facts='.$factsPath;
            $statusCommand .= ' --facts='.$factsPath;
            $planCommand .= ' --facts='.$factsPath;
        }

        $body = [
            'schema_version' => self::SCHEMA,
            'final_runtime_owner' => 'atlas_native',
            'steady_state_runtime_owner' => 'atlas_server',
            'operator_dependency_allowed' => false,
            'human_dependency_allowed' => false,
            'external_provider_dependency_allowed' => false,
            'tick_command' => $tickCommand,
            'status_command' => $statusCommand,
            'plan_command' => $planCommand,
            'cadence_seconds' => $cadence,
            'heartbeat_max_age_seconds' => $heartbeatMaxAge,
            'max_runtime_seconds' => $maxRuntime,
            'backoff_policy' => [
                'kind' => 'exponential_with_jitter',
                'initial_seconds' => 30,
                'multiplier' => 2.0,
                'max_seconds' => 1800,
                'jitter_seconds' => 5,
            ],
            'safety_stop_conditions' => [
                'master_switch_off',
                'safety_stop_event',
                'pause_requested',
                'stop_requested',
                'unattended_supervisor_critical_blocker',
                'heartbeat_stale_beyond_policy',
                'non_atlas_dependency_detected',
            ],
            'evidence_obligations' => [
                'daemon_cycle_hash',
                'cycle_receipt_hash',
                'state_hash',
                'supervisor_hash',
            ],
            'forbidden_command_substrings' => [
                'curl',
                'wget',
                'ssh',
                'git ',
                'docker',
                'claude',
                'codex',
                'cursor',
                'http',
                'aws',
                'sudo',
            ],
            'self_install'       => false,
            'expected_consumer' => 'atlas_existing_scheduler_or_launchd_bridge',
            'enabled_lanes'     => $enabledLanes,
            'disabled_lanes'    => $disabledLanes,
            'queue_thresholds'  => $queueThresholds,
        ];

        // Stable hash over the full manifest so consumers can detect drift.
        ksort($body);
        $body['manifest_hash'] = hash('sha256', (string) json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return $body;
    }
}
