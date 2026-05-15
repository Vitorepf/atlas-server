<?php

namespace App\Services\Ai\SelfConstruction;

/**
 * Scores a merge review packet plus scope verification across a fixed
 * grid of risk factors. Pure projection — never mutates packet, scope
 * or any real artifact.
 */
final class AgentMergeReviewRiskScorer
{
    public const SCHEMA_VERSION = 'atlas.self_construction.agent_merge_review_risk_score.v1';

    public const MODE = 'read_only_agent_merge_review_risk_score';

    public const RISK_BANDS = ['low', 'medium', 'high', 'critical'];

    public const NON_EXECUTION_GUARANTEES = [
        'agent_merge_review_risk_scorer_does_not_apply_patch',
        'agent_merge_review_risk_scorer_does_not_modify_real_files',
        'agent_merge_review_risk_scorer_does_not_advance_completion_claim',
        'agent_merge_review_risk_scorer_does_not_dispatch_agent',
        'agent_merge_review_risk_scorer_does_not_write_ledger',
    ];

    /**
     * @param  array<string, mixed>  $packet
     * @param  array<string, mixed>  $scopeVerification
     * @return array<string, mixed>
     */
    public function score(array $packet, array $scopeVerification = []): array
    {
        $files = (array) data_get($packet, 'packet.files', []);
        $stats = (array) data_get($packet, 'packet.file_stats', []);
        $artifactStats = (array) data_get($packet, 'packet.artifact_stats', []);
        $verification = (array) ($scopeVerification['verification'] ?? []);

        $score = 0;
        $factors = [];

        $fileCount = (int) ($stats['file_count'] ?? count($files));
        $netLines = (int) ($stats['net_lines'] ?? 0);
        $linesAdded = (int) ($stats['lines_added_total'] ?? 0);
        $linesDeleted = (int) ($stats['lines_deleted_total'] ?? 0);
        $deletedFiles = (int) ($stats['deleted_count'] ?? 0);
        $renamedFiles = (int) ($stats['renamed_count'] ?? 0);

        $forbiddenCount = (int) ($verification['forbidden_violation_count'] ?? 0);
        $crossAxisCount = (int) ($verification['cross_axis_violation_count'] ?? 0);
        $unsafePathCount = (int) ($verification['unsafe_path_violation_count'] ?? 0);
        $outOfScopeCount = (int) ($verification['out_of_scope_count'] ?? 0);

        $factors[] = $this->factor('blast_radius_file_count', $this->blastRadiusWeight($fileCount), $fileCount);
        $factors[] = $this->factor('blast_radius_net_lines', $this->netLinesWeight($linesAdded + $linesDeleted), $linesAdded + $linesDeleted);
        $factors[] = $this->factor('deletions_signal', $deletedFiles > 0 ? min(2, $deletedFiles) : 0, $deletedFiles);
        $factors[] = $this->factor('renames_signal', $renamedFiles > 0 ? 1 : 0, $renamedFiles);
        $factors[] = $this->factor('forbidden_paths', $forbiddenCount * 3, $forbiddenCount);
        $factors[] = $this->factor('cross_axis_paths', $crossAxisCount * 4, $crossAxisCount);
        $factors[] = $this->factor('unsafe_path_prefixes', $unsafePathCount * 4, $unsafePathCount);
        $factors[] = $this->factor('out_of_scope_paths', $outOfScopeCount * 2, $outOfScopeCount);
        $factors[] = $this->factor('failing_artifacts', (int) ($artifactStats['failing_count'] ?? 0) * 2, (int) ($artifactStats['failing_count'] ?? 0));
        $factors[] = $this->factor('artifacts_missing', isset($artifactStats['artifact_count']) && (int) $artifactStats['artifact_count'] === 0 ? 1 : 0, isset($artifactStats['artifact_count']) && (int) $artifactStats['artifact_count'] === 0 ? 1 : 0);

        foreach ($factors as $factor) {
            $score += (int) $factor['weight'];
        }

        $band = $this->band($score);

        $blockers = [];
        if ($forbiddenCount > 0) {
            $blockers[] = 'forbidden_path_violation_present';
        }
        if ($crossAxisCount > 0) {
            $blockers[] = 'cross_axis_violation_present';
        }
        if ($unsafePathCount > 0) {
            $blockers[] = 'unsafe_path_prefix_present';
        }
        if ($outOfScopeCount > 0) {
            $blockers[] = 'out_of_scope_paths_present';
        }
        if ((int) ($artifactStats['failing_count'] ?? 0) > 0) {
            $blockers[] = 'failing_artifact_present';
        }
        $blockers = array_values(array_unique($blockers));

        $envelope = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $band === 'critical' ? 'agent_merge_review_risk_critical' : 'agent_merge_review_risk_scored',
            'mode' => self::MODE,
            'apply_patch_allowed' => false,
            'real_file_write_allowed' => false,
            'completion_claim_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'risk' => [
                'overall_score' => $score,
                'overall_band' => $band,
                'factors' => $factors,
                'factor_count' => count($factors),
                'blast_radius' => [
                    'file_count' => $fileCount,
                    'lines_added' => $linesAdded,
                    'lines_deleted' => $linesDeleted,
                    'net_lines' => $netLines,
                    'deleted_files' => $deletedFiles,
                    'renamed_files' => $renamedFiles,
                ],
                'blockers' => $blockers,
                'blocker_count' => count($blockers),
                'critical' => $band === 'critical' || count($blockers) > 0,
            ],
            'non_execution_guarantees' => self::NON_EXECUTION_GUARANTEES,
        ];

        $envelope['risk_hash'] = $this->hashEnvelope($envelope);

        return $envelope;
    }

    private function blastRadiusWeight(int $fileCount): int
    {
        if ($fileCount <= 1) {
            return 0;
        }
        if ($fileCount <= 5) {
            return 1;
        }
        if ($fileCount <= 20) {
            return 2;
        }
        if ($fileCount <= 50) {
            return 3;
        }

        return 4;
    }

    private function netLinesWeight(int $totalLines): int
    {
        if ($totalLines <= 20) {
            return 0;
        }
        if ($totalLines <= 100) {
            return 1;
        }
        if ($totalLines <= 500) {
            return 2;
        }
        if ($totalLines <= 2000) {
            return 3;
        }

        return 4;
    }

    private function band(int $score): string
    {
        return match (true) {
            $score >= 10 => 'critical',
            $score >= 6 => 'high',
            $score >= 3 => 'medium',
            default => 'low',
        };
    }

    /**
     * @return array{name: string, weight: int, observation: int}
     */
    private function factor(string $name, int $weight, int $observation): array
    {
        return [
            'name' => $name,
            'weight' => max(0, $weight),
            'observation' => max(0, $observation),
        ];
    }

    /**
     * @param  array<string, mixed>  $envelope
     */
    private function hashEnvelope(array $envelope): string
    {
        $copy = $envelope;
        unset($copy['risk_hash']);

        return hash('sha256', (string) json_encode($copy, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
