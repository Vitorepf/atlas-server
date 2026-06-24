<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery\Cortex;

use JsonSerializable;

/**
 * The fused intent fact: a single purpose statement for a class, triangulated from up to three independent
 * legs (docblock extractor, decision history, sibling consensus), with an honest 0..100 confidence and any
 * detected conflicts between the legs. purpose_statement is null when no leg carried real evidence — the
 * triangulator NEVER fabricates an intent.
 */
final class TriangulatedIntentFact implements JsonSerializable
{
    /**
     * @param  array{extractor:list<string>, history:list<string>, siblings:list<string>}  $evidence
     * @param  list<string>  $conflicts
     */
    public function __construct(
        public readonly string $fqcn,
        public readonly ?string $purposeStatement,
        public readonly array $evidence,
        public readonly int $confidenceScore,
        public readonly array $conflicts,
    ) {
    }

    /**
     * @return array{fqcn:string, purpose_statement:?string, evidence:array{extractor:list<string>, history:list<string>, siblings:list<string>}, confidence_score:int, conflicts:list<string>}
     */
    public function toArray(): array
    {
        return [
            'fqcn' => $this->fqcn,
            'purpose_statement' => $this->purposeStatement,
            'evidence' => $this->evidence,
            'confidence_score' => $this->confidenceScore,
            'conflicts' => $this->conflicts,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
