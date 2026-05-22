<?php

namespace App\Services\Ai\Programming\Forge\Intelligence;

use App\Models\AiForgeIntake;
use App\Models\AiForgeWorkPacket;
use App\Services\Ai\Mission\MissionCanonicalHash;

final class ForgeProviderProjectionService
{
    public const SCHEMA_VERSION = 'atlas.forge.provider_projection.v1';

    /**
     * @param  array<string,mixed>  $contextGate
     * @param  array<string,mixed>  $scopeGuard
     * @param  array<string,mixed>  $testImpact
     * @return array<string,mixed>
     */
    public function build(
        AiForgeWorkPacket $packet,
        ?AiForgeIntake $intake,
        array $contextGate,
        array $scopeGuard,
        array $testImpact,
    ): array {
        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'sendable' => ($contextGate['status'] ?? null) !== 'blocked' && ($scopeGuard['status'] ?? null) !== 'blocked',
            'provider_safe' => true,
            'packet_id' => (string) $packet->packet_id,
            'surface' => 'atlas_forge',
            'flow' => 'programming.forge',
            'prompt_sections' => [
                'obra' => [
                    'title' => $intake?->obra_title,
                    'intent' => $intake?->normalized_intent,
                    'definition_of_done' => array_values((array) ($intake?->definition_of_done ?? [])),
                ],
                'work_packet' => [
                    'objective' => (string) $packet->objective,
                    'scope' => $packet->scope,
                    'acceptance_criteria' => array_values((array) ($packet->acceptance_criteria ?? [])),
                    'required_evidence' => array_values((array) ($packet->required_evidence ?? [])),
                ],
                'bounds' => [
                    'allowed_files' => array_values((array) ($scopeGuard['allowed_files'] ?? [])),
                    'forbidden_rules' => array_values((array) ($scopeGuard['forbidden_rules'] ?? [])),
                ],
                'verification' => [
                    'focused_tests' => array_values((array) ($testImpact['commands'] ?? [])),
                ],
            ],
            'forbidden_provider_behavior' => [
                'do_not_execute_without_evidence',
                'do_not_touch_files_outside_scope',
                'do_not_claim_completion_without_gate_result',
                'do_not_drop_observer_context_or_receipts',
            ],
        ];
        $payload['projection_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }
}
