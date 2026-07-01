<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure pre-seed gate: the external brain may NOT enqueue a new Autonomous task proposal unless it
 * explicitly names the gap it closes, the consumer that will use the result, the proof gate that
 * verifies it, and the compounding effect it produces. A high-scoring proposal that merely adds a
 * detector/report with no real downstream consumer is a DETACHED ORGAN — it looks useful but never
 * feeds anything, so it never closes the Autonomous circuit and is rejected regardless of score.
 *
 * MANDATORY METADATA (missing OR blank ⇒ rejected, fail-closed):
 *   gap_closed          — the concrete system gap this proposal closes
 *   downstream_consumer — the organ/task/planner that will actually consume the result
 *   proof_gate          — the runnable gate that verifies the proposal did what it claims
 *   compounding_effect  — the expected compounding value beyond this one task
 *
 * DETACHED ORGAN (rejected even when all four fields above are technically present):
 *   organ_kind is 'detector' or 'report' AND downstream_consumer is blank, OR downstream_consumer
 *   names the proposal's own organ_id (a detector "consuming" itself is not a real consumer).
 *
 * OUTPUT: {schema, seedable, blockers, originator_gate_matrix, metadata} — metadata is preserved
 * verbatim (even when rejected) so Task Fabric can respec around exactly what is missing.
 *
 * Pure: no I/O, no provider calls, no queue mutation.
 */
final class AtlasExternalBrainNoGapOriginatorGate
{
    public const SCHEMA = 'atlas.external_brain.no_gap_originator_gate.v1';

    private const DETACHED_ORGAN_KINDS = ['detector', 'report'];

    /**
     * @param  array<string,mixed>  $proposal
     * @return array{schema:string, seedable:bool, blockers:list<string>, originator_gate_matrix:list<array<string,mixed>>, metadata:array<string,mixed>}
     */
    public function evaluate(array $proposal): array
    {
        $organId            = trim((string) ($proposal['organ_id'] ?? ''));
        $organKind          = strtolower(trim((string) ($proposal['organ_kind'] ?? '')));
        $gapClosed          = trim((string) ($proposal['gap_closed'] ?? ''));
        $downstreamConsumer = trim((string) ($proposal['downstream_consumer'] ?? ''));
        $proofGate          = trim((string) ($proposal['proof_gate'] ?? ''));
        $compoundingEffect  = trim((string) ($proposal['compounding_effect'] ?? ''));

        $isDetachedOrgan = in_array($organKind, self::DETACHED_ORGAN_KINDS, true)
            && ($downstreamConsumer === '' || ($organId !== '' && $downstreamConsumer === $organId));

        $matrix = [
            ['check' => 'gap_closed_present', 'passed' => $gapClosed !== ''],
            ['check' => 'downstream_consumer_present', 'passed' => $downstreamConsumer !== ''],
            ['check' => 'proof_gate_present', 'passed' => $proofGate !== ''],
            ['check' => 'compounding_effect_present', 'passed' => $compoundingEffect !== ''],
            ['check' => 'not_detached_organ', 'passed' => ! $isDetachedOrgan],
        ];

        $blockers = [];
        if ($gapClosed === '') {
            $blockers[] = 'missing_gap_closed';
        }
        if ($downstreamConsumer === '') {
            $blockers[] = 'missing_downstream_consumer';
        }
        if ($proofGate === '') {
            $blockers[] = 'missing_proof_gate';
        }
        if ($compoundingEffect === '') {
            $blockers[] = 'missing_compounding_effect';
        }
        if ($isDetachedOrgan) {
            $blockers[] = 'detached_organ';
        }

        return [
            'schema' => self::SCHEMA,
            'seedable' => $blockers === [],
            'blockers' => $blockers,
            'originator_gate_matrix' => $matrix,
            'metadata' => [
                'organ_id' => $organId,
                'organ_kind' => $organKind,
                'gap_closed' => $gapClosed,
                'downstream_consumer' => $downstreamConsumer,
                'proof_gate' => $proofGate,
                'compounding_effect' => $compoundingEffect,
            ],
        ];
    }
}
