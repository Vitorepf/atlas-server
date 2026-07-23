<?php

declare(strict_types=1);

namespace App\Services\Ai\AobgWorkspaceOnboarding;

use App\Services\AtlasCode\AtlasCodeWorkspaceProfileService;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * Workspace-profile family for the AOBG workspace-onboarding façade: registering the
 * activated folder as an AWIS profile and inferring stack/test/build commands from the
 * repo layout. Depends only on the leaf support + the AWIS profile service; never on
 * another section.
 */
class WorkspaceProfileSection
{
    public function __construct(
        private readonly AtlasCodeWorkspaceProfileService $workspaceProfiles,
        private readonly AobgWorkspaceOnboardingSupport $support,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function registerWorkspaceProfile(string $workspacePath, string $workspaceId): array
    {
        try {
            $existing = $this->exactProfileForPathOrSlug($workspacePath, $workspaceId);
            $slug = is_array($existing) && trim((string) ($existing['slug'] ?? '')) !== ''
                ? trim((string) $existing['slug'])
                : $this->profileSlugForNewWorkspace($workspaceId, $workspacePath);
            $configured = $this->configuredProfileBySlug($slug);
            $parent = $this->parentProfileForPath($workspacePath, $slug);
            $inferred = $this->inferWorkspaceProfile($workspacePath);
            $existingIsDiscovery = $this->isDiscoveryProfile($existing);
            $policy = is_array($configured)
                ? $configured
                : ($existingIsDiscovery && is_array($parent) ? $parent : null);

            $profile = $this->workspaceProfiles->upsertPersistedProfile([
                'slug' => $slug,
                'name' => $this->profileString($configured, 'name', $this->profileString($existing, 'name', $inferred['name'])),
                'kind' => $this->profileString($configured, 'kind', $this->profileString($existing, 'kind', 'product')),
                'workspace_path' => $workspacePath,
                'repo_root' => $workspacePath,
                'production_status' => $this->profileString($policy, 'production_status', $this->profileString($existing, 'production_status', 'development')),
                'stack_summary' => $this->profileString($configured, 'stack_summary', $existingIsDiscovery ? $inferred['stack_summary'] : $this->profileString($existing, 'stack_summary', $inferred['stack_summary'])),
                'commands' => $this->profileMap($configured, 'commands', $this->profileMap($existing, 'commands', [])),
                'test_commands' => $this->profileList($existing, 'test_commands', $this->profileList($configured, 'test_commands', $inferred['test_commands'])),
                'build_commands' => $this->profileList($existing, 'build_commands', $this->profileList($configured, 'build_commands', $inferred['build_commands'])),
                'dev_server_command' => $this->profileString($existing, 'dev_server_command', null),
                'critical_areas' => $this->profileList($existing, 'critical_areas', $inferred['critical_areas']),
                'docs_status' => $this->profileString($policy, 'docs_status', $this->profileString($existing, 'docs_status', 'unknown')),
                'default_risk' => $this->profileString($policy, 'default_risk', $this->profileString($existing, 'default_risk', 'medium')),
                'deployment_notes' => $this->profileString($policy, 'deployment_notes', $this->profileString($existing, 'deployment_notes', '')),
                'surfaces_enabled' => $this->profileList($policy, 'surfaces_enabled', $this->profileList($existing, 'surfaces_enabled', ['atlas_ai', 'cartografia', 'code', 'atencao'])),
                'source' => 'aobg_workspace_activation',
                'status' => 'active',
            ]);

            return [
                'ok' => true,
                'reason' => 'workspace_profile_registered',
                'workspace' => $profile,
                'preserved_existing_profile' => is_array($existing),
                'preserved_config_profile' => is_array($configured),
                'inherited_parent_policy' => ! is_array($configured) && $existingIsDiscovery && is_array($parent),
            ];
        } catch (Throwable $e) {
            return [
                'ok' => false,
                'reason' => 'workspace_profile_registration_failed',
                'exception' => class_basename($e),
            ];
        }
    }

    /**
     * @return array<string,mixed>|null
     */
    private function exactProfileForPathOrSlug(string $workspacePath, string $workspaceId): ?array
    {
        $byPath = $this->workspaceProfiles->findByPath($workspacePath);
        if (is_array($byPath)) {
            return $byPath;
        }

        $bySlug = $this->workspaceProfiles->findBySlug($workspaceId);
        if (! is_array($bySlug)) {
            return null;
        }

        $path = $this->support->profilePath($bySlug);
        if ($path !== null && $this->samePath($path, $workspacePath)) {
            return $bySlug;
        }

        return null;
    }

    private function profileSlugForNewWorkspace(string $workspaceId, string $workspacePath): string
    {
        $parent = $this->parentProfileForPath($workspacePath, null);
        $parentSlug = trim((string) ($parent['slug'] ?? ''));
        $candidate = $parentSlug !== '' && $this->sameWorkspaceId($workspaceId, $parentSlug)
            ? $this->slugFromPath($workspacePath)
            : $this->profileSlug($workspaceId, $workspacePath);

        $existing = $this->workspaceProfiles->findBySlug($candidate);
        $existingPath = is_array($existing) ? $this->support->profilePath($existing) : null;
        if ($existingPath !== null && ! $this->samePath($existingPath, $workspacePath)) {
            $candidate = rtrim(substr($candidate, 0, 111), '-').'-'.substr(hash('sha256', $workspacePath), 0, 8);
        }

        return $candidate;
    }

    private function slugFromPath(string $workspacePath): string
    {
        $base = strtolower(trim(basename($workspacePath)));
        $base = preg_replace('/[^a-z0-9-]+/', '-', $base) ?? '';
        $base = trim($base, '-');
        if (strlen($base) < 3) {
            return 'workspace-'.substr(hash('sha256', $workspacePath), 0, 8);
        }

        return substr($base, 0, 120);
    }

    /**
     * @return array<string,mixed>|null
     */
    private function parentProfileForPath(string $workspacePath, ?string $childSlug): ?array
    {
        $workspacePath = rtrim(realpath($workspacePath) ?: $workspacePath, DIRECTORY_SEPARATOR);
        $childSlug = $childSlug !== null ? trim(strtolower($childSlug)) : null;
        $best = null;
        $bestLength = -1;

        foreach ($this->workspaceProfiles->listProfiles() as $profile) {
            $slug = trim(strtolower((string) ($profile['slug'] ?? '')));
            if ($childSlug !== null && $slug === $childSlug) {
                continue;
            }
            $root = $this->support->profilePath($profile);
            if ($root === null) {
                continue;
            }
            $root = rtrim(realpath($root) ?: $root, DIRECTORY_SEPARATOR);
            if ($workspacePath === $root || ! str_starts_with($workspacePath, $root.DIRECTORY_SEPARATOR)) {
                continue;
            }

            $length = strlen($root);
            if ($length > $bestLength) {
                $best = $profile;
                $bestLength = $length;
            }
        }

        return $best;
    }

    /**
     * @param  array<string,mixed>|null  $profile
     */
    private function isDiscoveryProfile(?array $profile): bool
    {
        return is_array($profile)
            && trim((string) ($profile['source'] ?? '')) === 'atlas-code-graph-index-all';
    }

    /**
     * @return array{name:string,stack_summary:string,test_commands:array<int,string>,build_commands:array<int,string>,critical_areas:array<int,string>}
     */
    private function inferWorkspaceProfile(string $workspacePath): array
    {
        $roots = $this->candidateProjectRoots($workspacePath);
        $testCommands = [];
        $buildCommands = [];
        $criticalAreas = [];
        $stack = [];

        foreach ($roots as $root) {
            $relative = $this->relativePath($workspacePath, $root);
            $prefix = $relative === '.' ? '' : 'cd '.$relative.' && ';
            if ($relative !== '.') {
                $criticalAreas[] = $relative;
            }

            $package = $this->packageScripts($root);
            if ($package !== []) {
                $runner = $this->nodeRunner($root);
                $stack[] = 'node';
                if (isset($package['test']) && ! str_contains(strtolower((string) $package['test']), 'no test specified')) {
                    $testCommands[] = $prefix.$runner.' run test';
                }
                if (isset($package['build'])) {
                    $buildCommands[] = $prefix.$runner.' run build';
                }
                if (is_file($root.DIRECTORY_SEPARATOR.'app.json') || is_file($root.DIRECTORY_SEPARATOR.'app.config.ts')) {
                    $stack[] = 'expo';
                }
            }

            if (is_file($root.DIRECTORY_SEPARATOR.'composer.json') || is_file($root.DIRECTORY_SEPARATOR.'artisan')) {
                $stack[] = is_file($root.DIRECTORY_SEPARATOR.'artisan') ? 'laravel' : 'php';
                if (is_file($root.DIRECTORY_SEPARATOR.'artisan')) {
                    $testCommands[] = $prefix.'php artisan test';
                }
            }

            if ($this->looksLikeStaticWebRoot($root)) {
                $stack[] = 'static-web';
                if ($this->hasFileMatching($root, ['*.js', 'tests/*.test.js', 'tests/*.test.mjs', 'tests/*.spec.js', 'tests/*.spec.mjs'])) {
                    $stack[] = 'javascript';
                }
                foreach ($this->staticWebTestCommands($root, $prefix) as $command) {
                    $testCommands[] = $command;
                }
            }
        }

        $stack = array_values(array_unique($stack));

        return [
            'name' => $this->humanName(basename($workspacePath) ?: 'workspace'),
            'stack_summary' => $stack === [] ? '' : implode(', ', $stack),
            'test_commands' => array_values(array_unique(array_slice($testCommands, 0, 12))),
            'build_commands' => array_values(array_unique(array_slice($buildCommands, 0, 12))),
            'critical_areas' => array_values(array_unique(array_slice($criticalAreas, 0, 24))),
        ];
    }

    /**
     * @return array<int,string>
     */
    private function candidateProjectRoots(string $workspacePath): array
    {
        $roots = [$workspacePath];
        try {
            foreach (File::directories($workspacePath) as $directory) {
                $base = basename($directory);
                if ($base === '' || str_starts_with($base, '.') || in_array($base, ['node_modules', 'vendor', 'storage'], true)) {
                    continue;
                }
                if ($this->looksLikeProjectRoot($directory)) {
                    $roots[] = $directory;
                }
            }
        } catch (Throwable) {
            return $roots;
        }

        return array_values(array_unique(array_slice($roots, 0, 18)));
    }

    private function looksLikeProjectRoot(string $directory): bool
    {
        foreach (['package.json', 'composer.json', 'artisan', 'vite.config.ts', 'vite.config.js', 'pyproject.toml', 'Cargo.toml'] as $marker) {
            if (is_file($directory.DIRECTORY_SEPARATOR.$marker)) {
                return true;
            }
        }

        return false;
    }

    private function looksLikeStaticWebRoot(string $root): bool
    {
        if (is_file($root.DIRECTORY_SEPARATOR.'index.html')) {
            return true;
        }

        return $this->hasFileMatching($root, ['*.html', 'css/*.css', 'tests/*.test.mjs', 'tests/*.test.js']);
    }

    /**
     * @param  array<int,string>  $patterns
     */
    private function hasFileMatching(string $root, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if ($this->globFiles($root, $pattern) !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int,string>
     */
    private function staticWebTestCommands(string $root, string $prefix): array
    {
        $tests = [];
        foreach (['tests/*.test.mjs', 'tests/*.test.js', 'tests/*.spec.mjs', 'tests/*.spec.js'] as $pattern) {
            foreach ($this->globFiles($root, $pattern) as $file) {
                $relative = $this->relativePath($root, $file);
                if ($relative !== '.') {
                    $tests[] = $relative;
                }
            }
        }
        $tests = array_values(array_unique(array_slice($tests, 0, 8)));
        if ($tests === []) {
            return [];
        }

        return [$prefix.'node --test '.implode(' ', $tests)];
    }

    /**
     * @return array<int,string>
     */
    private function globFiles(string $root, string $pattern): array
    {
        $matches = glob(rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$pattern);
        if (! is_array($matches)) {
            return [];
        }

        return array_values(array_filter($matches, static fn (string $path): bool => is_file($path)));
    }

    /**
     * @return array<string,mixed>
     */
    private function packageScripts(string $root): array
    {
        $path = $root.DIRECTORY_SEPARATOR.'package.json';
        if (! is_file($path) || ! is_readable($path)) {
            return [];
        }

        try {
            $json = json_decode((string) file_get_contents($path), true);
            $scripts = is_array($json) && is_array($json['scripts'] ?? null) ? $json['scripts'] : [];

            return $scripts;
        } catch (Throwable) {
            return [];
        }
    }

    private function nodeRunner(string $root): string
    {
        if (is_file($root.DIRECTORY_SEPARATOR.'pnpm-lock.yaml')) {
            return 'pnpm';
        }
        if (is_file($root.DIRECTORY_SEPARATOR.'yarn.lock')) {
            return 'yarn';
        }
        if (is_file($root.DIRECTORY_SEPARATOR.'bun.lockb')) {
            return 'bun';
        }

        return 'npm';
    }

    private function relativePath(string $base, string $path): string
    {
        $base = rtrim(realpath($base) ?: $base, DIRECTORY_SEPARATOR);
        $path = rtrim(realpath($path) ?: $path, DIRECTORY_SEPARATOR);
        if ($path === $base) {
            return '.';
        }
        if (str_starts_with($path, $base.DIRECTORY_SEPARATOR)) {
            return str_replace(DIRECTORY_SEPARATOR, '/', substr($path, strlen($base) + 1));
        }

        return $path;
    }

    private function humanName(string $value): string
    {
        $value = trim(preg_replace('/[^A-Za-z0-9]+/', ' ', $value) ?? $value);

        return $value === '' ? 'Workspace' : ucwords(strtolower($value));
    }

    private function samePath(string $left, string $right): bool
    {
        return rtrim(realpath($left) ?: $left, DIRECTORY_SEPARATOR)
            === rtrim(realpath($right) ?: $right, DIRECTORY_SEPARATOR);
    }

    private function sameWorkspaceId(string $left, string $right): bool
    {
        return $this->profileSlug($left, $left) === $this->profileSlug($right, $right);
    }

    private function profileSlug(string $workspaceId, string $workspacePath): string
    {
        $slug = strtolower(trim($workspaceId));
        $slug = preg_replace('/[^a-z0-9-]+/', '-', $slug) ?? '';
        $slug = trim($slug, '-');
        if (strlen($slug) < 3) {
            $slug = 'workspace-'.substr(hash('sha256', $workspacePath), 0, 8);
        }
        $slug = substr($slug, 0, 120);
        $slug = trim($slug, '-');

        return strlen($slug) >= 3 ? $slug : 'workspace-'.substr(hash('sha256', $workspacePath), 0, 8);
    }

    /**
     * @param  array<string,mixed>|null  $profile
     */
    private function profileString(?array $profile, string $key, ?string $fallback): ?string
    {
        $value = is_array($profile) ? ($profile[$key] ?? null) : null;
        if (is_string($value) && trim($value) !== '') {
            return trim($value);
        }

        return $fallback;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function configuredProfileBySlug(string $slug): ?array
    {
        try {
            $profiles = (array) config('atlas_projects.profiles', []);
            foreach ($profiles as $profile) {
                if (! is_array($profile)) {
                    continue;
                }
                if (trim(strtolower((string) ($profile['slug'] ?? $profile['id'] ?? ''))) === trim(strtolower($slug))) {
                    return $profile;
                }
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }

    /**
     * @param  array<string,mixed>|null  $profile
     * @param  array<int,string>  $fallback
     * @return array<int,string>
     */
    private function profileList(?array $profile, string $key, array $fallback): array
    {
        $value = is_array($profile) ? ($profile[$key] ?? null) : null;
        if (is_array($value)) {
            $list = [];
            foreach ($value as $item) {
                if (is_string($item) && trim($item) !== '') {
                    $list[] = trim($item);
                }
            }
            if ($list !== []) {
                return array_values(array_unique($list));
            }
        }

        return $fallback;
    }

    /**
     * @param  array<string,mixed>|null  $profile
     * @param  array<string,string>  $fallback
     * @return array<string,string>
     */
    private function profileMap(?array $profile, string $key, array $fallback): array
    {
        $value = is_array($profile) ? ($profile[$key] ?? null) : null;
        if (! is_array($value)) {
            return $fallback;
        }

        $out = [];
        foreach ($value as $mapKey => $mapValue) {
            if (! is_string($mapKey) || ! is_string($mapValue)) {
                continue;
            }
            if (trim($mapKey) === '' || trim($mapValue) === '') {
                continue;
            }
            $out[trim($mapKey)] = trim($mapValue);
        }

        return $out !== [] ? $out : $fallback;
    }
}
