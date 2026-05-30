<?php

declare(strict_types=1);

namespace App\Services\Ai\Foundry\Frontier\Armor;

use App\Services\Ai\AutonomousEvolution\AtlasAutonomousEvolutionLoopService;
use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionSubsystemBuilderService;
use App\Services\Ai\SelfDirectedEvolution\SelfDirectedEvolutionGapReadModelService;
use App\Services\Ai\SelfImprovement\AtlasSelfImprovementProposalBacklogService;

/**
 * Foundry AP-C · Armor stage I9 — Drift Mapper Gate.
 *
 * HARD adversarial gate. Each proposed packet MUST map to a MEASURED canonical
 * property, else the proposal is dropped with a machine-readable reason. This
 * fixes weak armor: it is NOT enough for a packet to name a real in-vocabulary
 * subsystem/schema — that target must additionally appear in the dossier's
 * cycle_receipts[] or evidence_packs[] carrying a CONCRETE measured value
 * (a numeric baseline/metric field OR a measured assertion {operator, baseline,
 * threshold}). A fabricated mapping naming a real-but-unmeasured subsystem is
 * dropped (canonical_property_unmeasured).
 *
 * GENERATES NOTHING. WRITES NOTHING canonical/code/production. No provider.
 * Pure rules engine; deterministic verdict hash via MissionCanonicalHash::sha256.
 *
 * Drop reasons (each independently able to drop a proposal):
 *   - empty_packet_mapping          : a packet has no/empty canonical_property_mapping.
 *   - packet_unmapped_to_canonical  : {area_id,subsystem,schema} not in canonical vocabulary.
 *   - canonical_property_unmeasured : in-vocabulary mapping but no measured-value
 *                                     entry in the dossier for that target.
 */
final class FrontierDriftMapperGate
{
    public const STAGE_ID = 'I9';

    public const REASON_EMPTY = 'empty_packet_mapping';

    public const REASON_UNMAPPED = 'packet_unmapped_to_canonical';

    public const REASON_UNMEASURED = 'canonical_property_unmeasured';

    /**
     * Canonical source-schema vocabulary (mapping target `schema` must be one).
     * Reused from the owning subsystems — never re-declared here.
     *
     * @var list<string>
     */
    private const CANONICAL_SCHEMAS = [
        AtlasSelfConstructionSubsystemBuilderService::PROPOSAL_SCHEMA,
        AtlasSelfImprovementProposalBacklogService::ITEM_SCHEMA_VERSION,
        AtlasAutonomousEvolutionLoopService::CONTROL_PLANE_SCHEMA,
        SelfDirectedEvolutionGapReadModelService::CANDIDATE_SCHEMA,
    ];

    /**
     * Canonical subsystem (gap_kind) vocabulary. Reused from the gap read model
     * owner's published gap_kind values — never re-declared as new domain truth.
     *
     * @var list<string>
     */
    private const CANONICAL_SUBSYSTEMS = [
        // self-construction subsystem builder kinds
        'missing_service_class',
        'pipeline_not_proven',
        'undocumented_subsystem',
        'partial_subsystem',
        // self-improvement backlog
        'self_improvement_backlog_item',
        // aael control-plane derived gap kinds
        'autonomous_evolution_stalled',
        'autonomous_evolution_no_progress',
        'autonomous_evolution_blocked_streak',
    ];

    /**
     * Adjudicate one proposal's per-packet canonical_property_mapping against the
     * MEASURED-property predicate, using ONLY the Harvester dossier as the source
     * of measured signal.
     *
     * @param  array<string,mixed>  $proposal  evolution_proposal.v1 (13-key projection)
     * @param  array<string,mixed>  $dossier   atlas.foundry.dossier.v1
     * @return array{
     *     stage:string,
     *     proposal_id:string,
     *     passed:bool,
     *     reason:?string,
     *     detail:?string,
     *     packet_results:list<array<string,mixed>>,
     *     measured_targets_count:int,
     *     verdict_hash:string
     * }
     */
    public function adjudicate(array $proposal, array $dossier): array
    {
        $proposalId = is_string($proposal['proposal_id'] ?? null) ? $proposal['proposal_id'] : '';
        $measuredTargets = $this->measuredTargets($dossier);

        $packets = $this->listOfArrays($proposal['proposed_packets'] ?? null);

        $packetResults = [];
        $passed = true;
        $reason = null;
        $detail = null;

        foreach ($packets as $index => $packet) {
            $packetId = is_string($packet['packet_id'] ?? null) ? $packet['packet_id'] : 'packet#'.$index;
            $mapping = $packet['canonical_property_mapping'] ?? null;

            [$packetReason, $packetDetail] = $this->evaluatePacket($mapping, $measuredTargets);

            $packetResults[] = [
                'packet_id' => $packetId,
                'mapping' => is_array($mapping) ? $this->mappingProjection($mapping) : null,
                'passed' => $packetReason === null,
                'reason' => $packetReason,
                'detail' => $packetDetail,
            ];

            if ($packetReason !== null && $passed) {
                $passed = false;
                $reason = $packetReason;
                $detail = $packetDetail;
            }
        }

        if ($packets === []) {
            $passed = false;
            $reason = self::REASON_EMPTY;
            $detail = 'proposal carries no proposed_packets to map';
        }

        $verdict = [
            'stage' => self::STAGE_ID,
            'proposal_id' => $proposalId,
            'passed' => $passed,
            'reason' => $reason,
            'detail' => $detail,
            'packet_results' => $packetResults,
            'measured_targets_count' => count($measuredTargets),
        ];
        $verdict['verdict_hash'] = 'sha256:'.MissionCanonicalHash::sha256($verdict);

        return $verdict;
    }

    /**
     * Evaluate a single packet mapping.
     *
     * @param  mixed  $mapping
     * @param  array<string,true>  $measuredTargets  set of measured target keys
     * @return array{0:?string,1:?string}  [reason|null, detail|null]
     */
    private function evaluatePacket(mixed $mapping, array $measuredTargets): array
    {
        if (! is_array($mapping)) {
            return [self::REASON_EMPTY, 'canonical_property_mapping missing'];
        }

        $areaId = $this->str($mapping['area_id'] ?? null);
        $subsystem = $this->str($mapping['subsystem'] ?? null);
        $schema = $this->str($mapping['schema'] ?? null);

        if ($areaId === '' || $subsystem === '' || $schema === '') {
            return [self::REASON_EMPTY, 'canonical_property_mapping has empty area_id/subsystem/schema'];
        }

        $inVocabulary = in_array($subsystem, self::CANONICAL_SUBSYSTEMS, true)
            && in_array($schema, self::CANONICAL_SCHEMAS, true);

        if (! $inVocabulary) {
            return [
                self::REASON_UNMAPPED,
                'subsystem/schema not in canonical vocabulary: '.$subsystem.' / '.$schema,
            ];
        }

        $targetKey = $this->targetKey($areaId, $subsystem, $schema);
        if (! isset($measuredTargets[$targetKey])) {
            return [
                self::REASON_UNMEASURED,
                'no measured baseline/metric entry in dossier for '.$targetKey,
            ];
        }

        return [null, null];
    }

    /**
     * Scan dossier cycle_receipts[] + evidence_packs[] for entries that carry a
     * CONCRETE measured value AND identify a {area_id,subsystem,schema} target.
     * Returns a set keyed by targetKey.
     *
     * @param  array<string,mixed>  $dossier
     * @return array<string,true>
     */
    private function measuredTargets(array $dossier): array
    {
        $entries = array_merge(
            $this->listOfArrays($dossier['cycle_receipts'] ?? null),
            $this->listOfArrays($dossier['evidence_packs'] ?? null),
        );

        $targets = [];
        foreach ($entries as $entry) {
            if (! $this->hasConcreteMeasure($entry)) {
                continue;
            }
            foreach ($this->entryTargetKeys($entry, $dossier) as $key) {
                $targets[$key] = true;
            }
        }

        return $targets;
    }

    /**
     * Concrete measure-signal predicate: a numeric baseline/metric field OR a
     * measured assertion {operator, baseline, threshold} on the same entry.
     *
     * @param  array<string,mixed>  $entry
     */
    private function hasConcreteMeasure(array $entry): bool
    {
        // Numeric baseline/metric field anywhere shallow on the entry.
        foreach (['baseline', 'metric', 'measured_value', 'post_value', 'value'] as $field) {
            if (array_key_exists($field, $entry) && $this->isNumeric($entry[$field])) {
                return true;
            }
        }

        // A measured assertion {operator, baseline, threshold}.
        foreach ($this->candidateAssertions($entry) as $assertion) {
            if (
                is_array($assertion)
                && array_key_exists('operator', $assertion)
                && is_string($assertion['operator']) && $assertion['operator'] !== ''
                && array_key_exists('baseline', $assertion) && $this->isNumeric($assertion['baseline'])
                && array_key_exists('threshold', $assertion) && $this->isNumeric($assertion['threshold'])
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Assertion-bearing sub-shapes to inspect for a measured assertion.
     *
     * @param  array<string,mixed>  $entry
     * @return list<mixed>
     */
    private function candidateAssertions(array $entry): array
    {
        $out = [$entry];
        foreach (['measured_assertion', 'assertion', 'measure', 'stable_metric'] as $field) {
            if (isset($entry[$field]) && is_array($entry[$field])) {
                $out[] = $entry[$field];
            }
        }
        foreach (['measured_assertions', 'assertions', 'stable_metrics', 'measures'] as $field) {
            foreach ($this->listOfArrays($entry[$field] ?? null) as $row) {
                $out[] = $row;
            }
        }

        return $out;
    }

    /**
     * Target keys an entry identifies. subsystem/schema come from the entry;
     * area_id falls back to the dossier area_id when the entry omits it.
     *
     * @param  array<string,mixed>  $entry
     * @param  array<string,mixed>  $dossier
     * @return list<string>
     */
    private function entryTargetKeys(array $entry, array $dossier): array
    {
        $entryArea = $this->str($entry['area_id'] ?? null);
        $areaId = $entryArea !== '' ? $entryArea : $this->str($dossier['area_id'] ?? null);
        $subsystem = $this->str($entry['subsystem'] ?? null);
        $schema = $this->str($entry['schema'] ?? ($entry['schema_version'] ?? null));

        if ($areaId === '' || $subsystem === '' || $schema === '') {
            return [];
        }

        return [$this->targetKey($areaId, $subsystem, $schema)];
    }

    private function targetKey(string $areaId, string $subsystem, string $schema): string
    {
        return $areaId.'|'.$subsystem.'|'.$schema;
    }

    /**
     * @param  array<string,mixed>  $mapping
     * @return array<string,mixed>
     */
    private function mappingProjection(array $mapping): array
    {
        return [
            'area_id' => $this->str($mapping['area_id'] ?? null),
            'subsystem' => $this->str($mapping['subsystem'] ?? null),
            'schema' => $this->str($mapping['schema'] ?? null),
            'measured_signal_ref' => $this->str($mapping['measured_signal_ref'] ?? null),
        ];
    }

    private function isNumeric(mixed $value): bool
    {
        return is_int($value) || is_float($value)
            || (is_string($value) && $value !== '' && is_numeric($value));
    }

    private function str(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    /**
     * @param  mixed  $value
     * @return list<array<string,mixed>>
     */
    private function listOfArrays(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $row) {
            if (is_array($row)) {
                $out[] = $row;
            }
        }

        return $out;
    }
}
