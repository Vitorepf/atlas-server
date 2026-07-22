<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\ExternalDeps;

final class AtlasLoopExternalDepInventoryReporter
{
    public function __construct(
        private readonly string $composerJsonPath = '',
        private readonly string $composerLockPath = '',
    ) {}

    /**
     * @return array{
     *     lock_present:bool,
     *     composer_json_present:bool,
     *     packages:list<array{
     *         name:string,
     *         dependency_type:string,
     *         declared_constraint:string,
     *         resolved_version:?string
     *     }>
     * }
     */
    public function inventory(): array
    {
        $composerJsonPath = $this->composerJsonPath !== '' ? $this->composerJsonPath : base_path('composer.json');
        $composerLockPath = $this->composerLockPath !== '' ? $this->composerLockPath : base_path('composer.lock');

        $composerJsonPresent = is_file($composerJsonPath);
        $lockPresent = is_file($composerLockPath);

        if (! $lockPresent) {
            return [
                'packages' => [],
                'lock_present' => false,
                'composer_json_present' => $composerJsonPresent,
            ];
        }

        $composerJson = $composerJsonPresent ? $this->decodeFile($composerJsonPath) : [];
        $composerLock = $this->decodeFile($composerLockPath);

        $require = $this->normalizeDeclaredDependencies($composerJson['require'] ?? []);
        $requireDev = $this->normalizeDeclaredDependencies($composerJson['require-dev'] ?? []);

        $resolvedRequire = $this->resolvedVersionsByName($composerLock['packages'] ?? []);
        $resolvedRequireDev = $this->resolvedVersionsByName($composerLock['packages-dev'] ?? []);

        return [
            'packages' => array_merge(
                $this->buildRows($require, 'require', $resolvedRequire),
                $this->buildRows($requireDev, 'require-dev', $resolvedRequireDev),
            ),
            'lock_present' => true,
            'composer_json_present' => $composerJsonPresent,
        ];
    }

    /**
     * @param  mixed  $value
     * @return array<string,string>
     */
    private function normalizeDeclaredDependencies(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $dependencies = [];
        foreach ($value as $name => $constraint) {
            if (! is_string($name) || ! is_string($constraint)) {
                continue;
            }

            $dependencies[$name] = $constraint;
        }

        ksort($dependencies, SORT_STRING);

        return $dependencies;
    }

    /**
     * @param  list<array<string,mixed>>  $packages
     * @return array<string,string>
     */
    private function resolvedVersionsByName(array $packages): array
    {
        $resolved = [];

        foreach ($packages as $package) {
            $name = $package['name'] ?? null;
            $version = $package['version'] ?? null;
            if (! is_string($name) || ! is_string($version)) {
                continue;
            }

            $resolved[$name] = $version;
        }

        ksort($resolved, SORT_STRING);

        return $resolved;
    }

    /**
     * @param  array<string,string>  $declaredDependencies
     * @param  array<string,string>  $resolvedVersions
     * @return list<array{
     *     name:string,
     *     dependency_type:string,
     *     declared_constraint:string,
     *     resolved_version:?string
     * }>
     */
    private function buildRows(array $declaredDependencies, string $dependencyType, array $resolvedVersions): array
    {
        $rows = [];

        foreach ($declaredDependencies as $name => $constraint) {
            $rows[] = [
                'name' => $name,
                'dependency_type' => $dependencyType,
                'declared_constraint' => $constraint,
                'resolved_version' => $resolvedVersions[$name] ?? null,
            ];
        }

        return $rows;
    }

    /**
     * @return array<string,mixed>
     */
    private function decodeFile(string $path): array
    {
        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : [];
    }
}
