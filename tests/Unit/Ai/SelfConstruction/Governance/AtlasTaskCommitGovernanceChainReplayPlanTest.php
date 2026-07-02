<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Governance;

use App\Services\Ai\SelfConstruction\Governance\AtlasTaskCommitGovernanceChain;
use App\Services\Ai\SelfConstruction\Governance\AtlasTaskGovernancePolicyPlane;
use App\Services\Ai\SelfConstruction\MergeGovernor\AtlasMergeGovernorAdmissionPolicy;
use App\Services\Ai\SelfConstruction\MergeGovernor\AtlasMergeGovernorReleaseDecisionLedger;
use App\Services\Ai\SelfConstruction\VerificationCourt\AtlasVerificationCourtVerdictLedger;
use PHPUnit\Framework\TestCase;

/**
 * Proves AtlasTaskCommitGovernanceChain::govern() composes the Verification Court's gate replay
 * plan {@see \App\Services\Ai\SelfConstruction\VerificationCourt\AtlasVerificationCourtGateReplayPlan}
 * for high/critical risk classifications, instead of deriving missing_rerun from flat policy data
 * alone. Low/medium risk keeps the policy-only derivation byte-identical.
 */
final class AtlasTaskCommitGovernanceChainReplayPlanTest extends TestCase
{
    private string $verdictLedgerPath;

    private string $releaseLedgerPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->verdictLedgerPath = sys_get_temp_dir().'/atlas_replay_verdict_'.uniqid('', true).'.jsonl';
        $this->releaseLedgerPath = sys_get_temp_dir().'/atlas_replay_release_'.uniqid('', true).'.jsonl';
    }

    protected function tearDown(): void
    {
        @unlink($this->verdictLedgerPath);
        @unlink($this->releaseLedgerPath);
        parent::tearDown();
    }

    /** @param  array<string,mixed>  $governanceConfig */
    private function chain(array $governanceConfig): AtlasTaskCommitGovernanceChain
    {
        return new AtlasTaskCommitGovernanceChain(
            verdictLedger: new AtlasVerificationCourtVerdictLedger($this->verdictLedgerPath),
            releaseLedger: new AtlasMergeGovernorReleaseDecisionLedger($this->releaseLedgerPath),
            clock: static fn (): string => '2026-01-01T00:00:00+00:00',
            modeOverride: AtlasTaskCommitGovernanceChain::MODE_OBSERVE,
            policyPlane: new AtlasTaskGovernancePolicyPlane($governanceConfig),
        );
    }

    /** Touching a MergeGovernor path is core-adjacent -> risk_level=high in the real classifier. */
    private function highRiskContext(array $checks): array
    {
        return [
            'task_packet_id' => 'task-replay-high',
            'project_id' => 'atlas-self-construction',
            'changed_files' => ['app/Services/Ai/SelfConstruction/MergeGovernor/SomeOrganFile.php'],
            'verification' => ['passed' => true, 'evidence_hash' => 'ev-replay-high', 'checks' => $checks],
        ];
    }

    /** @return array<string,mixed> */
    private function highInReleaseWindowConfig(): array
    {
        return [
            'risk_levels' => [
                'high' => ['required_checks' => [], 'in_release_window' => true, 'mode' => 'observe'],
            ],
        ];
    }

    public function test_unmet_replay_obligation_lands_in_missing_rerun_with_plan_hash_recorded(): void
    {
        // The gate replay plan demands 'phpunit_scoped' for any changed app/*.php file — this run
        // reports it explicitly as failed, so it must land in missing_rerun even though no
        // policy-declared required_checks entry names it.
        $result = $this->chain($this->highInReleaseWindowConfig())->govern($this->highRiskContext([
            'phpunit_scoped' => 'fail',
        ]));

        $this->assertSame('high', $result['risk_level']);
        $this->assertContains('phpunit_scoped', $result['missing_rerun']);
        $this->assertNotNull($result['gate_replay_plan_hash']);
        $this->assertSame(AtlasMergeGovernorAdmissionPolicy::DECISION_REPAIR, $result['decision']);
        $this->assertFalse($result['admitted']);
    }

    public function test_high_risk_run_with_satisfied_obligations_leaves_admission_unchanged(): void
    {
        $result = $this->chain($this->highInReleaseWindowConfig())->govern($this->highRiskContext([
            'phpunit_scoped' => 'pass',
            'diff_style_check' => 'pass',
            'false_green_guard' => 'pass',
            'receipt_quorum_check' => 'pass',
            'freshness_replay_check' => 'pass',
        ]));

        $this->assertSame('high', $result['risk_level']);
        $this->assertNotNull($result['gate_replay_plan_hash']);
        $this->assertSame(AtlasMergeGovernorAdmissionPolicy::DECISION_ADMITTED, $result['decision']);
        $this->assertTrue($result['admitted']);
        $this->assertSame([], $result['missing_rerun']);
    }

    public function test_medium_risk_envelope_stays_byte_identical_to_policy_only_derivation(): void
    {
        $config = [
            'risk_levels' => [
                'medium' => [
                    'required_checks' => ['syntax', 'boot'],
                    'in_release_window' => true,
                    'mode' => 'observe',
                ],
            ],
        ];

        $result = $this->chain($config)->govern([
            'task_packet_id' => 'task-replay-medium',
            'project_id' => 'atlas-self-construction',
            'changed_files' => ['app/Services/Foo/Bar.php'], // non-core-adjacent -> medium risk
            // 'phpunit_scoped' would be demanded by the gate replay plan for this file but is
            // deliberately reported as 'fail' here — medium risk must never reach that composition.
            'verification' => ['passed' => true, 'evidence_hash' => 'ev-replay-medium', 'checks' => [
                'syntax' => 'pass',
                'boot' => 'pass',
                'phpunit_scoped' => 'fail',
            ]],
        ]);

        $this->assertSame('medium', $result['risk_level']);
        $this->assertNull($result['gate_replay_plan_hash']);
        $this->assertSame([], $result['missing_rerun']);
        $this->assertSame(AtlasMergeGovernorAdmissionPolicy::DECISION_ADMITTED, $result['decision']);
        $this->assertTrue($result['admitted']);
    }
}
