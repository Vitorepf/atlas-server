<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\DeepFinding;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusDeepFindingEngineService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusScalarNormalizer;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusStringListNormalizer;

/**
 * Finding-construction core — extracted verbatim from
 * AreaFocusDeepFindingEngineService by the GOD-DEBULK split. Owns the single
 * makeFinding pipeline every deep-scan section routes through (enrich → hash →
 * priority → spec_seed), so the check families can live in their own Section
 * classes via plain DI instead of calling back into the façade. Taxonomy
 * constants stay public on the façade and are referenced qualified; bodies are
 * byte-identical to their pre-split façade originals.
 */
class DeepFindingFactory
{
    /** @var array<string,float> */
    private const CONFIDENCE_SCORE = [
        'high' => 0.9,
        'medium' => 0.6,
        'low' => 0.3,
    ];

    /** owner_candidate -> Self-Directed-Evolution spec_seed gap_kind. */
    private const OWNER_SPEC_GAP_KIND = [
        AreaFocusDeepFindingEngineService::OWNER_ATLAS_DEV => 'pipeline_not_proven',
        AreaFocusDeepFindingEngineService::OWNER_FORGE => 'partial_canon',
        AreaFocusDeepFindingEngineService::OWNER_AAEOS => 'partial_canon',
        AreaFocusDeepFindingEngineService::OWNER_SELF_DIRECTED_EVOLUTION => 'missing_service_class',
        AreaFocusDeepFindingEngineService::OWNER_EVIDENCE => 'pipeline_not_proven',
        AreaFocusDeepFindingEngineService::OWNER_PRODUCT_MODE => 'partial_canon',
    ];

    public function __construct(
        private readonly DeepFindingSupport $deepFindingSupport = new DeepFindingSupport,
    ) {}

    /**
     * @param  array<string,mixed>  $base
     * @param  array<string,mixed>  $focusConfig
     * @return array<string,mixed>
     */
    public function makeFinding(array $base, array $focusConfig): array
    {
        $areaId = (string) $base['area_id'];
        $focus = (string) $base['focus'];
        $kind = (string) $base['kind'];
        $owner = (string) $base['owner_candidate'];
        $severity = AreaFocusScalarNormalizer::severityOrMedium((string) $base['severity']);
        $confidence = $this->normalizeConfidence((string) ($base['confidence'] ?? 'medium'));
        $title = (string) $base['title'];

        $affectedPaths = AreaFocusStringListNormalizer::coercedStringValues($base['affected_paths'] ?? []);
        $affectedFiles = array_values(array_filter($affectedPaths, $this->deepFindingSupport->isCodePath(...)));
        $affectedDocs = array_values(array_filter($affectedPaths, static fn (string $p): bool => str_starts_with($p, 'docs/')));

        $sourceRef = (string) ($base['source_ref'] ?? ($kind.':'.$title));
        $raw = hash('sha256', json_encode([$areaId, $focus, $kind, $owner, $sourceRef], JSON_THROW_ON_ERROR));
        $findingId = 'afdf_'.substr($raw, 0, 16);
        $findingHash = 'sha256:'.$raw;

        $inFocus = $this->isInFocus($owner, $affectedPaths, $title.' '.(string) ($base['detail'] ?? ''), $focusConfig);
        $confidenceScore = self::CONFIDENCE_SCORE[$confidence] ?? 0.6;
        $priorityScore = (AreaFocusDeepFindingEngineService::SEVERITY_RANK[$severity] ?? 0) * 100
            + ($inFocus ? 50 : 0)
            + (int) round($confidenceScore * 10);

        $finding = [
            'schema_version' => AreaFocusDeepFindingEngineService::FINDING_SCHEMA,
            'finding_id' => $findingId,
            'finding_hash' => $findingHash,
            'area_id' => $areaId,
            'focus' => $focus,
            'title' => $title,
            'detail' => (string) ($base['detail'] ?? ''),
            'kind' => $kind,
            'severity' => $severity,
            'confidence' => $confidence,
            'confidence_score' => $confidenceScore,
            'owner_candidate' => $owner,
            'evidence_refs' => AreaFocusStringListNormalizer::coercedStringValues($base['evidence_refs'] ?? []),
            'affected_files' => $affectedFiles,
            'affected_docs' => $affectedDocs,
            'why_it_matters' => (string) ($base['why_it_matters'] ?? ''),
            'proposed_spec_title' => $this->proposedSpecTitle($kind, $title),
            'proposed_next_action' => (string) ($base['proposed_next_action'] ?? 'Operator review required.'),
            'in_focus' => $inFocus,
            'priority_score' => $priorityScore,
            'origin' => (string) ($base['origin'] ?? 'deep'),
            'origin_type' => (string) ($base['origin_type'] ?? $kind),
            'auto_execution_allowed' => false,
            'operator_review_required' => true,
        ];

        $finding['spec_seed'] = $this->specSeed($finding);

        return $finding;
    }

    public function normalizeConfidence(string $confidence): string
    {
        $confidence = strtolower(trim($confidence));

        return array_key_exists($confidence, self::CONFIDENCE_SCORE) ? $confidence : 'medium';
    }

    public function whyItMatters(string $kind, string $owner, string $detail): string
    {
        $base = match ($kind) {
            AreaFocusDeepFindingEngineService::KIND_TEST => 'Untested runtime in the development flow can regress silently and break the governed loop.',
            AreaFocusDeepFindingEngineService::KIND_DOC => 'Stale or missing canon lets the development flow drift from its source of truth.',
            AreaFocusDeepFindingEngineService::KIND_RISK => 'An unmanaged risk in the development flow can corrupt evidence or duplicate runtime authority.',
            AreaFocusDeepFindingEngineService::KIND_GAP => 'A gap in the development flow blocks work from reaching governed Dev/Forge execution.',
            AreaFocusDeepFindingEngineService::KIND_IMPLEMENTATION => 'An uncontracted capability has no reviewable spec, so the operator cannot safely authorise it.',
            AreaFocusDeepFindingEngineService::KIND_BUG => 'A failing gate hint signals the development flow may not be provably green.',
            default => 'This finding affects the integrity of the Atlas development flow.',
        };

        return $detail !== '' ? $base.' '.$detail : $base;
    }

    /**
     * Build a Self-Directed-Evolution-compatible gap candidate from a finding.
     * Shape matches {@see \App\Services\Ai\SelfDirectedEvolution\SelfDirectedEvolutionGapReadModelService}
     * candidates so it can flow straight into the Spec Proposal Adapter — drafted
     * by SDE, never here.
     *
     * @param  array<string,mixed>  $finding
     * @return array<string,mixed>
     */
    private function specSeed(array $finding): array
    {
        $owner = (string) $finding['owner_candidate'];
        $rawHash = (string) $finding['finding_hash'];

        return [
            'schema_version' => AreaFocusDeepFindingEngineService::SPEC_SEED_SCHEMA,
            'candidate_id' => 'gapc_'.substr(hash('sha256', 'deep_seed|'.$rawHash), 0, 16),
            'candidate_hash' => $rawHash,
            'source_owner' => $owner,
            'gap_kind' => self::OWNER_SPEC_GAP_KIND[$owner] ?? 'partial_canon',
            'title' => (string) $finding['title'],
            'rationale' => (string) $finding['why_it_matters'],
            'capability' => 'area_focus_'.(string) $finding['focus'],
            'risk_level' => (string) $finding['severity'],
            'evidence_refs' => $finding['evidence_refs'],
            'owner_doc_refs' => $finding['affected_docs'],
            'route_hint_owner' => $owner,
            'proposal_only' => true,
            'operator_review_required' => true,
        ];
    }

    private function proposedSpecTitle(string $kind, string $title): string
    {
        $prefix = match ($kind) {
            AreaFocusDeepFindingEngineService::KIND_BUG => 'Fix',
            AreaFocusDeepFindingEngineService::KIND_TEST => 'Pin with tests',
            AreaFocusDeepFindingEngineService::KIND_DOC => 'Restore canon for',
            AreaFocusDeepFindingEngineService::KIND_IMPLEMENTATION => 'Implement',
            AreaFocusDeepFindingEngineService::KIND_RUNTIME => 'Wire runtime for',
            AreaFocusDeepFindingEngineService::KIND_IMPROVEMENT => 'Improve',
            default => 'Resolve',
        };

        return $prefix.': '.$title;
    }

    /**
     * @param  list<string>  $affectedPaths
     * @param  array<string,mixed>  $focusConfig
     */
    private function isInFocus(string $owner, array $affectedPaths, string $text, array $focusConfig): bool
    {
        if (in_array($owner, [AreaFocusDeepFindingEngineService::OWNER_ATLAS_DEV, AreaFocusDeepFindingEngineService::OWNER_FORGE, AreaFocusDeepFindingEngineService::OWNER_AAEOS], true)) {
            return true;
        }
        $tokens = AreaFocusStringListNormalizer::coercedStringValues($focusConfig['tokens'] ?? []);
        $haystack = strtolower($text.' '.implode(' ', $affectedPaths));
        foreach ($tokens as $token) {
            if ($token !== '' && str_contains($haystack, $token)) {
                return true;
            }
        }

        return false;
    }
}
