<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\ForgeRivals;

use App\Services\Ai\Programming\AtlasForgeNativeRivalsCaseManifestService;
use RuntimeException;

/**
 * Atlas Forge Rivals · Cases Registry (v2).
 *
 * Canonical adapter on top of AtlasForgeNativeRivalsCaseManifestService
 * (single source of truth for the case body). Exposes a v2-shaped view to
 * the new operator battery flow with these guarantees:
 *
 *   - Presets: smoke, quick, release, full. Each maps to a list of case ids.
 *   - A preset that resolves to zero cases is a FATAL HARNESS BUG and
 *     throws EmptyPresetIsFatalHarnessBug — never a silent no-op battery.
 *   - Each case exposes exactly the 9 canonical fields the v2 schema needs.
 *
 * In Slice 0 every preset points at the legacy default case
 * (atlas-fair-claude-baseline-case-01). Slice 6 widens release/full with
 * additional curated cases when those cases are registered upstream.
 *
 * Tests inject a custom `$presetCases` map (e.g. `['smoke' => []]`) via the
 * constructor to exercise the zero-case fatal-bug path without reflection.
 */
final class AtlasForgeRivalsCasesRegistry
{
    public const SCHEMA_VERSION = 'atlas.forge.rivals.cases_registry.v1';

    public const PRESET_SMOKE = 'smoke';

    public const PRESET_QUICK = 'quick';

    public const PRESET_RELEASE = 'release';

    public const PRESET_FULL = 'full';

    /** @var list<string> */
    public const PRESETS = [
        self::PRESET_SMOKE,
        self::PRESET_QUICK,
        self::PRESET_RELEASE,
        self::PRESET_FULL,
    ];

    /** @var array<string,list<string>> */
    public const DEFAULT_PRESET_CASES = [
        self::PRESET_SMOKE => [AtlasForgeNativeRivalsCaseManifestService::DEFAULT_CASE_ID],
        self::PRESET_QUICK => [AtlasForgeNativeRivalsCaseManifestService::DEFAULT_CASE_ID],
        self::PRESET_RELEASE => [AtlasForgeNativeRivalsCaseManifestService::DEFAULT_CASE_ID],
        self::PRESET_FULL => [AtlasForgeNativeRivalsCaseManifestService::DEFAULT_CASE_ID],
    ];

    /** @var array<string,list<string>> */
    private readonly array $presetCases;

    /**
     * @param  array<string,list<string>>|null  $presetCases  Test seam — defaults to DEFAULT_PRESET_CASES.
     */
    public function __construct(
        private readonly AtlasForgeNativeRivalsCaseManifestService $legacyManifest,
        ?array $presetCases = null,
    ) {
        $this->presetCases = $presetCases ?? self::DEFAULT_PRESET_CASES;
    }

    /**
     * @return list<string>
     */
    public function presets(): array
    {
        return self::PRESETS;
    }

    /**
     * @return list<array{
     *   id:string,
     *   objective:string,
     *   allowed_files:list<string>,
     *   acceptance_criteria:list<string>,
     *   quick_test_command:string,
     *   full_test_command:string,
     *   expected_artifacts:list<string>,
     *   timeout_policy:array<string,int>,
     *   tags:list<string>
     * }>
     */
    public function casesForPreset(string $preset): array
    {
        $key = strtolower(trim($preset));
        if (! in_array($key, self::PRESETS, true)) {
            throw new \InvalidArgumentException(
                "Unknown preset: '{$preset}'. Supported: ".implode(', ', self::PRESETS).'.'
            );
        }

        $ids = $this->presetCases[$key] ?? [];
        if ($ids === []) {
            throw new EmptyPresetIsFatalHarnessBug(
                "Preset '{$key}' resolved to zero cases — fatal harness bug, never a legitimate operator state."
            );
        }

        return array_map(fn (string $id): array => $this->adaptLegacyCase($id, $key), $ids);
    }

    /**
     * @return array{
     *   id:string,
     *   objective:string,
     *   allowed_files:list<string>,
     *   acceptance_criteria:list<string>,
     *   quick_test_command:string,
     *   full_test_command:string,
     *   expected_artifacts:list<string>,
     *   timeout_policy:array<string,int>,
     *   tags:list<string>
     * }
     */
    private function adaptLegacyCase(string $caseId, string $preset): array
    {
        $manifest = $this->legacyManifest->manifest($caseId);
        $case = $manifest['case'] ?? null;
        if (! is_array($case) || ! isset($case['case_id'])) {
            throw new RuntimeException(
                "Cases registry could not adapt legacy case '{$caseId}' under preset '{$preset}'."
            );
        }

        $timeout = is_array($case['timeout_policy'] ?? null) ? $case['timeout_policy'] : [];
        $timeoutPolicy = [];
        foreach ($timeout as $k => $v) {
            $timeoutPolicy[(string) $k] = (int) $v;
        }

        return [
            'id' => (string) $case['case_id'],
            'objective' => (string) ($case['objective'] ?? ''),
            'allowed_files' => $this->stringList($case['allowed_files_scope'] ?? []),
            'acceptance_criteria' => $this->stringList($case['acceptance_gates'] ?? []),
            'quick_test_command' => (string) ($case['quick_test_command'] ?? ''),
            'full_test_command' => (string) ($case['full_test_command'] ?? ''),
            'expected_artifacts' => is_array($case['evidence_requirements'] ?? null)
                ? array_values(array_map(static fn ($k): string => (string) $k, array_keys($case['evidence_requirements'])))
                : [],
            'timeout_policy' => $timeoutPolicy,
            'tags' => ['preset:'.$preset, 'rivals:v2', 'forge:atlas-arm'],
        ];
    }

    /**
     * @param  mixed  $value
     * @return list<string>
     */
    private function stringList($value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_map(static fn ($v): string => (string) $v, $value));
    }
}
