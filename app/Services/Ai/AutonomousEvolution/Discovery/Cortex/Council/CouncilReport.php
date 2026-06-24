<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Council;

/**
 * The output of {@see AtlasCortexCouncilTriangulator::triangulate()}. Carries the RAW facts from every lens
 * (preserved verbatim — never collapsed into a number), the AGREEMENTS where ≥2 lenses independently asserted
 * the same fact signature, and the DISAGREEMENTS (lens-emitted disagreement_signals + cross-lens fact-vs-fact
 * conflicts, all as first-class FACTS).
 *
 * PÉTREO: NO scalar 'score', 'verdict', 'rank', or 'winner' field — operator memory: "Disagreement is itself a
 * FACT". The downstream consumer reads facts/agreements/disagreements as evidence; no aggregate ranking is
 * computed here.
 */
final class CouncilReport
{
    /**
     * @param  string  $subjectId
     * @param  array<string,list<array<string,mixed>>>  $rawFactsByLens  lens_id => list<fact>
     * @param  list<array{fact:array<string,mixed>, supporting_lens_ids:list<string>}>  $agreements
     * @param  list<array{kind:string, signal:string, lens_id:?string, fact?:array<string,mixed>}>  $disagreements
     * @param  list<string>  $participatingLensIds
     */
    public function __construct(
        public readonly string $subjectId,
        public readonly array $rawFactsByLens,
        public readonly array $agreements,
        public readonly array $disagreements,
        public readonly array $participatingLensIds,
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'subject_id' => $this->subjectId,
            'raw_facts_by_lens' => $this->rawFactsByLens,
            'agreements' => $this->agreements,
            'disagreements' => $this->disagreements,
            'participating_lens_ids' => $this->participatingLensIds,
        ];
    }
}
