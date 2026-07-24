<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Programming\AtlasDev\MinimaxFirst\AtlasMinimaxFirstWorkerService;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

/**
 * atlas:dev:minimax-worker:run — drop-in replacement for atlas:dev:senior-loop:run
 * when the Ap786 owner flow executor routes to provider=minimax_m27_cli.
 *
 * Receives the same structured finding + allowed-files + validation-commands that
 * Ap786OwnerFlowExecutor sends to the senior loop, runs the Codex→MiniMax pipeline,
 * and returns atlas.dev.senior_engineer_loop_execution.v1 compatible JSON on stdout.
 */
final class AtlasDevMinimaxWorkerRunCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:dev:minimax-worker:run
        {--finding-json= : JSON-encoded finding array (required)}
        {--allowed-files= : Comma-separated relative file paths the worker may modify}
        {--validation-commands= : JSON array of validation commands to run after implementation}
        {--worktree= : Absolute path to the git worktree (required)}
        {--repo-root= : Absolute path to the repository root (defaults to worktree)}
        {--max-repairs=2 : Maximum repair iterations on validation failure}
        {--dry-run : Plan only — do not write files or run validation}';

    protected $description = 'Run the Codex→MiniMax implementation worker for one bounded Atlas task';

    public function handle(AtlasMinimaxFirstWorkerService $worker): int
    {
        $findingJson = (string) $this->option('finding-json');
        $worktree    = (string) $this->option('worktree');
        $repoRoot    = (string) $this->option('repo-root');

        if ($findingJson === '') {
            $this->line(json_encode(['status' => 'blocked', 'blockers' => ['missing_finding_json']]));

            return self::FAILURE;
        }

        $finding = json_decode($findingJson, true);
        if (! is_array($finding)) {
            $this->line(json_encode(['status' => 'blocked', 'blockers' => ['invalid_finding_json']]));

            return self::FAILURE;
        }

        $allowedFilesRaw = (string) $this->option('allowed-files');
        $allowedFiles    = $allowedFilesRaw !== ''
            ? array_values(array_filter(array_map('trim', explode(',', $allowedFilesRaw))))
            : [];

        $validationRaw  = (string) $this->option('validation-commands');
        $validationCmds = [];
        if ($validationRaw !== '') {
            $decoded        = json_decode($validationRaw, true);
            $validationCmds = is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : [];
        }

        if ((bool) $this->option('dry-run')) {
            $this->line(json_encode([
                'schema_version' => AtlasMinimaxFirstWorkerService::SCHEMA,
                'status'         => 'plan_only',
                'dry_run'        => true,
                'finding_title'  => $finding['title'] ?? '',
                'allowed_files'  => $allowedFiles,
            ], JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $result = $worker->run([
            'finding'             => $finding,
            'allowed_files'       => $allowedFiles,
            'validation_commands' => $validationCmds,
            'worktree_path'       => $worktree,
            'repo_root'           => $repoRoot !== '' ? $repoRoot : $worktree,
            'max_repairs'         => max(0, (int) $this->option('max-repairs')),
        ]);

        $this->line($this->encode($result));

        return ($result['status'] ?? '') === 'completed' ? self::SUCCESS : self::FAILURE;
    }
}
