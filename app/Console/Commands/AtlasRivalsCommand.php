<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class AtlasRivalsCommand extends Command
{
    protected $signature = 'atlas:engineering:benchmark:rivals
        {action=readiness : readiness, run, run-atlas, run-claude-code, prepare, report, runbook or replay}
        {run? : Benchmark run id for replay}
        {--profile=fair-claude : Rivals benchmark profile. Only fair-claude is supported.}
        {--suite=atlas-fair-claude-v1 : Suite slug or id}
        {--workspace= : Workspace path for benchmark execution}
        {--case=* : Restrict execution to one or more case codes}
        {--tag=* : Restrict execution to cases carrying these tags}
        {--limit= : Maximum number of cases or report runs}
        {--tier= : Restrict execution to a corpus tier}
        {--domain= : Restrict execution to a corpus domain}
        {--risk= : Restrict execution to a risk profile}
        {--curation-status= : Restrict execution to a curation status}
        {--model=opus : Fair Claude model lock. Only opus is accepted.}
        {--model-policy=fixed : Fair Claude model policy. Only fixed is accepted.}
        {--test-command= : Explicit deterministic validation command}
        {--claude-code-baseline-workspace= : Separate workspace for claude-code baseline run}
        {--claude-code-baseline-binary= : Claude Code CLI binary override}
        {--claude-code-baseline-timeout=900 : Seconds to wait for Claude Code baseline run}
        {--claude-code-baseline-validation-timeout=300 : Seconds to wait for baseline deterministic validation}
        {--max-attempts=3 : Maximum Atlas repair attempts}
        {--permission=auto : auto, read, write or danger}
        {--sandbox=workspace : workspace, worktree or docker}
        {--provider-runtime=host : host, docker or auto for atlas:cli:dev execution}
        {--gate-profile=strict : Release gate profile: release, smoke, strict, advisory or off}
        {--keep-workspace : Keep isolated execution workspace after the run for debugging}
        {--no-auto-test : Disable auto-test for run and run-atlas}
        {--run-id= : Benchmark run id for replay; alias for the positional run argument}
        {--json : Print machine-readable JSON}';

    protected $description = 'Atlas Rivals: prove Atlas outperforms Claude Code CLI on your codebase.';

    private const SUPPORTED_PROFILES = ['fair-claude'];

    public function handle(): int
    {
        $profile = is_string($this->option('profile')) ? trim((string) $this->option('profile')) : 'fair-claude';

        if (! in_array($profile, self::SUPPORTED_PROFILES, true)) {
            $violation = [
                'error' => 'unsupported_profile',
                'message' => "Unsupported rivals profile: {$profile}. Supported: ".implode(', ', self::SUPPORTED_PROFILES).'.',
                'supported_profiles' => self::SUPPORTED_PROFILES,
                'requested_profile' => $profile,
            ];

            if ((bool) $this->option('json')) {
                $this->line(json_encode($violation, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

                return self::FAILURE;
            }

            $this->error($violation['message']);

            return self::FAILURE;
        }

        return $this->call('atlas:engineering:benchmark:claude-fair', $this->forwardArgs());
    }

    /**
     * @return array<string,mixed>
     */
    private function forwardArgs(): array
    {
        return array_filter([
            'action' => $this->argument('action'),
            'run' => $this->argument('run'),
            '--suite' => $this->stringOption('suite') ?: 'atlas-fair-claude-v1',
            '--workspace' => $this->stringOption('workspace'),
            '--case' => (array) $this->option('case'),
            '--tag' => (array) $this->option('tag'),
            '--limit' => $this->intOption('limit'),
            '--tier' => $this->stringOption('tier'),
            '--domain' => $this->stringOption('domain'),
            '--risk' => $this->stringOption('risk'),
            '--curation-status' => $this->stringOption('curation-status'),
            '--model' => $this->stringOption('model') ?: 'opus',
            '--model-policy' => $this->stringOption('model-policy') ?: 'fixed',
            '--test-command' => $this->stringOption('test-command'),
            '--claude-code-baseline-workspace' => $this->stringOption('claude-code-baseline-workspace'),
            '--claude-code-baseline-binary' => $this->stringOption('claude-code-baseline-binary'),
            '--claude-code-baseline-timeout' => $this->intOption('claude-code-baseline-timeout') ?: 900,
            '--claude-code-baseline-validation-timeout' => $this->intOption('claude-code-baseline-validation-timeout') ?: 300,
            '--max-attempts' => $this->intOption('max-attempts') ?: 3,
            '--permission' => $this->stringOption('permission') ?: 'auto',
            '--sandbox' => $this->stringOption('sandbox') ?: 'workspace',
            '--provider-runtime' => $this->stringOption('provider-runtime') ?: 'host',
            '--gate-profile' => $this->stringOption('gate-profile') ?: 'strict',
            '--keep-workspace' => (bool) $this->option('keep-workspace') ?: null,
            '--no-auto-test' => (bool) $this->option('no-auto-test') ?: null,
            '--run-id' => $this->stringOption('run-id'),
            '--json' => (bool) $this->option('json') ?: null,
        ], fn (mixed $value): bool => $value !== null && $value !== [] && $value !== '');
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function intOption(string $name): ?int
    {
        $value = $this->option($name);

        return is_numeric($value) ? (int) $value : null;
    }
}
