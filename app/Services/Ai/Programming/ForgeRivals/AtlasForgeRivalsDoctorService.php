<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\ForgeRivals;

use App\Console\Commands\AtlasForgeRivalsCommand;
use App\Services\Ai\Programming\ForgeRivals\Corpus\AtlasForgeRivalsProviderArenaCorpusService;
use App\Services\Ai\Programming\WorkspaceHygieneService;
use Symfony\Component\Process\Process;

/**
 * Atlas Forge Rivals · Doctor.
 *
 * Read-only environment probe: ensures the operator can safely run the
 * canonical battery on this machine. Never invokes a provider.
 *
 * Checks (each ok/info/blocker):
 *   - source repo is a git repo and `git worktree` is supported
 *   - source repo has no tracked python bytecode (.pyc / __pycache__)
 *   - PYTHONDONTWRITEBYTECODE planned for subprocesses
 *   - Atlas-rivals runs root is writable
 *   - canonical command + dispatcher class load
 *   - `claude` and `codex` CLI binaries discoverable (info-only)
 */
final class AtlasForgeRivalsDoctorService
{
    public function __construct(
        private readonly AtlasForgeRivalsRunPathResolver $paths,
        private readonly WorkspaceHygieneService $hygiene,
        private readonly ?AtlasForgeRivalsProviderArenaCorpusService $corpus = null,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function check(): array
    {
        $repoRoot = function_exists('base_path') ? rtrim(base_path(), '/') : getcwd();
        $repoRoot = is_string($repoRoot) && $repoRoot !== '' ? $repoRoot : '/Users/vitorepf/develop/Atlas/atlas-server';

        $checks = [];
        $blockers = [];

        // 1. git availability
        $gitVersion = $this->probe('git', ['--version']);
        $checks['git_available'] = [
            'ok' => $gitVersion['ok'],
            'value' => trim($gitVersion['stdout']),
        ];
        if (! $gitVersion['ok']) {
            $blockers[] = 'git_binary_missing';
        }

        // 2. source repo is git worktree
        $isGit = is_dir($repoRoot.'/.git');
        $checks['source_repo_is_git'] = ['ok' => $isGit, 'value' => $repoRoot];
        if (! $isGit) {
            $blockers[] = 'source_repo_not_git';
        }

        // 3. git worktree subcommand
        $worktreeProbe = $this->probe('git', ['-C', $repoRoot, 'worktree', 'list', '--porcelain']);
        $checks['git_worktree_supported'] = ['ok' => $worktreeProbe['ok'], 'value' => $worktreeProbe['ok'] ? 'yes' : ($worktreeProbe['stderr'] ?: 'no')];
        if (! $worktreeProbe['ok']) {
            $blockers[] = 'git_worktree_unsupported';
        }

        // 4. tracked python bytecode (canon hard blocker per spec)
        $bytecode = $this->hygiene->trackedPythonBytecode($repoRoot);
        $checks['tracked_python_bytecode'] = [
            'ok' => ! ($bytecode['tracked_count'] > 0),
            'value' => $bytecode['tracked_count'],
            'resolution_command' => $bytecode['resolution_command'] ?? '',
            'sample' => $bytecode['tracked_sample'] ?? [],
        ];
        if ($bytecode['tracked_count'] > 0) {
            $blockers[] = 'tracked_python_bytecode_present';
        }

        // 5. PYTHONDONTWRITEBYTECODE policy declared
        $checks['python_dont_write_bytecode_policy'] = [
            'ok' => true,
            'value' => 'PYTHONDONTWRITEBYTECODE=1 injected by RunRealService for all subprocesses',
        ];

        // 6. Atlas-rivals runs root writable
        $root = $this->paths->rootDirectory();
        $rootOk = is_dir($root) ? is_writable($root) : @mkdir($root, 0o755, true);
        $checks['runs_root_writable'] = ['ok' => (bool) $rootOk, 'value' => $root];
        if (! $rootOk) {
            $blockers[] = 'runs_root_not_writable';
        }

        // 7. canonical command + dispatcher load
        $checks['canonical_classes_loadable'] = [
            'ok' => class_exists(AtlasForgeRivalsCommand::class)
                && class_exists(AtlasForgeRivalsActionDispatcher::class),
            'value' => 'AtlasForgeRivalsCommand + AtlasForgeRivalsActionDispatcher',
        ];

        // 8. provider binaries discoverable (info-only)
        $claudeBinary = $this->configuredProviderBinary('claude_cli', 'claude');
        $codexBinary = $this->configuredProviderBinary('codex_cli', 'codex');
        $claudeProbe = $this->probeProviderBinary($claudeBinary);
        $codexProbe = $this->probeProviderBinary($codexBinary);
        $checks['provider_binary_claude'] = [
            'ok' => $claudeProbe['ok'],
            'value' => trim($claudeProbe['stdout']) ?: 'not found (real-mode runs will fail until installed)',
            'severity' => $claudeProbe['ok'] ? 'info' : 'warning',
            'configured_binary' => $claudeBinary,
        ];
        $checks['provider_binary_codex'] = [
            'ok' => $codexProbe['ok'],
            'value' => trim($codexProbe['stdout']) ?: 'not found (real-mode runs will fail until installed)',
            'severity' => $codexProbe['ok'] ? 'info' : 'warning',
            'configured_binary' => $codexBinary,
        ];

        // 9. corpus registry loadable (only when DI provided it — keeps unit
        // tests instantiating this service via `new` without container green).
        $corpusInfo = $this->probeCorpus();
        $checks['corpus_registry_loadable'] = [
            'ok' => $corpusInfo['ok'],
            'value' => $corpusInfo['value'],
            'severity' => $corpusInfo['ok'] ? 'info' : 'warning',
        ];
        if ($corpusInfo['error'] !== null) {
            $checks['corpus_registry_loadable']['error'] = $corpusInfo['error'];
        }

        // 10. policy declaration that external_rivals_certification stays
        // operator-approval-gated regardless of doctor output.
        $checks['external_rivals_policy_safe'] = [
            'ok' => true,
            'value' => 'external_rivals_certification=blocked_requires_operator_approval',
            'severity' => 'info',
        ];

        return [
            'status' => $blockers === [] ? 'ok' : 'blocked',
            'blockers' => $blockers,
            'checks' => $checks,
            'repo_root' => $repoRoot,
            'runs_root' => $root,
            'separated_from_external_rivals_certification' => true,
            'next_command' => $blockers === []
                ? 'php artisan atlas:forge:rivals setup --source-ref=HEAD --json'
                : 'fix blockers and re-run: php artisan atlas:forge:rivals doctor --json',
        ];
    }

    /**
     * @return array{ok:bool,value:string,error:?string}
     */
    private function probeCorpus(): array
    {
        if ($this->corpus === null) {
            return [
                'ok' => true,
                'value' => 'corpus_registry not injected (unit-test seam) — runtime always wires it',
                'error' => null,
            ];
        }
        try {
            $cases = $this->corpus->cases();
            $count = count($cases);

            return [
                'ok' => $count > 0,
                'value' => $count.' arena case(s) registered',
                'error' => $count > 0 ? null : 'corpus_empty',
            ];
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'value' => 'corpus_registry_threw',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * @param  list<string>  $args
     * @return array{ok:bool,stdout:string,stderr:string,exit_code:int}
     */
    private function probe(string $bin, array $args): array
    {
        try {
            $proc = new Process(array_merge([$bin], $args));
            $proc->setTimeout(10);
            $proc->run();

            return [
                'ok' => $proc->isSuccessful(),
                'stdout' => (string) $proc->getOutput(),
                'stderr' => (string) $proc->getErrorOutput(),
                'exit_code' => (int) $proc->getExitCode(),
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'stdout' => '', 'stderr' => $e->getMessage(), 'exit_code' => 127];
        }
    }

    private function configuredProviderBinary(string $provider, string $fallback): string
    {
        $configured = trim((string) config('atlas.ai.providers.'.$provider.'.binary', ''));

        return $configured !== '' ? $configured : $fallback;
    }

    /**
     * @return array{ok:bool,stdout:string,stderr:string,exit_code:int}
     */
    private function probeProviderBinary(string $binary): array
    {
        if (str_contains($binary, '/')) {
            $ok = is_file($binary) && is_executable($binary);

            return [
                'ok' => $ok,
                'stdout' => $ok ? $binary : '',
                'stderr' => $ok ? '' : 'configured provider binary is not executable: '.$binary,
                'exit_code' => $ok ? 0 : 127,
            ];
        }

        return $this->probe('which', [$binary]);
    }
}
