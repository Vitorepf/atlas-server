<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\TaskGraph;

/**
 * Deterministic missing-organ task planner.
 *
 * Converts a {@see AtlasSelfConstructionTaskGraphCoverageAuditor} verdict (or the raw missing
 * / thin organ rows) into draft task packets. Pure, facts-only. NEVER enqueues; emits drafts a
 * Task Fabric or future replenisher can quality-gate.
 *
 * Each organ may carry an optional `safe_targets` block describing the implementation/test pair
 * the planner is allowed to suggest. When that block is absent or incomplete, the planner WITHHOLDS
 * the draft and reports a withheld_gap with reason — it never invents file paths.
 *
 * Inputs:
 *   $coverage = AtlasSelfConstructionTaskGraphCoverageAuditor::audit() output, OR equivalent
 *               {missing_organs:list<string>, thin_organs:list<{organ_id:string,
 *               missing_evidence_classes?:list<string>}>}.
 *   $organs   = list<{organ_id, purpose, safe_targets?:{implementation:string, test:string}}>
 *               (typically taken from {@see AtlasSelfConstructionFinalOrganMap} extended with
 *               concrete safe_targets per organ).
 *   $wave     = optional wave label assigned to every draft (default 'self_construction_coverage').
 */
final class AtlasSelfConstructionMissingOrganTaskPlanner
{
    public const SCHEMA = 'atlas.self_construction.missing_organ_task_planner.v1';

    /**
     * @param  array<string,mixed>  $coverage
     * @param  list<array<string,mixed>>  $organs
     * @param  list<string>  $liveTargets  Implementation/test files already claimed by live tasks — organs whose
     *                                     targets appear here are withheld with reason 'live_target_exists'.
     * @return array<string,mixed>
     */
    public function plan(array $coverage, array $organs, string $wave = 'self_construction_coverage', array $liveTargets = []): array
    {
        $organsById = [];
        foreach ($organs as $organ) {
            $organsById[(string) $organ['organ_id']] = $organ;
        }

        $drafts = [];
        $withheld = [];
        $drafted = [];  // dedup: organ_id → true once a draft is emitted

        $missing = array_values((array) ($coverage['missing_organs'] ?? []));
        $thin = array_values((array) ($coverage['thin_organs'] ?? []));

        foreach ($missing as $organId) {
            $organId = (string) $organId;
            $organ = $organsById[$organId] ?? null;
            if ($organ === null) {
                $withheld[] = ['organ_id' => $organId, 'reason' => 'organ_metadata_not_supplied'];
                continue;
            }
            $withReason = $this->withholdReason($organ, $liveTargets);
            if ($withReason !== null) {
                $withheld[] = ['organ_id' => $organId, 'reason' => $withReason];
                continue;
            }
            $draft = $this->makeDraft($organId, $organ, 'missing', [], $wave);
            if ($draft === null) {
                $withheld[] = ['organ_id' => $organId, 'reason' => 'safe_targets_unavailable'];
                continue;
            }
            $drafted[$organId] = true;
            $drafts[] = $draft;
        }

        foreach ($thin as $row) {
            $organId = (string) ($row['organ_id'] ?? '');
            $missingClasses = array_values(array_map('strval', (array) ($row['missing_evidence_classes'] ?? [])));
            if (isset($drafted[$organId])) {
                $withheld[] = ['organ_id' => $organId, 'reason' => 'already_drafted'];
                continue;
            }
            $organ = $organsById[$organId] ?? null;
            if ($organ === null) {
                $withheld[] = ['organ_id' => $organId, 'reason' => 'organ_metadata_not_supplied'];
                continue;
            }
            $withReason = $this->withholdReason($organ, $liveTargets);
            if ($withReason !== null) {
                $withheld[] = ['organ_id' => $organId, 'reason' => $withReason];
                continue;
            }
            $draft = $this->makeDraft($organId, $organ, 'thin', $missingClasses, $wave);
            if ($draft === null) {
                $withheld[] = ['organ_id' => $organId, 'reason' => 'safe_targets_unavailable'];
                continue;
            }
            $drafts[] = $draft;
        }

        // Stable order by task_packet_id for deterministic output.
        usort($drafts, static fn (array $a, array $b): int => strcmp($a['task_packet_id'], $b['task_packet_id']));
        usort($withheld, static fn (array $a, array $b): int => strcmp($a['organ_id'], $b['organ_id']));

        return [
            'schema' => self::SCHEMA,
            'schema_version' => self::SCHEMA,
            'status' => 'ok',
            'draft_count' => count($drafts),
            'drafts' => $drafts,
            'withheld_gaps' => $withheld,
            'proof_summary' => sprintf(
                'missing=%d thin=%d drafts=%d withheld=%d',
                count($missing),
                count($thin),
                count($drafts),
                count($withheld),
            ),
        ];
    }

    /**
     * @param  array<string,mixed>  $organ
     * @param  list<string>  $liveTargets
     * @return string|null  null = pass, non-null = withheld reason code
     */
    private function withholdReason(array $organ, array $liveTargets): ?string
    {
        if ($liveTargets !== []) {
            $safeTargets = is_array($organ['safe_targets'] ?? null) ? $organ['safe_targets'] : [];
            $impl = (string) ($safeTargets['implementation'] ?? '');
            $test = (string) ($safeTargets['test'] ?? '');
            if (($impl !== '' && in_array($impl, $liveTargets, true)) ||
                ($test !== '' && in_array($test, $liveTargets, true))) {
                return 'live_target_exists';
            }
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $organ
     * @param  list<string>  $missingClasses
     * @return array<string,mixed>|null  null ⇒ safe_targets unavailable, draft withheld
     */
    private function makeDraft(string $organId, array $organ, string $coverageKind, array $missingClasses, string $wave): array|null
    {
        $safeTargets = is_array($organ['safe_targets'] ?? null) ? $organ['safe_targets'] : [];
        $impl = (string) ($safeTargets['implementation'] ?? '');
        $test = (string) ($safeTargets['test'] ?? '');
        if ($impl === '' || $test === '' || str_contains($impl, '..') || str_contains($test, '..')) {
            return null;
        }

        $purpose = (string) ($organ['purpose'] ?? '');
        $tags = array_values(array_unique(array_merge(
            ['self_construction', $organId, 'coverage_'.$coverageKind],
            array_values(array_map('strval', (array) ($organ['required_task_tags'] ?? []))),
        )));
        $taskId = sprintf('coverage-%s-%s-v1', $organId, $coverageKind);
        $prerequisiteIds = array_values(array_map('strval', (array) ($organ['depends_on'] ?? [])));

        $acceptance = [
            sprintf('Implement %s organ scaffolding so the task graph covers it deterministically.', $organId),
            'Output is deterministic, facts-only, no scalar scoring.',
            sprintf('/opt/homebrew/bin/php artisan test %s', $test),
        ];
        if ($missingClasses !== []) {
            $acceptance[] = sprintf(
                'Surface evidence classes that were missing: %s.',
                implode(', ', $missingClasses),
            );
        }

        // Priority: missing organs are higher priority than thin; more missing evidence = higher.
        $priorityValue = $coverageKind === 'missing' ? 10 : max(1, 5 - count($missingClasses));
        $priorityReason = $coverageKind === 'missing'
            ? sprintf('organ_absent_from_task_graph:%s', $organId)
            : sprintf('organ_thin_missing_%d_evidence_classes:%s', count($missingClasses), $organId);

        $requiredProof = [
            sprintf('test_file_must_pass:%s', $test),
            'evidence_hash_required',
            'implementation_notes_required',
        ];

        return [
            'task_packet_id'     => $taskId,
            'objective'          => sprintf('Cover organ "%s" (%s gap): %s', $organId, $coverageKind, $purpose),
            'allowed_files'      => [$impl, $test],
            'scope_in'           => [$impl, $test],
            'acceptance_criteria' => $acceptance,
            'required_evidence'  => ['tests_or_gates_result', 'implementation_notes'],
            'depends_on'         => $prerequisiteIds,
            'prerequisite_ids'   => $prerequisiteIds,
            'required_proof'     => $requiredProof,
            'priority'           => ['value' => $priorityValue, 'reason' => $priorityReason],
            'wave'               => $wave,
            'tags'               => $tags,
            'rationale'          => sprintf(
                'task graph coverage gap (%s) on organ %s; missing evidence classes: %s',
                $coverageKind,
                $organId,
                $missingClasses === [] ? 'n/a' : implode(',', $missingClasses),
            ),
        ];
    }
}
