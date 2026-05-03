<?php

namespace App\Services\Engineering;

use App\Services\Tools\AtlasToolEvidenceStore;
use App\Support\AtlasSecurity;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Symfony\Component\Yaml\Yaml;

class EngineeringApiContractService
{
    /**
     * @var array<int,string>
     */
    private const SPEC_CANDIDATES = [
        'openapi.json',
        'openapi.yaml',
        'openapi.yml',
        'docs/openapi.json',
        'docs/openapi.yaml',
        'docs/openapi.yml',
        'docs/api/openapi.json',
        'docs/api/openapi.yaml',
        'docs/api/openapi.yml',
        'storage/api-docs/api-docs.json',
    ];

    public function __construct(private readonly AtlasToolEvidenceStore $evidence) {}

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function validate(string $workspace, array $options = []): array
    {
        $workspace = realpath($workspace) ?: $workspace;
        $startedAt = hrtime(true);
        $strict = (bool) ($options['strict'] ?? false);
        $runContextType = $this->nullableString($options['run_context_type'] ?? null);
        $runContextId = $this->nullableString($options['run_context_id'] ?? null);
        $artifactRoot = storage_path('app/engineering-api-contracts/'.now()->format('Ymd-His').'-'.substr(hash('sha256', $workspace.random_int(1, PHP_INT_MAX)), 0, 10));
        File::ensureDirectoryExists($artifactRoot);

        $specPath = $this->specPath($workspace, $this->nullableString($options['spec'] ?? null));
        $findings = [];
        $spec = null;
        $status = 'passed';

        if ($specPath === null) {
            $status = 'skipped';
            $findings[] = $this->finding(
                'atlas_api_contract.spec_missing',
                'OpenAPI specification not found',
                'No OpenAPI specification was found in the workspace.',
                'medium',
                null,
                null,
                false,
            );
        } else {
            [$spec, $parseFindings] = $this->parseSpec($specPath);
            array_push($findings, ...$parseFindings);
            if ($spec !== null) {
                array_push($findings, ...$this->contractFindings($spec, $strict));
            }
        }

        $blockingCount = collect($findings)->where('blocks_resolved', true)->count();
        if ($blockingCount > 0) {
            $status = 'failed';
        }

        $routes = $this->apiRoutes();
        $metrics = [
            'spec_detected' => $specPath !== null,
            'route_count' => count($routes),
            'documented_path_count' => is_array($spec) ? count((array) ($spec['paths'] ?? [])) : 0,
            'finding_count' => count($findings),
            'blocking_finding_count' => $blockingCount,
        ];

        $payload = [
            'status' => $status,
            'workspace_hash' => hash('sha256', $workspace),
            'artifact_root' => $artifactRoot,
            'artifact_root_hash' => hash('sha256', $artifactRoot),
            'spec_path' => $specPath ? $this->relativePath($workspace, $specPath) : null,
            'strict' => $strict,
            'summary' => [
                ...$metrics,
                'severity_counts' => collect($findings)->countBy('severity')->all(),
            ],
            'routes' => $routes,
            'findings' => $findings,
            'metrics' => $metrics,
            'recommendations' => $this->recommendations($specPath, $findings),
            'cost_posture' => 'free_local_or_project_local',
            'paid_tool_required' => false,
            'duration_ms' => (int) ((hrtime(true) - $startedAt) / 1_000_000),
        ];

        File::put($artifactRoot.'/api-contract.json', json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $artifactPaths = [
            'result' => $artifactRoot.'/api-contract.json',
        ];
        if ($specPath !== null && File::isFile($specPath)) {
            $redactedSpecPath = $artifactRoot.'/openapi-source-redacted.'.pathinfo($specPath, PATHINFO_EXTENSION);
            File::put($redactedSpecPath, AtlasSecurity::redactString(File::get($specPath)));
            $artifactPaths['openapi_spec'] = $redactedSpecPath;
        }

        $this->evidence->recordExternalToolResult('atlas_api_contract', $workspace, [
            'status' => $status,
            'duration_ms' => $payload['duration_ms'],
            'findings' => $findings,
            'metrics' => $metrics,
            'artifact_paths' => $artifactPaths,
            'category' => 'api_contract',
            'reason' => 'api_contract_validation',
        ], [
            'surface' => 'engineering_api_contract',
            'source' => 'engineering_api_contract_service',
            'run_context_type' => $runContextType,
            'run_context_id' => $runContextId,
            'metadata' => [
                'strict' => $strict,
                'spec_path' => $payload['spec_path'],
                'artifact_root_hash' => $payload['artifact_root_hash'],
            ],
        ]);

        return $payload;
    }

    private function specPath(string $workspace, ?string $explicit): ?string
    {
        if ($explicit !== null) {
            $candidate = str_starts_with($explicit, DIRECTORY_SEPARATOR) ? $explicit : $workspace.'/'.$explicit;
            $real = realpath($candidate);

            return $real && File::isFile($real) && str_starts_with($real, $workspace.DIRECTORY_SEPARATOR) ? $real : null;
        }

        foreach (self::SPEC_CANDIDATES as $candidate) {
            $path = $workspace.'/'.$candidate;
            if (File::isFile($path)) {
                return realpath($path) ?: $path;
            }
        }

        return null;
    }

    /**
     * @return array{0:?array<string,mixed>,1:array<int,array<string,mixed>>}
     */
    private function parseSpec(string $specPath): array
    {
        try {
            $raw = File::get($specPath);
            $extension = strtolower(pathinfo($specPath, PATHINFO_EXTENSION));
            $spec = $extension === 'json'
                ? json_decode($raw, true, flags: JSON_THROW_ON_ERROR)
                : $this->parseYaml($raw);

            if (! is_array($spec)) {
                throw new \RuntimeException('OpenAPI spec root must be an object.');
            }

            $findings = [];
            if (! is_string($spec['openapi'] ?? null) && ! is_string($spec['swagger'] ?? null)) {
                $findings[] = $this->finding('atlas_api_contract.version_missing', 'OpenAPI version missing', 'Spec must define openapi or swagger version.', 'high', $specPath, null, true);
            }

            if (! is_array($spec['paths'] ?? null)) {
                $findings[] = $this->finding('atlas_api_contract.paths_missing', 'OpenAPI paths missing', 'Spec must define a paths object.', 'high', $specPath, null, true);
            }

            return [$spec, $findings];
        } catch (\Throwable $exception) {
            return [null, [
                $this->finding(
                    'atlas_api_contract.spec_invalid',
                    'OpenAPI specification is invalid',
                    $exception->getMessage(),
                    'high',
                    $specPath,
                    null,
                    true,
                ),
            ]];
        }
    }

    /**
     * Keep YAML support dependency-free for local workspaces. If symfony/yaml or
     * ext-yaml is installed, use it; otherwise parse the subset OpenAPI specs
     * commonly use for paths/methods/responses.
     *
     * @return array<string,mixed>
     */
    private function parseYaml(string $raw): array
    {
        if (class_exists(Yaml::class)) {
            $parsed = Yaml::parse($raw);

            return is_array($parsed) ? $parsed : [];
        }

        if (function_exists('yaml_parse')) {
            $parsed = yaml_parse($raw);

            return is_array($parsed) ? $parsed : [];
        }

        $lines = collect(preg_split('/\R/', $raw) ?: [])
            ->map(fn (string $line): string => preg_replace('/\s+#.*$/', '', rtrim($line)) ?: '')
            ->filter(fn (string $line): bool => trim($line) !== '' && ! str_starts_with(ltrim($line), '#'))
            ->values()
            ->all();
        $index = 0;

        return $this->parseYamlBlock($lines, $index, 0);
    }

    /**
     * @param  array<int,string>  $lines
     * @return array<string,mixed>
     */
    private function parseYamlBlock(array $lines, int &$index, int $indent): array
    {
        $result = [];

        while ($index < count($lines)) {
            $line = $lines[$index];
            $lineIndent = strlen($line) - strlen(ltrim($line, ' '));
            if ($lineIndent < $indent) {
                break;
            }

            if ($lineIndent > $indent) {
                $index++;

                continue;
            }

            $content = trim($line);
            if (! str_contains($content, ':')) {
                $index++;

                continue;
            }

            [$key, $value] = explode(':', $content, 2);
            $key = $this->yamlScalar($key);
            $value = trim($value);
            $index++;

            if ($value === '') {
                $result[(string) $key] = $this->parseYamlBlock($lines, $index, $this->nextYamlIndent($lines, $index, $indent + 2));

                continue;
            }

            $result[(string) $key] = $this->yamlScalar($value);
        }

        return $result;
    }

    /**
     * @param  array<int,string>  $lines
     */
    private function nextYamlIndent(array $lines, int $index, int $fallback): int
    {
        if (! isset($lines[$index])) {
            return $fallback;
        }

        return strlen($lines[$index]) - strlen(ltrim($lines[$index], ' '));
    }

    private function yamlScalar(string $value): mixed
    {
        $value = trim($value);
        if ((str_starts_with($value, '"') && str_ends_with($value, '"')) || (str_starts_with($value, "'") && str_ends_with($value, "'"))) {
            return substr($value, 1, -1);
        }

        return match (strtolower($value)) {
            'true' => true,
            'false' => false,
            'null', '~' => null,
            default => $value,
        };
    }

    /**
     * @param  array<string,mixed>  $spec
     * @return array<int,array<string,mixed>>
     */
    private function contractFindings(array $spec, bool $strict): array
    {
        $findings = [];
        $documentedOperations = $this->documentedOperations((array) ($spec['paths'] ?? []));
        $implementedOperations = $this->implementedOperations();

        foreach ($documentedOperations as $operationKey => $operation) {
            if (! isset($implementedOperations[$operationKey])) {
                $findings[] = $this->finding(
                    'atlas_api_contract.operation_not_implemented',
                    'Documented API operation is not implemented',
                    strtoupper((string) $operation['method']).' '.$operation['path'].' exists in OpenAPI but not in Laravel routes.',
                    'high',
                    null,
                    null,
                    true,
                    ['operation' => $operation],
                );
            }

            if (! (bool) ($operation['has_responses'] ?? false)) {
                $findings[] = $this->finding(
                    'atlas_api_contract.responses_missing',
                    'API operation has no response contract',
                    strtoupper((string) $operation['method']).' '.$operation['path'].' must define responses.',
                    'high',
                    null,
                    null,
                    true,
                    ['operation' => $operation],
                );
            }
        }

        foreach ($implementedOperations as $operationKey => $operation) {
            if (! isset($documentedOperations[$operationKey])) {
                $findings[] = $this->finding(
                    'atlas_api_contract.route_not_documented',
                    'Laravel API route is not documented in OpenAPI',
                    strtoupper((string) $operation['method']).' '.$operation['path'].' exists in routes but not in OpenAPI.',
                    $strict ? 'high' : 'medium',
                    null,
                    null,
                    $strict,
                    ['operation' => $operation],
                );
            }
        }

        return $findings;
    }

    /**
     * @param  array<string,mixed>  $paths
     * @return array<string,array<string,mixed>>
     */
    private function documentedOperations(array $paths): array
    {
        $operations = [];
        foreach ($paths as $path => $pathItem) {
            if (! is_string($path) || ! is_array($pathItem)) {
                continue;
            }

            foreach (['get', 'post', 'put', 'patch', 'delete'] as $method) {
                $operation = $pathItem[$method] ?? null;
                if (! is_array($operation)) {
                    continue;
                }

                $normalizedPath = $this->normalizePath($path);
                $operations[$this->operationKey($method, $normalizedPath)] = [
                    'method' => $method,
                    'path' => $normalizedPath,
                    'has_responses' => is_array($operation['responses'] ?? null) && $operation['responses'] !== [],
                ];
            }
        }

        return $operations;
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function implementedOperations(): array
    {
        return collect($this->apiRoutes())
            ->flatMap(function (array $route): array {
                return collect((array) ($route['methods'] ?? []))
                    ->mapWithKeys(fn (string $method): array => [
                        $this->operationKey($method, (string) $route['path']) => [
                            'method' => strtolower($method),
                            'path' => $route['path'],
                            'name' => $route['name'] ?? null,
                        ],
                    ])
                    ->all();
            })
            ->all();
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function apiRoutes(): array
    {
        return collect(Route::getRoutes())
            ->map(function ($route): array {
                $methods = array_values(array_diff($route->methods(), ['HEAD']));
                $uri = $this->normalizePath('/'.$route->uri());

                return [
                    'uri' => $route->uri(),
                    'path' => $uri,
                    'methods' => $methods,
                    'name' => $route->getName(),
                ];
            })
            ->filter(fn (array $route): bool => $route['path'] !== '/health')
            ->values()
            ->all();
    }

    private function normalizePath(string $path): string
    {
        $path = '/'.ltrim(trim($path), '/');
        $path = preg_replace('/\{([^}:]+):[^}]+\}/', '{$1}', $path) ?: $path;
        $path = preg_replace('/\{([^}]+)\}/', '{$1}', $path) ?: $path;

        return rtrim($path, '/') ?: '/';
    }

    private function operationKey(string $method, string $path): string
    {
        return strtolower($method).' '.$this->canonicalOperationPath($path);
    }

    private function canonicalOperationPath(string $path): string
    {
        $path = $this->normalizePath($path);

        return preg_replace('/\{[^}]+\}/', '{}', $path) ?: $path;
    }

    /**
     * @return array<string,mixed>
     */
    private function finding(string $ruleId, string $title, string $message, string $severity, ?string $file, ?int $line, bool $blocks, array $metadata = []): array
    {
        return [
            'rule_id' => $ruleId,
            'title' => $title,
            'message' => AtlasSecurity::redactString($message),
            'severity' => $severity,
            'file' => $file,
            'line' => $line,
            'blocks_resolved' => $blocks,
            'metadata' => $metadata,
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $findings
     * @return array<int,array<string,mixed>>
     */
    private function recommendations(?string $specPath, array $findings): array
    {
        $recommendations = [];
        if ($specPath === null) {
            $recommendations[] = [
                'tool' => 'atlas_api_contract',
                'action' => 'add_openapi_spec',
                'why' => 'API contract validation needs an OpenAPI JSON/YAML file in the workspace.',
                'paid_tool_required' => false,
            ];
        }

        if (collect($findings)->contains(fn (array $finding): bool => ($finding['rule_id'] ?? null) === 'atlas_api_contract.route_not_documented')) {
            $recommendations[] = [
                'tool' => 'atlas_api_contract',
                'action' => 'document_laravel_routes',
                'why' => 'Routes should be represented in OpenAPI before release gates rely on contract evidence.',
                'paid_tool_required' => false,
            ];
        }

        return $recommendations;
    }

    private function relativePath(string $workspace, string $path): string
    {
        $real = realpath($path) ?: $path;

        return str_starts_with($real, $workspace.DIRECTORY_SEPARATOR)
            ? ltrim(substr($real, strlen($workspace)), DIRECTORY_SEPARATOR)
            : basename($real);
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? Str::limit($value, 1000, '') : null;
    }
}
