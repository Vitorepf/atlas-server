<?php

namespace App\Console\Commands;

use App\Services\Ai\Programming\AtlasProgrammingSurfaceCommandBuilder;
use Illuminate\Console\Command;

class AtlasCliFixCommand extends Command
{
    protected $signature = 'atlas:cli:fix
        {description?* : What to fix. Empty means Atlas should infer from the current quality gate}
        {--workspace= : Workspace path. Defaults to current directory}
        {--provider= : Force claude_cli, codex_cli or claude_codex}
        {--max-iterations=3}
        {--auto-test : Run detected tests after each attempt}
        {--allow-write : Confirm scoped workspace writes for this run}
        {--plan-only : Run preflight and print the repair execution plan without calling provider}
        {--json : Print machine-readable JSON}';

    protected $description = 'Run Atlas dev repair loop for a known failing test, bug or quality gate.';

    public function handle(AtlasProgrammingSurfaceCommandBuilder $commands): int
    {
        return $this->call('atlas:cli:dev', $commands->repairDevArguments(
            workspace: $this->workspace(),
            provider: $this->provider(),
            description: trim(implode(' ', (array) $this->argument('description'))),
            maxIterations: (int) $this->option('max-iterations'),
            autoTest: (bool) $this->option('auto-test'),
            allowWrite: (bool) $this->option('allow-write'),
            planOnly: (bool) $this->option('plan-only'),
            json: (bool) $this->option('json'),
        ));
    }

    private function workspace(): string
    {
        $workspace = (string) ($this->option('workspace') ?: getcwd() ?: config('atlas.ai.workdir'));
        $resolved = realpath($workspace);

        return $resolved && is_dir($resolved) ? $resolved : $workspace;
    }

    private function provider(): ?string
    {
        $provider = $this->option('provider');

        return is_string($provider) && $provider !== '' ? $provider : null;
    }
}
