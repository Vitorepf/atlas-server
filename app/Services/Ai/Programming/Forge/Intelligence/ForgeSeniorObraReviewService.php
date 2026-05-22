<?php

namespace App\Services\Ai\Programming\Forge\Intelligence;

use App\Models\AiForgeIntake;
use App\Models\AiForgeWorkPacket;
use App\Services\Ai\Mission\MissionCanonicalHash;

final class ForgeSeniorObraReviewService
{
    public const SCHEMA_VERSION = 'atlas.forge.senior_obra_review.v1';

    /**
     * @param  array<string,mixed>  $contextGate
     * @param  array<string,mixed>  $testImpact
     * @param  array<string,mixed>  $workcellRoute
     * @return array<string,mixed>
     */
    public function review(
        AiForgeWorkPacket $packet,
        ?AiForgeIntake $intake,
        array $contextGate,
        array $testImpact,
        array $workcellRoute,
    ): array {
        $blockers = [];
        if (($contextGate['status'] ?? null) === 'blocked') {
            $blockers[] = 'context_gate_blocked';
        }
        if ((array) ($testImpact['commands'] ?? []) === []) {
            $blockers[] = 'no_test_strategy';
        }
        if ((array) ($packet->acceptance_criteria ?? []) === []) {
            $blockers[] = 'missing_acceptance_criteria';
        }
        if ((array) ($packet->required_evidence ?? []) === []) {
            $blockers[] = 'missing_required_evidence';
        }

        $status = $blockers === [] ? 'passed' : 'blocked';
        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'packet_id' => (string) $packet->packet_id,
            'obra_uuid' => $intake?->uuid,
            'workcell' => $workcellRoute['workcell'] ?? null,
            'review_checks' => [
                'scope_declared' => (string) ($packet->scope ?? '') !== '',
                'tests_declared_or_inferred' => (array) ($testImpact['commands'] ?? []) !== [],
                'evidence_declared' => (array) ($packet->required_evidence ?? []) !== [],
                'context_gate_not_blocked' => ($contextGate['status'] ?? null) !== 'blocked',
            ],
            'blockers' => $blockers,
            'decision' => $status === 'passed' ? 'packet_ready_for_governed_execution' : 'repair_plan_before_execution',
        ];
        $payload['review_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }
}
