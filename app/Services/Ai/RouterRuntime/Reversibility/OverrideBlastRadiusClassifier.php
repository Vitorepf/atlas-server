<?php

declare(strict_types=1);

namespace App\Services\Ai\RouterRuntime\Reversibility;

final class OverrideBlastRadiusClassifier
{
    private const SCHEMA_VERSION = 'atlas.router.override_blast_radius.v1';

    private const RANKS = ['local' => 0, 'module' => 1, 'system' => 2];

    /**
     * @param array<string, mixed> $override
     *
     * @return array{schema_version: string, blast_radius: string, blast_rank: int, fail_closed: bool, reasons: list<array{code: string, detail: string}>, provider_invoked: false, classification_hash: string}
     */
    public function classify(array $override): array
    {
        $scope = is_string($override['scope'] ?? null) ? $override['scope'] : '';
        $modules = $this->stringList($override['affected_modules'] ?? null);
        $primary = is_string($override['primary_domain'] ?? null) ? $override['primary_domain'] : '';
        $secondary = $this->stringList($override['secondary_domains'] ?? null);

        $moduleCount = count($modules);
        $secondarySpread = $this->hasSecondarySpread($secondary, $primary);

        $reasons = [];
        $failClosed = false;

        if ($scope === 'system' || $moduleCount >= 3 || $secondarySpread) {
            $radius = 'system';
            $reasons[] = ['code' => 'system_signal', 'detail' => $this->detail($scope, $moduleCount, $secondarySpread, 'system')];
        } elseif ($scope === 'module' || ($moduleCount >= 1 && $moduleCount <= 2)) {
            $radius = 'module';
            $reasons[] = ['code' => 'module_signal', 'detail' => $this->detail($scope, $moduleCount, $secondarySpread, 'module')];
        } elseif ($scope === 'local' && $moduleCount === 0 && ! $secondarySpread) {
            $radius = 'local';
            $reasons[] = ['code' => 'local_signal', 'detail' => 'local scope with no module or domain spread'];
        } else {
            $radius = 'system';
            $failClosed = true;
            $reasons[] = ['code' => 'fail_closed_system', 'detail' => 'unrecognized scope with no module or domain signals; failing closed to system'];
        }

        $rank = $this->rankOf($radius);
        $payload = [$radius, $rank, $failClosed, $scope, $modules, $primary, $secondary];

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'blast_radius' => $radius,
            'blast_rank' => $rank,
            'fail_closed' => $failClosed,
            'reasons' => $reasons,
            'provider_invoked' => false,
            'classification_hash' => hash('sha256', (string) json_encode($payload)),
        ];
    }

    public function rankOf(string $radius): int
    {
        return self::RANKS[$radius] ?? 2;
    }

    private function detail(string $scope, int $moduleCount, bool $secondarySpread, string $radius): string
    {
        if ($scope === $radius) {
            return 'override declared ' . $radius . ' scope';
        }

        if ($secondarySpread && $radius === 'system') {
            return 'secondary domains diverge from primary domain';
        }

        return $moduleCount . ' affected modules map to ' . $radius . ' radius';
    }

    /**
     * @param list<string> $secondary
     */
    private function hasSecondarySpread(array $secondary, string $primary): bool
    {
        foreach ($secondary as $domain) {
            if ($domain !== '' && $domain !== $primary) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, 'is_string'));
    }
}
