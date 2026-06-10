<?php

namespace App\Services\Tools;

use App\Models\AtlasToolDefinition;
use App\Models\AtlasToolInstallation;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

class AtlasToolRegistryService
{
    public function __construct(private readonly AtlasToolDefinitionCatalog $catalog) {}

    public function syncSeedDefinitions(): int
    {
        if (! DatabaseTableAvailability::has('atlas_tool_definitions')) {
            return 0;
        }

        $count = 0;
        foreach ($this->catalog->definitions() as $definition) {
            AtlasToolDefinition::query()->updateOrCreate(
                ['slug' => $definition['slug']],
                $this->definitionForSchema($definition),
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
     * @return array<string,mixed>|null
     */
    public function commandCatalog(string $slug, string $workspace): ?array
    {
        $definition = $this->definition($slug);
        if (! $definition) {
            return null;
        }

        $detected = $this->detect($definition, $workspace);

        return [
            'tool' => $detected,
            'commands' => $this->safeCommands($definition, (string) ($detected['binary'] ?? $definition->slug)),
        ];
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

        if (DatabaseTableAvailability::has('atlas_tool_installations')) {
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
            'execution_tier' => $definition->execution_tier ?? data_get($definition->metadata, 'execution_tier', 'T1'),
            'expected_cost' => $definition->expected_cost ?? data_get($definition->metadata, 'expected_cost', 'local_fast'),
            'default_trigger' => $definition->default_trigger ?? data_get($definition->metadata, 'default_trigger', 'manual_or_policy'),
            'authority_role' => $definition->authority_role ?? data_get($definition->metadata, 'authority_role', 'primary'),
            'authority_group' => $definition->authority_group ?? data_get($definition->metadata, 'authority_group'),
            'safe_commands' => $this->safeCommands($definition, (string) ($result['binary'] ?? $definition->slug)),
            ...$result,
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function safeCommands(AtlasToolDefinition $definition, string $binary): array
    {
        $commands = collect((array) data_get($definition->metadata, 'safe_commands', []))
            ->filter(fn (mixed $command): bool => is_array($command))
            ->map(fn (array $command): array => $this->normalizeSafeCommand($command, $binary))
            ->filter(fn (array $command): bool => $command['command'] !== [])
            ->values();

        if ($commands->isNotEmpty()) {
            return $commands->all();
        }

        if ($binary === 'internal') {
            return [];
        }

        return [[
            'name' => 'version',
            'category' => 'diagnostic',
            'description' => 'Detecta a versao da ferramenta sem analisar o workspace.',
            'command' => [$binary, '--version'],
            'dry_run_default' => true,
            'recommended_surface' => 'manual_diagnostic',
            'creates_evidence' => true,
            'blocking_capable' => false,
            'network_allowed' => false,
            'max_execution_tier' => $definition->execution_tier ?? data_get($definition->metadata, 'execution_tier', 'T1'),
            'sandbox_mode' => 'workspace',
            'privacy_level' => 'standard',
            'task_type' => 'diagnostic',
            'requires_provider_safe' => false,
        ]];
    }

    /**
     * @param  array<string,mixed>  $command
     * @return array<string,mixed>
     */
    private function normalizeSafeCommand(array $command, string $binary): array
    {
        $argv = collect((array) ($command['command'] ?? []))
            ->map(fn (mixed $part): string => str_replace('{binary}', $binary, (string) $part))
            ->filter(fn (string $part): bool => trim($part) !== '')
            ->values()
            ->all();

        return [
            'name' => (string) ($command['name'] ?? 'default'),
            'category' => (string) ($command['category'] ?? 'diagnostic'),
            'description' => (string) ($command['description'] ?? 'Comando recomendado pelo Atlas Tool Registry.'),
            'command' => $argv,
            'dry_run_default' => (bool) ($command['dry_run_default'] ?? true),
            'recommended_surface' => (string) ($command['recommended_surface'] ?? 'manual_diagnostic'),
            'creates_evidence' => (bool) ($command['creates_evidence'] ?? true),
            'blocking_capable' => (bool) ($command['blocking_capable'] ?? false),
            'network_allowed' => (bool) ($command['network_allowed'] ?? false),
            'max_execution_tier' => $command['max_execution_tier'] ?? null,
            'sandbox_mode' => $command['sandbox_mode'] ?? 'workspace',
            'privacy_level' => $command['privacy_level'] ?? 'standard',
            'task_type' => $command['task_type'] ?? 'diagnostic',
            'requires_provider_safe' => (bool) ($command['requires_provider_safe'] ?? false),
        ];
    }

    /**
     * @param  array<string,mixed>  $definition
     * @return array<string,mixed>
     */
    private function definitionForSchema(array $definition): array
    {
        if (! DatabaseTableAvailability::has('atlas_tool_definitions')) {
            return $definition;
        }

        return collect($definition)
            ->filter(fn (mixed $_, string $column): bool => $column === 'slug' || DatabaseTableAvailability::hasColumn('atlas_tool_definitions', $column))
            ->all();
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
            'codeql' => 'brew install codeql',
            'infer' => 'brew install infer',
            'checkov' => 'brew install checkov',
            'terrascan' => 'brew install terrascan',
            'kube_linter' => 'brew install kube-linter',
            'kube_score' => 'brew install kube-score',
            'dockle' => 'brew install goodwithtech/r/dockle',
            'scancode' => 'brew install scancode-toolkit',
            'ort' => 'brew install oss-review-toolkit',
            'licensee' => 'gem install licensee',
            'serena' => 'Install Serena MCP server for semantic code intelligence',
            'tree_sitter' => 'brew install tree-sitter',
            'ast_grep' => 'brew install ast-grep',
            'universal_ctags' => 'brew install universal-ctags',
            'aider' => 'pipx install aider-chat',
            'continue' => 'Install Continue CLI/IDE integration',
            'openhands' => 'Install OpenHands in an isolated runtime',
            'rector' => 'composer require rector/rector --dev',
            'phpmd' => 'composer require phpmd/phpmd --dev',
            'phpcpd' => 'composer require sebastian/phpcpd --dev',
            'composer_require_checker' => 'composer require maglnet/composer-require-checker --dev',
            'composer_unused' => 'composer require composer-unused/composer-unused --dev',
            'knip' => 'npm install --save-dev knip',
            'ts_prune' => 'npm install --save-dev ts-prune',
            'schemathesis' => 'pipx install schemathesis',
            'pact' => 'npm install --save-dev @pact-foundation/pact',
            'prism' => 'npm install --save-dev @stoplight/prism-cli',
            'wiremock' => 'brew install wiremock',
            'bruno' => 'brew install bruno',
            'infection' => 'composer require infection/infection --dev',
            'stryker' => 'npm install --save-dev @stryker-mutator/core',
            'fast_check' => 'npm install --save-dev fast-check',
            'axe_core' => 'npm install --save-dev axe-core',
            'pa11y' => 'npm install --save-dev pa11y',
            'lighthouse_ci' => 'npm install --save-dev @lhci/cli',
            'dependency_cruiser' => 'npm install --save-dev dependency-cruiser',
            'madge' => 'npm install --save-dev madge',
            'deptrac' => 'composer require qossmic/deptrac-shim --dev',
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
