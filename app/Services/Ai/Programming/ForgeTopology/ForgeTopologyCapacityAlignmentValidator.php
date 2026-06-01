<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\ForgeTopology;

final class ForgeTopologyCapacityAlignmentValidator
{
    private const SCHEMA_VERSION = 'atlas.aaeos.forge_capacity_alignment.v1';

    private const CAPACITY_STATE_UNAVAILABLE = 'unavailable';

    private const STATUS_SELECTED = 'selected';

    private const STATUS_FALLBACK_SELECTED = 'fallback_selected';

    private const DEFECT_PROVIDER_ABSENT = 'role_provider_absent_from_capacity';

    private const DEFECT_SELECTED_ON_EXHAUSTED = 'selected_role_on_exhausted_capacity';

    /**
     * @param  array<int, array<string, mixed>>  $roles
     * @param  array<int, array<string, mixed>>  $providerCapacity
     * @return array{
     *     schema_version: 'atlas.aaeos.forge_capacity_alignment.v1',
     *     coherent: bool,
     *     defects: list<array{code: string, role: string, provider: string}>,
     *     orphan_providers: list<string>,
     *     exhausted_selected: list<string>
     * }
     */
    public function inspect(array $roles, array $providerCapacity): array
    {
        $capacityIndex = $this->buildCapacityIndex($providerCapacity);

        $defects = [];
        $orphanProviders = [];
        $exhaustedSelected = [];

        foreach ($roles as $role) {
            if (! is_array($role)) {
                continue;
            }

            $provider = $this->stringField($role, 'provider');

            if ($provider === '') {
                continue;
            }

            $roleName = $this->stringField($role, 'role');

            if (! array_key_exists($provider, $capacityIndex)) {
                $orphanProviders[] = $provider;
                $defects[] = [
                    'code' => self::DEFECT_PROVIDER_ABSENT,
                    'role' => $roleName,
                    'provider' => $provider,
                ];

                continue;
            }

            if ($capacityIndex[$provider] !== self::CAPACITY_STATE_UNAVAILABLE) {
                continue;
            }

            if (! $this->isActiveSelection($this->stringField($role, 'status'))) {
                continue;
            }

            $exhaustedSelected[] = $roleName;
            $defects[] = [
                'code' => self::DEFECT_SELECTED_ON_EXHAUSTED,
                'role' => $roleName,
                'provider' => $provider,
            ];
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'coherent' => $defects === [],
            'defects' => $defects,
            'orphan_providers' => $orphanProviders,
            'exhausted_selected' => $exhaustedSelected,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $providerCapacity
     * @return array<string, string>
     */
    private function buildCapacityIndex(array $providerCapacity): array
    {
        $index = [];

        foreach ($providerCapacity as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $provider = $this->stringField($entry, 'provider');

            if ($provider === '') {
                continue;
            }

            $index[$provider] = $this->stringField($entry, 'capacity_state');
        }

        return $index;
    }

    private function isActiveSelection(string $status): bool
    {
        return $status === self::STATUS_SELECTED
            || $status === self::STATUS_FALLBACK_SELECTED;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function stringField(array $payload, string $key): string
    {
        $value = $payload[$key] ?? null;

        if (is_string($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return '';
    }
}
