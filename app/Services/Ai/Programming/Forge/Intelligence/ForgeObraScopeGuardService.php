<?php

namespace App\Services\Ai\Programming\Forge\Intelligence;

use App\Models\AiForgeIntake;
use App\Models\AiForgeWorkPacket;
use App\Services\Ai\Mission\MissionCanonicalHash;

final class ForgeObraScopeGuardService
{
    public const SCHEMA_VERSION = 'atlas.forge.obra_scope_guard.v1';

    /**
     * @param  array<string,mixed>  $intelligence
     * @return array<string,mixed>
     */
    public function guard(AiForgeWorkPacket $packet, ?AiForgeIntake $intake, array $intelligence): array
    {
        $allowed = array_values(array_unique(array_merge(
            array_values((array) ($packet->expected_files ?? [])),
            array_values((array) ($intelligence['likely_files'] ?? [])),
        )));
        $risk = (string) ($packet->risk_band ?? 'medium');
        $status = ($allowed === [] && in_array($risk, ['high', 'critical'], true)) ? 'blocked' : 'passed';

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'packet_id' => (string) $packet->packet_id,
            'allowed_files' => $allowed,
            'forbidden_rules' => [
                'do_not_modify_unrelated_user_changes',
                'do_not_change_files_outside_packet_scope_without_new_packet',
                'do_not_delete_evidence_or_receipts',
                'do_not_change_provider_policy_inside_forge_packet',
            ],
            'max_files_without_operator_review' => in_array($risk, ['high', 'critical'], true) ? 5 : 12,
            'requires_operator_review' => in_array($risk, ['high', 'critical'], true),
        ];
        $payload['scope_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }
}
