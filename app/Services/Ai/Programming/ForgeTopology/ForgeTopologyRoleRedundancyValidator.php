<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\ForgeTopology;

use App\Services\Ai\Programming\AtlasForgeProviderTopologyService;

final class ForgeTopologyRoleRedundancyValidator
{
    private const SCHEMA_VERSION = 'atlas.aaeos.forge_role_redundancy.v1';

    private const DEFECT_REVIEWER_EQUALS_BUILDER = 'reviewer_equals_builder_provider';

    private const DEFECT_CRITICAL_REVIEWER_MISSING = 'critical_reviewer_missing';

    private const DEFECT_PRIMARY_BUILDER_MISSING = 'primary_builder_missing';

    /**
     * @param  array<string,mixed>  $roles
     * @return array{
     *     schema_version: string,
     *     coherent: bool,
     *     defects: list<array{code: string, detail: string}>,
     *     primary_provider: ?string,
     *     reviewer_provider: ?string,
     *     shares_provider: bool
     * }
     */
    public function inspect(array $roles): array
    {
        $primary = $this->identity($roles, AtlasForgeProviderTopologyService::ROLE_PRIMARY_BUILDER);
        $reviewer = $this->identity($roles, AtlasForgeProviderTopologyService::ROLE_CRITICAL_REVIEWER);

        $primaryPresent = $primary !== null;
        $reviewerPresent = $reviewer !== null;

        $sharesProvider = $primaryPresent
            && $reviewerPresent
            && $primary['provider'] === $reviewer['provider']
            && $primary['model'] === $reviewer['model'];

        $defects = [];

        if (! $primaryPresent) {
            $defects[] = [
                'code' => self::DEFECT_PRIMARY_BUILDER_MISSING,
                'detail' => 'primary_builder role is absent or has no provider identity',
            ];
        }

        if (! $reviewerPresent) {
            $defects[] = [
                'code' => self::DEFECT_CRITICAL_REVIEWER_MISSING,
                'detail' => 'critical_reviewer role is absent or has no provider identity',
            ];
        }

        if ($primaryPresent && $reviewerPresent && $sharesProvider) {
            $defects[] = [
                'code' => self::DEFECT_REVIEWER_EQUALS_BUILDER,
                'detail' => 'critical_reviewer shares the primary_builder provider and model, so review has no real redundancy',
            ];
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'coherent' => $defects === [],
            'defects' => $defects,
            'primary_provider' => $primaryPresent ? $primary['provider'] : null,
            'reviewer_provider' => $reviewerPresent ? $reviewer['provider'] : null,
            'shares_provider' => $sharesProvider,
        ];
    }

    /**
     * @param  array<string,mixed>  $roles
     * @return array{provider: string, model: string}|null
     */
    private function identity(array $roles, string $role): ?array
    {
        $entry = $roles[$role] ?? null;

        if (! is_array($entry)) {
            return null;
        }

        $provider = $this->normalize($entry['provider'] ?? null);

        if ($provider === '') {
            return null;
        }

        return [
            'provider' => $provider,
            'model' => $this->normalize($entry['model'] ?? null),
        ];
    }

    private function normalize(mixed $value): string
    {
        if (! is_string($value)) {
            return '';
        }

        return strtolower(trim($value));
    }
}
