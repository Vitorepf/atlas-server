<?php

namespace App\Services\Ai\Programming\Sdd\Gates;

use App\Models\AtlasSpec;
use App\Services\Ai\Programming\Sdd\AssumptionLedger;

/**
 * Blocks SDD pipeline progression when there is at least one unresolved
 * `blocking_ambiguity` assumption on the spec.
 */
class SddClarificationGate
{
    public function __construct(
        private readonly AssumptionLedger $ledger,
    ) {}

    /**
     * @return array{status:string,blocking_count:int,questions:list<string>}
     */
    public function evaluate(AtlasSpec $spec): array
    {
        $blocking = $this->ledger->blockingFor($spec);
        $questions = [];
        foreach ($blocking as $assumption) {
            foreach ((array) $assumption->clarification_questions_json as $q) {
                if (is_string($q) && trim($q) !== '') {
                    $questions[] = $q;
                }
            }
            if ($questions === [] && trim((string) $assumption->text) !== '') {
                $questions[] = "Resolve assumption: {$assumption->text}";
            }
        }
        $status = $blocking === [] ? 'passed' : 'needs_clarification';

        return [
            'schema_version' => 'atlas.sdd_clarification_gate.v1',
            'status' => $status,
            'blocking_count' => count($blocking),
            'questions' => array_values(array_unique($questions)),
        ];
    }
}
