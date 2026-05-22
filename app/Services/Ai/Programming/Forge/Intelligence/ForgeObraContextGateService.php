<?php

namespace App\Services\Ai\Programming\Forge\Intelligence;

use App\Models\AiForgeIntake;
use App\Models\AiForgeWorkPacket;
use App\Services\Ai\Mission\MissionCanonicalHash;

final class ForgeObraContextGateService
{
    public const SCHEMA_VERSION = 'atlas.forge.obra_context_gate.v1';

    /**
     * @param  array<string,mixed>  $intelligence
     * @return array<string,mixed>
     */
    public function evaluate(AiForgeWorkPacket $packet, ?AiForgeIntake $intake, array $intelligence): array
    {
        $missing = [];
        $risk = (string) ($packet->risk_band ?? 'medium');
        $likelyFiles = array_values((array) ($intelligence['likely_files'] ?? []));
        $ownerDocs = array_values((array) ($intelligence['owner_docs'] ?? []));
        $requiredEvidence = array_values((array) ($packet->required_evidence ?? []));
        $acceptanceCriteria = array_values((array) ($packet->acceptance_criteria ?? []));

        if ($acceptanceCriteria === []) {
            $missing[] = 'acceptance_criteria';
        }
        if ($requiredEvidence === []) {
            $missing[] = 'required_evidence';
        }
        if (in_array($risk, ['high', 'critical'], true) && $likelyFiles === []) {
            $missing[] = 'likely_files_for_high_risk_packet';
        }
        if (in_array($risk, ['high', 'critical'], true) && $ownerDocs === []) {
            $missing[] = 'owner_docs_for_high_risk_packet';
        }
        if (($intake?->status ?? null) === 'blocked') {
            $missing[] = 'intake_blocked';
        }

        $status = $missing === []
            ? 'passed'
            : (in_array($risk, ['high', 'critical'], true) ? 'blocked' : 'watch');

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'packet_id' => (string) $packet->packet_id,
            'risk_band' => $risk,
            'missing' => $missing,
            'required_before_real_execution' => [
                'acceptance_criteria',
                'required_evidence',
                'owner_docs_when_high_risk',
                'likely_files_when_high_risk',
            ],
            'remediation' => $status === 'blocked'
                ? 'Attach owner docs, likely files, acceptance criteria and evidence refs before real execution.'
                : null,
        ];
        $payload['gate_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }
}
