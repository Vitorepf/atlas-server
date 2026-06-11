<?php

namespace App\Services\Ai\Hermes\Mesh;

use App\Services\Ai\Hermes\HermesAdapterReceipt;
use App\Services\Ai\Hermes\Support\HermesStringListNormalizer;
use Illuminate\Support\Str;

/**
 * Governed profile catalog for the Atlas Executive Mesh.
 *
 * A profile maps a mesh role to a fixed shape of governed capabilities:
 * `['toolsets'=>string[],'provider'=>?string,'model'=>?string,'skills'=>string[]]`.
 * The catalog is owned by Atlas policy via
 * `config('atlas.ai.providers.hermes_cli.mesh.profiles', [])` keyed by role —
 * Hermes never authors it. This resolver is PURE and side-effect-free: it reads
 * the policy catalog, intersects the configured toolsets with what the passed
 * capability manifest (a read model) actually supports, and returns a sealed
 * `atlas.hermes.profile_resolution.v1` receipt. It NEVER calls a provider and
 * NEVER invents a provider/model not present in config.
 *
 * Fail-closed: if the role is absent OR the catalog is empty/invalid, it
 * resolves to a MINIMAL READ profile (toolsets=['file'], no provider, no model,
 * no skills) and marks `role_known=>false`. The manifest filter mirrors
 * {@see \App\Services\Ai\Hermes\HermesDelegationAdapter::allowedChildToolsets}
 * exactly: it only prunes toolsets WHEN the manifest carries at least one
 * `toolset` entry; in the stateless case (no toolset entries) the configured
 * toolsets are kept verbatim. `hermes_profile_can_decide` is always false and
 * `authority` is always `atlas`.
 */
class HermesProfileResolver
{
    use HermesAdapterReceipt;

    /**
     * @var array<int,string>
     */
    private const MINIMAL_READ_TOOLSETS = ['file'];

    /**
     * Resolve the governed profile for a mesh role.
     *
     * @param  array<int,array<string,mixed>>  $capabilityManifest
     * @return array<string,mixed>
     */
    public function resolve(string $role, array $capabilityManifest = []): array
    {
        $role = $this->string($role, 190) ?? '';

        $catalog = $this->catalog();
        $rawProfile = $role !== '' && isset($catalog[$role]) && is_array($catalog[$role])
            ? $catalog[$role]
            : null;

        $roleKnown = $rawProfile !== null;

        if (! $roleKnown) {
            // Fail-closed: unknown role or empty/invalid catalog -> minimal read.
            $configuredToolsets = self::MINIMAL_READ_TOOLSETS;
            $provider = null;
            $model = null;
            $skills = [];
        } else {
            $configuredToolsets = HermesStringListNormalizer::arrayUnique($rawProfile['toolsets'] ?? null, 120, dropFalsyStrings: true);
            if ($configuredToolsets === []) {
                $configuredToolsets = self::MINIMAL_READ_TOOLSETS;
            }
            $provider = $this->string($rawProfile['provider'] ?? null, 190);
            $model = $this->string($rawProfile['model'] ?? null, 190);
            $skills = HermesStringListNormalizer::arrayUnique($rawProfile['skills'] ?? null, 190, dropFalsyStrings: true);
        }

        $resolvedToolsets = $this->filterToolsets($configuredToolsets, $capabilityManifest);
        $droppedToolsets = collect($configuredToolsets)
            ->reject(fn (string $toolset): bool => in_array($toolset, $resolvedToolsets, true))
            ->unique()
            ->values()
            ->all();

        $receipt = [
            'schema_version' => 'atlas.hermes.profile_resolution.v1',
            'resolver' => 'hermes_profile_resolver',
            'authority' => 'atlas',
            'hermes_profile_can_decide' => false,
            'role' => $role !== '' ? $role : null,
            'role_known' => $roleKnown,
            'resolved' => [
                'toolsets' => $resolvedToolsets,
                'provider' => $provider,
                'model' => $model,
                'skills' => $skills,
            ],
            'dropped_toolsets' => $droppedToolsets,
            'manifest_filter_applied' => $this->manifestHasToolsetEntries($capabilityManifest),
            'status' => $roleKnown ? 'resolved_known_role' : 'resolved_minimal_read_fallback',
        ];

        return $this->withReceiptHash($receipt);
    }

    /**
     * The Atlas-owned profile catalog, keyed by role. Defaults to empty (no
     * known roles) so an unconfigured mesh fails closed to minimal read.
     *
     * @return array<string,mixed>
     */
    private function catalog(): array
    {
        $catalog = config('atlas.ai.providers.hermes_cli.mesh.profiles', []);

        return is_array($catalog) ? $catalog : [];
    }

    /**
     * Intersect the configured toolsets with what the manifest supports.
     *
     * Mirrors HermesDelegationAdapter::allowedChildToolsets: the manifest filter
     * is skipped entirely when the manifest carries no `toolset` entries (the
     * stateless case), so the configured toolsets are kept verbatim. When the
     * manifest DOES carry toolset entries, only present-and-supported toolsets
     * survive.
     *
     * @param  array<int,string>  $configuredToolsets
     * @param  array<int,array<string,mixed>>  $capabilityManifest
     * @return array<int,string>
     */
    private function filterToolsets(array $configuredToolsets, array $capabilityManifest): array
    {
        $manifestHasToolsets = $this->manifestHasToolsetEntries($capabilityManifest);

        return collect($configuredToolsets)
            ->reject(fn (string $toolset): bool => $manifestHasToolsets && ! $this->toolsetPresentInManifest($toolset, $capabilityManifest))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<int,array<string,mixed>>  $capabilityManifest
     */
    private function manifestHasToolsetEntries(array $capabilityManifest): bool
    {
        foreach ($capabilityManifest as $entry) {
            if (is_array($entry) && $this->string($entry['capability_class'] ?? null, 40) === 'toolset') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int,array<string,mixed>>  $capabilityManifest
     */
    private function toolsetPresentInManifest(string $toolset, array $capabilityManifest): bool
    {
        foreach ($capabilityManifest as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $class = $this->string($entry['capability_class'] ?? null, 40);
            $key = $this->string($entry['capability_key'] ?? null, 190);
            if ($class === 'toolset' && $key === $toolset && (bool) ($entry['supported'] ?? false) === true) {
                return true;
            }
        }

        return false;
    }

    private function string(mixed $value, int $limit): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : Str::limit($value, $limit, '');
    }
}
