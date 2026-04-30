<?php

namespace App\Console\Commands;

use App\Services\Ai\Cli\AtlasCliDoctorService;
use App\Support\AtlasSecurity;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;

class AtlasCliFinalCommand extends Command
{
    protected $signature = 'atlas:cli:final
        {--workspace= : Workspace path. Defaults to current directory}
        {--run-tests : Run detected test command through the doctor}
        {--refresh-providers : Refresh provider health before checking}
        {--strict : Return failure unless every final-product block passes}
        {--json : Print machine-readable JSON}';

    protected $description = 'Verify Atlas CLI final-product readiness against the B0-B8 implementation plan.';

    public function handle(AtlasCliDoctorService $doctor): int
    {
        $workspace = $this->workspace();
        $doctorPayload = $doctor->diagnose(
            workspace: $workspace,
            refreshProviders: (bool) $this->option('refresh-providers'),
            runTests: (bool) $this->option('run-tests') || (bool) $this->option('strict'),
        );

        $blocks = $this->blocks($doctorPayload);
        $status = collect($blocks)->contains(fn (array $block): bool => ($block['status'] ?? null) !== 'passed')
            ? 'needs_review'
            : 'passed';

        $payload = [
            'ok' => $status === 'passed',
            'status' => $status,
            'generated_at' => now()->toJSON(),
            'workspace' => $workspace,
            'blocks' => $blocks,
            'doctor' => [
                'status' => data_get($doctorPayload, 'readiness.status'),
                'score' => data_get($doctorPayload, 'readiness.score'),
                'next_actions' => data_get($doctorPayload, 'readiness.next_actions', []),
            ],
        ];
        $payload = AtlasSecurity::redactArray($payload);

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $this->exitCode($payload);
        }

        $this->render($payload);

        return $this->exitCode($payload);
    }

    /**
     * @param  array<string,mixed>  $doctorPayload
     * @return array<string,array<string,mixed>>
     */
    private function blocks(array $doctorPayload): array
    {
        return [
            'B0_glossary' => $this->block(
                File::exists(dirname(base_path()).'/docs/atlas-glossary.md')
                    && File::exists(dirname(base_path()).'/AtlasVault/00-constituicao/atlas-glossary.md'),
                'Glossario canonico existe no docs/ e no Vault constitucional.',
            ),
            'B1_debug_research' => $this->commandBlock(['atlas:ai:chat'], ['debug', 'research']),
            'B2_dev_repair_loop' => $this->commandBlock(['atlas:cli:dev', 'atlas:cli:fix']),
            'B3_trace_tool_events' => $this->tableCommandBlock(['ai_tool_events'], ['atlas:cli:trace']),
            'B4_permission_sessions' => $this->tableCommandBlock(['ai_permission_sessions'], ['atlas:cli:permissions']),
            'B5_memory_deltas' => $this->tableCommandBlock(['ai_memory_deltas'], ['atlas:cli:memory']),
            'B6_router_compare' => $this->tableCommandBlock(['ai_router_decisions'], ['atlas:cli:compare']),
            'B7_terminal_tui' => $this->commandBlock(['atlas:cli:tui', 'atlas:cli:dashboard']),
            'B8_distribution' => $this->commandBlock(['atlas:cli:version', 'atlas:cli:update', 'atlas:cli:rollback', 'atlas:cli:bootstrap', 'atlas:cli:doctor']),
            'product_hardening' => $this->productHardeningBlock(),
            'release_preflight' => $this->releasePreflightBlock(),
            'final_doctor' => $this->block(
                data_get($doctorPayload, 'readiness.status') === 'passed',
                'atlas doctor readiness precisa estar passed.',
                ['score' => data_get($doctorPayload, 'readiness.score')],
            ),
        ];
    }

    /**
     * @param  array<int,string>  $commands
     * @param  array<int,string>  $launcherTokens
     * @return array<string,mixed>
     */
    private function commandBlock(array $commands, array $launcherTokens = []): array
    {
        $registered = $this->registeredCommands();
        $missing = array_values(array_filter($commands, fn (string $command): bool => ! in_array($command, $registered, true)));
        $launcherMissing = array_values(array_filter($launcherTokens, fn (string $token): bool => ! str_contains($this->launcher(), $token)));

        return $this->block($missing === [] && $launcherMissing === [], 'Comandos registrados e launcher cobre os atalhos esperados.', [
            'missing_commands' => $missing,
            'missing_launcher_tokens' => $launcherMissing,
        ]);
    }

    /**
     * @param  array<int,string>  $tables
     * @param  array<int,string>  $commands
     * @return array<string,mixed>
     */
    private function tableCommandBlock(array $tables, array $commands): array
    {
        $missingTables = array_values(array_filter($tables, fn (string $table): bool => ! Schema::hasTable($table)));
        $command = $this->commandBlock($commands);

        return $this->block($missingTables === [] && $command['status'] === 'passed', 'Tabelas e comandos do bloco existem.', [
            'missing_tables' => $missingTables,
            'command_check' => $command,
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function productHardeningBlock(): array
    {
        $commands = $this->commandBlock(['atlas:cli:dogfood', 'atlas:cli:release'], ['dogfood', 'release']);
        $finalDocs = $this->existingPaths((array) config('atlas.cli.final_product_doc_paths', []));
        $releaseDocs = $this->existingPaths((array) config('atlas.cli.release_checklist_paths', []));
        $ci = File::exists(base_path('.github/workflows/atlas-cli.yml'));

        return $this->block(
            $commands['status'] === 'passed' && $finalDocs !== [] && $releaseDocs !== [] && $ci,
            'Hardening de produto precisa cobrir dogfood, release, docs finais e CI.',
            [
                'command_check' => $commands,
                'final_product_docs' => $finalDocs,
                'release_checklists' => $releaseDocs,
                'ci_workflow' => $ci ? base_path('.github/workflows/atlas-cli.yml') : null,
            ],
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function releasePreflightBlock(): array
    {
        $command = [
            PHP_BINARY,
            'artisan',
            'atlas:cli:release',
            '--release-version=v2.0.0',
            '--preflight',
            '--no-final',
            '--skip-dogfood',
            '--allow-dirty',
            '--json',
            '--workspace='.$this->workspace(),
        ];

        $process = new Process($command, base_path(), AtlasSecurity::processEnv(profile: 'internal'));
        $process->setTimeout(180);
        $process->run();

        $stdout = trim(AtlasSecurity::redactString($process->getOutput()));
        $stderr = trim(AtlasSecurity::redactString($process->getErrorOutput()));
        $decoded = json_decode($stdout, true);
        $passed = $process->isSuccessful()
            && is_array($decoded)
            && ($decoded['status'] ?? null) === 'passed';

        return $this->block(
            $passed,
            $passed
                ? 'Release preflight estrutural passa sem chamar final recursivo nem dogfood real.'
                : 'Release preflight estrutural precisa passar antes de declarar produto final.',
            [
                'exit_code' => $process->getExitCode() ?? self::FAILURE,
                'status' => is_array($decoded) ? ($decoded['status'] ?? null) : null,
                'command' => AtlasSecurity::redactCommand($command),
                'command_display' => AtlasSecurity::commandLineForDisplay($command),
                'stdout_excerpt' => mb_substr($stdout, 0, 2000),
                'stderr_excerpt' => mb_substr($stderr, 0, 2000),
            ],
        );
    }

    /**
     * @param  array<string,mixed>  $metadata
     * @return array<string,mixed>
     */
    private function block(bool $passed, string $detail, array $metadata = []): array
    {
        return [
            'status' => $passed ? 'passed' : 'needs_review',
            'detail' => $detail,
            'metadata' => $metadata,
        ];
    }

    /**
     * @return array<int,string>
     */
    private function registeredCommands(): array
    {
        return array_keys($this->getApplication()?->all() ?? []);
    }

    private function launcher(): string
    {
        $path = base_path('bin/atlas');

        return File::exists($path) ? File::get($path) : '';
    }

    /**
     * @param  array<int,string>  $paths
     * @return array<int,string>
     */
    private function existingPaths(array $paths): array
    {
        return collect($paths)
            ->filter(fn (mixed $path): bool => is_string($path) && $path !== '' && File::exists($path))
            ->values()
            ->all();
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function render(array $payload): void
    {
        $this->newLine();
        $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Atlas CLI Final</>', (string) $payload['status']);
        $this->components->twoColumnDetail('Workspace', (string) $payload['workspace']);
        $this->components->twoColumnDetail('Doctor', (string) data_get($payload, 'doctor.status').' / '.(string) data_get($payload, 'doctor.score'));

        $this->newLine();
        $this->table(
            ['bloco', 'status', 'detalhe'],
            collect($payload['blocks'])->map(fn (array $block, string $name): array => [
                $name,
                $block['status'] ?? '-',
                $block['detail'] ?? '-',
            ])->values()->all(),
        );
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function exitCode(array $payload): int
    {
        return $payload['status'] === 'passed' || ! (bool) $this->option('strict')
            ? self::SUCCESS
            : self::FAILURE;
    }

    private function workspace(): string
    {
        $workspace = (string) ($this->option('workspace') ?: getcwd() ?: config('atlas.ai.workdir'));
        $resolved = realpath($workspace);

        return $resolved && is_dir($resolved) ? $resolved : $workspace;
    }
}
