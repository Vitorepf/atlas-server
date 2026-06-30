<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Completion;

/**
 * Final-closure regression gate: blocks the final verdict when any ORDINARY progress path
 * marks a non_atlas actor as `steady_state_required`. Bootstrap, visibility and emergency
 * labels remain ADVISORY ONLY when final owner is atlas_native.
 *
 * Pure, facts-only, deterministic — NO disk / DB / provider calls.
 *
 * Expected facts shape:
 * {
 *   final_runtime_owner: string,
 *   paths: [
 *     { id: string, kind?: 'ordinary'|'bootstrap'|'visibility'|'emergency',
 *       label?: string, steady_state_required: [..non_atlas actor strings..] }
 *   ]
 * }
 *
 * "non_atlas actor" = any actor string NOT in {atlas_native, atlas_server}.
 */
final class AtlasSelfConstructionHumanDependencyRegressionGate
{
    public const SCHEMA = 'atlas.self_construction.human_dependency_regression_gate.v1';

    public const STATUS_PASSED = 'passed';

    public const STATUS_BLOCKED = 'blocked';

    /** @var list<string> */
    public const ALLOWED_EXCEPTION_LABELS = ['bootstrap', 'visibility', 'emergency'];

    /** @var list<string> */
    public const ATLAS_NATIVE_ACTORS = ['atlas_native', 'atlas_server'];

    /**
     * Actors that indicate a pasted-session, manual-recovery, or non-Atlas-native execution
     * workflow. These emit a named blocker distinct from generic non-atlas actors so operators
     * and Maestro can distinguish "ordinary human dependency" from "pasted-session anti-pattern".
     *
     * @var list<string>
     */
    public const PASTED_SESSION_ANTIPATTERNS = [
        'pasted_session',
        'paste_session',
        'session_paste',
        'manual_recovery',
        'non_atlas_native_execution',
        'human_session_paste',
        'pasted_context',
    ];

    /**
     * @param  array<string,mixed>  $facts
     * @return array<string,mixed>
     */
    public function check(array $facts): array
    {
        $blockers = [];
        $inspectedPaths = [];

        $finalOwner = (string) ($facts['final_runtime_owner'] ?? '');
        if ($finalOwner !== 'atlas_native') {
            $blockers[] = 'final_runtime_owner_not_atlas_native:'.$finalOwner;
        }

        $paths = is_array($facts['paths'] ?? null) ? $facts['paths'] : [];
        foreach ($paths as $path) {
            if (! is_array($path)) {
                continue;
            }
            $id = (string) ($path['id'] ?? 'unknown');
            $kind = (string) ($path['kind'] ?? 'ordinary');
            $label = (string) ($path['label'] ?? '');
            $steadyStateRequired = array_values(array_map('strval', (array) ($path['steady_state_required'] ?? [])));
            $nonAtlas = array_values(array_filter(
                $steadyStateRequired,
                fn (string $actor): bool => ! in_array($actor, self::ATLAS_NATIVE_ACTORS, true),
            ));

            $isAdvisoryException = in_array($kind, self::ALLOWED_EXCEPTION_LABELS, true)
                || in_array($label, self::ALLOWED_EXCEPTION_LABELS, true);

            $inspectedPaths[] = [
                'id' => $id,
                'kind' => $kind,
                'label' => $label,
                'non_atlas_steady_state_actors' => $nonAtlas,
                'advisory_exception_applied' => $isAdvisoryException && $finalOwner === 'atlas_native',
            ];

            if ($nonAtlas === []) {
                continue;
            }
            if ($isAdvisoryException && $finalOwner === 'atlas_native') {
                continue;
            }

            foreach ($nonAtlas as $actor) {
                if (in_array($actor, self::PASTED_SESSION_ANTIPATTERNS, true)) {
                    $blockers[] = sprintf('pasted_session_recovery_in_ordinary_path:path=%s:actor=%s', $id, $actor);
                } else {
                    $blockers[] = sprintf('steady_state_non_atlas_actor:path=%s:actor=%s', $id, $actor);
                }
            }
        }

        $passed = $blockers === [];

        return [
            'schema' => self::SCHEMA,
            'schema_version' => self::SCHEMA,
            'status' => $passed ? self::STATUS_PASSED : self::STATUS_BLOCKED,
            'passed' => $passed,
            'blockers' => $blockers,
            'allowed_exception_labels' => self::ALLOWED_EXCEPTION_LABELS,
            'inspected_paths' => $inspectedPaths,
            'proof_summary' => sprintf(
                'final_owner=%s paths=%d blockers=%d',
                $finalOwner,
                count($inspectedPaths),
                count($blockers),
            ),
        ];
    }
}
