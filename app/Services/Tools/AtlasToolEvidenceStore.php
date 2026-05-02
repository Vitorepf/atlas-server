<?php

namespace App\Services\Tools;

use App\Models\AtlasToolArtifact;
use App\Models\AtlasToolFinding;
use App\Models\AtlasToolRun;
use App\Support\AtlasSecurity;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

class AtlasToolEvidenceStore
{
    public function __construct(
        private readonly AtlasToolRegistryService $registry,
        private readonly AtlasToolResultNormalizer $normalizer,
    ) {}

    /**
     * @param  array<string,mixed>  $payload
     * @param  array<string,mixed>  $context
     */
    public function recordExternalToolResult(string $toolSlug, string $workspace, array $payload, array $context = []): ?AtlasToolRun
    {
        if (! Schema::hasTable('atlas_tool_runs')) {
            return null;
        }

        $workspace = realpath($workspace) ?: $workspace;
        $definition = $this->registry->definition($toolSlug);
        $normalized = $this->normalizer->normalize($toolSlug, $payload);

        $run = AtlasToolRun::query()->create([
            'tool_definition_id' => $definition?->id,
            'tool_slug' => $toolSlug,
            'surface' => (string) ($context['surface'] ?? 'engineering'),
            'workspace_hash' => hash('sha256', $workspace),
            'workspace' => $workspace,
            'run_context_type' => $context['run_context_type'] ?? null,
            'run_context_id' => $context['run_context_id'] ?? null,
            'status' => (string) ($payload['status'] ?? 'unknown'),
            'required' => (bool) ($payload['required'] ?? false),
            'failure_policy' => (string) ($payload['failure_policy'] ?? $definition?->default_failure_policy ?? 'advisory'),
            'policy_decision' => (string) ($payload['policy_decision'] ?? 'allowed'),
            'command_hash' => isset($payload['command']) ? hash('sha256', json_encode($payload['command'], JSON_UNESCAPED_SLASHES) ?: '') : null,
            'exit_code' => isset($payload['exit_code']) && is_numeric($payload['exit_code']) ? (int) $payload['exit_code'] : null,
            'started_at' => $context['started_at'] ?? now(),
            'finished_at' => $context['finished_at'] ?? now(),
            'duration_ms' => (int) ($payload['duration_ms'] ?? 0),
            'summary_json' => $normalized['summary'],
            'normalized_result_json' => $normalized,
            'policy_decision_json' => (array) ($payload['policy_decision_json'] ?? []),
            'metadata_json' => [
                'source' => $context['source'] ?? 'external_result',
                'category' => $payload['category'] ?? null,
                'reason' => $payload['reason'] ?? null,
                'command' => isset($payload['command']) ? AtlasSecurity::redactCommand((array) $payload['command']) : null,
                ...((array) ($context['metadata'] ?? [])),
            ],
        ]);

        foreach ((array) ($payload['artifact_paths'] ?? []) as $type => $path) {
            if (is_string($path)) {
                $this->attachPath($run, (string) $type, $path);
            }
        }

        foreach ($normalized['findings'] as $finding) {
            $this->recordFinding($run, $finding);
        }

        return $run->refresh();
    }

    public function attachText(AtlasToolRun $run, string $type, string $filename, string $content): AtlasToolArtifact
    {
        $root = storage_path('app/atlas-tool-runs/'.$run->id);
        File::ensureDirectoryExists($root);
        $path = $root.'/'.$filename;
        File::put($path, AtlasSecurity::redactString($content));

        return $this->attachPath($run, $type, $path);
    }

    public function attachPath(AtlasToolRun $run, string $type, string $path): AtlasToolArtifact
    {
        $real = realpath($path) ?: $path;
        $artifact = AtlasToolArtifact::query()->create([
            'tool_run_id' => $run->id,
            'type' => $type,
            'path' => $real,
            'filename' => basename($real),
            'mime_type' => $this->mimeType($real),
            'size_bytes' => File::isFile($real) ? File::size($real) : 0,
            'sha256' => File::isFile($real) ? hash_file('sha256', $real) : hash('sha256', $real),
            'is_redacted' => true,
            'preview_json' => $this->preview($real),
        ]);

        if ($type === 'stdout' && $run->stdout_artifact_id === null) {
            $run->forceFill(['stdout_artifact_id' => $artifact->id])->save();
        }

        if ($type === 'stderr' && $run->stderr_artifact_id === null) {
            $run->forceFill(['stderr_artifact_id' => $artifact->id])->save();
        }

        return $artifact;
    }

    /**
     * @param  array<string,mixed>  $finding
     */
    private function recordFinding(AtlasToolRun $run, array $finding): AtlasToolFinding
    {
        return AtlasToolFinding::query()->create([
            'tool_run_id' => $run->id,
            'rule_id' => $finding['rule_id'] ?? null,
            'title' => (string) ($finding['title'] ?? 'Tool finding'),
            'message' => $finding['message'] ?? null,
            'severity' => (string) ($finding['severity'] ?? 'medium'),
            'confidence' => $finding['confidence'] ?? null,
            'file_path' => $finding['file_path'] ?? null,
            'line' => $finding['line'] ?? null,
            'end_line' => $finding['end_line'] ?? null,
            'fingerprint' => $finding['fingerprint'] ?? null,
            'blocks_resolved' => (bool) ($finding['blocks_resolved'] ?? false),
            'status' => 'open',
            'metadata_json' => (array) ($finding['metadata'] ?? []),
        ]);
    }

    private function mimeType(string $path): ?string
    {
        return str_ends_with($path, '.json') ? 'application/json' : (str_ends_with($path, '.txt') ? 'text/plain' : null);
    }

    /**
     * @return array<string,mixed>
     */
    private function preview(string $path): array
    {
        if (! File::isFile($path)) {
            return [];
        }

        $content = File::get($path);

        return [
            'excerpt' => mb_substr(AtlasSecurity::redactString($content), 0, 2000),
            'truncated' => mb_strlen($content) > 2000,
        ];
    }
}
