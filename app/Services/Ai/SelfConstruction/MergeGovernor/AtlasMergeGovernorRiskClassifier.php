<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\MergeGovernor;

/**
 * Pure Self-Construction service that classifies a completed release candidate by BLAST RADIUS before
 * any mainline admission decision.
 *
 * INPUT: release-candidate facts:
 *   { changed_files, touched_organs, risk_classification, verification_result, rollback_plan,
 *     project_lane:{project_id, allowed_scope_roots}, scope_deviations }
 *
 * OUTPUT: { schema, risk_level ∈ {low,medium,high,blocked}, reasons:list<string>, summary:array }
 *
 * INVARIANTS:
 *   - DETERMINISTIC: identical input ⇒ byte-identical envelope.
 *   - NO scalar score / rank. NO provider call. NO shell. NO git. NO storage mutation.
 *   - BLOCKERS:
 *       * scope_deviations not empty
 *       * verification_result.passed !== true
 *       * rollback_plan missing or missing 'mode'
 *       * forbidden core organ touched (FORBIDDEN_ORGANS)
 *       * project lane mismatch (changed_files outside project_lane.allowed_scope_roots)
 *   - DOCS-ONLY (every file under docs/ or with .md/.txt extension) ⇒ low
 *   - CORE_ORGAN touched (FORBIDDEN_ORGANS) ⇒ blocked (forbidden) OR high if not forbidden but
 *     core-adjacent organs (CORE_ADJACENT_ORGANS)
 *   - Otherwise — medium
 */
final class AtlasMergeGovernorRiskClassifier
{
    public const SCHEMA = 'atlas.mergegovernor.risk_classification.v1';

    public const RISK_LOW = 'low';

    public const RISK_MEDIUM = 'medium';

    public const RISK_HIGH = 'high';

    public const RISK_BLOCKED = 'blocked';

    /** Organs whose mutation is FORBIDDEN to any auto-promoted release candidate. */
    public const FORBIDDEN_ORGANS = ['Constitution', 'MasterSwitch', 'WorkspaceMaterializer'];

    /** Organs that are core-adjacent — touching them escalates the risk to high. */
    public const CORE_ADJACENT_ORGANS = ['Merge Governor', 'Verification Court', 'Task Fabric'];

    /**
     * @param  array{
     *     changed_files?:list<string>,
     *     touched_organs?:list<string>,
     *     risk_classification?:string,
     *     verification_result?:array{passed?:bool},
     *     rollback_plan?:array{mode?:string},
     *     project_lane?:array{project_id?:string, allowed_scope_roots?:list<string>},
     *     scope_deviations?:list<array<string,mixed>>
     * }  $candidate
     * @return array{schema:string, risk_level:string, reasons:list<string>, summary:array<string,mixed>}
     */
    public function classify(array $candidate): array
    {
        $changed = is_array($candidate['changed_files'] ?? null) ? array_values(array_map('strval', $candidate['changed_files'])) : [];
        $organs = is_array($candidate['touched_organs'] ?? null) ? array_values(array_map('strval', $candidate['touched_organs'])) : [];
        $verification = is_array($candidate['verification_result'] ?? null) ? $candidate['verification_result'] : [];
        $rollback = is_array($candidate['rollback_plan'] ?? null) ? $candidate['rollback_plan'] : [];
        $lanePresent = array_key_exists('project_lane', $candidate) && is_array($candidate['project_lane']);
        $lane = $lanePresent ? $candidate['project_lane'] : [];
        $deviations = is_array($candidate['scope_deviations'] ?? null) ? array_values($candidate['scope_deviations']) : [];
        $riskClassification = (string) ($candidate['risk_classification'] ?? '');
        $taskEvidenceRef = (string) ($candidate['task_evidence_ref'] ?? '');

        $reasons = [];
        $blocked = false;

        if ($changed === []) {
            $reasons[] = 'empty_changed_files';
            $blocked = true;
        }
        if (! $lanePresent) {
            $reasons[] = 'project_lane_missing';
            $blocked = true;
        }
        if ($riskClassification !== '' && ! in_array($riskClassification, [self::RISK_LOW, self::RISK_MEDIUM, self::RISK_HIGH, self::RISK_BLOCKED], true)) {
            $reasons[] = 'unknown_risk_classification:'.$riskClassification;
            $blocked = true;
        }
        if ($taskEvidenceRef === '') {
            $reasons[] = 'missing_task_evidence_ref';
            $blocked = true;
        }

        if ($deviations !== []) {
            $reasons[] = 'scope_deviations_present:'.count($deviations);
            $blocked = true;
        }
        if (($verification['passed'] ?? null) !== true) {
            $reasons[] = 'verification_not_passed';
            $blocked = true;
        }
        if (! isset($rollback['mode']) || (string) $rollback['mode'] === '') {
            $reasons[] = 'rollback_plan_missing';
            $blocked = true;
        }
        $forbiddenHit = array_values(array_intersect($organs, self::FORBIDDEN_ORGANS));
        if ($forbiddenHit !== []) {
            $reasons[] = 'forbidden_organ_touched:'.implode(',', $forbiddenHit);
            $blocked = true;
        }
        $laneRoots = is_array($lane['allowed_scope_roots'] ?? null) ? array_map('strval', $lane['allowed_scope_roots']) : [];
        if ($laneRoots !== []) {
            $escaped = [];
            foreach ($changed as $f) {
                if (! $this->insideAnyRoot($f, $laneRoots)) {
                    $escaped[] = $f;
                }
            }
            if ($escaped !== []) {
                $reasons[] = 'project_lane_mismatch:'.count($escaped);
                $blocked = true;
            }
        }

        if ($blocked) {
            sort($reasons, SORT_STRING);

            return $this->envelope(self::RISK_BLOCKED, $reasons, $changed, $organs);
        }

        $coreAdjacent = array_values(array_intersect($organs, self::CORE_ADJACENT_ORGANS));
        $isDocsOnly = $changed !== [] && $this->everyFileIsDocs($changed);
        if ($coreAdjacent !== []) {
            $reasons[] = 'core_adjacent_organ_touched:'.implode(',', $coreAdjacent);
            $risk = self::RISK_HIGH;
        } elseif ($isDocsOnly) {
            $reasons[] = 'docs_only_change';
            $risk = self::RISK_LOW;
        } else {
            $reasons[] = 'service_or_test_change';
            $risk = self::RISK_MEDIUM;
        }

        sort($reasons, SORT_STRING);

        return $this->envelope($risk, $reasons, $changed, $organs);
    }

    /**
     * @param  list<string>  $reasons
     * @param  list<string>  $changed
     * @param  list<string>  $organs
     * @return array{schema:string, risk_level:string, reasons:list<string>, summary:array<string,mixed>}
     */
    private function envelope(string $risk, array $reasons, array $changed, array $organs): array
    {
        return [
            'schema' => self::SCHEMA,
            'risk_level' => $risk,
            'reasons' => $reasons,
            'summary' => [
                'changed_file_count' => count($changed),
                'touched_organ_count' => count($organs),
            ],
        ];
    }

    /**
     * @param  list<string>  $laneRoots
     */
    private function insideAnyRoot(string $path, array $laneRoots): bool
    {
        foreach ($laneRoots as $root) {
            $root = rtrim($root, '/');
            if ($root !== '' && (str_starts_with($path, $root.'/') || $path === $root)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $files
     */
    private function everyFileIsDocs(array $files): bool
    {
        foreach ($files as $f) {
            $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
            $isDocs = str_starts_with($f, 'docs/') || in_array($ext, ['md', 'txt', 'rst'], true);
            if (! $isDocs) {
                return false;
            }
        }

        return true;
    }
}
