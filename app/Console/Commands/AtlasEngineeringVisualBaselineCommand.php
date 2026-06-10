<?php

namespace App\Console\Commands;

use App\Services\Ai\Support\AppendOnlyJsonlStore;
use App\Services\Ai\Support\JsonFileStore;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class AtlasEngineeringVisualBaselineCommand extends Command
{
    protected $signature = 'atlas:engineering:visual-baseline
        {action=list : list, promote or history}
        {--workspace= : Target workspace path. Defaults to current directory}
        {--manifest=atlas-visual-report/manifest.json : Visual smoke manifest path, relative to workspace unless absolute}
        {--route=* : Restrict to one or more route paths}
        {--limit=50 : Maximum history events to return}
        {--apply : Apply baseline changes. Default is dry-run}
        {--json : Print machine-readable JSON}';

    protected $description = 'List or promote Atlas Engineering visual smoke DOM and screenshot baselines.';

    public function handle(): int
    {
        $action = trim((string) $this->argument('action'));
        $workspace = $this->workspace();

        $payload = match ($action) {
            'list' => $this->listBaselines($workspace),
            'promote' => $this->promoteBaselines($workspace, ! (bool) $this->option('apply')),
            'history' => $this->baselineHistory($workspace),
            default => ['status' => 'failed', 'reason' => 'unsupported_action', 'action' => $action],
        };

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return ($payload['status'] ?? null) === 'failed' ? self::FAILURE : self::SUCCESS;
        }

        $this->render($payload);

        return ($payload['status'] ?? null) === 'failed' ? self::FAILURE : self::SUCCESS;
    }

    private function workspace(): string
    {
        $workspace = (string) ($this->option('workspace') ?: getcwd() ?: base_path());
        $resolved = realpath($workspace);

        if (! $resolved || ! is_dir($resolved)) {
            throw new \InvalidArgumentException("Workspace invalido: {$workspace}");
        }

        return $resolved;
    }

    /**
     * @return array<string,mixed>
     */
    private function listBaselines(string $workspace): array
    {
        $root = $this->baselineRoot($workspace);
        $routes = $this->routeFilter();
        $baselines = File::isDirectory($root)
            ? collect(File::files($root))
                ->filter(fn ($file): bool => $file->getExtension() === 'json')
                ->map(function ($file): ?array {
                    $decoded = JsonFileStore::readArray($file->getPathname());

                    return is_array($decoded) ? array_merge($decoded, [
                        'baseline_file' => $file->getFilename(),
                    ]) : null;
                })
                ->filter()
                ->filter(fn (array $baseline): bool => $routes === [] || in_array((string) ($baseline['route'] ?? ''), $routes, true))
                ->values()
                ->all()
            : [];

        return [
            'status' => 'completed',
            'workspace_hash' => hash('sha256', $workspace),
            'baseline_root_hash' => hash('sha256', $root),
            'count' => count($baselines),
            'baselines' => $baselines,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function promoteBaselines(string $workspace, bool $dryRun): array
    {
        $manifestPath = $this->manifestPath($workspace);
        if (! File::isFile($manifestPath)) {
            return [
                'status' => 'failed',
                'reason' => 'manifest_not_found',
                'manifest' => $manifestPath,
            ];
        }

        $manifest = JsonFileStore::readArray($manifestPath);
        if (! is_array($manifest)) {
            return [
                'status' => 'failed',
                'reason' => 'manifest_invalid_json',
                'manifest' => $manifestPath,
            ];
        }

        $root = $this->baselineRoot($workspace);
        $routeFilter = $this->routeFilter();
        $routes = collect((array) ($manifest['routes'] ?? []))
            ->filter(fn (mixed $route): bool => is_array($route) && is_string($route['route'] ?? null) && is_string($route['body_hash'] ?? null))
            ->filter(fn (array $route): bool => $routeFilter === [] || in_array((string) $route['route'], $routeFilter, true))
            ->values();
        $promotions = [];

        foreach ($routes as $route) {
            $routePath = (string) $route['route'];
            $bodyHash = (string) $route['body_hash'];
            $baselinePath = $root.'/'.$this->routeSlug($routePath).'.json';
            $previous = JsonFileStore::readArray($baselinePath);
            $previousHash = is_array($previous) ? (string) ($previous['body_hash'] ?? '') : null;
            $screenshotHash = is_string(data_get($route, 'screenshot.sha256')) ? (string) data_get($route, 'screenshot.sha256') : null;
            $previousScreenshotHash = is_array($previous) ? (string) ($previous['screenshot_sha256'] ?? '') : null;
            $screenshotArtifact = is_string(data_get($route, 'screenshot.artifact')) ? (string) data_get($route, 'screenshot.artifact') : null;
            $effectiveScreenshotHash = $screenshotHash ?? (is_array($previous) ? ($previous['screenshot_sha256'] ?? null) : null);
            $effectiveScreenshotArtifact = $screenshotArtifact ?? (is_array($previous) ? ($previous['screenshot_artifact'] ?? null) : null);
            $baselineImage = $root.'/'.$this->routeSlug($routePath).'.png';
            $status = $previousHash === null || $previousHash === ''
                ? 'created'
                : (hash_equals($previousHash, $bodyHash) && ($screenshotHash === null || hash_equals((string) $previousScreenshotHash, $screenshotHash)) ? 'unchanged' : 'updated');
            $baseline = [
                'route' => $routePath,
                'body_hash' => $bodyHash,
                'artifact' => $route['artifact'] ?? null,
                'screenshot_sha256' => $effectiveScreenshotHash,
                'screenshot_artifact' => $effectiveScreenshotArtifact,
                'screenshot_baseline_file' => $effectiveScreenshotHash !== null ? basename($baselineImage) : null,
                'source_status_code' => $route['status_code'] ?? null,
                'source_baseline_status' => data_get($route, 'baseline.status'),
                'source_screenshot_baseline_status' => data_get($route, 'screenshot.baseline.status'),
                'source_manifest_hash' => hash_file('sha256', $manifestPath),
                'source_artifact_dir' => $manifest['artifact_dir'] ?? null,
                'workspace_hash' => hash('sha256', $workspace),
                'promoted_at' => now()->toJSON(),
                'previous_body_hash' => $previousHash,
                'previous_screenshot_sha256' => $previousScreenshotHash,
            ];

            if (! $dryRun) {
                JsonFileStore::write($baselinePath, $baseline, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                if ($screenshotArtifact !== null) {
                    $sourceScreenshot = $this->manifestArtifactPath($manifestPath, (string) ($manifest['artifact_dir'] ?? null), $screenshotArtifact);
                    if ($sourceScreenshot !== null && File::isFile($sourceScreenshot)) {
                        File::copy($sourceScreenshot, $baselineImage);
                    }
                }
                $this->appendAuditEvent($root, [
                    'event' => 'promoted',
                    'route' => $routePath,
                    'status' => $status,
                    'body_hash' => $bodyHash,
                    'previous_body_hash' => $previousHash,
                    'screenshot_sha256' => $effectiveScreenshotHash,
                    'previous_screenshot_sha256' => $previousScreenshotHash,
                    'baseline_file' => basename($baselinePath),
                    'screenshot_baseline_file' => $effectiveScreenshotHash !== null ? basename($baselineImage) : null,
                    'source_manifest_hash' => $baseline['source_manifest_hash'],
                    'source_artifact_dir' => $baseline['source_artifact_dir'],
                    'promoted_at' => $baseline['promoted_at'],
                ]);
            }

            $promotions[] = [
                'route' => $routePath,
                'status' => $status,
                'body_hash' => $bodyHash,
                'previous_body_hash' => $previousHash,
                'screenshot_sha256' => $effectiveScreenshotHash,
                'previous_screenshot_sha256' => $previousScreenshotHash,
                'baseline_file' => basename($baselinePath),
                'screenshot_baseline_file' => $effectiveScreenshotHash !== null ? basename($baselineImage) : null,
            ];
        }

        return [
            'status' => 'completed',
            'dry_run' => $dryRun,
            'manifest' => $manifestPath,
            'workspace_hash' => hash('sha256', $workspace),
            'promoted_count' => count($promotions),
            'promotions' => $promotions,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function baselineHistory(string $workspace): array
    {
        $root = $this->baselineRoot($workspace);
        $historyPath = $this->historyPath($root);
        $routes = $this->routeFilter();
        $limit = max(1, min(500, (int) ($this->option('limit') ?: 50)));
        $events = [];

        if (File::isFile($historyPath)) {
            $lines = array_reverse(file($historyPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []);
            foreach ($lines as $line) {
                $decoded = json_decode((string) $line, true);
                if (! is_array($decoded)) {
                    continue;
                }
                if ($routes !== [] && ! in_array((string) ($decoded['route'] ?? ''), $routes, true)) {
                    continue;
                }

                $events[] = $decoded;
                if (count($events) >= $limit) {
                    break;
                }
            }
        }

        return [
            'status' => 'completed',
            'workspace_hash' => hash('sha256', $workspace),
            'baseline_root_hash' => hash('sha256', $root),
            'history_log_hash' => hash('sha256', $historyPath),
            'count' => count($events),
            'events' => $events,
        ];
    }

    /**
     * @param  array<string,mixed>  $event
     */
    private function appendAuditEvent(string $root, array $event): void
    {
        AppendOnlyJsonlStore::appendUsingFilePutContents($this->historyPath($root), $event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @return array<int,string>
     */
    private function routeFilter(): array
    {
        return collect((array) $this->option('route'))
            ->filter(fn (mixed $route): bool => is_scalar($route) && trim((string) $route) !== '')
            ->map(fn (mixed $route): string => $this->normalizeRoute((string) $route))
            ->unique()
            ->values()
            ->all();
    }

    private function manifestPath(string $workspace): string
    {
        $manifest = trim((string) ($this->option('manifest') ?: 'atlas-visual-report/manifest.json'));
        if ($manifest === '') {
            $manifest = 'atlas-visual-report/manifest.json';
        }

        if (str_starts_with($manifest, '/')) {
            return $manifest;
        }

        $path = $workspace.'/'.ltrim($manifest, '/');
        $resolved = realpath($path);

        return $resolved ?: $path;
    }

    private function manifestArtifactPath(string $manifestPath, ?string $artifactDir, string $artifact): ?string
    {
        if (str_contains($artifact, '..') || str_starts_with($artifact, '/')) {
            return null;
        }

        $manifestDir = dirname($manifestPath);
        $artifactRoot = $artifactDir !== null && basename($manifestDir) !== basename($artifactDir)
            ? dirname($manifestDir).'/'.trim($artifactDir, '/')
            : $manifestDir;
        $path = $artifactRoot.'/'.ltrim($artifact, '/');
        $resolved = realpath($path);

        return $resolved ?: $path;
    }

    private function baselineRoot(string $workspace): string
    {
        return storage_path('app/engineering-visual-baselines/'.substr(hash('sha256', $workspace), 0, 16));
    }

    private function historyPath(string $root): string
    {
        return $root.'/_history.jsonl';
    }

    private function normalizeRoute(string $route): string
    {
        $route = trim($route);
        if ($route === '') {
            return '/';
        }

        return str_starts_with($route, '/') ? $route : '/'.$route;
    }

    private function routeSlug(string $route): string
    {
        $slug = Str::slug(trim($route, '/') ?: 'root');

        return $slug !== '' ? $slug : 'root';
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function render(array $payload): void
    {
        $this->newLine();
        $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Atlas Visual Baseline</>', (string) ($payload['status'] ?? 'unknown'));

        if (($payload['status'] ?? null) === 'failed') {
            $this->error((string) ($payload['reason'] ?? 'baseline_failed'));

            return;
        }

        if (array_key_exists('dry_run', $payload)) {
            $this->components->twoColumnDetail('Mode', (bool) $payload['dry_run'] ? 'dry-run' : 'applied');
            $this->components->twoColumnDetail('Promoted', (string) ($payload['promoted_count'] ?? 0));
            $rows = collect((array) ($payload['promotions'] ?? []))
                ->map(fn (array $promotion): array => [
                    $promotion['route'] ?? '-',
                    $promotion['status'] ?? '-',
                    $promotion['body_hash'] ?? '-',
                    $promotion['previous_body_hash'] ?? '-',
                ])
                ->all();
        } elseif (array_key_exists('events', $payload)) {
            $this->components->twoColumnDetail('Count', (string) ($payload['count'] ?? 0));
            $rows = collect((array) ($payload['events'] ?? []))
                ->map(fn (array $event): array => [
                    $event['route'] ?? '-',
                    $event['status'] ?? '-',
                    $event['body_hash'] ?? '-',
                    $event['promoted_at'] ?? '-',
                ])
                ->all();
        } else {
            $this->components->twoColumnDetail('Count', (string) ($payload['count'] ?? 0));
            $rows = collect((array) ($payload['baselines'] ?? []))
                ->map(fn (array $baseline): array => [
                    $baseline['route'] ?? '-',
                    $baseline['body_hash'] ?? '-',
                    $baseline['promoted_at'] ?? $baseline['created_at'] ?? '-',
                    $baseline['baseline_file'] ?? '-',
                ])
                ->all();
        }

        if ($rows !== []) {
            $this->newLine();
            $this->table(['route', 'status/hash', 'current/promoted', 'previous/file'], $rows);
        }
    }
}
