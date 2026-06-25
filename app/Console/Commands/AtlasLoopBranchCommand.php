<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\Branching\AtlasLoopCycleBranchManifest;
use App\Services\Ai\AutonomousEvolution\Branching\AtlasLoopCycleBranchMergeOrDiscard;
use App\Services\Ai\AutonomousEvolution\Branching\AtlasLoopCycleBranchOutcome;
use App\Services\Ai\AutonomousEvolution\Branching\AtlasLoopCycleBranchSpawner;
use Closure;
use Illuminate\Console\Command;

/**
 * Operator surface for loop branch lifecycle:
 *   atlas:loop:branch spawn   --parent-cycle=<id> --hypothesis=<h> [--base-commit=<sha>] [--json]
 *   atlas:loop:branch merge   --branch-id=<id> [--diff=<path>] [--json]
 *   atlas:loop:branch discard --branch-id=<id> [--json]
 *   atlas:loop:branch history [--json]
 */
final class AtlasLoopBranchCommand extends Command
{
    public const EXIT_OK = 0;

    public const EXIT_REFUSED = 1;

    public const EXIT_USAGE = 2;

    protected $signature = 'atlas:loop:branch {action : spawn|merge|discard|history}
        {--parent-cycle= : parent cycle id (spawn)}
        {--hypothesis= : branch hypothesis (spawn)}
        {--base-commit= : optional base commit SHA (spawn)}
        {--branch-id= : branch id (merge|discard)}
        {--diff= : path to diff file (merge)}
        {--json}';

    protected $description = 'Loop branch CLI (spawn | merge | discard | history).';

    public function handle(): int
    {
        $action = (string) $this->argument('action');

        return match ($action) {
            'spawn' => $this->spawn(),
            'merge' => $this->merge(),
            'discard' => $this->discard(),
            'history' => $this->history(),
            default => $this->envelope('unknown', null, null, 'unknown_subaction', self::EXIT_USAGE),
        };
    }

    private function spawn(): int
    {
        if (! $this->masterEnabled()) {
            return $this->envelope('spawn', null, null, 'loop_master_off', self::EXIT_REFUSED);
        }
        $parent = (string) ($this->option('parent-cycle') ?? '');
        $hypothesis = (string) ($this->option('hypothesis') ?? '');
        if ($parent === '' || $hypothesis === '') {
            return $this->envelope('spawn', null, null, 'missing_parent_or_hypothesis', self::EXIT_REFUSED);
        }
        $baseSha = (string) ($this->option('base-commit') ?? str_repeat('0', 40));

        $spawner = $this->spawner();
        $result = $spawner->spawn($parent, $hypothesis, $baseSha);
        if (($result['ok'] ?? false) !== true) {
            return $this->envelope('spawn', null, null, (string) ($result['refusal_reason'] ?? 'spawn_refused'), self::EXIT_REFUSED);
        }
        /** @var AtlasLoopCycleBranchManifest $manifest */
        $manifest = $result['manifest'];

        return $this->envelope('spawn', $manifest->branchId, 'open', null, self::EXIT_OK);
    }

    private function merge(): int
    {
        if (! $this->masterEnabled()) {
            return $this->envelope('merge', null, null, 'loop_master_off', self::EXIT_REFUSED);
        }
        $branchId = (string) ($this->option('branch-id') ?? '');
        if ($branchId === '') {
            return $this->envelope('merge', null, null, 'missing_branch_id', self::EXIT_REFUSED);
        }
        $manifest = $this->loadManifest($branchId);
        if ($manifest === null) {
            return $this->envelope('merge', $branchId, null, 'branch_manifest_not_found', self::EXIT_REFUSED);
        }
        $diffPath = (string) ($this->option('diff') ?? '');
        $diffContent = $diffPath !== '' && is_file($diffPath) ? (string) file_get_contents($diffPath) : '';
        $outcome = new AtlasLoopCycleBranchOutcome(success: true, diffSummary: substr($diffContent, 0, 256), diffContent: $diffContent);
        $record = $this->mergeService()->merge($manifest, $outcome);

        return $this->envelope('merge', $branchId, (string) ($record['terminal_state'] ?? 'unknown'), (string) ($record['cert_reason'] ?? '') ?: null, self::EXIT_OK);
    }

    private function discard(): int
    {
        if (! $this->masterEnabled()) {
            return $this->envelope('discard', null, null, 'loop_master_off', self::EXIT_REFUSED);
        }
        $branchId = (string) ($this->option('branch-id') ?? '');
        if ($branchId === '') {
            return $this->envelope('discard', null, null, 'missing_branch_id', self::EXIT_REFUSED);
        }
        $manifest = $this->loadManifest($branchId);
        if ($manifest === null) {
            return $this->envelope('discard', $branchId, null, 'branch_manifest_not_found', self::EXIT_REFUSED);
        }
        $record = $this->mergeService()->discard($manifest);

        return $this->envelope('discard', $branchId, (string) ($record['terminal_state'] ?? 'unknown'), null, self::EXIT_OK);
    }

    private function history(): int
    {
        $root = $this->historyRoot();
        $rows = [];
        if (is_dir($root)) {
            foreach (glob($root.'/*.json') ?: [] as $file) {
                $decoded = json_decode((string) file_get_contents($file), true);
                if (! is_array($decoded)) {
                    continue;
                }
                $manifest = (array) ($decoded['manifest'] ?? []);
                $rows[] = [
                    'parent_cycle_id' => (string) ($manifest['parent_cycle_id'] ?? ''),
                    'branch_id' => (string) ($decoded['branch_id'] ?? ($manifest['branch_id'] ?? '')),
                    'terminal_state' => (string) ($decoded['terminal_state'] ?? ''),
                    'created_at' => (string) ($manifest['created_at'] ?? ''),
                    'hypothesis' => (string) ($manifest['hypothesis'] ?? ''),
                ];
            }
        }
        $this->emitRaw(['action' => 'history', 'rows' => $rows]);

        return self::EXIT_OK;
    }

    /**
     * @return array<string,mixed>
     */
    private function spawner(): AtlasLoopCycleBranchSpawner
    {
        if (app()->bound(AtlasLoopCycleBranchSpawner::class)) {
            return app(AtlasLoopCycleBranchSpawner::class);
        }

        return new AtlasLoopCycleBranchSpawner(
            $this->branchesRoot(),
            masterEnabled: fn (): bool => $this->masterEnabled(),
        );
    }

    private function mergeService(): AtlasLoopCycleBranchMergeOrDiscard
    {
        if (app()->bound(AtlasLoopCycleBranchMergeOrDiscard::class)) {
            return app(AtlasLoopCycleBranchMergeOrDiscard::class);
        }

        return new AtlasLoopCycleBranchMergeOrDiscard(
            $this->historyRoot(),
            Closure::fromCallable(static fn (string $diff, array $manifest): array => ['accepted' => true, 'reason' => 'cli_default_gate']),
        );
    }

    private function loadManifest(string $branchId): ?AtlasLoopCycleBranchManifest
    {
        $manifestPath = $this->branchesRoot().'/'.$branchId.'/manifest.json';
        if (! is_file($manifestPath)) {
            return null;
        }
        $decoded = json_decode((string) file_get_contents($manifestPath), true);
        if (! is_array($decoded)) {
            return null;
        }

        return new AtlasLoopCycleBranchManifest(
            parentCycleId: (string) ($decoded['parent_cycle_id'] ?? ''),
            branchId: (string) ($decoded['branch_id'] ?? $branchId),
            hypothesis: (string) ($decoded['hypothesis'] ?? ''),
            baseCommitSha: (string) ($decoded['base_commit_sha'] ?? ''),
            createdAt: (string) ($decoded['created_at'] ?? ''),
            workspacePath: (string) ($decoded['workspace_path'] ?? ''),
        );
    }

    private function branchesRoot(): string
    {
        if (function_exists('config')) {
            $override = config('atlas.loop.branches.root');
            if (is_string($override) && $override !== '') {
                return $override;
            }
        }

        return (function_exists('storage_path') ? storage_path('atlas-loop/branches') : sys_get_temp_dir().'/atlas-loop/branches');
    }

    private function historyRoot(): string
    {
        return $this->branchesRoot().'/_history';
    }

    private function masterEnabled(): bool
    {
        if (function_exists('config')) {
            $v = config('atlas.loop.master_enabled');
            if ($v !== null) {
                return (bool) $v;
            }
        }

        return (bool) env('ATLAS_LOOP_MASTER_ENABLED', false);
    }

    private function envelope(string $action, ?string $branchId, ?string $terminalState, ?string $reason, int $exitCode): int
    {
        $this->emitRaw([
            'action' => $action,
            'branch_id' => $branchId,
            'terminal_state' => $terminalState,
            'reason' => $reason,
        ]);

        return $exitCode;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function emitRaw(array $payload): void
    {
        $this->getOutput()->writeln((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }
}
