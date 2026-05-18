<?php

namespace App\Services\Ai\Programming\Forge;

use App\Models\AiForgeIntake;
use App\Models\AiForgeWorkPacket;
use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\Programming\AtlasDev\Schemas\EscalationPacket;
use Illuminate\Support\Str;

/**
 * Composes `atlas.forge.work_packet.v1` rows attached to an `AiForgeIntake`.
 *
 * Source of packets, in priority order:
 *  1. Caller-provided `work_packets` list (explicit override).
 *  2. `EscalationPacket::suggestedWorkPackets` when origin=escalation_packet.
 *  3. Deterministic heuristic from the prompt text (origin=direct).
 *
 * Every packet carries the minimum shape required for an auditable Forge
 * Obra: objective, scope, acceptance_criteria, required_evidence, risk_band.
 * Optional richer fields (expected_files, dependencies, suggested_tests,
 * role_slot) are preserved when callers supply them.
 */
final class ForgeWorkPacketComposer
{
    /**
     * @param  array<int,array<string,mixed>>  $supplied
     * @return array<int,AiForgeWorkPacket>
     */
    public function composeForIntake(
        AiForgeIntake $intake,
        ?EscalationPacket $escalationPacket,
        array $supplied,
        string $promptForFallback,
        string $defaultRiskBand,
    ): array {
        $rawPackets = $supplied;
        if ($rawPackets === [] && $escalationPacket !== null) {
            $rawPackets = $escalationPacket->suggestedWorkPackets;
        }
        if ($rawPackets === []) {
            $rawPackets = $this->fallbackFromPrompt($promptForFallback);
        }

        $persisted = [];
        foreach (array_values($rawPackets) as $index => $raw) {
            if (! is_array($raw)) {
                continue;
            }
            $persisted[] = $this->persistPacket($intake, $index + 1, $raw, $defaultRiskBand);
        }

        return $persisted;
    }

    /**
     * @param  array<string,mixed>  $raw
     */
    private function persistPacket(AiForgeIntake $intake, int $position, array $raw, string $defaultRiskBand): AiForgeWorkPacket
    {
        $packetId = (string) ($raw['id']
            ?? $raw['packet_id']
            ?? sprintf('wp-%03d', $position));
        $title = (string) ($raw['title']
            ?? $raw['objective']
            ?? "Work Packet {$position}");
        $objective = (string) ($raw['objective']
            ?? $raw['title']
            ?? $title);

        $acceptanceCriteria = array_values((array) ($raw['acceptance_criteria']
            ?? ['Implementation matches objective', 'Verification receipt attached']));
        $requiredEvidence = array_values((array) ($raw['required_evidence']
            ?? ['work_packet_receipts', 'verification_receipt']));

        $expectedFiles = array_values((array) ($raw['expected_files']
            ?? $raw['areas']
            ?? $raw['expected_areas']
            ?? []));
        $dependencies = array_values((array) ($raw['dependencies'] ?? []));
        $risks = array_values((array) ($raw['risks'] ?? []));
        $suggestedTests = array_values((array) ($raw['suggested_tests']
            ?? $raw['tests']
            ?? []));

        $riskBand = $this->normalizeRiskBand((string) ($raw['risk_band'] ?? $defaultRiskBand));

        $hashPayload = [
            'intake_id' => $intake->id,
            'position' => $position,
            'packet_id' => $packetId,
            'objective' => $objective,
            'scope' => $raw['scope'] ?? null,
            'expected_files' => $expectedFiles,
            'dependencies' => $dependencies,
            'risks' => $risks,
            'acceptance_criteria' => $acceptanceCriteria,
            'required_evidence' => $requiredEvidence,
            'suggested_tests' => $suggestedTests,
            'role_slot' => $raw['role_slot'] ?? null,
            'risk_band' => $riskBand,
        ];

        return AiForgeWorkPacket::query()->create([
            'schema_version' => ForgeIntakeCanon::WORK_PACKET_SCHEMA_VERSION,
            'uuid' => (string) Str::uuid(),
            'intake_id' => $intake->id,
            'packet_position' => $position,
            'packet_id' => $packetId,
            'title' => $title,
            'objective' => $objective,
            'scope' => isset($raw['scope']) && is_string($raw['scope']) ? $raw['scope'] : null,
            'expected_files' => $expectedFiles === [] ? null : $expectedFiles,
            'dependencies' => $dependencies === [] ? null : $dependencies,
            'risks' => $risks === [] ? null : $risks,
            'acceptance_criteria' => $acceptanceCriteria,
            'required_evidence' => $requiredEvidence,
            'suggested_tests' => $suggestedTests === [] ? null : $suggestedTests,
            'status' => ForgeIntakeCanon::PACKET_STATUS_PROPOSED,
            'owner' => isset($raw['owner']) && is_string($raw['owner']) && $raw['owner'] !== '' ? $raw['owner'] : null,
            'role_slot' => isset($raw['role_slot']) && is_string($raw['role_slot']) && $raw['role_slot'] !== '' ? $raw['role_slot'] : null,
            'risk_band' => $riskBand,
            'packet_hash' => MissionCanonicalHash::sha256($hashPayload),
        ]);
    }

    /**
     * Deterministic heuristic: split a prompt into 1..N packet hints by
     * sentence/conjunction markers. Conservative — emits 1 packet when no
     * structural markers exist. This is INTAKE only; downstream Forge can
     * replan packets after richer context.
     *
     * @return array<int,array<string,mixed>>
     */
    private function fallbackFromPrompt(string $prompt): array
    {
        $prompt = trim($prompt);
        if ($prompt === '') {
            return [];
        }

        $parts = preg_split('/(?:\n+|\.\s+|;\s*|\s+e\s+tambem\s+|\s+depois\s+|\s+por\s+fim\s+|\s+\+\s+)/iu', $prompt) ?: [$prompt];
        $parts = array_values(array_filter(array_map('trim', $parts), static fn (string $p): bool => $p !== '' && mb_strlen($p) >= 4));

        if ($parts === []) {
            $parts = [$prompt];
        }

        $packets = [];
        foreach (array_slice($parts, 0, 6) as $index => $part) {
            $position = $index + 1;
            $packets[] = [
                'id' => sprintf('wp-%03d', $position),
                'title' => Str::limit($part, 120, ''),
                'objective' => $part,
                'scope' => null,
                'acceptance_criteria' => [
                    'Implementation matches objective: '.Str::limit($part, 80, ''),
                    'Verification receipt produced',
                ],
                'required_evidence' => [
                    'work_packet_receipts',
                    'verification_receipt',
                ],
            ];
        }

        return $packets;
    }

    private function normalizeRiskBand(string $band): string
    {
        $band = strtolower(trim($band));
        if (! in_array($band, ForgeIntakeCanon::RISK_BANDS, true)) {
            return ForgeIntakeCanon::RISK_BAND_MEDIUM;
        }

        return $band;
    }
}
