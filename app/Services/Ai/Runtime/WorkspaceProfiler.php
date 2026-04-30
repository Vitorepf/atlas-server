<?php

namespace App\Services\Ai\Runtime;

use App\Support\AtlasSecurity;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

class WorkspaceProfiler
{
    public function profile(string $workspace, bool $refresh = false): WorkspaceProfile
    {
        $workspace = realpath($workspace) ?: $workspace;
        $cachePath = $this->cachePath($workspace);
        $ttl = (int) config('atlas.ai.runtime.profile_cache_ttl_seconds', 300);

        if (! $refresh && File::exists($cachePath) && File::lastModified($cachePath) >= time() - $ttl) {
            $cached = json_decode(File::get($cachePath), true);
            if (is_array($cached)) {
                return $this->fromArray($cached);
            }
        }

        $repoRoot = $this->run(['git', 'rev-parse', '--show-toplevel'], $workspace);
        $repoRoot = $repoRoot !== '' ? $repoRoot : null;
        $branch = $this->run(['git', 'branch', '--show-current'], $workspace) ?: null;
        $head = $this->run(['git', 'rev-parse', '--short', 'HEAD'], $workspace) ?: null;
        $dirtyFiles = $this->dirtyFiles($workspace);
        $files = $this->files($workspace);
        $scripts = $this->scripts($workspace);
        $profile = new WorkspaceProfile(
            workspace: $workspace,
            repoRoot: $repoRoot,
            branch: $branch,
            head: $head,
            dirtyFiles: $dirtyFiles,
            stack: $this->stack($workspace),
            packageManager: $this->packageManager($workspace),
            scripts: $scripts,
            testCommands: $this->testCommands($workspace, $scripts),
            files: $files,
            importantFiles: $this->importantFiles($workspace, $files),
            cacheKey: hash('sha256', implode('|', [$workspace, $repoRoot, $branch, $head, count($files), implode(',', $dirtyFiles)])),
            generatedAt: now()->toJSON(),
            metadata: [
                'file_count_sampled' => count($files),
                'cache_path' => $cachePath,
            ],
        );

        File::ensureDirectoryExists(dirname($cachePath));
        File::put($cachePath, json_encode($profile->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return $profile;
    }

    /**
     * @param  array<string,mixed>  $data
     */
    private function fromArray(array $data): WorkspaceProfile
    {
        return new WorkspaceProfile(
            workspace: (string) ($data['workspace'] ?? ''),
            repoRoot: is_string($data['repo_root'] ?? null) ? $data['repo_root'] : null,
            branch: is_string($data['branch'] ?? null) ? $data['branch'] : null,
            head: is_string($data['head'] ?? null) ? $data['head'] : null,
            dirtyFiles: array_values(array_filter((array) ($data['dirty_files'] ?? []), 'is_string')),
            stack: array_values(array_filter((array) ($data['stack'] ?? []), 'is_string')),
            packageManager: is_string($data['package_manager'] ?? null) ? $data['package_manager'] : null,
            scripts: array_filter((array) ($data['scripts'] ?? []), 'is_string'),
            testCommands: array_values(array_filter((array) ($data['test_commands'] ?? []), 'is_string')),
            files: array_values(array_filter((array) ($data['files'] ?? []), 'is_string')),
            importantFiles: array_values(array_filter((array) ($data['important_files'] ?? []), 'is_string')),
            cacheKey: (string) ($data['cache_key'] ?? ''),
            generatedAt: is_string($data['generated_at'] ?? null) ? $data['generated_at'] : null,
            metadata: is_array($data['metadata'] ?? null) ? $data['metadata'] : [],
        );
    }

    /**
     * @return array<int,string>
     */
    private function dirtyFiles(string $workspace): array
    {
        $status = $this->run(['git', 'status', '--short'], $workspace);
        if ($status === '') {
            return [];
        }

        return collect(explode("\n", $status))
            ->map(fn (string $line): string => trim($line))
            ->filter()
            ->take(100)
            ->values()
            ->all();
    }

    /**
     * @return array<int,string>
     */
    private function files(string $workspace): array
    {
        $max = (int) config('atlas.ai.runtime.profile_max_files', 1200);
        $output = $this->run([
            'rg',
            '--files',
            '--hidden',
            '--glob',
            '!.git',
            '--glob',
            '!node_modules',
            '--glob',
            '!vendor',
            '--glob',
            '!storage/framework',
            '--glob',
            '!storage/logs',
        ], $workspace, 10);

        if ($output === '') {
            $output = $this->run(['git', 'ls-files'], $workspace, 10);
        }

        return collect(explode("\n", $output))
            ->map(fn (string $line): string => trim($line))
            ->filter()
            ->take($max)
            ->values()
            ->all();
    }

    /**
     * @return array<int,string>
     */
    private function stack(string $workspace): array
    {
        $stack = [];

        if (File::exists($workspace.'/artisan')) {
            $stack[] = 'laravel';
            $stack[] = 'php';
        }

        if (File::exists($workspace.'/composer.json')) {
            $stack[] = 'composer';
        }

        if (File::exists($workspace.'/package.json')) {
            $package = $this->jsonFile($workspace.'/package.json');
            $deps = array_merge((array) ($package['dependencies'] ?? []), (array) ($package['devDependencies'] ?? []));
            $stack[] = 'node';
            if (isset($deps['expo'])) {
                $stack[] = 'expo';
            }
            if (isset($deps['react-native'])) {
                $stack[] = 'react-native';
            }
            if (isset($deps['@tamagui/core']) || isset($deps['tamagui'])) {
                $stack[] = 'tamagui';
            }
        }

        if (File::exists($workspace.'/vite.config.ts') || File::exists($workspace.'/vite.config.js')) {
            $stack[] = 'vite';
        }

        return array_values(array_unique($stack));
    }

    private function packageManager(string $workspace): ?string
    {
        foreach (['pnpm-lock.yaml' => 'pnpm', 'yarn.lock' => 'yarn', 'bun.lockb' => 'bun', 'package-lock.json' => 'npm'] as $file => $manager) {
            if (File::exists($workspace.'/'.$file)) {
                return $manager;
            }
        }

        return File::exists($workspace.'/package.json') ? 'npm' : (File::exists($workspace.'/composer.json') ? 'composer' : null);
    }

    /**
     * @return array<string,string>
     */
    private function scripts(string $workspace): array
    {
        $scripts = [];
        $package = $this->jsonFile($workspace.'/package.json');
        foreach ((array) ($package['scripts'] ?? []) as $name => $command) {
            if (is_string($name) && is_string($command)) {
                $scripts[$name] = $command;
            }
        }

        $composer = $this->jsonFile($workspace.'/composer.json');
        foreach ((array) ($composer['scripts'] ?? []) as $name => $command) {
            if (! is_string($name)) {
                continue;
            }

            $scripts['composer:'.$name] = is_array($command)
                ? implode(' && ', array_map(fn (mixed $part): string => (string) $part, $command))
                : (string) $command;
        }

        return $scripts;
    }

    /**
     * @param  array<string,string>  $scripts
     * @return array<int,string>
     */
    private function testCommands(string $workspace, array $scripts): array
    {
        $commands = [];

        if (File::exists($workspace.'/artisan')) {
            $commands[] = 'php artisan test';
        }

        foreach ($scripts as $name => $command) {
            if (str_contains(strtolower($name), 'test')) {
                $commands[] = str_starts_with($name, 'composer:')
                    ? 'composer '.substr($name, strlen('composer:'))
                    : ($this->packageManager($workspace) ?: 'npm').' run '.$name;
            }
        }

        if (File::exists($workspace.'/vendor/bin/phpunit')) {
            $commands[] = './vendor/bin/phpunit';
        }

        return array_values(array_unique($commands));
    }

    /**
     * @param  array<int,string>  $files
     * @return array<int,string>
     */
    private function importantFiles(string $workspace, array $files): array
    {
        $candidates = [
            'README.md',
            'composer.json',
            'package.json',
            'artisan',
            'routes/api.php',
            'routes/web.php',
            'config/atlas.php',
            'app/Services/Ai/AiPromptBuilder.php',
            'app/Services/Ai/AiWorker.php',
        ];

        return collect($candidates)
            ->filter(fn (string $path): bool => File::exists($workspace.'/'.$path) || in_array($path, $files, true))
            ->values()
            ->all();
    }

    /**
     * @return array<string,mixed>
     */
    private function jsonFile(string $path): array
    {
        if (! File::exists($path)) {
            return [];
        }

        $decoded = json_decode(File::get($path), true);

        return is_array($decoded) ? $decoded : [];
    }

    private function run(array $command, string $cwd, int $timeout = 5): string
    {
        try {
            $process = new Process($command, $cwd, AtlasSecurity::processEnv(profile: 'tool'));
            $process->setTimeout($timeout);
            $process->run();
        } catch (\Throwable) {
            return '';
        }

        return $process->isSuccessful() ? trim(AtlasSecurity::redactString($process->getOutput())) : '';
    }

    private function cachePath(string $workspace): string
    {
        return storage_path('app/ai/workspaces/'.hash('sha256', $workspace).'.json');
    }
}
