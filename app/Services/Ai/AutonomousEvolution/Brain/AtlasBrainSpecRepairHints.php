<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Brain;

/**
 * REPAIR HINTS substrate — given a list of inspector deficiencies (the keys
 * AtlasTaskPacketQualityInspector emits), return a deterministic mapping to
 * concrete repair suggestions. Inverse of the simulator: simulator says "this
 * spec would be blocked", this organ says "to unblock, do these things".
 *
 * Pure, no I/O. The hint texts are intentionally concrete (no generic
 * "improve the objective" — every hint names a field + a minimal action).
 *
 * Pétreo: réu never edits the repair table (else it'd map a deficiency to
 * "ignore it", weakening the gate's discriminating power).
 */
final class AtlasBrainSpecRepairHints
{
    public const SCHEMA = 'atlas.brain.spec_repair_hints.v1';

    /** Static, fixed table — frozen behavior, byte-stable output per input. */
    private const TABLE = [
        'missing_objective' => 'set objective to a ≥40-char string naming a real FQCN or .php file',
        'empty_allowed_files' => 'add at least one repo-relative .php path to allowed_files',
        'missing_acceptance_criteria' => 'add at least one acceptance line shaped like "php artisan test --filter=<OneTest> passes"',
        'missing_required_evidence' => 'set required_evidence to ["tests_or_gates_result"]',
        'bare_directory_in_allowed_files' => 'replace each bare directory with concrete .php files (the committer rejects bare dirs)',
        'forbidden_self_target_in_allowed_files' => 'remove any allowed_file under FORBIDDEN_SELF_TARGETS — the brain cannot author against its own gates',
        'test_evidence_without_test_in_allowed_files' => 'add a tests/Unit/.../<X>Test.php path to allowed_files (the worker needs to author the proof)',
        'permanent_human_or_external_provider_dependency' => 'remove the human/operator/provider runtime-dependency clause from objective + acceptance',
        'default_worktree_or_sandbox_policy' => 'use the shared-main + narrow allowed_files default (no worktree/sandbox unless exceptional)',
        'content_truncated' => 'finish the objective sentence — it ends with an ellipsis / truncation marker',
        'vague_objective' => 'name the concrete FQCN/file/`php artisan` invocation in the objective (≥40 chars + a real anchor)',
        'acceptance_not_runnable' => 'phrase the acceptance with a runnable signal (php artisan test / runs / executes)',
        'acceptance_coverage_mismatch' => 'mention at least one allowed_file basename in the acceptance text',
        'blind_orphan_wiring_proxy' => 'change "wire the orphan into the live flow" → offer a disposition choice (wire-or-delete)',
        'scope_repair_removed_required_target_from_allowed_files' => 'restore the required target to allowed_files or reframe the acceptance away from it',
        'scope_incoherent' => 'add the uncovered allowed_files to scope_in (advisory; not blocking)',
    ];

    /**
     * Map each deficiency to its concrete repair hint. Unknown deficiencies are skipped silently so a future
     * inspector deficiency doesn't crash the brain — it just won't have a hint yet (call this a fallback to
     * "originate fresh" implicitly).
     *
     * @param  list<string>  $deficiencies
     * @return array{schema:string, hints:list<array{deficiency:string, repair:string}>, unknown:list<string>}
     */
    public function repair(array $deficiencies): array
    {
        $hints = [];
        $unknown = [];
        foreach ($deficiencies as $def) {
            $def = trim((string) $def);
            if ($def === '') {
                continue;
            }
            if (isset(self::TABLE[$def])) {
                $hints[] = ['deficiency' => $def, 'repair' => self::TABLE[$def]];
            } else {
                $unknown[] = $def;
            }
        }

        return [
            'schema' => self::SCHEMA,
            'hints' => $hints,
            'unknown' => array_values(array_unique($unknown)),
        ];
    }
}
