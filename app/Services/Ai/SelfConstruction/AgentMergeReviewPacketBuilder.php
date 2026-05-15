<?php

namespace App\Services\Ai\SelfConstruction;

use Carbon\CarbonImmutable;

/**
 * Builds a normalized merge review packet from a synthetic diff manifest
 * and an artifact manifest. Pure projection — never applies a patch,
 * never touches real files, never advances a slice pointer.
 */
final class AgentMergeReviewPacketBuilder
{
    public const SCHEMA_VERSION = 'atlas.self_construction.agent_merge_review_packet.v1';

    public const MODE = 'read_only_agent_merge_review_packet';

    public const CHANGE_KINDS = ['added', 'modified', 'deleted', 'renamed'];

    public const NON_EXECUTION_GUARANTEES = [
        'agent_merge_review_packet_does_not_apply_patch',
        'agent_merge_review_packet_does_not_modify_real_files',
        'agent_merge_review_packet_does_not_advance_completion_claim',
        'agent_merge_review_packet_does_not_persist_claim',
        'agent_merge_review_packet_does_not_write_ledger',
        'agent_merge_review_packet_does_not_dispatch_agent',
    ];

    /**
     * @param  array<string, mixed>  $diffManifest
     * @param  array<string, mixed>  $artifactManifest
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function build(array $diffManifest, array $artifactManifest = [], array $context = []): array
    {
        $packetId = (string) ($context['packet_id'] ?? 'agent-merge-review-packet-unknown');
        $claimId = (string) ($context['claim_id'] ?? 'agent-merge-review-claim-unknown');
        $taskPacketId = (string) ($context['task_packet_id'] ?? 'agent-merge-review-task-packet-unknown');
        $generatedAt = (string) ($context['generated_at'] ?? CarbonImmutable::now()->toIso8601String());

        $files = $this->normalizeFiles((array) ($diffManifest['files'] ?? []));
        $stats = $this->fileStats($files);
        $artifacts = $this->normalizeArtifacts((array) ($artifactManifest['artifacts'] ?? []));
        $artifactStats = $this->artifactStats($artifacts);

        $envelope = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => 'agent_merge_review_packet_ready',
            'mode' => self::MODE,
            'apply_patch_allowed' => false,
            'real_file_write_allowed' => false,
            'completion_claim_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'generated_at' => $generatedAt,
            'packet' => [
                'packet_id' => $packetId,
                'claim_id' => $claimId,
                'task_packet_id' => $taskPacketId,
                'source' => (string) ($diffManifest['source'] ?? 'synthetic_diff_manifest'),
                'base_revision' => (string) ($diffManifest['base_revision'] ?? 'baseline-unknown'),
                'head_revision' => (string) ($diffManifest['head_revision'] ?? 'head-unknown'),
                'files' => $files,
                'file_stats' => $stats,
                'artifacts' => $artifacts,
                'artifact_stats' => $artifactStats,
            ],
            'non_execution_guarantees' => self::NON_EXECUTION_GUARANTEES,
        ];

        $envelope['packet_hash'] = $this->hashEnvelope($envelope);

        return $envelope;
    }

    /**
     * @param  array<int, mixed>  $rawFiles
     * @return list<array<string, mixed>>
     */
    private function normalizeFiles(array $rawFiles): array
    {
        $normalized = [];
        foreach ($rawFiles as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $path = isset($entry['path']) ? (string) $entry['path'] : null;
            if ($path === null || $path === '') {
                continue;
            }
            $kind = (string) ($entry['change_kind'] ?? 'modified');
            if (! in_array($kind, self::CHANGE_KINDS, true)) {
                $kind = 'modified';
            }
            $additions = max(0, (int) ($entry['lines_added'] ?? 0));
            $deletions = max(0, (int) ($entry['lines_deleted'] ?? 0));
            $hunks = max(0, (int) ($entry['hunk_count'] ?? 0));
            $previousPath = isset($entry['previous_path']) && $entry['previous_path'] !== ''
                ? (string) $entry['previous_path']
                : null;
            $contentHash = isset($entry['content_hash']) && $entry['content_hash'] !== ''
                ? (string) $entry['content_hash']
                : null;

            $normalized[] = [
                'path' => $path,
                'previous_path' => $previousPath,
                'change_kind' => $kind,
                'lines_added' => $additions,
                'lines_deleted' => $deletions,
                'hunk_count' => $hunks,
                'content_hash' => $contentHash,
            ];
        }

        usort($normalized, static fn ($a, $b) => strcmp((string) $a['path'], (string) $b['path']));

        return array_values($normalized);
    }

    /**
     * @param  list<array<string, mixed>>  $files
     * @return array<string, int>
     */
    private function fileStats(array $files): array
    {
        $byKind = array_fill_keys(self::CHANGE_KINDS, 0);
        $additions = 0;
        $deletions = 0;
        $hunks = 0;
        foreach ($files as $file) {
            $byKind[$file['change_kind']]++;
            $additions += (int) $file['lines_added'];
            $deletions += (int) $file['lines_deleted'];
            $hunks += (int) $file['hunk_count'];
        }

        return [
            'file_count' => count($files),
            'added_count' => $byKind['added'],
            'modified_count' => $byKind['modified'],
            'deleted_count' => $byKind['deleted'],
            'renamed_count' => $byKind['renamed'],
            'lines_added_total' => $additions,
            'lines_deleted_total' => $deletions,
            'hunk_count_total' => $hunks,
            'net_lines' => $additions - $deletions,
        ];
    }

    /**
     * @param  array<int, mixed>  $rawArtifacts
     * @return list<array<string, mixed>>
     */
    private function normalizeArtifacts(array $rawArtifacts): array
    {
        $normalized = [];
        foreach ($rawArtifacts as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $kind = (string) ($entry['kind'] ?? 'unknown');
            $name = (string) ($entry['name'] ?? 'artifact-unknown');
            $status = (string) ($entry['status'] ?? 'unknown');
            $evidenceHash = isset($entry['evidence_hash']) && $entry['evidence_hash'] !== ''
                ? (string) $entry['evidence_hash']
                : null;

            $normalized[] = [
                'kind' => $kind,
                'name' => $name,
                'status' => $status,
                'evidence_hash' => $evidenceHash,
                'passing' => $status === 'passed' || $status === 'green',
            ];
        }

        usort($normalized, static fn ($a, $b) => strcmp((string) $a['kind'].'/'.(string) $a['name'], (string) $b['kind'].'/'.(string) $b['name']));

        return array_values($normalized);
    }

    /**
     * @param  list<array<string, mixed>>  $artifacts
     * @return array<string, int|bool>
     */
    private function artifactStats(array $artifacts): array
    {
        $passing = 0;
        $failing = 0;
        foreach ($artifacts as $artifact) {
            if ($artifact['passing'] === true) {
                $passing++;
            } else {
                $failing++;
            }
        }

        return [
            'artifact_count' => count($artifacts),
            'passing_count' => $passing,
            'failing_count' => $failing,
            'all_passing' => count($artifacts) > 0 && $failing === 0,
        ];
    }

    /**
     * @param  array<string, mixed>  $envelope
     */
    private function hashEnvelope(array $envelope): string
    {
        $copy = $envelope;
        unset($copy['packet_hash']);

        return hash('sha256', (string) json_encode($copy, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
