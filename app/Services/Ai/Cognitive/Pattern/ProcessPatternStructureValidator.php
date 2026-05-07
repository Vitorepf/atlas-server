<?php

namespace App\Services\Ai\Cognitive\Pattern;

class ProcessPatternStructureValidator
{
    private const CATEGORIES = ['decision', 'process', 'communication', 'optimization', 'failure_recovery', 'architecture'];

    private const REQUIRED = ['name', 'category', 'intent', 'problem_context', 'forces', 'solution', 'consequences'];

    /**
     * @param  array<string,mixed>  $pattern
     * @return array<string,mixed>
     */
    public function validate(array $pattern): array
    {
        foreach (self::REQUIRED as $field) {
            if (blank($pattern[$field] ?? null)) {
                return $this->result('blocked', 'pattern_structure_missing_'.$field);
            }
        }

        if (! in_array((string) $pattern['category'], self::CATEGORIES, true)) {
            return $this->result('blocked', 'pattern_structure_invalid_category');
        }

        if (! is_array($pattern['forces']) || ! is_array($pattern['solution']) || ! is_array($pattern['consequences'])) {
            return $this->result('blocked', 'pattern_structure_invalid_json_fields');
        }

        return $this->result('passed', 'pattern_structure_complete');
    }

    /**
     * @return array<string,mixed>
     */
    private function result(string $status, string $reason): array
    {
        return [
            'schema_version' => 'atlas.cognitive.process_pattern_structure_validation.v1',
            'status' => $status,
            'reason' => $reason,
        ];
    }
}
