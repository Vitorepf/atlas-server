<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery\Cortex\MemoryIntegration;

final class AtlasCortexMemoryWriteProposalGate
{
    /**
     * @param  array{
     *     memory_entry_id:string,
     *     fact_payload:array<string,mixed>,
     *     grounding:array{status:string,inventory_items:list<array<string,mixed>>},
     *     privacy_class?:string
     * }  $proposal
     * @return array{
     *     accepted:bool,
     *     reason:?string,
     *     write_ticket:?array{target_memory_entry_id:string,fact_payload:array<string,mixed>},
     *     decision_record:array<string,mixed>
     * }
     */
    public function evaluate(array $proposal, ?string $approvalToken): array
    {
        $memoryEntryId = (string) ($proposal['memory_entry_id'] ?? '');
        $factPayload = is_array($proposal['fact_payload'] ?? null) ? $proposal['fact_payload'] : [];
        $grounding = is_array($proposal['grounding'] ?? null) ? $proposal['grounding'] : ['status' => 'ungrounded', 'inventory_items' => []];
        $privacyClass = is_string($proposal['privacy_class'] ?? null) ? (string) $proposal['privacy_class'] : 'normal';

        $reason = null;
        if (trim((string) $approvalToken) === '') {
            $reason = 'missing_operator_approval';
        } elseif (($grounding['status'] ?? 'ungrounded') !== 'grounded') {
            $reason = 'ungrounded';
        } elseif (in_array($privacyClass, ['sensitive', 'secret', 'cyber'], true)) {
            $reason = 'forbidden_privacy_class';
        }

        $accepted = $reason === null;
        $writeTicket = $accepted
            ? [
                'target_memory_entry_id' => $memoryEntryId,
                'fact_payload' => $factPayload,
            ]
            : null;

        return [
            'accepted' => $accepted,
            'reason' => $reason,
            'write_ticket' => $writeTicket,
            'decision_record' => [
                'gate' => 'atlas.cortex.memory_write_proposal_gate.v1',
                'target_memory_entry_id' => $memoryEntryId,
                'decision' => $accepted ? 'approved' : 'rejected',
                'reason' => $reason,
                'approval_token_present' => trim((string) $approvalToken) !== '',
                'grounding_status' => (string) ($grounding['status'] ?? 'ungrounded'),
                'privacy_class' => $privacyClass,
                'fact_payload' => $factPayload,
            ],
        ];
    }
}
