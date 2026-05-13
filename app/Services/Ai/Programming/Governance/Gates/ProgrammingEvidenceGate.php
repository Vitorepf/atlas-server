<?php

namespace App\Services\Ai\Programming\Governance\Gates;

use App\Models\AtlasProgrammingWorkItem;

/**
 * Rejects "evidence" that is just a textual summary. Each receipt must carry
 * at least one of: command, output, files, tests, diff_path. Hash and source
 * fields are required for ledger traceability.
 *
 * @see docs/engineering-knowledge-base/atlas-programming-governance-system-contracts.md (Contrato 5)
 */
class ProgrammingEvidenceGate implements ProgrammingGateContract
{
    public function name(): string
    {
        return 'evidence-required';
    }

    public function evaluate(AtlasProgrammingWorkItem $workItem): ProgrammingGateOutcome
    {
        $refs = (array) $workItem->evidence_refs_json;
        if ($refs === []) {
            return ProgrammingGateOutcome::failed('evidence_ledger_empty');
        }

        $verified = 0;
        $invalid = [];
        foreach ($refs as $idx => $ref) {
            if (! is_array($ref)) {
                $invalid[] = ['index' => $idx, 'reason' => 'not_an_object'];

                continue;
            }
            if (! $this->hasMechanicalProof($ref)) {
                $invalid[] = ['index' => $idx, 'reason' => 'no_mechanical_proof'];

                continue;
            }
            // Receipts that did not land in the engineering evidence ledger
            // are not governance-grade — they are unverifiable promises.
            if (($ref['storage']['persisted'] ?? false) !== true) {
                $invalid[] = ['index' => $idx, 'reason' => 'not_persisted_in_ledger'];

                continue;
            }
            $verified++;
        }

        if ($verified === 0) {
            return ProgrammingGateOutcome::failed(
                'no_verifiable_receipt',
                ['invalid_receipts' => $invalid, 'total_receipts' => count($refs)],
            );
        }

        return ProgrammingGateOutcome::passed([
            'verified_receipts' => $verified,
            'total_receipts' => count($refs),
            'invalid_receipts' => $invalid,
        ]);
    }

    /**
     * @param  array<string,mixed>  $ref
     */
    private function hasMechanicalProof(array $ref): bool
    {
        foreach (['command', 'output', 'tests', 'files', 'diff_path', 'artifact_url'] as $field) {
            $value = $ref[$field] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return true;
            }
            if (is_array($value) && $value !== []) {
                return true;
            }
        }

        return false;
    }
}
