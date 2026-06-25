<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\MultiProject;

/**
 * Compact governance dossier for one project stewardship lane. Groups all auditable evidence
 * (admission + 8 other sections + autonomy readiness) into a single deterministic JSON structure.
 *
 * Output: {schema_version, dossier_id, project_id, state, mandatory_sections, sections,
 *          blockers, holds, proof_summary}
 *
 * Pure: no provider, no clock read internally (uses the autonomy readiness verdict for state), no
 * filesystem I/O. dossier_id is sha256 of canonical_json of the full structure minus dossier_id
 * itself — identical inputs ⇒ identical dossier_id.
 */
final class AtlasProjectLaneGovernanceDossier
{
    public const SCHEMA = 'atlas.multiproject.lane_governance_dossier.v1';

    public const MANDATORY_SECTIONS = [
        'admission',
        'namespace',
        'isolation',
        'verification_court',
        'release_governor',
        'receipt_policy',
        'rollback_policy',
        'knowledge_sync',
        'autonomy_readiness',
    ];

    /**
     * @param  array<string,array<string,mixed>>  $sections           per-section evidence map
     * @param  array<string,mixed>                $autonomyReadiness  output of AtlasProjectLaneAutonomyReadiness::compose()
     * @return array<string,mixed>
     */
    public function export(string $projectId, array $sections, array $autonomyReadiness): array
    {
        // Always include every mandatory section key, defaulting absent ones to an empty payload so
        // the dossier shape is deterministic across lanes.
        $normalizedSections = [];
        foreach (self::MANDATORY_SECTIONS as $key) {
            $normalizedSections[$key] = $key === 'autonomy_readiness'
                ? $autonomyReadiness
                : (array) ($sections[$key] ?? []);
        }

        $state = (string) ($autonomyReadiness['state'] ?? 'unknown');
        $blockers = array_values((array) ($autonomyReadiness['blockers'] ?? []));
        $holds = array_values((array) ($autonomyReadiness['holds'] ?? []));

        $proofSummary = [
            'admission_admitted' => (bool) ($normalizedSections['admission']['admitted'] ?? false),
            'isolation_passed' => (bool) ($normalizedSections['isolation']['passed'] ?? false),
            'verification_court_passed' => (bool) ($normalizedSections['verification_court']['passed'] ?? false),
            'release_governor_passed' => (bool) ($normalizedSections['release_governor']['passed'] ?? false),
            'receipt_policy_passed' => (bool) ($normalizedSections['receipt_policy']['passed'] ?? false),
            'rollback_policy_present' => $normalizedSections['rollback_policy'] !== [],
            'knowledge_sync_ready' => (bool) ($normalizedSections['knowledge_sync']['ready'] ?? false),
            'autonomy_state' => $state,
        ];

        $dossier = [
            'schema_version' => self::SCHEMA,
            'project_id' => $projectId,
            'state' => $state,
            'mandatory_sections' => self::MANDATORY_SECTIONS,
            'sections' => $normalizedSections,
            'blockers' => $blockers,
            'holds' => $holds,
            'proof_summary' => $proofSummary,
        ];
        $dossier['dossier_id'] = hash('sha256', $this->canonicalJson($dossier));

        return $dossier;
    }

    private function canonicalJson(mixed $value): string
    {
        return (string) json_encode($this->canonicalize($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn ($v) => $this->canonicalize($v), $value);
        }
        ksort($value);
        foreach ($value as $k => $v) {
            $value[$k] = $this->canonicalize($v);
        }

        return $value;
    }
}
