<?php

namespace App\Services\Ai\SelfConstruction\ControlPlane;

/**
 * Builds a deterministic preview envelope for expected diff artifacts.
 * It never reads or writes the real diff and never applies patches.
 */
final class AgentControlPlaneDiffArtifactPreviewBuilder
{
    public const SCHEMA_VERSION = 'atlas.self_construction.agent_control_plane_diff_artifact_preview.v1';

    public const MODE = 'read_only_agent_control_plane_diff_artifact_preview';

    /** @return array<string, mixed> */
    public function preview(array $workspacePlan, array $manifestPlan = []): array
    {
        $paths = (array) ($manifestPlan['output_paths'] ?? $workspacePlan['write_set'] ?? []);

        $seen = [];
        $artifacts = [];
        $duplicatePathCount = 0;
        $invalidPathCount = 0;

        foreach ($paths as $path) {
            if (! is_string($path) || trim($path) === '') {
                $invalidPathCount++;

                continue;
            }
            $normalized = trim($path);
            if (isset($seen[$normalized])) {
                $duplicatePathCount++;

                continue;
            }
            $seen[$normalized] = true;

            $artifacts[] = [
                'path' => $normalized,
                'expected_diff_kind' => $this->kind($normalized),
                'preview_hash' => hash('sha256', $normalized.'|diff-preview'),
                'real_diff_read_allowed' => false,
                'patch_apply_allowed' => false,
            ];
        }

        // Deterministic ordering: the same path set in any input order yields the same artifact
        // list and, by extension, the same diff_preview_hash.
        usort($artifacts, static fn (array $a, array $b): int => strcmp($a['path'], $b['path']));

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'status' => $artifacts === [] ? 'diff_preview_empty' : 'diff_preview_ready',
            'workspace_plan_id' => (string) ($workspacePlan['workspace_plan_id'] ?? ''),
            'artifact_count' => count($artifacts),
            'duplicate_path_count' => $duplicatePathCount,
            'invalid_path_count' => $invalidPathCount,
            'artifacts' => $artifacts,
            'real_diff_read_allowed' => false,
            'patch_apply_allowed' => false,
            'real_file_write_allowed' => false,
            'dispatch_allowed' => false,
            'provider_call_allowed' => false,
            'token_spend_allowed' => false,
            'ledger_write_allowed' => false,
            'self_programming_allowed' => false,
        ];
        $payload['diff_preview_hash'] = $this->stableHash($payload);

        return $payload;
    }

    private function kind(string $path): string
    {
        if (str_ends_with(strtolower($path), '.md')) {
            return 'documentation_change';
        }
        if (str_contains($path, 'tests/')) {
            return 'test_change';
        }
        if (str_ends_with(strtolower($path), '.php')) {
            return 'php_change';
        }

        return 'artifact_change';
    }

    private function stableHash(array $payload): string
    {
        unset($payload['diff_preview_hash']);

        return hash('sha256', (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
