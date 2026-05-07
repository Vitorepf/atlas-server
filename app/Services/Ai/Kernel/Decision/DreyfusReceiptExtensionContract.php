<?php

namespace App\Services\Ai\Kernel\Decision;

class DreyfusReceiptExtensionContract
{
    public const SCHEMA_VERSION = 'atlas.decide.extension.dreyfus.v1';

    /**
     * @param  array<string,mixed>  $resolution
     * @return array<string,mixed>
     */
    public function cognitiveDecision(array $resolution): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'dreyfus_stage_resolved' => $this->stage($resolution['dreyfus_stage_resolved'] ?? null),
            'pedagogy_mode_resolved' => $this->mode($resolution['pedagogy_mode_resolved'] ?? null),
            'selection_explanation' => is_array($resolution['selection_explanation'] ?? null) ? $resolution['selection_explanation'] : [],
            'knowledge_node_id' => trim((string) ($resolution['knowledge_node_id'] ?? '')),
            'confidence' => is_numeric($resolution['confidence'] ?? null) ? round((float) $resolution['confidence'], 2) : null,
            'source' => trim((string) ($resolution['source'] ?? 'unknown')) ?: 'unknown',
        ];
    }

    /**
     * @param  array<string,mixed>  $resolution
     * @return array<string,mixed>
     */
    public function envelopeHints(array $resolution): array
    {
        $decision = $this->cognitiveDecision($resolution);

        return [
            'input_kind' => 'cognitive',
            'dreyfus_stage_target' => $decision['dreyfus_stage_resolved'],
            'pedagogy_mode_resolved' => $decision['pedagogy_mode_resolved'],
            'knowledge_node_id' => $decision['knowledge_node_id'],
        ];
    }

    private function stage(mixed $stage): int
    {
        return max(1, min(5, (int) $stage));
    }

    private function mode(mixed $mode): string
    {
        $mode = trim((string) $mode);

        return $mode !== '' && $mode !== 'auto' ? $mode : 'novato';
    }
}
