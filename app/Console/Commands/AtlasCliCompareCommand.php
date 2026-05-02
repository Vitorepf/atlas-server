<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class AtlasCliCompareCommand extends Command
{
    protected $signature = 'atlas:cli:compare
        {input?* : Question or task}
        {--mode=direct}
        {--workspace= : Workspace path. Defaults to current directory}
        {--no-stream}
        {--json}';

    protected $description = 'Run explicit Claude + Codex dual-review through Atlas.';

    public function handle(): int
    {
        $input = trim(implode(' ', (array) $this->argument('input')));
        if ($input === '') {
            $input = 'Compare criticamente a tarefa atual usando Claude e Codex como motores internos do Atlas.';
        }

        return $this->call('atlas:ai:chat', array_filter([
            'input' => $input,
            '--provider' => 'claude_codex',
            '--mode' => $this->mode(),
            '--workspace' => $this->workspace(),
            '--stream' => ! (bool) $this->option('no-stream') && ! (bool) $this->option('json'),
            '--json' => (bool) $this->option('json'),
        ], fn (mixed $value): bool => $value !== null && $value !== false && $value !== ''));
    }

    private function mode(): string
    {
        $mode = (string) $this->option('mode');

        return in_array($mode, ['direct', 'plan', 'review', 'dev', 'debug', 'research'], true) ? $mode : 'direct';
    }

    private function workspace(): string
    {
        $workspace = (string) ($this->option('workspace') ?: getcwd() ?: config('atlas.ai.workdir'));
        $resolved = realpath($workspace);

        return $resolved && is_dir($resolved) ? $resolved : $workspace;
    }
}
