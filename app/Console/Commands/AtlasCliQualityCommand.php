<?php

namespace App\Console\Commands;

use App\Services\Ai\Cli\AtlasCliQualityService;
use Illuminate\Console\Command;

class AtlasCliQualityCommand extends Command
{
    protected $signature = 'atlas:cli:quality
        {--workspace= : Workspace path. Defaults to current directory}
        {--run-tests : Run detected or provided test command}
        {--command= : Explicit test command}
        {--yes : Approve test command execution}
        {--json : Print machine-readable JSON}';

    protected $description = 'Run Atlas native quality/completion gate for the current workspace.';

    public function handle(AtlasCliQualityService $quality): int
    {
        $payload = $quality->evaluate(
            workspace: $this->workspace(),
            runTests: (bool) $this->option('run-tests'),
            testCommand: is_string($this->option('command')) ? $this->option('command') : null,
            approved: (bool) $this->option('yes'),
        );

        if ((bool) $this->option('json')) {
            $this->line(json_encode(array_merge(['ok' => $payload['status'] !== 'failed'], $payload), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $payload['status'] === 'failed' ? self::FAILURE : self::SUCCESS;
        }

        $this->render($payload);

        return $payload['status'] === 'failed' ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function render(array $payload): void
    {
        $this->newLine();
        $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Atlas Quality Gate</>', (string) $payload['status']);
        $this->components->twoColumnDetail('Workspace', (string) $payload['workspace']);
        $this->components->twoColumnDetail('Dirty files', (string) $payload['dirty_count']);
        $this->components->twoColumnDetail('Diff hash', (string) ($payload['diff_hash'] ?: '-'));

        $this->newLine();
        $this->line('<fg=bright-blue;options=bold>Gates</>');
        $this->table(
            ['gate', 'status', 'detail'],
            collect((array) $payload['quality_gates'])
                ->map(fn (array $gate): array => [$gate['name'], $gate['status'], $gate['detail']])
                ->all(),
        );

        if ((array) $payload['changed_files'] !== []) {
            $this->line('Arquivos alterados:');
            foreach ((array) $payload['changed_files'] as $file) {
                $this->line('  - '.$file);
            }
        }

        $packet = (array) $payload['completion_packet'];
        $this->newLine();
        $this->line('<fg=bright-blue;options=bold>Completion Packet</>');
        $this->line((string) $packet['summary']);

        if ((array) ($packet['risks'] ?? []) !== []) {
            $this->line('Riscos:');
            foreach ((array) $packet['risks'] as $risk) {
                $this->line('  - '.$risk);
            }
        }
    }

    private function workspace(): string
    {
        $workspace = (string) ($this->option('workspace') ?: getcwd() ?: config('atlas.ai.workdir'));
        $resolved = realpath($workspace);

        return $resolved && is_dir($resolved) ? $resolved : $workspace;
    }
}
