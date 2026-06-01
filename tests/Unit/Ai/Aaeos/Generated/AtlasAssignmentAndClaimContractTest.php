<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasAssignmentAndClaimContractService;
use Tests\TestCase;

/**
 * Pins the Selection Policy (1..6), Collision Rules and Read-Only Phase
 * invariants from the doc. Pure, no DB.
 *
 * @see docs/engineering-knowledge-base/self-construction/assignment-and-claim-contract.md
 */
class AtlasAssignmentAndClaimContractTest extends TestCase
{
    private function service(): AtlasAssignmentAndClaimContractService
    {
        return new AtlasAssignmentAndClaimContractService;
    }

    /**
     * A fully-qualified packet: available, no collision, deps complete, fences
     * every hot scope, has a validator, does not authorize execution.
     *
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private function goodPacket(array $overrides = []): array
    {
        return array_merge([
            'packet_id' => 'AIP-SPLIT-DOCS-0001',
            'status' => 'available',
            'collision_risk' => 'none',
            'dependencies' => [],
            'dependencies_complete' => true,
            'allowed_files' => ['docs/engineering-knowledge-base/self-construction/'],
            'forbidden_files' => [
                'routes/', 'database/migrations/', 'app/providers/',
                'runtimes/python/voice_realtime/', 'app/services/kernel/',
                'app/services/ai/providers/', 'commands/daemon-runner.php',
            ],
            'has_required_validator' => true,
            'authorizes_execution' => false,
            'packet_hash' => 'sha256:p1',
            'split_hash' => 'sha256:s1',
        ], $overrides);
    }

    public function test_qualifying_packet_yields_read_only_preview_with_execution_disabled(): void
    {
        $out = $this->service()->select([
            'assignment_id' => 'ASSIGN-20260601-0001',
            'candidates' => [$this->goodPacket()],
        ]);

        $this->assertSame(AtlasAssignmentAndClaimContractService::STATUS_READY, $out['status']);
        $this->assertSame('AIP-SPLIT-DOCS-0001', $out['selected_packet_id']);
        $this->assertSame(AtlasAssignmentAndClaimContractService::SCHEMA, $out['schema_version']);
        // Read-Only Phase invariants — never persisted, never executable.
        $this->assertFalse($out['execution_allowed']);
        $this->assertSame('preview_only_not_persisted', $out['claim_state']);
        $this->assertSame('read_only_preview', $out['session_owner']);
        // Baseline stop conditions are always present (AI protocol stop list).
        $this->assertContains('stale_packet_hash', $out['stop_conditions']);
        $this->assertContains('failed_gate', $out['stop_conditions']);
    }

    public function test_safest_first_ordering_beats_input_order_for_throughput(): void
    {
        // First in input is a higher-collision packet; the safer "none" packet
        // listed second must win ("prefer the safest unblocked packet").
        $risky = $this->goodPacket([
            'packet_id' => 'AIP-RISKY',
            'collision_risk' => 'low',
        ]);
        $safe = $this->goodPacket([
            'packet_id' => 'AIP-SAFE',
            'collision_risk' => 'none',
        ]);

        $out = $this->service()->select(['candidates' => [$risky, $safe]]);

        $this->assertSame('AIP-SAFE', $out['selected_packet_id']);
    }

    public function test_only_one_packet_is_ever_selected(): void
    {
        // Two equally-qualifying packets: exactly one is chosen (one session,
        // one packet) and the result carries a single scalar selected id.
        $out = $this->service()->select([
            'candidates' => [
                $this->goodPacket(['packet_id' => 'AIP-A']),
                $this->goodPacket(['packet_id' => 'AIP-B']),
            ],
        ]);

        $this->assertSame(AtlasAssignmentAndClaimContractService::STATUS_READY, $out['status']);
        $this->assertSame('AIP-A', $out['selected_packet_id']);
        $this->assertIsString($out['selected_packet_id']);
    }

    public function test_unavailable_high_collision_and_incomplete_deps_are_blocked_with_reasons(): void
    {
        $out = $this->service()->select([
            'candidates' => [
                $this->goodPacket(['packet_id' => 'AIP-CLAIMED', 'status' => 'claimed']),
                $this->goodPacket(['packet_id' => 'AIP-HOT', 'collision_risk' => 'high']),
                $this->goodPacket([
                    'packet_id' => 'AIP-DEP',
                    'dependencies' => ['AIP-OTHER'],
                    'dependencies_complete' => false,
                ]),
            ],
        ]);

        $this->assertSame(AtlasAssignmentAndClaimContractService::STATUS_BLOCKED, $out['status']);
        $this->assertNull($out['selected_packet_id']);
        $this->assertFalse($out['execution_allowed']);

        $byId = [];
        foreach ($out['blocked_reasons'] as $r) {
            $byId[$r['packet_id']] = $r['reasons'];
        }
        $this->assertContains('status_not_available', $byId['AIP-CLAIMED']);
        $this->assertContains('collision_risk_too_high', $byId['AIP-HOT']);
        $this->assertContains('dependencies_incomplete', $byId['AIP-DEP']);
    }

    public function test_packet_that_omits_hot_forbidden_scopes_is_rejected(): void
    {
        // forbidden_files only fences routes — missing voice/kernel/provider/
        // migration/daemon — so Selection Policy 5 must reject it.
        $out = $this->service()->select([
            'candidates' => [$this->goodPacket([
                'packet_id' => 'AIP-NOFENCE',
                'forbidden_files' => ['routes/'],
            ])],
        ]);

        $this->assertSame(AtlasAssignmentAndClaimContractService::STATUS_BLOCKED, $out['status']);
        $this->assertContains(
            'forbidden_files_missing_hot_scopes',
            $out['blocked_reasons'][0]['reasons'],
        );
    }

    public function test_allowed_files_overlapping_a_claimed_packet_is_rejected(): void
    {
        // Selection Policy 4 / Collision "overlaps another claimed packet":
        // the packet's allowed dir collides with an already-claimed file.
        $out = $this->service()->select([
            'already_claimed_files' => ['docs/engineering-knowledge-base/self-construction/work-splitter-contract.md'],
            'candidates' => [$this->goodPacket(['packet_id' => 'AIP-OVERLAP'])],
        ]);

        $this->assertSame(AtlasAssignmentAndClaimContractService::STATUS_BLOCKED, $out['status']);
        $this->assertContains(
            'allowed_files_overlap_claimed_packet',
            $out['blocked_reasons'][0]['reasons'],
        );
    }

    public function test_execution_authorizing_packet_is_rejected_in_read_only_phase(): void
    {
        // Collision Rule: "tries to authorize runtime execution without receipt."
        $out = $this->service()->select([
            'candidates' => [$this->goodPacket([
                'packet_id' => 'AIP-EXEC',
                'authorizes_execution' => true,
            ])],
        ]);

        $this->assertSame(AtlasAssignmentAndClaimContractService::STATUS_BLOCKED, $out['status']);
        $this->assertContains(
            'execution_authorization_not_allowed_in_preview',
            $out['blocked_reasons'][0]['reasons'],
        );
        $this->assertFalse($this->service()->hasSelectablePacket([
            'candidates' => [$this->goodPacket(['authorizes_execution' => true])],
        ]));
    }
}
