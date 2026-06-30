<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Autonomy;

use InvalidArgumentException;

/**
 * Deterministic ladder of Self-Construction autonomy levels (bootstrap → atlas_native_24_7).
 *
 * - Pure data structure with NO provider calls, NO disk writes.
 * - Each level declares: allowed_capabilities, forbidden_capabilities,
 *   evidence_prerequisites, stop_conditions and final_runtime_owner / steady_state_runtime_owner.
 * - Atlas-native final levels expose final_runtime_owner=atlas_native,
 *   steady_state_runtime_owner=atlas_server and all autonomy dependency flags set to FALSE.
 *
 * Transitions only allowed to the next level in the ordered list (refuses skips and downgrades).
 */
final class AtlasSelfConstructionAutonomyLevelLadder
{
    public const SCHEMA = 'atlas.self_construction.autonomy_level_ladder.v1';

    public const LEVEL_BOOTSTRAP = 'bootstrap';

    public const LEVEL_ASSISTED = 'assisted';

    public const LEVEL_ATLAS_SUPERVISED = 'atlas_supervised';

    public const LEVEL_ATLAS_NATIVE_BOUNDED = 'atlas_native_bounded';

    public const LEVEL_ATLAS_NATIVE_24_7 = 'atlas_native_24_7';

    public const FINAL_OWNER_ATLAS_NATIVE = 'atlas_native';

    public const STEADY_STATE_RUNTIME_OWNER = 'atlas_server';

    public const PROMOTION_DELIVERY_THRESHOLD = 3;

    public const DEGRADATION_FAILURE_THRESHOLD = 2;

    public const PAUSED_SAFETY_THRESHOLD = 1;

    /**
     * Deterministic order — bootstrap first, atlas_native_24_7 last.
     *
     * @var list<string>
     */
    private const ORDER = [
        self::LEVEL_BOOTSTRAP,
        self::LEVEL_ASSISTED,
        self::LEVEL_ATLAS_SUPERVISED,
        self::LEVEL_ATLAS_NATIVE_BOUNDED,
        self::LEVEL_ATLAS_NATIVE_24_7,
    ];

    /**
     * @return list<string>
     */
    public function order(): array
    {
        return self::ORDER;
    }

    /**
     * @return array<string,mixed>
     */
    public function describe(string $level): array
    {
        return match ($level) {
            self::LEVEL_BOOTSTRAP => $this->bootstrap(),
            self::LEVEL_ASSISTED => $this->assisted(),
            self::LEVEL_ATLAS_SUPERVISED => $this->atlasSupervised(),
            self::LEVEL_ATLAS_NATIVE_BOUNDED => $this->atlasNativeBounded(),
            self::LEVEL_ATLAS_NATIVE_24_7 => $this->atlasNative24x7(),
            default => throw new InvalidArgumentException('unknown autonomy level: '.$level),
        };
    }

    /**
     * @return array<int, array<string,mixed>>
     */
    public function levels(): array
    {
        $levels = [];
        foreach (self::ORDER as $name) {
            $levels[] = $this->describe($name);
        }

        return $levels;
    }

    /**
     * Refuses skips and downgrades — only allows promotion to the immediately-next level.
     *
     * @return array{allowed:bool, reason:?string, from:string, to:string}
     */
    public function transition(string $from, string $to): array
    {
        if (! in_array($from, self::ORDER, true)) {
            return ['allowed' => false, 'reason' => 'unknown_source_level', 'from' => $from, 'to' => $to];
        }
        if (! in_array($to, self::ORDER, true)) {
            return ['allowed' => false, 'reason' => 'unknown_target_level', 'from' => $from, 'to' => $to];
        }
        $fromIndex = array_search($from, self::ORDER, true);
        $toIndex = array_search($to, self::ORDER, true);
        if ($toIndex < $fromIndex) {
            return ['allowed' => false, 'reason' => 'downgrade_refused', 'from' => $from, 'to' => $to];
        }
        if ($toIndex === $fromIndex) {
            return ['allowed' => false, 'reason' => 'noop_refused', 'from' => $from, 'to' => $to];
        }
        if ($toIndex - $fromIndex > 1) {
            return ['allowed' => false, 'reason' => 'level_skip_refused', 'from' => $from, 'to' => $to];
        }

        return ['allowed' => true, 'reason' => null, 'from' => $from, 'to' => $to];
    }

    /**
     * Evaluate runtime signals against thresholds and return the recommended autonomy state.
     *
     * @param  array{current_level?:string, green_deliveries?:int, failures?:int, safety_stops?:int}  $signals
     * @return array{level:string, is_paused:bool, is_degraded:bool, reasons:list<string>}
     */
    public function evaluate(array $signals): array
    {
        $currentLevel = (string) ($signals['current_level'] ?? self::LEVEL_BOOTSTRAP);
        $greenDeliveries = (int) ($signals['green_deliveries'] ?? 0);
        $failures = (int) ($signals['failures'] ?? 0);
        $safetyStops = (int) ($signals['safety_stops'] ?? 0);

        if ($safetyStops >= self::PAUSED_SAFETY_THRESHOLD) {
            return ['level' => 'paused', 'is_paused' => true, 'is_degraded' => false, 'reasons' => ['paused:safety_threshold_reached:'.$safetyStops]];
        }

        if ($failures >= self::DEGRADATION_FAILURE_THRESHOLD) {
            return ['level' => 'degraded', 'is_paused' => false, 'is_degraded' => true, 'reasons' => ['degraded:failure_threshold_reached:'.$failures]];
        }

        if ($greenDeliveries >= self::PROMOTION_DELIVERY_THRESHOLD) {
            $idx = array_search($currentLevel, self::ORDER, true);
            $nextLevel = ($idx !== false && $idx < count(self::ORDER) - 1) ? self::ORDER[$idx + 1] : $currentLevel;

            return ['level' => $nextLevel, 'is_paused' => false, 'is_degraded' => false, 'reasons' => ['promote:delivery_threshold_reached:'.$greenDeliveries]];
        }

        return ['level' => $currentLevel, 'is_paused' => false, 'is_degraded' => false, 'reasons' => ['hold:insufficient_evidence']];
    }

    /**
     * @return array<string,mixed>
     */
    private function bootstrap(): array
    {
        return [
            'schema_version' => self::SCHEMA,
            'level' => self::LEVEL_BOOTSTRAP,
            'rank' => 0,
            'description' => 'Operator hand-driven bring-up; Atlas exists but does not act autonomously.',
            'allowed_capabilities' => ['read_state', 'emit_facts', 'render_runbook'],
            'forbidden_capabilities' => [
                'self_programming', 'auto_commit', 'auto_merge', 'queue_replenishment', 'continuous_runtime',
            ],
            'evidence_prerequisites' => ['operator_invocation_log'],
            'stop_conditions' => ['operator_pause'],
            'final_runtime_owner' => 'operator',
            'steady_state_runtime_owner' => 'operator',
            'autonomy_dependencies' => $this->dependencyFlags(operator: true, claude: false, codex: false, providerNetwork: false),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function assisted(): array
    {
        return [
            'schema_version' => self::SCHEMA,
            'level' => self::LEVEL_ASSISTED,
            'rank' => 1,
            'description' => 'External AI assistant (Claude Code / Codex) drives the keyboard; Atlas plans and audits.',
            'allowed_capabilities' => ['propose_packets', 'audit_evidence', 'recommend_merges'],
            'forbidden_capabilities' => [
                'auto_commit', 'auto_merge', 'continuous_runtime', 'unattended_provider_call',
            ],
            'evidence_prerequisites' => ['external_assistant_proposal', 'operator_review'],
            'stop_conditions' => ['operator_pause', 'assistant_unavailable'],
            'final_runtime_owner' => 'external_assistant',
            'steady_state_runtime_owner' => 'external_assistant',
            'autonomy_dependencies' => $this->dependencyFlags(operator: true, claude: true, codex: true, providerNetwork: true),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function atlasSupervised(): array
    {
        return [
            'schema_version' => self::SCHEMA,
            'level' => self::LEVEL_ATLAS_SUPERVISED,
            'rank' => 2,
            'description' => 'Atlas plans and executes scoped patches; operator approves merges before stamping.',
            'allowed_capabilities' => [
                'propose_packets', 'native_implementation', 'scoped_apply', 'verification_request',
            ],
            'forbidden_capabilities' => ['auto_merge_without_operator', 'unbounded_continuous_runtime'],
            'evidence_prerequisites' => ['scoped_apply_verdict', 'verification_request_signed', 'operator_merge_approval'],
            'stop_conditions' => ['operator_pause', 'verification_failed', 'safety_stop'],
            'final_runtime_owner' => 'atlas_supervised_by_operator',
            'steady_state_runtime_owner' => self::STEADY_STATE_RUNTIME_OWNER,
            'autonomy_dependencies' => $this->dependencyFlags(operator: true, claude: false, codex: false, providerNetwork: false),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function atlasNativeBounded(): array
    {
        return [
            'schema_version' => self::SCHEMA,
            'level' => self::LEVEL_ATLAS_NATIVE_BOUNDED,
            'rank' => 3,
            'description' => 'Atlas runs the bounded cycle end-to-end; quotas and safety stops gate continuous runtime.',
            'allowed_capabilities' => [
                'continuous_runtime_bounded', 'native_implementation', 'scoped_apply',
                'verification_merge', 'learning_integration', 'auto_commit_scoped',
            ],
            'forbidden_capabilities' => ['unbounded_continuous_runtime', 'master_switch_self_flip'],
            'evidence_prerequisites' => [
                'bounded_cycle_verdict', 'verification_merge_decision', 'safety_stop_check',
            ],
            'stop_conditions' => [
                'cycle_quota_reached', 'verification_failed', 'safety_stop', 'queue_drained',
            ],
            'final_runtime_owner' => self::FINAL_OWNER_ATLAS_NATIVE,
            'steady_state_runtime_owner' => self::STEADY_STATE_RUNTIME_OWNER,
            'autonomy_dependencies' => $this->dependencyFlags(operator: false, claude: false, codex: false, providerNetwork: false),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function atlasNative24x7(): array
    {
        return [
            'schema_version' => self::SCHEMA,
            'level' => self::LEVEL_ATLAS_NATIVE_24_7,
            'rank' => 4,
            'description' => 'Atlas-native 24/7 runtime: continuous cycles, full self-construction, operator only opens app to inspect.',
            'allowed_capabilities' => [
                'continuous_runtime_24_7', 'native_implementation', 'scoped_apply',
                'verification_merge', 'learning_integration', 'auto_commit_scoped',
                'queue_replenishment', 'safety_stop_self_arm',
            ],
            'forbidden_capabilities' => ['master_switch_self_flip', 'petreo_core_self_edit'],
            'evidence_prerequisites' => [
                'continuous_cycle_verdict', 'verification_merge_decision', 'safety_stop_check', 'evidence_ledger_write',
            ],
            'stop_conditions' => ['safety_stop', 'master_switch_off', 'petreo_violation'],
            'final_runtime_owner' => self::FINAL_OWNER_ATLAS_NATIVE,
            'steady_state_runtime_owner' => self::STEADY_STATE_RUNTIME_OWNER,
            'autonomy_dependencies' => $this->dependencyFlags(operator: false, claude: false, codex: false, providerNetwork: false),
        ];
    }

    /**
     * @return array<string,bool>
     */
    private function dependencyFlags(
        bool $operator,
        bool $claude,
        bool $codex,
        bool $providerNetwork,
    ): array {
        return [
            'depends_on_operator' => $operator,
            'depends_on_claude_code' => $claude,
            'depends_on_codex' => $codex,
            'depends_on_external_provider_network' => $providerNetwork,
        ];
    }
}
