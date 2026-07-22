<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\LiveCycle\FactPassing;

use InvalidArgumentException;

final class AtlasLoopPhaseBoundaryFactSchemaRegistry
{
    /**
     * @return list<string>
     */
    public function boundaries(): array
    {
        return array_keys($this->schemas());
    }

    /**
     * @return array{
     *     required_keys:list<string>,
     *     field_types:array<string,string>,
     *     provenance_fields:array<string,string>
     * }
     */
    public function schemaFor(string $boundary): array
    {
        $schemas = $this->schemas();
        if (! array_key_exists($boundary, $schemas)) {
            throw new InvalidArgumentException(sprintf('Unknown phase boundary [%s].', $boundary));
        }

        return $schemas[$boundary];
    }

    /**
     * @return array<string, array{
     *     required_keys:list<string>,
     *     field_types:array<string,string>,
     *     provenance_fields:array<string,string>
     * }>
     */
    private function schemas(): array
    {
        return [
            'orient->comprehend' => $this->schema(
                ['scope_snapshot', 'surface_inventory', 'target_signal'],
                ['scope_snapshot' => 'array', 'surface_inventory' => 'array', 'target_signal' => 'string'],
            ),
            'comprehend->decide-leverage' => $this->schema(
                ['comprehension_model', 'pressure_points', 'candidate_levers'],
                ['comprehension_model' => 'array', 'pressure_points' => 'array', 'candidate_levers' => 'array'],
            ),
            'decide-leverage->architect' => $this->schema(
                ['selected_lever', 'selection_rationale', 'guardrails'],
                ['selected_lever' => 'string', 'selection_rationale' => 'array', 'guardrails' => 'array'],
            ),
            'architect->decompose' => $this->schema(
                ['design_contract', 'obligations', 'consumer_contracts'],
                ['design_contract' => 'array', 'obligations' => 'array', 'consumer_contracts' => 'array'],
            ),
            'decompose->implement' => $this->schema(
                ['task_packets', 'allowed_files', 'acceptance_contract'],
                ['task_packets' => 'array', 'allowed_files' => 'array', 'acceptance_contract' => 'array'],
            ),
            'implement->certify' => $this->schema(
                ['patch_receipt', 'validation_evidence', 'changed_paths'],
                ['patch_receipt' => 'array', 'validation_evidence' => 'array', 'changed_paths' => 'array'],
            ),
            'certify->close-on-main' => $this->schema(
                ['certification_receipt', 'merge_readiness', 'closeout_evidence'],
                ['certification_receipt' => 'array', 'merge_readiness' => 'array', 'closeout_evidence' => 'array'],
            ),
        ];
    }

    /**
     * @param  list<string>  $requiredKeys
     * @param  array<string,string>  $fieldTypes
     * @return array{
     *     required_keys:list<string>,
     *     field_types:array<string,string>,
     *     provenance_fields:array<string,string>
     * }
     */
    private function schema(array $requiredKeys, array $fieldTypes): array
    {
        return [
            'required_keys' => $requiredKeys,
            'field_types' => $fieldTypes,
            'provenance_fields' => [
                'emitted_by_phase' => 'string',
                'emitted_at' => 'string',
                'cycle_id' => 'string',
            ],
        ];
    }
}
