<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Invariant-backed duplicate detector for Autonomous simplification: two organs are never merged
 * on class-name similarity alone. Duplicate confirmation requires at least TWO independent
 * semantic channels to agree — normalized_purpose, io_contract (inputs+outputs), consumer
 * overlap, evidence_refs overlap — name is never one of those channels, so a same-name-only or
 * suffix-only match (FooService vs FooServiceV2) can never by itself clear the floor.
 *
 * Input shape:
 *   { candidate_a: Candidate, candidate_b: Candidate }
 *   Candidate = {
 *       name?:               string,   // informational only — never a matching channel
 *       normalized_purpose?: string,
 *       io_contract?:        array{inputs?: list<string>, outputs?: list<string>},
 *       consumers?:          list<string>,
 *       evidence_refs?:      list<string>,
 *   }
 *
 * Pure: no I/O, no provider calls, deterministic — callers supply the structured facts.
 * @unwired-until 2026-08-05 (Obra #7 W2: capability testada aguardando consumidor; triagem 2026-07-06)
 */
final class AtlasExternalBrainSemanticDuplicateDetector
{
    public const SCHEMA = 'atlas.self_construction.external_brain.semantic_duplicate_detector.v1';

    /** Minimum number of independent semantic channels that must agree before duplicate_confirmed. */
    private const MIN_MATCHING_CHANNELS = 2;

    /**
     * @param  array{candidate_a?: array<string,mixed>, candidate_b?: array<string,mixed>}  $facts
     * @return array<string,mixed>
     */
    public function detect(array $facts): array
    {
        $a = is_array($facts['candidate_a'] ?? null) ? $facts['candidate_a'] : [];
        $b = is_array($facts['candidate_b'] ?? null) ? $facts['candidate_b'] : [];

        $purposeMatch = $this->purposeMatches($a, $b);
        $ioMatch = $this->ioContractMatches($a, $b);
        $consumerOverlap = array_intersect(
            $this->stringList($a['consumers'] ?? []),
            $this->stringList($b['consumers'] ?? []),
        );
        $evidenceOverlap = array_intersect(
            $this->stringList($a['evidence_refs'] ?? []),
            $this->stringList($b['evidence_refs'] ?? []),
        );

        $matchingChannels = array_keys(array_filter([
            'normalized_purpose' => $purposeMatch,
            'io_contract' => $ioMatch,
            'consumer_overlap' => $consumerOverlap !== [],
            'evidence_refs' => $evidenceOverlap !== [],
        ]));

        $confidence = count($matchingChannels);
        $duplicateConfirmed = $confidence >= self::MIN_MATCHING_CHANNELS;

        return [
            'schema' => self::SCHEMA,
            'duplicate_confirmed' => $duplicateConfirmed,
            'matching_channels' => $matchingChannels,
            'confidence' => $confidence,
            'reason' => $duplicateConfirmed
                ? 'multiple_semantic_channels_agree'
                : 'insufficient_semantic_evidence: name similarity alone never counts as a matching channel',
        ];
    }

    /**
     * @param  array<string,mixed>  $a
     * @param  array<string,mixed>  $b
     */
    private function purposeMatches(array $a, array $b): bool
    {
        $purposeA = strtolower(trim((string) ($a['normalized_purpose'] ?? '')));
        $purposeB = strtolower(trim((string) ($b['normalized_purpose'] ?? '')));

        return $purposeA !== '' && $purposeA === $purposeB;
    }

    /**
     * @param  array<string,mixed>  $a
     * @param  array<string,mixed>  $b
     */
    private function ioContractMatches(array $a, array $b): bool
    {
        $ioA = is_array($a['io_contract'] ?? null) ? $a['io_contract'] : [];
        $ioB = is_array($b['io_contract'] ?? null) ? $b['io_contract'] : [];

        $inputsA = $this->stringList($ioA['inputs'] ?? []);
        $outputsA = $this->stringList($ioA['outputs'] ?? []);

        if ($inputsA === [] && $outputsA === []) {
            return false;
        }

        return $this->setsEqual($inputsA, $this->stringList($ioB['inputs'] ?? []))
            && $this->setsEqual($outputsA, $this->stringList($ioB['outputs'] ?? []));
    }

    /** @return list<string> */
    private function stringList(mixed $value): array
    {
        return array_values(array_map('strval', (array) $value));
    }

    /**
     * @param  list<string>  $a
     * @param  list<string>  $b
     */
    private function setsEqual(array $a, array $b): bool
    {
        sort($a);
        sort($b);

        return $a === $b;
    }
}
