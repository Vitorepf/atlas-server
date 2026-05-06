<?php

namespace App\Services\Engineering;

use App\Services\Tools\AtlasToolEvidenceStore;
use App\Services\Tools\AtlasToolResultNormalizer;
use App\Support\AtlasSecurity;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

class EngineeringQualityScanService
{
    private const OUTPUT_LIMIT = 12000;

    public function __construct(
        private readonly AtlasToolEvidenceStore $toolEvidence,
        private readonly AtlasToolResultNormalizer $toolNormalizer,
    ) {}

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function scan(string $workspace, array $options = []): array
    {
        $workspace = realpath($workspace) ?: $workspace;
        $profile = $this->profile((string) ($options['profile'] ?? 'auto'));
        $timeout = max(10, (int) ($options['timeout'] ?? 300));
        $changedOnly = (bool) ($options['changed_only'] ?? false);
        $startedAt = hrtime(true);
        $runContextType = $this->nullableString($options['run_context_type'] ?? null);
        $runContextId = $this->nullableString($options['run_context_id'] ?? null);
        $artifactRoot = storage_path('app/engineering-quality-scans/'.now()->format('Ymd-His').'-'.substr(hash('sha256', $workspace.random_int(1, PHP_INT_MAX)), 0, 10));
        File::ensureDirectoryExists($artifactRoot);

        $targets = $this->changedTargets($workspace, $changedOnly);
        $plans = $this->filterPlans(
            $this->plans($workspace, $profile, $targets),
            $this->stringList($options['include_categories'] ?? []),
            $this->stringList($options['include_tools'] ?? []),
        );
        $tools = [];
        $findings = [];

        foreach ($plans as $plan) {
            $tool = $this->executePlan($workspace, $artifactRoot, $plan, $timeout);
            $tools[] = $tool;
            array_push($findings, ...$this->findingsForTool($tool));
        }

        $recommendations = $this->recommendations($tools, $profile);
        $summary = [
            'tool_count' => count($tools),
            'passed_count' => collect($tools)->where('status', 'passed')->count(),
            'failed_count' => collect($tools)->where('status', 'failed')->count(),
            'skipped_count' => collect($tools)->where('status', 'skipped')->count(),
            'timeout_count' => collect($tools)->where('status', 'timeout')->count(),
            'finding_count' => count($findings),
            'blocking_finding_count' => collect($findings)->where('blocks_resolved', true)->count(),
            'recommendation_count' => count($recommendations),
        ];

        $status = $summary['failed_count'] > 0 || $summary['timeout_count'] > 0 || $summary['blocking_finding_count'] > 0
            ? 'failed'
            : 'passed';

        $payload = [
            'status' => $status,
            'profile' => $profile,
            'workspace_hash' => hash('sha256', $workspace),
            'artifact_root' => $artifactRoot,
            'artifact_root_hash' => hash('sha256', $artifactRoot),
            'changed_only' => $changedOnly,
            'targets' => $targets,
            'scope' => [
                'include_categories' => $this->stringList($options['include_categories'] ?? []),
                'include_tools' => $this->stringList($options['include_tools'] ?? []),
            ],
            'summary' => $summary,
            'tools' => $tools,
            'findings' => $findings,
            'recommendations' => $recommendations,
            'cost_posture' => 'free_local_or_project_local',
            'paid_tool_required' => false,
            'duration_ms' => (int) ((hrtime(true) - $startedAt) / 1_000_000),
        ];

        $this->writeScanManifest($artifactRoot, $payload);
        $this->recordToolRuntimeEvidence($workspace, $artifactRoot, $payload, [
            'run_context_type' => $runContextType,
            'run_context_id' => $runContextId,
        ]);
        $this->writeScanManifest($artifactRoot, $payload);

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function writeScanManifest(string $artifactRoot, array $payload): void
    {
        File::ensureDirectoryExists($artifactRoot);
        File::put($artifactRoot.'/scan.json', json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function profile(string $profile): string
    {
        $profile = strtolower(trim($profile));

        return in_array($profile, ['auto', 'fast', 'standard', 'release', 'deep'], true) ? $profile : 'auto';
    }

    /**
     * @return array<int,string>
     */
    private function changedTargets(string $workspace, bool $changedOnly): array
    {
        if (! $changedOnly || ! File::isDirectory($workspace.'/.git')) {
            return [];
        }

        $process = new Process(['git', 'status', '--short'], $workspace, AtlasSecurity::processEnv([], 'tool'));
        $process->setTimeout(15);
        $process->run();

        if (($process->getExitCode() ?? 1) !== 0) {
            return [];
        }

        return collect(explode("\n", $process->getOutput()))
            ->map(fn (string $line): string => trim(substr($line, 3)))
            ->filter(fn (string $path): bool => $path !== '' && File::isFile($workspace.'/'.$path))
            ->values()
            ->all();
    }

    /**
     * @param  array<int,string>  $targets
     * @return array<int,array<string,mixed>>
     */
    private function plans(string $workspace, string $profile, array $targets): array
    {
        $plans = [];
        $plans[] = $this->composerValidatePlan($workspace);
        $plans[] = $this->workspaceBinaryPlan('laravel_pint', 'vendor/bin/pint', ['./vendor/bin/pint', '--test'], $workspace, File::isFile($workspace.'/composer.json'));
        $phpTargets = $this->targetArgs($targets, ['php']);
        $frontendTargets = $this->targetArgs($targets, ['js', 'jsx', 'ts', 'tsx', 'vue', 'mjs', 'cjs', 'json', 'jsonc']);

        $plans[] = $this->workspaceBinaryPlan('phpstan', 'vendor/bin/phpstan', array_values(array_filter(['./vendor/bin/phpstan', 'analyse', '--no-progress', '--error-format=json', ...$phpTargets])), $workspace, File::exists($workspace.'/phpstan.neon') || File::exists($workspace.'/phpstan.neon.dist'));
        $plans[] = $this->workspaceBinaryPlan('psalm', 'vendor/bin/psalm', array_values(array_filter(['./vendor/bin/psalm', '--output-format=json', ...$phpTargets])), $workspace, File::exists($workspace.'/psalm.xml') || File::exists($workspace.'/psalm.xml.dist'));
        $plans[] = $this->workspaceBinaryPlan('typescript', 'node_modules/.bin/tsc', ['./node_modules/.bin/tsc', '--noEmit', '--pretty', 'false'], $workspace, File::isFile($workspace.'/tsconfig.json'));
        $plans[] = $this->workspaceBinaryPlan('biome', 'node_modules/.bin/biome', ['./node_modules/.bin/biome', 'ci', '--reporter=json', ...($frontendTargets ?: ['.'])], $workspace, $this->hasAny($workspace, ['biome.json', 'biome.jsonc']));
        $plans[] = $this->workspaceBinaryPlan('eslint', 'node_modules/.bin/eslint', ['./node_modules/.bin/eslint', ...($frontendTargets ?: ['.']), '--format', 'json'], $workspace, $this->hasAny($workspace, ['eslint.config.js', 'eslint.config.mjs', '.eslintrc', '.eslintrc.json', '.eslintrc.js']));
        $plans[] = $this->globalBinaryPlan('gitleaks', ['gitleaks', 'detect', '--source', '.', '--redact', '--report-format=json'], $workspace, in_array($profile, ['auto', 'standard', 'release', 'deep'], true));
        $plans[] = $this->globalBinaryPlan('semgrep', ['semgrep', '--json', '--quiet', ...($this->targetArgs($targets) ?: ['.'])], $workspace, in_array($profile, ['release', 'deep'], true));
        $plans[] = $this->globalBinaryPlan('osv_scanner', ['osv-scanner', '--format', 'json', '.'], $workspace, in_array($profile, ['standard', 'release', 'deep'], true) && $this->hasAnyDependencyManifest($workspace));
        $plans[] = $this->globalBinaryPlan('trivy', ['trivy', 'fs', '--format', 'json', '--quiet', '--scanners', 'vuln,secret,misconfig', '.'], $workspace, in_array($profile, ['release', 'deep'], true));
        $plans[] = $this->globalBinaryPlan('syft', ['syft', '.', '-o', 'json'], $workspace, in_array($profile, ['release', 'deep'], true));
        $plans[] = $this->globalBinaryPlan('grype', ['grype', 'dir:.', '-o', 'json'], $workspace, in_array($profile, ['release', 'deep'], true));
        $plans[] = $this->shellCheckPlan($workspace, $targets);
        $plans[] = $this->globalBinaryPlan('hadolint', ['hadolint', 'Dockerfile'], $workspace, File::isFile($workspace.'/Dockerfile'));

        return array_values(array_filter($plans));
    }

    /**
     * @param  array<int,array<string,mixed>>  $plans
     * @param  array<int,string>  $categories
     * @param  array<int,string>  $toolSlugs
     * @return array<int,array<string,mixed>>
     */
    private function filterPlans(array $plans, array $categories, array $toolSlugs): array
    {
        if ($categories === [] && $toolSlugs === []) {
            return $plans;
        }

        return collect($plans)
            ->filter(function (array $plan) use ($categories, $toolSlugs): bool {
                $category = (string) ($plan['category'] ?? '');
                $slug = (string) ($plan['slug'] ?? '');

                return ($categories !== [] && in_array($category, $categories, true))
                    || ($toolSlugs !== [] && in_array($slug, $toolSlugs, true));
            })
            ->values()
            ->all();
    }

    /**
     * @return array<int,string>
     */
    private function targetArgs(array $targets, array $extensions = []): array
    {
        return array_values(array_filter($targets, function (string $path) use ($extensions): bool {
            if ($path === '') {
                return false;
            }

            if ($extensions === []) {
                return true;
            }

            $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

            return in_array($extension, $extensions, true);
        }));
    }

    /**
     * @param  array<int,string>  $paths
     */
    private function hasAny(string $workspace, array $paths): bool
    {
        foreach ($paths as $path) {
            if (File::exists($workspace.'/'.$path)) {
                return true;
            }
        }

        return false;
    }

    private function hasAnyDependencyManifest(string $workspace): bool
    {
        return $this->hasAny($workspace, [
            'composer.lock',
            'package-lock.json',
            'pnpm-lock.yaml',
            'yarn.lock',
            'bun.lockb',
            'go.sum',
            'Cargo.lock',
            'Gemfile.lock',
            'poetry.lock',
            'Pipfile.lock',
            'requirements.txt',
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function composerValidatePlan(string $workspace): array
    {
        $composer = (new ExecutableFinder)->find('composer');

        return [
            'slug' => 'composer_validate',
            'category' => 'quality',
            'available' => $composer !== null && File::isFile($workspace.'/composer.json'),
            'applicable' => File::isFile($workspace.'/composer.json'),
            'command' => $composer ? [$composer, 'validate', '--no-interaction', '--strict'] : ['composer', 'validate', '--no-interaction', '--strict'],
            'missing_reason' => $composer === null ? 'composer_not_found' : 'composer_json_missing',
            'parser' => 'generic',
            'required' => false,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function workspaceBinaryPlan(string $slug, string $binary, array $command, string $workspace, bool $applicable): array
    {
        $path = $workspace.'/'.$binary;

        return [
            'slug' => $slug,
            'category' => 'quality',
            'available' => File::isFile($path),
            'applicable' => $applicable,
            'command' => $command,
            'missing_reason' => ! $applicable ? 'not_applicable' : $slug.'_not_installed_in_workspace',
            'parser' => $slug,
            'required' => false,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function globalBinaryPlan(string $slug, array $command, string $workspace, bool $applicable): array
    {
        $binary = (string) ($command[0] ?? $slug);
        $resolved = (new ExecutableFinder)->find($binary);
        if ($resolved) {
            $command[0] = $resolved;
        }

        return [
            'slug' => $slug,
            'category' => $this->toolCategory($slug),
            'available' => $resolved !== null,
            'applicable' => $applicable,
            'command' => $command,
            'missing_reason' => ! $applicable ? 'not_applicable' : $slug.'_not_found_in_path',
            'parser' => $slug,
            'required' => false,
        ];
    }

    private function toolCategory(string $slug): string
    {
        return match ($slug) {
            'gitleaks', 'semgrep', 'trivy', 'osv_scanner' => 'security',
            'syft', 'grype' => 'supply_chain',
            default => 'quality',
        };
    }

    /**
     * @param  array<int,string>  $targets
     * @return array<string,mixed>
     */
    private function shellCheckPlan(string $workspace, array $targets): array
    {
        $shellFiles = $targets !== []
            ? array_values(array_filter($targets, fn (string $path): bool => $this->isShellScript($workspace.'/'.$path)))
            : collect(array_merge(
                File::glob($workspace.'/*.sh') ?: [],
                File::glob($workspace.'/bin/*') ?: [],
                File::glob($workspace.'/scripts/*.sh') ?: [],
            ))
                ->filter(fn (string $path): bool => File::isFile($path))
                ->map(fn (string $path): string => Str::after($path, rtrim($workspace, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR))
                ->filter(fn (string $path): bool => $this->isShellScript($workspace.'/'.$path) && ! str_contains($path, 'vendor/') && ! str_contains($path, 'node_modules/'))
                ->take(50)
                ->values()
                ->all();

        $shellcheck = (new ExecutableFinder)->find('shellcheck');

        return [
            'slug' => 'shellcheck',
            'category' => 'quality',
            'available' => $shellcheck !== null,
            'applicable' => $shellFiles !== [],
            'command' => array_values(array_filter([$shellcheck ?: 'shellcheck', '--format=json', ...$shellFiles])),
            'missing_reason' => $shellFiles === [] ? 'no_shell_files' : 'shellcheck_not_found_in_path',
            'parser' => 'shellcheck',
            'required' => false,
        ];
    }

    private function isShellScript(string $path): bool
    {
        if (! File::isFile($path)) {
            return false;
        }

        if (str_ends_with($path, '.sh')) {
            return true;
        }

        $handle = fopen($path, 'rb');
        if (! $handle) {
            return false;
        }

        $firstLine = fgets($handle, 160) ?: '';
        fclose($handle);

        return str_starts_with($firstLine, '#!') && preg_match('/\b(?:bash|sh|zsh)\b/', $firstLine) === 1;
    }

    /**
     * @param  array<string,mixed>  $plan
     * @return array<string,mixed>
     */
    private function executePlan(string $workspace, string $artifactRoot, array $plan, int $timeout): array
    {
        $slug = (string) $plan['slug'];
        if (! (bool) ($plan['applicable'] ?? false)) {
            return $this->skippedTool($plan, 'not_applicable');
        }

        if (! (bool) ($plan['available'] ?? false)) {
            return $this->skippedTool($plan, (string) ($plan['missing_reason'] ?? 'tool_missing'));
        }

        $startedAt = hrtime(true);
        $process = new Process((array) $plan['command'], $workspace, AtlasSecurity::processEnv(['CI' => '1'], 'tool'));
        $process->setTimeout($timeout);
        $timedOut = false;

        try {
            $process->run();
        } catch (\Throwable $exception) {
            $timedOut = str_contains(strtolower($exception->getMessage()), 'timed out');
        }

        $durationMs = (int) ((hrtime(true) - $startedAt) / 1_000_000);
        $stdout = AtlasSecurity::redactString($process->getOutput());
        $stderr = AtlasSecurity::redactString($process->getErrorOutput());
        $toolRoot = $artifactRoot.'/'.$slug;
        File::ensureDirectoryExists($toolRoot);
        File::put($toolRoot.'/stdout.txt', $stdout);
        File::put($toolRoot.'/stderr.txt', $stderr);

        $exitCode = $timedOut ? null : ($process->getExitCode() ?? 1);
        $status = $timedOut ? 'timeout' : ($exitCode === 0 ? 'passed' : 'failed');
        $parsed = $this->parseFindings((string) $plan['parser'], $stdout, $stderr);
        $metrics = $this->toolNormalizer->metricsFromOutput((string) $plan['parser'], $stdout, $stderr);
        $artifacts = $this->toolNormalizer->artifactsFromOutput((string) $plan['parser'], $stdout, $stderr);
        File::put($toolRoot.'/result.json', json_encode([
            'status' => $status,
            'exit_code' => $exitCode,
            'findings' => $parsed,
            'metrics' => $metrics,
            'artifacts' => $artifacts,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return [
            'slug' => $slug,
            'category' => $plan['category'] ?? 'quality',
            'status' => $status,
            'required' => (bool) ($plan['required'] ?? false),
            'exit_code' => $exitCode,
            'duration_ms' => $durationMs,
            'command' => AtlasSecurity::redactCommand((array) $plan['command']),
            'stdout_artifact' => $slug.'/stdout.txt',
            'stderr_artifact' => $slug.'/stderr.txt',
            'result_artifact' => $slug.'/result.json',
            'artifact_paths' => [
                'stdout' => $toolRoot.'/stdout.txt',
                'stderr' => $toolRoot.'/stderr.txt',
                'result' => $toolRoot.'/result.json',
            ],
            'stdout_excerpt' => Str::limit($this->stripAnsi($stdout), self::OUTPUT_LIMIT, "\n...[truncated]"),
            'stderr_excerpt' => Str::limit($this->stripAnsi($stderr), self::OUTPUT_LIMIT, "\n...[truncated]"),
            'findings' => $parsed,
            'metrics' => $metrics,
            'artifacts' => $artifacts,
        ];
    }

    /**
     * @param  array<string,mixed>  $plan
     * @return array<string,mixed>
     */
    private function skippedTool(array $plan, string $reason): array
    {
        return [
            'slug' => $plan['slug'] ?? 'unknown',
            'category' => $plan['category'] ?? 'quality',
            'status' => 'skipped',
            'required' => (bool) ($plan['required'] ?? false),
            'reason' => $reason,
            'command' => AtlasSecurity::redactCommand((array) ($plan['command'] ?? [])),
            'findings' => [],
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function parseFindings(string $parser, string $stdout, string $stderr): array
    {
        return $this->toolNormalizer->findingsFromOutput($parser, $stdout, $stderr);
    }

    /**
     * @param  array<string,mixed>  $tool
     * @return array<int,array<string,mixed>>
     */
    private function findingsForTool(array $tool): array
    {
        $parsed = collect((array) ($tool['findings'] ?? []))
            ->filter(fn (mixed $finding): bool => is_array($finding))
            ->map(fn (array $finding): array => array_merge([
                'tool' => $tool['slug'] ?? 'unknown',
                'category' => $tool['category'] ?? 'quality',
            ], $finding))
            ->values()
            ->all();

        if ($parsed !== []) {
            return $parsed;
        }

        if (in_array($tool['status'] ?? null, ['failed', 'timeout'], true)) {
            $message = trim((string) ($tool['stderr_excerpt'] ?? ''))
                ?: trim((string) ($tool['stdout_excerpt'] ?? ''))
                ?: 'Quality tool failed.';

            return [[
                'tool' => $tool['slug'] ?? 'unknown',
                'category' => $tool['category'] ?? 'quality',
                'severity' => 'high',
                'rule_id' => ($tool['slug'] ?? 'tool').'_exit_code',
                'title' => 'Quality tool failed',
                'message' => $message,
                'file' => null,
                'line' => null,
                'blocks_resolved' => true,
            ]];
        }

        return [];
    }

    /**
     * @param  array<int,array<string,mixed>>  $tools
     * @return array<int,array<string,mixed>>
     */
    private function recommendations(array $tools, string $profile): array
    {
        return collect($tools)
            ->filter(fn (array $tool): bool => ($tool['status'] ?? null) === 'skipped')
            ->map(fn (array $tool): ?array => $this->recommendationForSkippedTool($tool, $profile))
            ->filter()
            ->unique(fn (array $recommendation): string => (string) ($recommendation['tool'] ?? '').':'.(string) ($recommendation['reason'] ?? ''))
            ->values()
            ->all();
    }

    /**
     * @param  array<string,mixed>  $tool
     * @return array<string,mixed>|null
     */
    private function recommendationForSkippedTool(array $tool, string $profile): ?array
    {
        $slug = (string) ($tool['slug'] ?? '');
        $reason = (string) ($tool['reason'] ?? '');
        if ($reason === '' || in_array($reason, ['not_applicable', 'composer_json_missing', 'no_shell_files'], true)) {
            return null;
        }

        $catalog = [
            'laravel_pint' => [
                'title' => 'Adicionar Laravel Pint project-local',
                'install_hint' => 'composer require laravel/pint --dev',
                'why' => 'Formata PHP com a regra padrao do Laravel sem servico externo.',
            ],
            'phpstan' => [
                'title' => 'Adicionar PHPStan project-local',
                'install_hint' => 'composer require phpstan/phpstan --dev',
                'why' => 'Aumenta o sinal estatico para erros de tipo e contratos PHP.',
            ],
            'psalm' => [
                'title' => 'Adicionar Psalm project-local',
                'install_hint' => 'composer require vimeo/psalm --dev',
                'why' => 'Complementa analise estatica PHP quando o repo ja tem configuracao Psalm.',
            ],
            'typescript' => [
                'title' => 'Instalar TypeScript project-local',
                'install_hint' => 'npm install --save-dev typescript',
                'why' => 'Permite gate deterministico de typecheck para frontends e pacotes TS.',
            ],
            'biome' => [
                'title' => 'Instalar Biome project-local',
                'install_hint' => 'npm install --save-dev @biomejs/biome',
                'why' => 'Fornece lint/format rapido para JS/TS quando ha configuracao Biome.',
            ],
            'eslint' => [
                'title' => 'Instalar ESLint project-local',
                'install_hint' => 'npm install --save-dev eslint',
                'why' => 'Executa lint JS/TS com a configuracao do proprio repo.',
            ],
            'gitleaks' => [
                'title' => 'Instalar Gitleaks local',
                'install_hint' => 'brew install gitleaks',
                'why' => 'Detecta secrets sem enviar codigo para servico externo.',
            ],
            'semgrep' => [
                'title' => 'Instalar Semgrep local',
                'install_hint' => 'brew install semgrep',
                'why' => 'Adiciona regras de seguranca e qualidade sem depender de SaaS pago.',
            ],
            'shellcheck' => [
                'title' => 'Instalar ShellCheck local',
                'install_hint' => 'brew install shellcheck',
                'why' => 'Valida scripts shell como o launcher do Atlas.',
            ],
            'hadolint' => [
                'title' => 'Instalar Hadolint local',
                'install_hint' => 'brew install hadolint',
                'why' => 'Valida Dockerfile e reduz erro operacional no Docker Harness.',
            ],
            'osv_scanner' => [
                'title' => 'Instalar OSV-Scanner local',
                'install_hint' => 'brew install osv-scanner',
                'why' => 'Detecta vulnerabilidades conhecidas em lockfiles sem depender de servico pago.',
            ],
            'trivy' => [
                'title' => 'Instalar Trivy local',
                'install_hint' => 'brew install trivy',
                'why' => 'Escaneia vulnerabilidades, secrets e misconfiguracoes no filesystem/container.',
            ],
            'syft' => [
                'title' => 'Instalar Syft local',
                'install_hint' => 'brew install syft',
                'why' => 'Gera SBOM local para auditoria de supply chain e evidencias de release.',
            ],
            'grype' => [
                'title' => 'Instalar Grype local',
                'install_hint' => 'brew install grype',
                'why' => 'Analisa vulnerabilidades de dependencias a partir do workspace ou SBOM.',
            ],
        ];

        if (! isset($catalog[$slug])) {
            return null;
        }

        $securityTool = in_array($slug, ['gitleaks', 'semgrep', 'trivy', 'osv_scanner', 'grype'], true);

        return [
            'tool' => $slug,
            'category' => $tool['category'] ?? 'quality',
            'priority' => $securityTool && in_array($profile, ['standard', 'release', 'deep'], true) ? 'high' : 'medium',
            'reason' => $reason,
            'title' => $catalog[$slug]['title'],
            'why' => $catalog[$slug]['why'],
            'install_hint' => $catalog[$slug]['install_hint'],
            'cost_posture' => 'free_local_or_project_local',
            'paid_tool_required' => false,
            'blocks_scan' => false,
        ];
    }

    private function stripAnsi(string $value): string
    {
        return preg_replace('/\x1B(?:[@-Z\\\\-_]|\[[0-?]*[ -\/]*[@-~])/', '', $value) ?? $value;
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    /**
     * @return array<int,string>
     */
    private function stringList(mixed $value): array
    {
        if (is_string($value)) {
            $value = explode(',', $value);
        }

        if (! is_array($value)) {
            return [];
        }

        return collect($value)
            ->filter(fn (mixed $item): bool => is_scalar($item))
            ->map(fn (mixed $item): string => strtolower(trim((string) $item)))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<string,mixed>  $payload
     * @param  array{run_context_type?:?string,run_context_id?:?string}  $context
     */
    private function recordToolRuntimeEvidence(string $workspace, string $artifactRoot, array $payload, array $context = []): void
    {
        if (! Schema::hasTable('atlas_tool_runs')) {
            return;
        }

        foreach ((array) ($payload['tools'] ?? []) as $tool) {
            if (! is_array($tool)) {
                continue;
            }

            $this->toolEvidence->recordExternalToolResult((string) ($tool['slug'] ?? 'unknown'), $workspace, $tool, [
                'surface' => 'engineering_quality_scan',
                'source' => 'engineering_quality_scan_service',
                'run_context_type' => $context['run_context_type'] ?? null,
                'run_context_id' => $context['run_context_id'] ?? null,
                'metadata' => [
                    'scan_artifact_root_hash' => hash('sha256', $artifactRoot),
                    'profile' => $payload['profile'] ?? null,
                    'changed_only' => $payload['changed_only'] ?? false,
                ],
            ]);
        }
    }
}
