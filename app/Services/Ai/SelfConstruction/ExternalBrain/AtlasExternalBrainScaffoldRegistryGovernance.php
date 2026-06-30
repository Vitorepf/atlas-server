<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure governance service. Validates scaffold registry entries and classifies
 * the registry's overall health.
 *
 * Input facts:
 *   entries — list of {id, version, status, safety_checks, provider_safe_summary,
 *              rollback_plan, compatible_with?}.
 *     version             — SemVer string (x.y.z).
 *     status              — 'active'|'retired'|'experimental'|'deprecated'.
 *     safety_checks       — non-empty list of check names (AC3 required).
 *     provider_safe_summary — non-empty string (AC3 required).
 *     rollback_plan       — non-empty string or non-empty array (AC3 required).
 *     compatible_with     — optional list of version strings.
 *
 * AC2 — structural validation:
 *   - version must match x.y.z (SemVer).
 *   - status must be one of the four allowed values.
 *   - compatible_with, if present, must be an array.
 *
 * AC3 — required-field rejection:
 *   - safety_checks absent or empty               → missing_safety_checks.
 *   - provider_safe_summary absent or empty       → missing_provider_safe_summary.
 *   - rollback_plan absent or empty               → missing_rollback_plan.
 *
 * registry_health:
 *   'healthy'  — zero rejections AND at least one valid active entry.
 *   'degraded' — some rejections but at least one valid active entry.
 *   'critical' — no valid active entries.
 *
 * AC4 outputs: valid_entries, rejected_entries, active_variants, retired_variants,
 *   registry_health.
 *
 * Pure, deterministic, no providers, no I/O.
 */
final class AtlasExternalBrainScaffoldRegistryGovernance
{
    public const SCHEMA = 'atlas.external_brain.scaffold_registry_governance.v1';

    private const ALLOWED_STATUSES = ['active', 'retired', 'experimental', 'deprecated'];
    private const SEMVER_PATTERN   = '/^\d+\.\d+\.\d+$/';

    /**
     * @param  array<string,mixed>  $facts
     * @return array<string,mixed>
     */
    public function govern(array $facts): array
    {
        $entries = is_array($facts['entries'] ?? null) ? $facts['entries'] : [];

        $validEntries    = [];
        $rejectedEntries = [];
        $activeVariants  = [];
        $retiredVariants = [];

        foreach ($entries as $entry) {
            $id      = (string) ($entry['id'] ?? '');
            $reasons = $this->validate($entry);

            if (empty($reasons)) {
                $validEntries[] = $id;
                $status = strtolower(trim((string) ($entry['status'] ?? '')));
                if ($status === 'active') {
                    $activeVariants[] = $id;
                } elseif ($status === 'retired') {
                    $retiredVariants[] = $id;
                }
            } else {
                $rejectedEntries[] = ['id' => $id, 'rejection_reasons' => $reasons];
            }
        }

        $health = $this->health(count($entries), count($rejectedEntries), count($activeVariants));

        return [
            'schema_version'   => self::SCHEMA,
            'valid_entries'    => $validEntries,
            'rejected_entries' => $rejectedEntries,
            'active_variants'  => $activeVariants,
            'retired_variants' => $retiredVariants,
            'registry_health'  => $health,
        ];
    }

    private function validate(mixed $entry): array
    {
        if (! is_array($entry)) {
            return ['invalid_entry_type'];
        }

        $reasons = [];

        // AC2: version format.
        $version = trim((string) ($entry['version'] ?? ''));
        if (! preg_match(self::SEMVER_PATTERN, $version)) {
            $reasons[] = 'invalid_version_format';
        }

        // AC2: status.
        $status = strtolower(trim((string) ($entry['status'] ?? '')));
        if (! in_array($status, self::ALLOWED_STATUSES, true)) {
            $reasons[] = 'invalid_status';
        }

        // AC2: compatible_with if present must be array.
        if (array_key_exists('compatible_with', $entry) && ! is_array($entry['compatible_with'])) {
            $reasons[] = 'invalid_compatible_with';
        }

        // AC3: safety_checks required and non-empty.
        $safetyChecks = is_array($entry['safety_checks'] ?? null) ? $entry['safety_checks'] : [];
        if (empty($safetyChecks)) {
            $reasons[] = 'missing_safety_checks';
        }

        // AC3: provider_safe_summary required and non-empty.
        $summary = trim((string) ($entry['provider_safe_summary'] ?? ''));
        if ($summary === '') {
            $reasons[] = 'missing_provider_safe_summary';
        }

        // AC3: rollback_plan required and non-empty.
        $rollback = $entry['rollback_plan'] ?? null;
        $rollbackEmpty = is_array($rollback) ? empty($rollback) : (trim((string) $rollback) === '');
        if ($rollback === null || $rollbackEmpty) {
            $reasons[] = 'missing_rollback_plan';
        }

        return $reasons;
    }

    private function health(int $total, int $rejected, int $activeValid): string
    {
        if ($activeValid === 0) {
            return 'critical';
        }
        if ($rejected > 0) {
            return 'degraded';
        }

        return 'healthy';
    }
}
