<?php

namespace App\Services\Tools;

use App\Models\AtlasToolDefinition;
use App\Models\AtlasToolInstallation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

class AtlasToolRegistryService
{
    public function __construct(private readonly AtlasToolDefinitionCatalog $catalog) {}

    public function syncSeedDefinitions(): int
    {
        if (! Schema::hasTable('atlas_tool_definitions')) {
            return 0;
        }

        $count = 0;
        foreach ($this->catalog->definitions() as $definition) {
            AtlasToolDefinition::query()->updateOrCreate(
                ['slug' => $definition['slug']],
                $definition,
            );
            $count++;
        }

        return $count;
    }

    /**
     * @return Collection<int,AtlasToolDefinition>
     */
    public function definitions(): Collection
    {
        $this->syncSeedDefinitions();

        return AtlasToolDefinition::query()
            ->orderBy('category')
            ->orderBy('slug')
            ->get();
    }

    public function definition(string $slug): ?AtlasToolDefinition
    {
        $this->syncSeedDefinitions();

        return AtlasToolDefinition::query()->where('slug', $slug)->first();
    }

    /**
     * @return array<string,mixed>
     */
    public function doctor(string $workspace): array
    {
        $workspace = realpath($workspace) ?: $workspace;
        $definitions = $this->definitions();

        return [
            'status' => 'ok',
            'workspace_hash' => hash('sha256', $workspace),
            'tool_count' => $definitions->count(),
            'tools' => $definitions
                ->map(fn (AtlasToolDefinition $definition): array => $this->detect($definition, $workspace))
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function detect(AtlasToolDefinition $definition, string $workspace): array
    {
        $workspace = realpath($workspace) ?: $workspace;
        $binaries = (array) data_get($definition->detect_json, 'binaries', []);
        $layers = (array) data_get($definition->runtime_json, 'execution_layers', ['host']);
        $best = null;

        if (in_array('atlas_internal', $layers, true) || in_array('internal', $binaries, true)) {
            $best = [
                'status' => 'ready',
                'execution_layer' => 'atlas_internal',
                'binary' => 'internal',
                'binary_path_hash' => hash('sha256', base_path()),
                'version' => (string) config('app.version', 'atlas'),
                'install_hint' => null,
            ];
        }

        if ($best === null) {
            foreach ($layers as $layer) {
                foreach ($binaries as $binary) {
                    $path = $this->resolveBinary((string) $binary, $workspace, (string) $layer);
                    if ($path === null) {
                        continue;
                    }

                    $best = [
                        'status' => 'ready',
                        'execution_layer' => $layer,
                        'binary' => $binary,
                        'binary_path_hash' => hash('sha256', $path),
                        'version' => $this->detectVersion($path, $workspace),
                        'install_hint' => null,
                    ];
                    break 2;
                }
            }
        }

        $result = $best ?: [
            'status' => 'missing',
            'execution_layer' => $layers[0] ?? 'host',
            'binary' => $binaries[0] ?? $definition->slug,
            'binary_path_hash' => null,
            'version' => null,
            'install_hint' => $this->installHint($definition->slug),
        ];

        if (Schema::hasTable('atlas_tool_installations')) {
            AtlasToolInstallation::query()->updateOrCreate(
                [
                    'tool_definition_id' => $definition->id,
                    'workspace_hash' => hash('sha256', $workspace),
                    'execution_layer' => (string) $result['execution_layer'],
                ],
                [
                    'status' => (string) $result['status'],
                    'version' => $result['version'],
                    'binary_path_hash' => $result['binary_path_hash'],
                    'detected_at' => now(),
                    'metadata_json' => [
                        'binary' => $result['binary'],
                        'install_hint' => $result['install_hint'],
                    ],
                ],
            );
        }

        return [
            'slug' => $definition->slug,
            'name' => $definition->name,
            'type' => $definition->type,
            'category' => $definition->category,
            'capabilities' => $definition->capabilities_json,
            'risks' => $definition->risks_json,
            'risk_level' => $definition->risk_level,
            'cost_posture' => $definition->cost_posture,
            ...$result,
        ];
    }

    private function resolveBinary(string $binary, string $workspace, string $layer): ?string
    {
        if ($layer === 'workspace' || str_contains($binary, '/')) {
            $candidate = $workspace.'/'.ltrim($binary, '/');

            return File::isFile($candidate) && is_executable($candidate) ? $candidate : null;
        }

        return (new ExecutableFinder)->find($binary);
    }

    private function detectVersion(string $path, string $workspace): ?string
    {
        $process = new Process([$path, '--version'], $workspace);
        $process->setTimeout(5);

        try {
            $process->run();
        } catch (\Throwable) {
            return null;
        }

        $output = trim($process->getOutput() ?: $process->getErrorOutput());

        return $output !== '' ? mb_substr($output, 0, 120) : null;
    }

    private function installHint(string $slug): ?string
    {
        return match ($slug) {
            'gitleaks' => 'brew install gitleaks',
            'semgrep' => 'brew install semgrep',
            'shellcheck' => 'brew install shellcheck',
            'hadolint' => 'brew install hadolint',
            'trivy' => 'brew install trivy',
            'syft' => 'brew install syft',
            'grype' => 'brew install grype',
            'osv_scanner' => 'brew install osv-scanner',
            'typescript' => 'npm install --save-dev typescript',
            'eslint' => 'npm install --save-dev eslint',
            'biome' => 'npm install --save-dev @biomejs/biome',
            'laravel_pint' => 'composer require laravel/pint --dev',
            'phpstan' => 'composer require phpstan/phpstan --dev',
            'psalm' => 'composer require vimeo/psalm --dev',
            'playwright' => 'npm install --save-dev @playwright/test',
            'cypress' => 'npm install --save-dev cypress',
            default => null,
        };
    }
}
