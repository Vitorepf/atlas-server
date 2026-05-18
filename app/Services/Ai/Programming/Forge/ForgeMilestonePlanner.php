<?php

namespace App\Services\Ai\Programming\Forge;

use App\Models\AiForgeIntake;
use App\Models\AiForgeMilestone;
use App\Services\Ai\Mission\MissionCanonicalHash;
use Illuminate\Support\Str;

/**
 * Plans the 5 canonical milestones every Atlas Forge Obra carries:
 *
 *   1. design_context   — context pack + spec confirmation
 *   2. implementation   — work packet execution
 *   3. verification     — test + verification gates
 *   4. docs             — canonical doc updates
 *   5. certification    — final certification gate
 *
 * Milestones are inception-time projections; per-milestone execution
 * (status transitions, evidence attachment) is owned by downstream Forge
 * services (out of scope here).
 */
final class ForgeMilestonePlanner
{
    /**
     * @return array<int,AiForgeMilestone>
     */
    public function planForIntake(AiForgeIntake $intake): array
    {
        $milestones = [];
        foreach ($this->blueprint() as $position => $blueprint) {
            $milestones[] = $this->persistMilestone($intake, $position + 1, $blueprint);
        }

        return $milestones;
    }

    /**
     * @param  array<string,mixed>  $blueprint
     */
    private function persistMilestone(AiForgeIntake $intake, int $position, array $blueprint): AiForgeMilestone
    {
        $hashPayload = [
            'intake_id' => $intake->id,
            'position' => $position,
            'milestone_id' => $blueprint['milestone_id'],
            'expected_artifacts' => $blueprint['expected_artifacts'],
            'required_gates' => $blueprint['required_gates'],
            'required_evidence' => $blueprint['required_evidence'],
        ];

        return AiForgeMilestone::query()->create([
            'schema_version' => ForgeIntakeCanon::MILESTONE_SCHEMA_VERSION,
            'uuid' => (string) Str::uuid(),
            'intake_id' => $intake->id,
            'position' => $position,
            'milestone_id' => $blueprint['milestone_id'],
            'title' => $blueprint['title'],
            'description' => $blueprint['description'] ?? null,
            'expected_artifacts' => $blueprint['expected_artifacts'],
            'required_gates' => $blueprint['required_gates'],
            'required_evidence' => $blueprint['required_evidence'],
            'status' => 'pending',
            'milestone_hash' => MissionCanonicalHash::sha256($hashPayload),
        ]);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function blueprint(): array
    {
        return [
            [
                'milestone_id' => ForgeIntakeCanon::MILESTONE_DESIGN_CONTEXT,
                'title' => 'Design + Context Load',
                'description' => 'Load canonical docs, code intelligence and prior evidence; confirm spec scope.',
                'expected_artifacts' => ['context_pack', 'spec_lookup_result', 'risk_register'],
                'required_gates' => ['spec_anchor_valid', 'context_sufficiency_passed'],
                'required_evidence' => ['plan', 'context_pack'],
            ],
            [
                'milestone_id' => ForgeIntakeCanon::MILESTONE_IMPLEMENTATION,
                'title' => 'Implementation',
                'description' => 'Execute work packets per dependencies; produce diffs and receipts.',
                'expected_artifacts' => ['work_packet_diffs', 'work_packet_receipts'],
                'required_gates' => ['work_packets_scoped', 'reservations_valid', 'permissions_approved'],
                'required_evidence' => ['work_packet_receipts'],
            ],
            [
                'milestone_id' => ForgeIntakeCanon::MILESTONE_VERIFICATION,
                'title' => 'Verification',
                'description' => 'Run tests, gate runs and verification receipts for every packet acceptance criteria.',
                'expected_artifacts' => ['verification_receipt', 'test_results'],
                'required_gates' => ['verification_receipt_present', 'no_unresolved_blockers'],
                'required_evidence' => ['verification_receipt'],
            ],
            [
                'milestone_id' => ForgeIntakeCanon::MILESTONE_DOCS,
                'title' => 'Documentation Update',
                'description' => 'Update canonical docs (engineering knowledge base, runbooks) reflecting the Obra outcome.',
                'expected_artifacts' => ['updated_canonical_docs'],
                'required_gates' => ['docs_health_passed'],
                'required_evidence' => ['evidence_pack'],
            ],
            [
                'milestone_id' => ForgeIntakeCanon::MILESTONE_CERTIFICATION,
                'title' => 'Certification',
                'description' => 'Run certification quality-aware checks; pass or block Obra completion.',
                'expected_artifacts' => ['certification_receipt'],
                'required_gates' => ['certification_passed_or_blocked'],
                'required_evidence' => ['certification'],
            ],
        ];
    }
}
