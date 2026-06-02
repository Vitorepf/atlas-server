<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

/**
 * S108 — L8FrameEvolutionProposalEnvelopeBuilder (block: L8 Transcendence, phase P1).
 *
 * Builds an `atlas.architecture.redesign_proposal.v1` envelope for L8-P1
 * (self-evolving FRAME, not self-evolving code). The L8 transcendence map says
 * frame evolution must START as an auditable proposal only: the builder declares
 * the structural changes, the motivating evidence, the expected metric deltas and
 * who proposed it — and NOTHING ELSE. It carries ZERO write/apply authority. The
 * envelope is a description handed to the downstream gate (S109+) and the
 * Architecture Evolution Proposal Runtime; the builder never mutates state,
 * never applies a redesign, never signs and never promotes.
 *
 * Source of truth: `atlas-architecture-evolution-proposal-runtime.md` (canonical
 * `atlas.architecture.redesign_proposal.v1` envelope schema, the canonical
 * sovereignty-layer list, and `motivating_evidence_min_obras: 5`) and
 * `atlas-aaeos-l8-transcendence-map.md` (L8-P1 "Frame evolution starts as an
 * auditable proposal only").
 *
 * Pure decision/builder function: no I/O, DB, Eloquent, facades, HTTP/provider
 * calls, git/Process, filesystem, clock/now() or randomness. The `proposal_id`
 * is a DETERMINISTIC content digest (sha256 of the normalized title + actor +
 * structural targets), so identical input always yields an identical envelope.
 * Every returned field is computed from the `$input` argument via real rules.
 *
 * Ordered build rules:
 *   1. motivating evidence — fewer than {@see MIN_MOTIVATING_OBRAS} obra-grounded
 *      evidence items blocks (`status = blocked`, `apply_authority = false`).
 *   2. structural changes — a proposal with no structural change targets blocks.
 *   3. proposed — otherwise the envelope is `ready_for_review`.
 *
 * Regardless of status, `apply_authority` and `write_authority` are ALWAYS false:
 * the envelope is an auditable proposal, never an applier.
 */
final class L8FrameEvolutionProposalEnvelopeBuilder
{
    /**
     * Canonical envelope schema. MUST match the Architecture Evolution Proposal
     * Runtime byte-for-byte.
     */
    public const SCHEMA = 'atlas.architecture.redesign_proposal.v1';

    /**
     * Canonical minimum count of obra-grounded motivating evidence items required
     * before a frame-evolution proposal is well-formed
     * (`motivating_evidence_min_obras: 5`). Below this the envelope blocks.
     */
    public const MIN_MOTIVATING_OBRAS = 5;

    public const STATUS_BLOCKED = 'blocked';

    public const STATUS_READY_FOR_REVIEW = 'ready_for_review';

    public const BLOCKER_MISSING_MOTIVATING_EVIDENCE = 'missing_motivating_evidence';

    public const BLOCKER_MISSING_STRUCTURAL_CHANGE = 'missing_structural_change';

    /**
     * Canonical sovereignty layers. A structural target that touches any of these
     * canonical parent docs flips `touches_sovereignty_layer` to true (the
     * downstream gate then demands an independent human reviewer). Mirrors the
     * "Camadas de Soberania (lista canonica)" block of the runtime doc.
     *
     * @var list<string>
     */
    private const SOVEREIGNTY_LAYERS = [
        'atlas-sovereign-operating-system',
        'atlas-epistemic-operating-system',
        'atlas-evidence-certification-runtime',
        'atlas-trust-ledger-canonical',
        'atlas-cartography-nomenclature-contract',
        'atlas-canonical-glossary-and-naming',
        'atlas-cognition-operating-system',
        'atlas-ai-knowledge-governance-system',
    ];

    /**
     * Canonical expected-metric-delta dimensions of the envelope.
     *
     * @var list<string>
     */
    private const METRIC_DIMENSIONS = ['throughput', 'reliability', 'operator_friction'];

    /**
     * @param  array<string, mixed>  $input
     * @return array{
     *     schema_version: string,
     *     proposal_id: string,
     *     title: string,
     *     structural_changes: list<array{target_doc: string, proposed_state_description: string, schema_changes: list<string>, layer_count_before: int, layer_count_after: int, touches_sovereignty_layer: bool}>,
     *     motivating_evidence: list<array{obra_id: string, limitation_observed: string, frequency: int}>,
     *     motivating_evidence_count: int,
     *     expected_metrics_delta: array{throughput: string, reliability: string, operator_friction: string},
     *     touches_sovereignty_layer: bool,
     *     sovereignty_layers_touched: list<string>,
     *     proposed_by_actor: array{kind: string, id: string, autonomy_level: string},
     *     status: string,
     *     blockers: list<string>,
     *     apply_authority: bool,
     *     write_authority: bool
     * }
     */
    public function build(array $input): array
    {
        $title = $this->stringValue($input, ['title', 'name'], '');
        $structuralChanges = $this->structuralChanges($input);
        $motivatingEvidence = $this->motivatingEvidence($input);
        $expectedMetricsDelta = $this->expectedMetricsDelta($input);
        $proposedByActor = $this->proposedByActor($input);

        $sovereigntyLayersTouched = $this->sovereigntyLayersTouched($structuralChanges);
        $touchesSovereigntyLayer = $sovereigntyLayersTouched !== [];

        // The canonical gate is `motivating_evidence_min_obras: 5` — a minimum of
        // DISTINCT obra-grounded works, not raw evidence rows. The schema gives each
        // item its own `frequency`, so one Obra observed N times is a single Obra,
        // not N. Counting duplicate obra_id rows would fail-open a proposal grounded
        // in fewer than five distinct Obras past a breadth gate.
        $distinctObraCount = $this->distinctObraCount($motivatingEvidence);

        $blockers = [];

        // Rule 1 — fewer than the canonical minimum DISTINCT obra-grounded works blocks.
        if ($distinctObraCount < self::MIN_MOTIVATING_OBRAS) {
            $blockers[] = self::BLOCKER_MISSING_MOTIVATING_EVIDENCE;
        }

        // Rule 2 — a proposal with no structural change target is not a frame change.
        if ($structuralChanges === []) {
            $blockers[] = self::BLOCKER_MISSING_STRUCTURAL_CHANGE;
        }

        $status = $blockers === [] ? self::STATUS_READY_FOR_REVIEW : self::STATUS_BLOCKED;

        return [
            'schema_version' => self::SCHEMA,
            'proposal_id' => $this->proposalId($title, $proposedByActor, $structuralChanges),
            'title' => $title,
            'structural_changes' => $structuralChanges,
            'motivating_evidence' => $motivatingEvidence,
            // Reports the spec-meaningful figure the gate keys on: distinct
            // obra-grounded works (not raw rows), matching `min_obras`.
            'motivating_evidence_count' => $distinctObraCount,
            'expected_metrics_delta' => $expectedMetricsDelta,
            'touches_sovereignty_layer' => $touchesSovereigntyLayer,
            'sovereignty_layers_touched' => $sovereigntyLayersTouched,
            'proposed_by_actor' => $proposedByActor,
            'status' => $status,
            'blockers' => $blockers,
            // The envelope is an auditable proposal only: never an applier, never a writer.
            'apply_authority' => false,
            'write_authority' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return list<array{target_doc: string, proposed_state_description: string, schema_changes: list<string>, layer_count_before: int, layer_count_after: int, touches_sovereignty_layer: bool}>
     */
    private function structuralChanges(array $input): array
    {
        $raw = $input['structural_changes'] ?? [];
        if (! is_array($raw)) {
            return [];
        }

        $changes = [];
        foreach ($raw as $change) {
            if (! is_array($change)) {
                continue;
            }

            $targetDoc = $this->stringValue($change, ['target_doc', 'target', 'doc'], '');
            if ($targetDoc === '') {
                continue;
            }

            $changes[] = [
                'target_doc' => $targetDoc,
                'proposed_state_description' => $this->stringValue(
                    $change,
                    ['proposed_state_description', 'description', 'proposed_state'],
                    '',
                ),
                'schema_changes' => $this->stringList($change, 'schema_changes'),
                'layer_count_before' => $this->intValue($change, 'layer_count_before', 0),
                'layer_count_after' => $this->intValue($change, 'layer_count_after', 0),
                'touches_sovereignty_layer' => $this->isSovereigntyLayer($targetDoc),
            ];
        }

        return $changes;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return list<array{obra_id: string, limitation_observed: string, frequency: int}>
     */
    private function motivatingEvidence(array $input): array
    {
        $raw = $input['motivating_evidence'] ?? [];
        if (! is_array($raw)) {
            return [];
        }

        $evidence = [];
        foreach ($raw as $item) {
            if (! is_array($item)) {
                continue;
            }

            $obraId = $this->stringValue($item, ['obra_id', 'obra', 'id'], '');
            if ($obraId === '') {
                // An evidence item with no obra anchor is not obra-grounded; drop it.
                continue;
            }

            $evidence[] = [
                'obra_id' => $obraId,
                'limitation_observed' => $this->stringValue(
                    $item,
                    ['limitation_observed', 'limitation', 'observed'],
                    '',
                ),
                'frequency' => max(0, $this->intValue($item, 'frequency', 1)),
            ];
        }

        return $evidence;
    }

    /**
     * Count DISTINCT obra ids across the validated evidence rows. The canonical
     * gate is `motivating_evidence_min_obras` — a breadth-of-works threshold — so
     * the same Obra cited on multiple rows counts once.
     *
     * @param  list<array{obra_id: string, limitation_observed: string, frequency: int}>  $evidence
     */
    private function distinctObraCount(array $evidence): int
    {
        $seen = [];
        foreach ($evidence as $item) {
            $seen[$item['obra_id']] = true;
        }

        return count($seen);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{throughput: string, reliability: string, operator_friction: string}
     */
    private function expectedMetricsDelta(array $input): array
    {
        $raw = $input['expected_metrics_delta'] ?? [];
        $source = is_array($raw) ? $raw : [];

        $delta = [];
        foreach (self::METRIC_DIMENSIONS as $dimension) {
            $delta[$dimension] = $this->stringValue($source, [$dimension], 'unspecified');
        }

        /** @var array{throughput: string, reliability: string, operator_friction: string} $delta */
        return $delta;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{kind: string, id: string, autonomy_level: string}
     */
    private function proposedByActor(array $input): array
    {
        $raw = $input['proposed_by_actor'] ?? $input['proposed_by'] ?? [];
        $source = is_array($raw) ? $raw : [];

        $kind = $this->stringValue($source, ['kind', 'type'], '');
        $kind = in_array($kind, ['agent', 'operator'], true) ? $kind : 'agent';

        return [
            'kind' => $kind,
            'id' => $this->stringValue($source, ['id', 'actor_id'], 'unknown'),
            'autonomy_level' => $this->autonomyLevel($source),
        ];
    }

    /**
     * @param  array<string, mixed>  $source
     */
    private function autonomyLevel(array $source): string
    {
        $value = $source['autonomy_level'] ?? $source['level'] ?? null;

        if (is_string($value) && trim($value) !== '') {
            return strtoupper(trim($value));
        }

        if (is_int($value)) {
            return 'L'.$value;
        }

        return 'L8';
    }

    /**
     * Distinct canonical sovereignty layers touched by the structural targets,
     * in canonical declaration order (stable, de-duplicated).
     *
     * @param  list<array{target_doc: string, proposed_state_description: string, schema_changes: list<string>, layer_count_before: int, layer_count_after: int, touches_sovereignty_layer: bool}>  $structuralChanges
     * @return list<string>
     */
    private function sovereigntyLayersTouched(array $structuralChanges): array
    {
        $targets = [];
        foreach ($structuralChanges as $change) {
            $targets[$this->normalizeDoc($change['target_doc'])] = true;
        }

        $touched = [];
        foreach (self::SOVEREIGNTY_LAYERS as $layer) {
            if (isset($targets[$layer])) {
                $touched[] = $layer;
            }
        }

        return $touched;
    }

    private function isSovereigntyLayer(string $targetDoc): bool
    {
        return in_array($this->normalizeDoc($targetDoc), self::SOVEREIGNTY_LAYERS, true);
    }

    private function normalizeDoc(string $targetDoc): string
    {
        $normalized = strtolower(trim($targetDoc));

        return preg_replace('/\.md$/', '', $normalized) ?? $normalized;
    }

    /**
     * Deterministic content-addressed proposal identifier. Pure: sha256 over the
     * normalized title, actor and ordered structural targets — no uuid, no clock,
     * no randomness. Identical input yields an identical id.
     *
     * @param  array{kind: string, id: string, autonomy_level: string}  $actor
     * @param  list<array{target_doc: string, proposed_state_description: string, schema_changes: list<string>, layer_count_before: int, layer_count_after: int, touches_sovereignty_layer: bool}>  $structuralChanges
     */
    private function proposalId(string $title, array $actor, array $structuralChanges): string
    {
        $targets = [];
        foreach ($structuralChanges as $change) {
            $targets[] = $this->normalizeDoc($change['target_doc']);
        }

        $digest = implode('|', [
            strtolower(trim($title)),
            $actor['kind'],
            $actor['id'],
            $actor['autonomy_level'],
            implode(',', $targets),
        ]);

        return 'arp_'.substr(hash('sha256', $digest), 0, 16);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $keys
     */
    private function stringValue(array $payload, array $keys, string $default): string
    {
        foreach ($keys as $key) {
            $value = $payload[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return $default;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    private function stringList(array $payload, string $key): array
    {
        $raw = $payload[$key] ?? [];
        if (! is_array($raw)) {
            return [];
        }

        $list = [];
        foreach ($raw as $value) {
            if (is_string($value) && trim($value) !== '') {
                $list[] = trim($value);
            }
        }

        return $list;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function intValue(array $payload, string $key, int $default): int
    {
        $value = $payload[$key] ?? $default;

        if (is_int($value)) {
            return $value;
        }

        // A float that is non-finite (NAN / ±INF) or outside the platform int range
        // is not a representable count/frequency. Casting such a value with (int)
        // emits a PHP warning — an observable side-effect that breaks this builder's
        // purity/determinism contract — so fall back to the declared default. Finite
        // in-range floats still truncate exactly as before.
        if (is_float($value)) {
            return $this->isIntRepresentableFloat($value) ? (int) $value : $default;
        }

        // A numeric string casts to int without a "not representable" warning even
        // when it overflows (it silently clamps to PHP_INT_MAX/MIN), so it needs no
        // finite/in-range guard — only float-typed values do.
        if (is_string($value) && is_numeric($value)) {
            return (int) $value;
        }

        return $default;
    }

    /**
     * Whether a float can be cast to int without a "not representable" warning:
     * it must be finite and within the platform integer range. Compared as floats
     * because PHP_INT_MAX rounds up when cast to float, so a strict `<` guards the
     * upper edge.
     */
    private function isIntRepresentableFloat(float $value): bool
    {
        return is_finite($value)
            && $value >= (float) PHP_INT_MIN
            && $value < (float) PHP_INT_MAX;
    }
}
