<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\MultiProject;

use App\Support\CanonicalValue;

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

    public const FORBIDDEN_EVIDENCE_FIELDS = ['raw_prompt', 'provider_trace', 'operator_approval_receipt', 'human_approval'];

    /**
     * @param  array<string,array<string,mixed>>  $sections           per-section evidence map
     * @param  array<string,mixed>                $autonomyReadiness  output of AtlasProjectLaneAutonomyReadiness::compose()
     * @return array<string,mixed>
     */
    public function export(string $projectId, array $sections, array $autonomyReadiness): array
    {
        $normalizedSections = [];
        $missingSections = [];
        foreach (self::MANDATORY_SECTIONS as $key) {
            $data = $key === 'autonomy_readiness'
                ? $autonomyReadiness
                : (array) ($sections[$key] ?? []);
            foreach (self::FORBIDDEN_EVIDENCE_FIELDS as $f) {
                unset($data[$f]);
            }
            if ($data !== []) {
                $data['section_hash'] = hash('sha256', (string) json_encode(
                    CanonicalValue::canonicalize($data),
                    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
                ));
            } else {
                $missingSections[] = 'missing_section:'.$key;
            }
            $normalizedSections[$key] = $data;
        }

        $state = (string) ($autonomyReadiness['state'] ?? 'unknown');
        $blockers = array_values(array_merge(
            (array) ($autonomyReadiness['blockers'] ?? []),
            $missingSections,
        ));
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
            'dossier_ready' => $blockers === [],
            'provider_safe' => true,
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
        return (string) json_encode(CanonicalValue::canonicalize($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

}
