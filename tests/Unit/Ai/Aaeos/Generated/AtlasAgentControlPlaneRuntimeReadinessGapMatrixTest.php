<?php

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasAgentControlPlaneRuntimeReadinessGapMatrixService;
use Tests\TestCase;

/**
 * Pins the Closing Note five-artifact gate and the observational-matrix
 * invariants from the doc. Pure, no DB.
 *
 * @see docs/engineering-knowledge-base/self-construction/audits/atlas-agent-control-plane-runtime-readiness-gap-matrix-v1.md
 */
class AtlasAgentControlPlaneRuntimeReadinessGapMatrixTest extends TestCase
{
    private function service(): AtlasAgentControlPlaneRuntimeReadinessGapMatrixService
    {
        return new AtlasAgentControlPlaneRuntimeReadinessGapMatrixService;
    }

    /** The five complete, valid artifacts that the Closing Note demands. */
    private function fiveValidArtifacts(): array
    {
        return [
            'signed_promotion_gate_id' => 'gate-abc-123',
            'replay_diff_status' => 'improved',
            'release_dossier_hash' => 'sha256:deadbeef',
            'mutation_guard_passed' => true,
            'first_real_evidence_event_id' => 'evt-001',
        ];
    }

    public function test_default_matrix_has_zero_runtime_y_rows_every_runtime_cell_blocked(): void
    {
        $matrix = $this->service()->matrix();

        // The doc: the Runtime column intentionally has no Y rows in this window.
        $this->assertSame(0, $matrix['runtime_y_count']);
        $this->assertTrue($matrix['all_runtime_cells_blocked']);
        $this->assertSame(20, $matrix['block_count']);

        foreach ($matrix['rows'] as $row) {
            $this->assertNotSame('Y', $row['runtime'], "Block {$row['block']} must not be Runtime=Y by default");
        }
    }

    public function test_all_five_valid_artifacts_promote_a_promotable_block_to_runtime_y(): void
    {
        $result = $this->service()->promoteRuntimeCell('Scope Lock', $this->fiveValidArtifacts());

        $this->assertTrue($result['promotion_allowed']);
        $this->assertSame('Y', $result['runtime_cell']);
        $this->assertSame([], $result['missing_artifacts']);
        $this->assertSame([], $result['invalid_artifacts']);
        $this->assertCount(5, $result['present_artifacts']);
    }

    public function test_missing_any_single_artifact_keeps_the_runtime_cell_N(): void
    {
        $artifacts = $this->fiveValidArtifacts();
        unset($artifacts['release_dossier_hash']);

        $result = $this->service()->promoteRuntimeCell('Scope Lock', $artifacts);

        $this->assertFalse($result['promotion_allowed']);
        $this->assertSame('N', $result['runtime_cell']);
        $this->assertSame(['release_dossier_hash'], $result['missing_artifacts']);
        $this->assertSame('missing_required_artifacts', $result['blocked_reason']);
    }

    public function test_regressed_replay_diff_is_invalid_and_blocks_promotion(): void
    {
        $artifacts = $this->fiveValidArtifacts();
        $artifacts['replay_diff_status'] = 'regressed';

        $result = $this->service()->promoteRuntimeCell('Real Dispatch', $artifacts);

        $this->assertFalse($result['promotion_allowed']);
        $this->assertSame('N', $result['runtime_cell']);
        $this->assertContains('replay_diff_status', $result['invalid_artifacts']);
        $this->assertSame('artifact_present_but_invalid', $result['blocked_reason']);
    }

    public function test_mutation_guard_must_be_true_truthy_strings_do_not_count(): void
    {
        $artifacts = $this->fiveValidArtifacts();
        $artifacts['mutation_guard_passed'] = 'true'; // string, not boolean true

        $result = $this->service()->promoteRuntimeCell('Real Dispatch', $artifacts);

        $this->assertFalse($result['promotion_allowed']);
        $this->assertContains('mutation_guard_passed', $result['invalid_artifacts']);
    }

    public function test_not_yet_runtime_capable_block_is_hard_blocked_even_with_all_five(): void
    {
        // adapter_execution_runtime is in the not_yet_runtime_capable (4) list.
        $result = $this->service()->promoteRuntimeCell('adapter_execution_runtime', $this->fiveValidArtifacts());

        $this->assertFalse($result['promotion_allowed']);
        $this->assertSame('N', $result['runtime_cell']);
        $this->assertSame('block_is_not_yet_runtime_capable', $result['blocked_reason']);
    }

    public function test_matrix_promotes_only_blocks_with_passing_artifacts(): void
    {
        $matrix = $this->service()->matrix([
            'Scope Lock' => $this->fiveValidArtifacts(),
            'Real Dispatch' => array_merge($this->fiveValidArtifacts(), ['replay_diff_status' => 'regressed']),
        ]);

        $this->assertSame(1, $matrix['runtime_y_count']);
        $this->assertSame(['Scope Lock'], $matrix['runtime_y_blocks']);
        $this->assertFalse($matrix['all_runtime_cells_blocked']);

        $scopeLock = collect($matrix['rows'])->firstWhere('block', 'Scope Lock');
        $realDispatch = collect($matrix['rows'])->firstWhere('block', 'Real Dispatch');
        $this->assertSame('Y', $scopeLock['runtime']);
        $this->assertSame('N', $realDispatch['runtime']);
    }

    public function test_audit_flags_a_rendered_runtime_y_row_with_no_passing_promotion(): void
    {
        // Regras para IA: never render Runtime=Y without the five artifacts.
        $audit = $this->service()->auditRenderedMatrix([
            ['block' => 'Kill Switch', 'runtime' => 'Y', 'runtime_promotion' => null],
            ['block' => 'Rollback', 'runtime' => 'N', 'runtime_promotion' => null],
        ]);

        $this->assertFalse($audit['compliant']);
        $this->assertCount(1, $audit['violations']);
        $this->assertSame('Kill Switch', $audit['violations'][0]['block']);
        $this->assertSame('runtime_y_without_five_artifact_promotion', $audit['violations'][0]['reason']);
    }
}
