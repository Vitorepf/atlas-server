<?php

namespace App\Services\Ai\Kernel\Capability;

use Illuminate\Support\Collection;

class AtlasCapabilityRegistry
{
    /**
     * @param  array<string,mixed>|null  $capabilities
     * @param  array<string,mixed>|null  $surfaces
     */
    public function __construct(
        private readonly ?array $capabilities = null,
        private readonly ?array $surfaces = null,
    ) {}

    /**
     * @return Collection<int,CapabilityManifest>
     */
    public function all(): Collection
    {
        return collect($this->capabilityConfig())
            ->map(fn (mixed $manifest, string $id): CapabilityManifest => CapabilityManifest::fromArray($id, is_array($manifest) ? $manifest : []))
            ->sortBy('id')
            ->values();
    }

    public function find(string $id): ?CapabilityManifest
    {
        return $this->all()->first(fn (CapabilityManifest $manifest): bool => $manifest->id === $id);
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    public function surfaces(): array
    {
        return $this->surfaceConfig();
    }

    /**
     * @return array<int,string>
     */
    public function surfaceCapabilities(string $surfaceId): array
    {
        $surface = $this->surfaceConfig()[$surfaceId] ?? [];
        $capabilities = is_array($surface) ? ($surface['capabilities'] ?? []) : [];

        if (! is_array($capabilities)) {
            return [];
        }

        return array_values(array_unique(array_filter(
            array_map(fn (mixed $capability): string => trim((string) $capability), $capabilities),
            fn (string $capability): bool => $capability !== '',
        )));
    }

    public function surfaceImplements(string $surfaceId, string $capabilityId): bool
    {
        return in_array($capabilityId, $this->surfaceCapabilities($surfaceId), true);
    }

    /**
     * @return array{valid:bool,errors:array<int,string>,warnings:array<int,string>,surface_coverage:array{valid:bool,errors:array<int,string>}}
     */
    public function complianceReport(): array
    {
        $errors = [];
        $warnings = [];
        $surfaceCoverageErrors = [];
        $surfaces = $this->surfaceConfig();

        foreach ($this->all() as $capability) {
            $this->validateManifestShape($capability, $errors);
            $this->validateSurfaceCoverage($capability, array_keys($surfaces), $errors, $surfaceCoverageErrors);

            foreach ($capability->requiredSurfaces as $surfaceId) {
                if (! array_key_exists($surfaceId, $surfaces)) {
                    $errors[] = "Capability {$capability->id} requires unknown surface {$surfaceId}.";

                    continue;
                }

                if (! $this->surfaceImplements($surfaceId, $capability->id)) {
                    $errors[] = "Surface {$surfaceId} must implement required capability {$capability->id}.";
                }
            }

            foreach ($capability->notSupported as $entry) {
                if (! array_key_exists($entry['surface'], $surfaces)) {
                    $errors[] = "Capability {$capability->id} not_supported references unknown surface {$entry['surface']}.";
                }

                if (($entry['reason'] ?? '') === '') {
                    $errors[] = "Capability {$capability->id} not_supported entry for {$entry['surface']} must include a reason.";
                }
            }

            foreach ($capability->testSuite as $path) {
                if (! is_file(base_path($path))) {
                    $errors[] = "Capability {$capability->id} declares missing test {$path}.";
                }
            }

            if ($capability->testSuite === []) {
                $errors[] = "Capability {$capability->id} must declare at least one test_suite entry.";
            }

            foreach ($capability->optionalSurfaces as $surfaceId) {
                if (! array_key_exists($surfaceId, $surfaces)) {
                    $warnings[] = "Capability {$capability->id} lists unknown optional surface {$surfaceId}.";
                }
            }
        }

        foreach ($surfaces as $surfaceId => $surface) {
            $declared = is_array($surface) && is_array($surface['capabilities'] ?? null)
                ? $surface['capabilities']
                : [];

            foreach ($declared as $capabilityId) {
                if ($this->find((string) $capabilityId) === null) {
                    $errors[] = "Surface {$surfaceId} declares unknown capability {$capabilityId}.";
                }
            }
        }

        return [
            'valid' => $errors === [],
            'errors' => array_values(array_unique($errors)),
            'warnings' => array_values(array_unique($warnings)),
            'surface_coverage' => [
                'valid' => $surfaceCoverageErrors === [],
                'errors' => array_values(array_unique($surfaceCoverageErrors)),
            ],
        ];
    }

    /**
     * @param  array<int,string>  $errors
     */
    private function validateManifestShape(CapabilityManifest $capability, array &$errors): void
    {
        if ($capability->schemaVersion !== 'atlas.capability.v1') {
            $errors[] = "Capability {$capability->id} has unsupported schema {$capability->schemaVersion}.";
        }

        foreach (['id' => $capability->id, 'version' => $capability->version, 'title' => $capability->title, 'owner' => $capability->owner] as $field => $value) {
            if ($value === '') {
                $errors[] = "Capability {$capability->id} is missing required field {$field}.";
            }
        }
    }

    /**
     * @param  array<int,string>  $surfaceIds
     * @param  array<int,string>  $errors
     * @param  array<int,string>  $surfaceCoverageErrors
     */
    private function validateSurfaceCoverage(CapabilityManifest $capability, array $surfaceIds, array &$errors, array &$surfaceCoverageErrors): void
    {
        $notSupportedSurfaces = array_values(array_unique(array_map(
            fn (array $entry): string => $entry['surface'],
            $capability->notSupported,
        )));

        $required = array_flip($capability->requiredSurfaces);
        $optional = array_flip($capability->optionalSurfaces);
        $notSupported = array_flip($notSupportedSurfaces);

        foreach ($surfaceIds as $surfaceId) {
            $classifications = 0;
            $classifications += isset($required[$surfaceId]) ? 1 : 0;
            $classifications += isset($optional[$surfaceId]) ? 1 : 0;
            $classifications += isset($notSupported[$surfaceId]) ? 1 : 0;

            if ($classifications === 0) {
                $message = "Capability {$capability->id} must classify surface {$surfaceId} as required, optional, or not_supported.";
                $errors[] = $message;
                $surfaceCoverageErrors[] = $message;
            }

            if ($classifications > 1) {
                $message = "Capability {$capability->id} classifies surface {$surfaceId} more than once.";
                $errors[] = $message;
                $surfaceCoverageErrors[] = $message;
            }
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function capabilityConfig(): array
    {
        $capabilities = $this->capabilities ?? config('atlas_ai.capabilities', []);

        return is_array($capabilities) ? $capabilities : [];
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function surfaceConfig(): array
    {
        $surfaces = $this->surfaces ?? config('atlas_ai.surfaces', []);

        return is_array($surfaces) ? $surfaces : [];
    }
}
