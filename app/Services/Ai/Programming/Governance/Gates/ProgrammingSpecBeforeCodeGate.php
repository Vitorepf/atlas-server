<?php

namespace App\Services\Ai\Programming\Governance\Gates;

use App\Models\AtlasProgrammingWorkItem;

/**
 * Enforces that any structural change carries a spec snapshot with the
 * canonical fields (objective, context, behavior, files, risks, tests,
 * evidence_required, rollback, completion_criteria).
 *
 * @see docs/engineering-knowledge-base/atlas-programming-governance-system-contracts.md (Contrato 2)
 */
class ProgrammingSpecBeforeCodeGate implements ProgrammingGateContract
{
    /** @var list<string> */
    private const REQUIRED_FIELDS = [
        'objective',
        'context',
        'expected_behavior',
        'likely_files',
        'risks',
        'tests',
        'evidence_required',
        'rollback',
        'completion_criteria',
    ];

    public function name(): string
    {
        return 'spec-before-code';
    }

    public function evaluate(AtlasProgrammingWorkItem $workItem): ProgrammingGateOutcome
    {
        if ($workItem->spec_hash === null || empty($workItem->spec_json)) {
            return ProgrammingGateOutcome::failed(
                'spec_missing',
                ['scope_mode' => $workItem->scope_mode],
            );
        }

        $missing = [];
        foreach (self::REQUIRED_FIELDS as $field) {
            $value = $workItem->spec_json[$field] ?? null;
            if ($value === null || (is_array($value) && $value === []) || (is_string($value) && trim($value) === '')) {
                $missing[] = $field;
            }
        }

        if ($missing !== []) {
            return ProgrammingGateOutcome::failed(
                'spec_missing_required_fields',
                ['missing_fields' => $missing],
            );
        }

        return ProgrammingGateOutcome::passed([
            'spec_hash' => $workItem->spec_hash,
            'fields_present' => self::REQUIRED_FIELDS,
        ]);
    }
}
