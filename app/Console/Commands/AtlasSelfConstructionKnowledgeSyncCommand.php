<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SelfConstruction\KnowledgeSync\AtlasKnowledgeSyncDocsDriftGate;
use App\Services\Ai\SelfConstruction\KnowledgeSync\AtlasKnowledgeSyncRequiredArtifactMap;
use Illuminate\Console\Command;
use Throwable;
use App\Console\Concerns\EmitsCanonicalJson;

/**
 * READ-ONLY CLI for the Knowledge Sync surface. Four verbs:
 *   inspect   — services + non-execution guarantees.
 *   artifacts — read candidate JSON, emit required artifacts (no commands run).
 *   gate      — read candidate + observed JSON, run docs-drift gate, print blockers.
 *   plan      — print post-merge action FACTS (artifact list with command hints).
 */
final class AtlasSelfConstructionKnowledgeSyncCommand extends Command
{
    use EmitsCanonicalJson;

    /** @var string */
    protected $signature = 'atlas:self-construction:knowledge-sync {action : inspect|artifacts|gate|plan} {--candidate=} {--observed=} {--json}';

    /** @var string */
    protected $description = 'Read-only Knowledge Sync surface: inspect / artifacts / gate / plan.';

    public function handle(): int
    {
        $action = (string) $this->argument('action');
        $payload = match ($action) {
            'inspect' => $this->inspect(),
            'artifacts' => $this->artifacts(),
            'gate' => $this->gate(),
            'plan' => $this->plan(),
            default => ['status' => 'unknown_action', 'action' => $action],
        };
        $this->line($this->encode($payload));

        return ($payload['status'] ?? 'ok') === 'ok' || ! isset($payload['status']) ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return array<string,mixed>
     */
    private function inspect(): array
    {
        return [
            'status' => 'ok',
            'verbs' => ['inspect', 'artifacts', 'gate', 'plan'],
            'services' => [
                AtlasKnowledgeSyncRequiredArtifactMap::SCHEMA,
                AtlasKnowledgeSyncDocsDriftGate::SCHEMA,
            ],
            'non_execution_guarantees' => [
                'runs_sync_command' => false,
                'runs_index_command' => false,
                'writes_storage' => false,
                'invokes_shell_or_git' => false,
                'calls_external_providers' => false,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function artifacts(): array
    {
        $candidate = $this->readJson('candidate');
        if (! is_array($candidate)) {
            return ['status' => 'usage_error', 'reason' => '--candidate JSON file required'];
        }
        $map = $this->app()->make(AtlasKnowledgeSyncRequiredArtifactMap::class)->derive($candidate);

        return ['status' => 'ok', 'artifact_map' => $map];
    }

    /**
     * @return array<string,mixed>
     */
    private function gate(): array
    {
        $candidate = $this->readJson('candidate');
        $observed = $this->readJson('observed');
        if (! is_array($candidate) || ! is_array($observed)) {
            return ['status' => 'usage_error', 'reason' => '--candidate and --observed JSON files required'];
        }
        $artifacts = $this->app()->make(AtlasKnowledgeSyncRequiredArtifactMap::class)->derive($candidate);
        $gate = $this->app()->make(AtlasKnowledgeSyncDocsDriftGate::class)->evaluate(array_merge($observed, [
            'required_artifacts' => $artifacts['required_artifacts'],
        ]));

        return ['status' => 'ok', 'artifact_map' => $artifacts, 'docs_drift_gate' => $gate];
    }

    /**
     * @return array<string,mixed>
     */
    private function plan(): array
    {
        $candidate = $this->readJson('candidate');
        if (! is_array($candidate)) {
            return ['status' => 'usage_error', 'reason' => '--candidate JSON file required'];
        }
        $artifacts = $this->app()->make(AtlasKnowledgeSyncRequiredArtifactMap::class)->derive($candidate);
        $actions = [];
        foreach ($artifacts['required_artifacts'] as $a) {
            $actions[] = [
                'artifact_id' => $a['artifact_id'],
                'command_hint' => $a['command_hint'],
                'reason' => $a['reason'],
                'note' => 'CLI does not execute — operator/orchestrator runs the command',
            ];
        }

        return ['status' => 'ok', 'post_merge_actions' => $actions];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function readJson(string $optionName): ?array
    {
        $path = (string) ($this->option($optionName) ?? '');
        if ($path === '' || ! is_file($path)) {
            return null;
        }
        try {
            $decoded = json_decode((string) file_get_contents($path), true);
        } catch (Throwable) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    /** @return \Illuminate\Contracts\Container\Container */
    private function app()
    {
        return $this->getLaravel();
    }
}
