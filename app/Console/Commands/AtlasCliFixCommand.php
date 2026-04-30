<?php

namespace App\Console\Commands;

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
        {--json : Print machine-readable JSON}';

    protected $description = 'Run Atlas dev repair loop for a known failing test, bug or quality gate.';

    public function handle(): int
    {
        $description = trim(implode(' ', (array) $this->argument('description')));
        $task = $description !== ''
            ? "Corrija: {$description}"
            : 'Corrija o ultimo teste falho, bug ou quality gate detectado neste workspace. Primeiro inspecione o estado atual, depois aplique a menor correcao segura.';

        return $this->call('atlas:cli:dev', array_filter([
            'task' => [$task],
            '--workspace' => $this->workspace(),
            '--provider' => $this->provider(),
            '--permission' => 'write',
            '--allow-write' => (bool) $this->option('allow-write'),
            '--auto-test' => (bool) $this->option('auto-test'),
            '--complete' => true,
            '--max-iterations' => (string) max(1, min(10, (int) $this->option('max-iterations'))),
            '--json' => (bool) $this->option('json'),
        ], fn (mixed $value): bool => $value !== null && $value !== false && $value !== ''));
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
