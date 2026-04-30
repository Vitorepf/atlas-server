<?php

namespace App\Console\Commands;

use App\Services\Ai\Cli\AtlasCliDogfoodService;
use App\Support\AtlasSecurity;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

class AtlasCliReleaseCommand extends Command
{
    protected $signature = 'atlas:cli:release
        {--release-version= : Release version, for example v2.0.0}
        {--channel=stable : stable or beta}
        {--workspace= : Workspace path. Defaults to current directory}
        {--no-final : Do not run atlas final --strict inside the release gate}
        {--refresh-providers : Refresh provider health when running final}
        {--skip-dogfood : Skip the dogfood gate for pre-release validation}
        {--allow-dirty : Allow release readiness in a dirty worktree}
        {--preflight : Structural validation only; skipped final/dogfood gates are allowed}
        {--create-tag : Create an annotated git tag when every gate passes}
        {--json : Print machine-readable JSON}';

    protected $description = 'Run the Atlas CLI release readiness gate and optionally create a version tag.';

    public function handle(AtlasCliDogfoodService $dogfood): int
    {
        $checks = $this->checks($dogfood);
        $status = $this->statusFromChecks($checks);
        $tag = null;

        if ((bool) $this->option('create-tag') && (bool) $this->option('preflight')) {
            $checks[] = [
                'name' => 'git_tag',
                'status' => 'failed',
                'detail' => 'Tag bloqueada em --preflight. Rode release final sem skips.',
            ];
            $status = 'failed';
        } elseif ((bool) $this->option('create-tag')) {
            if ($status !== 'passed') {
                $checks[] = [
                    'name' => 'git_tag',
                    'status' => 'failed',
                    'detail' => 'Tag bloqueada porque release readiness nao passou.',
                ];
                $status = 'failed';
            } else {
                $tag = $this->createTag((string) $this->releaseVersion());
                $checks[] = $tag;
                $status = $this->statusFromChecks($checks);
            }
        }

        $payload = [
            'ok' => $status === 'passed',
            'status' => $status,
            'generated_at' => now()->toJSON(),
            'version' => $this->releaseVersion(),
            'channel' => $this->channel(),
            'preflight' => (bool) $this->option('preflight'),
            'workspace' => $this->workspace(),
            'checks' => $checks,
            'tag' => $tag,
            'next_actions' => $this->nextActions($checks),
        ];
        $payload = AtlasSecurity::redactArray($payload);

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $payload['ok'] ? self::SUCCESS : self::FAILURE;
        }

        $this->render($payload);

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function checks(AtlasCliDogfoodService $dogfood): array
    {
        $version = $this->releaseVersion();
        $dirty = $this->process(['git', 'status', '--short']);
        $dirtyLines = collect(explode("\n", trim($dirty['stdout'])))
            ->map(fn (string $line): string => trim($line))
            ->filter()
            ->values()
            ->all();
        $final = $this->finalCheck();
        $dogfoodReport = (bool) $this->option('skip-dogfood')
            ? ['status' => 'skipped', 'message' => 'Dogfood gate pulado explicitamente.']
            : $dogfood->report($this->workspace(), 3, requireReal: true);

        return [
            [
                'name' => 'version',
                'status' => $version && preg_match('/^v?\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/', $version) === 1 ? 'passed' : 'failed',
                'detail' => $version ? "Versao {$version}." : 'Informe atlas release --version=vMAJOR.MINOR.PATCH.',
            ],
            [
                'name' => 'worktree',
                'status' => $dirtyLines === [] || (bool) $this->option('allow-dirty') ? 'passed' : 'failed',
                'detail' => $dirtyLines === [] ? 'Worktree limpa.' : count($dirtyLines).' mudanca(s) no worktree; permitido apenas por --allow-dirty.',
                'metadata' => [
                    'dirty_count' => count($dirtyLines),
                    'dirty_preview' => array_slice($dirtyLines, 0, 25),
                    'dirty_truncated_count' => max(0, count($dirtyLines) - 25),
                    'allow_dirty' => (bool) $this->option('allow-dirty'),
                ],
            ],
            [
                'name' => 'final_product_docs',
                'status' => $this->anyPathExists((array) config('atlas.cli.final_product_doc_paths', [])) ? 'passed' : 'failed',
                'detail' => 'Documento unico de produto final precisa existir.',
                'paths' => array_values((array) config('atlas.cli.final_product_doc_paths', [])),
            ],
            [
                'name' => 'release_checklist',
                'status' => $this->anyPathExists((array) config('atlas.cli.release_checklist_paths', [])) ? 'passed' : 'failed',
                'detail' => 'Checklist de release precisa existir.',
                'paths' => array_values((array) config('atlas.cli.release_checklist_paths', [])),
            ],
            [
                'name' => 'ci_workflow',
                'status' => File::exists(base_path('.github/workflows/atlas-cli.yml')) ? 'passed' : 'failed',
                'detail' => 'CI obrigatorio precisa validar testes, diff check e atlas final --strict.',
                'path' => base_path('.github/workflows/atlas-cli.yml'),
            ],
            [
                'name' => 'dogfood',
                'status' => (bool) $this->option('skip-dogfood') ? 'skipped' : ($dogfoodReport['status'] ?? 'failed'),
                'detail' => (bool) $this->option('skip-dogfood')
                    ? 'Dogfood pulado por --skip-dogfood.'
                    : 'Dogfood final precisa cobrir 3 dias de uso real, cenarios obrigatorios e nenhuma falha bloqueante.',
                'metadata' => $dogfoodReport,
            ],
            [
                'name' => 'final_readiness',
                'status' => $final['status'],
                'detail' => $final['detail'],
                'metadata' => $final,
            ],
        ];
    }

    /**
     * @return array{status:string,detail:string,exit_code?:int,stdout?:string,stderr?:string,command?:array<int,string>}
     */
    private function finalCheck(): array
    {
        if ((bool) $this->option('no-final')) {
            return [
                'status' => 'skipped',
                'detail' => 'atlas final --strict pulado por --no-final.',
            ];
        }

        $command = [PHP_BINARY, 'artisan', 'atlas:cli:final', '--strict', '--json', '--workspace='.$this->workspace()];
        if ((bool) $this->option('refresh-providers')) {
            $command[] = '--refresh-providers';
        }

        $process = new Process($command, base_path(), AtlasSecurity::processEnv(profile: 'internal'));
        $process->setTimeout(1200);
        $process->run();
        $stdout = trim(AtlasSecurity::redactString($process->getOutput()));
        $stderr = trim(AtlasSecurity::redactString($process->getErrorOutput()));

        return [
            'status' => $process->isSuccessful() ? 'passed' : 'failed',
            'detail' => $process->isSuccessful() ? 'atlas final --strict passou.' : 'atlas final --strict falhou.',
            'exit_code' => $process->getExitCode() ?? self::FAILURE,
            'stdout' => $stdout,
            'stderr' => $stderr,
            'command' => AtlasSecurity::redactCommand($command),
            'command_display' => AtlasSecurity::commandLineForDisplay($command),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function createTag(string $version): array
    {
        $exists = $this->process(['git', 'rev-parse', '--verify', '--quiet', "refs/tags/{$version}"]);
        if ($exists['exit_code'] === 0) {
            return [
                'name' => 'git_tag',
                'status' => 'failed',
                'detail' => "Tag {$version} ja existe.",
            ];
        }

        $tag = $this->process(['git', 'tag', '-a', $version, '-m', "Atlas CLI {$version}"]);

        return [
            'name' => 'git_tag',
            'status' => $tag['exit_code'] === 0 ? 'passed' : 'failed',
            'detail' => $tag['exit_code'] === 0 ? "Tag {$version} criada." : 'Falha ao criar tag.',
            'metadata' => $tag,
        ];
    }

    /**
     * @param  array<int,string>  $command
     * @return array{exit_code:int,stdout:string,stderr:string}
     */
    private function process(array $command): array
    {
        $process = new Process($command, base_path(), AtlasSecurity::processEnv(profile: 'internal'));
        $process->setTimeout(600);
        $process->run();

        return [
            'exit_code' => $process->getExitCode() ?? self::FAILURE,
            'stdout' => trim(AtlasSecurity::redactString($process->getOutput())),
            'stderr' => trim(AtlasSecurity::redactString($process->getErrorOutput())),
            'command' => AtlasSecurity::redactCommand($command),
            'command_display' => AtlasSecurity::commandLineForDisplay($command),
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $checks
     */
    private function statusFromChecks(array $checks): string
    {
        $statuses = collect($checks)->pluck('status');

        return match (true) {
            $statuses->contains('failed') => 'failed',
            $statuses->contains('needs_review') => 'needs_review',
            $statuses->contains('skipped') && ! (bool) $this->option('preflight') => 'needs_review',
            default => 'passed',
        };
    }

    /**
     * @param  array<int,string>  $paths
     */
    private function anyPathExists(array $paths): bool
    {
        return collect($paths)
            ->filter(fn (mixed $path): bool => is_string($path) && $path !== '')
            ->contains(fn (string $path): bool => File::exists($path));
    }

    /**
     * @param  array<int,array<string,mixed>>  $checks
     * @return array<int,string>
     */
    private function nextActions(array $checks): array
    {
        return collect($checks)
            ->reject(fn (array $check): bool => ($check['status'] ?? null) === 'passed' || (($check['status'] ?? null) === 'skipped' && (bool) $this->option('preflight')))
            ->map(fn (array $check): string => match ($check['name'] ?? null) {
                'version' => 'Rode atlas release --version=v2.0.0.',
                'worktree' => 'Finalize, commite ou use --allow-dirty apenas para pre-release local.',
                'dogfood' => 'Rode atlas dogfood run para smoke limpo e registre uso real ate atlas dogfood report --strict passar.',
                'final_readiness' => 'Rode atlas final --strict --refresh-providers e corrija os gates. Use --preflight apenas para validacao estrutural.',
                default => 'Corrija o gate '.$check['name'].'.',
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function render(array $payload): void
    {
        $this->newLine();
        $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Atlas CLI Release</>', (string) $payload['status']);
        $this->components->twoColumnDetail('Version', (string) ($payload['version'] ?? '-'));
        $this->components->twoColumnDetail('Channel', (string) $payload['channel']);
        $this->components->twoColumnDetail('Preflight', (bool) $payload['preflight'] ? 'sim' : 'nao');

        $this->table(
            ['gate', 'status', 'detalhe'],
            collect($payload['checks'])->map(fn (array $check): array => [
                $check['name'] ?? '-',
                $check['status'] ?? '-',
                $check['detail'] ?? '-',
            ])->all(),
        );

        if ($payload['next_actions'] !== []) {
            $this->newLine();
            $this->line('Proximas acoes:');
            foreach ($payload['next_actions'] as $action) {
                $this->line('  - '.$action);
            }
        }
    }

    private function stringOption(string $key): ?string
    {
        $value = $this->option($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function releaseVersion(): ?string
    {
        return $this->stringOption('release-version');
    }

    private function channel(): string
    {
        $channel = (string) $this->option('channel');

        return in_array($channel, ['stable', 'beta'], true) ? $channel : 'stable';
    }

    private function workspace(): string
    {
        $workspace = (string) ($this->option('workspace') ?: getcwd() ?: config('atlas.ai.workdir'));
        $resolved = realpath($workspace);

        return $resolved && is_dir($resolved) ? $resolved : $workspace;
    }
}
