<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Completion;

/**
 * Atlas-native completion evidence verifier.
 *
 * Pure, deterministic, facts-only. Accepts an injected facts array and returns an envelope
 * proving Atlas-native closure of the Self-Construction OS. NO scalar scoring is emitted.
 *
 * Required proof sections (each must be present AND truthy in facts):
 *   - final_runtime_owner_atlas_native
 *   - steady_state_runtime_owner_atlas_server_or_native
 *   - autonomy_dependencies_all_false
 *   - serving_queue_health
 *   - native_worker_readiness
 *   - verification_court_readiness
 *   - merge_governor_readiness
 *   - rollback_readiness
 *   - learning_transfer_readiness
 *   - docs_health
 *   - kb_sync
 *   - code_index_readiness
 *   - multi_project_lane_readiness
 */
final class AtlasSelfConstructionAtlasNativeEvidenceVerifier
{
    public const SCHEMA = 'atlas.self_construction.atlas_native_evidence_verifier.v1';

    public const STATUS_READY = 'atlas_native_ready';

    public const STATUS_BLOCKED = 'atlas_native_blocked';

    /** @var list<string> */
    private const REQUIRED_SECTIONS = [
        'final_runtime_owner_atlas_native',
        'steady_state_runtime_owner_atlas_server_or_native',
        'autonomy_dependencies_all_false',
        'serving_queue_health',
        'native_worker_readiness',
        'verification_court_readiness',
        'merge_governor_readiness',
        'rollback_readiness',
        'learning_transfer_readiness',
        'docs_health',
        'kb_sync',
        'code_index_readiness',
        'multi_project_lane_readiness',
    ];

    /** @var list<string> */
    private const AUTONOMY_DEPENDENCY_FLAGS = [
        'depends_on_operator',
        'depends_on_claude_code',
        'depends_on_codex',
        'depends_on_external_provider_network',
    ];

    /**
     * @param  array<string,mixed>  $facts
     * @return array<string,mixed>
     */
    public function verify(array $facts): array
    {
        $blockers = [];
        $observed = [];

        // 1. final_runtime_owner = atlas_native.
        $finalOwner = (string) ($facts['final_runtime_owner'] ?? '');
        $observed['final_runtime_owner_atlas_native'] = $finalOwner === 'atlas_native';
        if ($finalOwner !== 'atlas_native') {
            $blockers[] = 'final_runtime_owner_not_atlas_native:'.$finalOwner;
        }

        // 2. steady_state_runtime_owner ∈ {atlas_server, atlas_native}.
        $steady = (string) ($facts['steady_state_runtime_owner'] ?? '');
        $observed['steady_state_runtime_owner_atlas_server_or_native'] = in_array($steady, ['atlas_server', 'atlas_native'], true);
        if (! $observed['steady_state_runtime_owner_atlas_server_or_native']) {
            $blockers[] = 'steady_state_runtime_owner_not_native_or_server:'.$steady;
        }

        // 3. autonomy dependency flags — ALL must be present AND false.
        $deps = is_array($facts['autonomy_dependencies'] ?? null) ? $facts['autonomy_dependencies'] : [];
        $allDepsFalse = true;
        foreach (self::AUTONOMY_DEPENDENCY_FLAGS as $flag) {
            if (! array_key_exists($flag, $deps)) {
                $allDepsFalse = false;
                $blockers[] = 'autonomy_dependency_missing:'.$flag;

                continue;
            }
            if ((bool) $deps[$flag] !== false) {
                $allDepsFalse = false;
                $blockers[] = 'autonomy_dependency_true:'.$flag;
            }
        }
        $observed['autonomy_dependencies_all_false'] = $allDepsFalse;

        // 4-13. Remaining facts-only readiness toggles.
        $remaining = [
            'serving_queue_health' => 'serving_queue_unhealthy',
            'native_worker_readiness' => 'native_worker_not_ready',
            'verification_court_readiness' => 'verification_court_not_ready',
            'merge_governor_readiness' => 'merge_governor_not_ready',
            'rollback_readiness' => 'rollback_not_ready',
            'learning_transfer_readiness' => 'learning_transfer_not_ready',
            'docs_health' => 'docs_unhealthy',
            'kb_sync' => 'kb_not_synced',
            'code_index_readiness' => 'code_index_not_ready',
            'multi_project_lane_readiness' => 'multi_project_lane_not_ready',
        ];
        foreach ($remaining as $section => $blocker) {
            $ready = (bool) ($facts[$section] ?? false);
            $observed[$section] = $ready;
            if (! $ready) {
                $blockers[] = $blocker;
            }
        }

        $passed = $blockers === [];

        return [
            'schema' => self::SCHEMA,
            'schema_version' => self::SCHEMA,
            'status' => $passed ? self::STATUS_READY : self::STATUS_BLOCKED,
            'passed' => $passed,
            'blockers' => $blockers,
            'required_sections' => self::REQUIRED_SECTIONS,
            'observed_sections' => $observed,
            'proof_summary' => sprintf(
                'sections_required=%d sections_observed_ready=%d blockers=%d',
                count(self::REQUIRED_SECTIONS),
                count(array_filter($observed, static fn (bool $v): bool => $v)),
                count($blockers),
            ),
        ];
    }
}
