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
 * Closes the false-green hole where govern() passed missing_rerun=[] as a hard-coded literal.
 * Proves the required-rerun set is bound to the Policy Plane's per-risk-level required_checks and
 * compared against the checks that actually ran (pass|skip|fail), so a skipped required re-run is
 * no longer invisible to the admission decision.
 */
final class AtlasTaskCommitGovernanceChainRerunBindingTest extends TestCase
{
    private string $verdictLedgerPath;

    private string $releaseLedgerPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->verdictLedgerPath = sys_get_temp_dir().'/atlas_rerun_verdict_'.uniqid('', true).'.jsonl';
        $this->releaseLedgerPath = sys_get_temp_dir().'/atlas_rerun_release_'.uniqid('', true).'.jsonl';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->verdictLedgerPath)) {
            unlink($this->verdictLedgerPath);
        }
        if (file_exists($this->releaseLedgerPath)) {
            unlink($this->releaseLedgerPath);
        }
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
            'task_packet_id' => 'task-rerun-high',
            'project_id' => 'atlas-self-construction',
            'changed_files' => ['app/Services/Ai/SelfConstruction/MergeGovernor/SomeOrganFile.php'],
            'verification' => ['passed' => true, 'evidence_hash' => 'ev-rerun-high', 'checks' => $checks],
        ];
    }

    // ── (a) required check skipped -> missing_rerun contains it, decision reflects it ──

    public function test_skipped_required_check_lands_in_missing_rerun_and_admission_reflects_it(): void
    {
        $config = [
            'risk_levels' => [
                'high' => [
                    'required_checks' => ['syntax', 'boot', 'task_tests'],
                    'in_release_window' => true,
                    'mode' => 'observe',
                ],
            ],
        ];

        $result = $this->chain($config)->govern($this->highRiskContext([
            'syntax' => 'pass',
            'boot' => 'pass',
            'task_tests' => 'skip',
        ]));

        $this->assertSame('high', $result['risk_level']);
        $this->assertSame(AtlasMergeGovernorAdmissionPolicy::DECISION_REPAIR, $result['decision']);
        $this->assertFalse($result['admitted']);
        $this->assertContains('missing_rerun:task_tests', $result['blockers']);
        $this->assertNotContains('missing_rerun:syntax', $result['blockers']);
        $this->assertNotContains('missing_rerun:boot', $result['blockers']);
    }

    public function test_missing_check_key_entirely_is_not_flagged(): void
    {
        // A required check that never appears in $checks at all is left alone -- this stays a
        // strictly additive safety net over an OBSERVED skip/fail, never a retroactive tightening
        // of every caller that predates this binding and never populated a full checks map.
        $config = [
            'risk_levels' => [
                'high' => [
                    'required_checks' => ['syntax', 'boot', 'task_tests'],
                    'in_release_window' => true,
                    'mode' => 'observe',
                ],
            ],
        ];

        $result = $this->chain($config)->govern($this->highRiskContext([
            'syntax' => 'pass',
            'boot' => 'pass',
        ]));

        $this->assertNotContains('missing_rerun:task_tests', $result['blockers']);
        $this->assertSame(AtlasMergeGovernorAdmissionPolicy::DECISION_ADMITTED, $result['decision']);
    }

    public function test_failed_required_check_also_lands_in_missing_rerun(): void
    {
        $config = [
            'risk_levels' => [
                'high' => [
                    'required_checks' => ['syntax', 'boot', 'task_tests'],
                    'in_release_window' => true,
                    'mode' => 'observe',
                ],
            ],
        ];

        $result = $this->chain($config)->govern($this->highRiskContext([
            'syntax' => 'pass',
            'boot' => 'pass',
            'task_tests' => 'fail',
        ]));

        $this->assertContains('missing_rerun:task_tests', $result['blockers']);
    }

    // ── (b) all required checks pass -> missing_rerun empty, admission unchanged ──

    public function test_all_required_checks_passing_leaves_missing_rerun_empty_and_admits(): void
    {
        $config = [
            'risk_levels' => [
                'high' => [
                    'required_checks' => ['syntax', 'boot', 'task_tests'],
                    'in_release_window' => true,
                    'mode' => 'observe',
                ],
            ],
        ];

        $result = $this->chain($config)->govern($this->highRiskContext([
            'syntax' => 'pass',
            'boot' => 'pass',
            'task_tests' => 'pass',
        ]));

        $this->assertSame(AtlasMergeGovernorAdmissionPolicy::DECISION_ADMITTED, $result['decision']);
        $this->assertTrue($result['admitted']);
        $this->assertSame([], $result['blockers']);
    }

    // ── (c) empty policy-declared required-check set -> behavior matches today (empty) ──

    public function test_empty_policy_declared_required_checks_keeps_missing_rerun_empty(): void
    {
        // No risk_levels declared at all -- requiredChecksFor() returns [] for every level.
        $result = $this->chain([])->govern([
            'task_packet_id' => 'task-rerun-default',
            'project_id' => 'atlas-self-construction',
            'changed_files' => ['app/Services/Foo.php'], // non-core-adjacent -> medium risk, in default window
            'verification' => ['passed' => true, 'evidence_hash' => 'ev-rerun-default', 'checks' => []],
        ]);

        $this->assertSame(AtlasMergeGovernorAdmissionPolicy::DECISION_ADMITTED, $result['decision']);
        $this->assertTrue($result['admitted']);
        $this->assertSame([], $result['blockers']);
    }
}
