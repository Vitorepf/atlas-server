<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\MergeGovernor;

use App\Services\Ai\SelfConstruction\MergeGovernor\AtlasMergeGovernorAdmissionPolicy;
use App\Services\Ai\SelfConstruction\MergeGovernor\AtlasMergeGovernorRiskClassifier;
use PHPUnit\Framework\TestCase;

/**
 * Proves AtlasMergeGovernorAdmissionPolicy: low-risk + verified + conformant rollback ⇒ admitted;
 * red verification ⇒ rejected with verification_not_server_side_green; missing evidence hash ⇒
 * repair_required:evidence_hash_missing; non-conformant rollback at low/medium risk ⇒ repair_required;
 * HIGH risk without conformant rollback ⇒ blocked with high_risk_requires_conformant_rollback;
 * HIGH risk allowed only when release_window_policy explicitly admits 'high'.
 */
final class AtlasMergeGovernorAdmissionPolicyTest extends TestCase
{
    private function baseFacts(string $risk = AtlasMergeGovernorRiskClassifier::RISK_LOW): array
    {
        return [
            'project_id' => 'demo',
            'risk_classification' => ['risk_level' => $risk, 'reasons' => []],
            'rollback_gate' => ['conformant' => true, 'blockers' => []],
            'verification_court' => ['server_side_green' => true, 'evidence_hash' => 'evh-1', 'missing_rerun' => [], 'project_id' => 'demo'],
            'release_window_policy' => ['allowed_risk_levels' => ['low', 'medium']],
        ];
    }

    public function test_low_risk_verified_conformant_yields_admitted(): void
    {
        $r = (new AtlasMergeGovernorAdmissionPolicy)->decide($this->baseFacts());
        $this->assertSame(AtlasMergeGovernorAdmissionPolicy::DECISION_ADMITTED, $r['decision']);
        $this->assertSame([], $r['blockers']);
    }

    public function test_red_verification_yields_rejected(): void
    {
        $f = $this->baseFacts();
        $f['verification_court']['server_side_green'] = false;
        $r = (new AtlasMergeGovernorAdmissionPolicy)->decide($f);
        $this->assertSame(AtlasMergeGovernorAdmissionPolicy::DECISION_REJECTED, $r['decision']);
        $this->assertContains('verification_not_server_side_green', $r['blockers']);
    }

    public function test_missing_evidence_hash_yields_repair_required(): void
    {
        $f = $this->baseFacts();
        $f['verification_court']['evidence_hash'] = '';
        $r = (new AtlasMergeGovernorAdmissionPolicy)->decide($f);
        $this->assertSame(AtlasMergeGovernorAdmissionPolicy::DECISION_REPAIR, $r['decision']);
        $this->assertContains('evidence_hash_missing', $r['blockers']);
    }

    public function test_non_conformant_rollback_at_medium_risk_yields_repair_required(): void
    {
        $f = $this->baseFacts(AtlasMergeGovernorRiskClassifier::RISK_MEDIUM);
        $f['rollback_gate'] = ['conformant' => false, 'blockers' => ['missing_restore_strategy']];
        $r = (new AtlasMergeGovernorAdmissionPolicy)->decide($f);
        $this->assertSame(AtlasMergeGovernorAdmissionPolicy::DECISION_REPAIR, $r['decision']);
        $this->assertContains('rollback_not_conformant', $r['blockers']);
        $this->assertContains('rollback:missing_restore_strategy', $r['blockers']);
    }

    public function test_high_risk_without_conformant_rollback_is_blocked(): void
    {
        $f = $this->baseFacts(AtlasMergeGovernorRiskClassifier::RISK_HIGH);
        $f['rollback_gate'] = ['conformant' => false, 'blockers' => []];
        $f['release_window_policy']['allowed_risk_levels'] = ['low', 'medium', 'high'];
        $r = (new AtlasMergeGovernorAdmissionPolicy)->decide($f);
        $this->assertSame(AtlasMergeGovernorAdmissionPolicy::DECISION_BLOCKED, $r['decision']);
        $this->assertContains('high_risk_requires_conformant_rollback', $r['blockers']);
    }

    public function test_high_risk_outside_release_window_is_blocked(): void
    {
        $f = $this->baseFacts(AtlasMergeGovernorRiskClassifier::RISK_HIGH);
        // release window allows only low+medium ⇒ high is blocked.
        $r = (new AtlasMergeGovernorAdmissionPolicy)->decide($f);
        $this->assertSame(AtlasMergeGovernorAdmissionPolicy::DECISION_BLOCKED, $r['decision']);
        $this->assertContains('risk_level_outside_release_window:high', $r['blockers']);
    }

    public function test_high_risk_admitted_only_when_window_explicitly_allows_high(): void
    {
        $f = $this->baseFacts(AtlasMergeGovernorRiskClassifier::RISK_HIGH);
        $f['release_window_policy']['allowed_risk_levels'] = ['low', 'medium', 'high'];
        $r = (new AtlasMergeGovernorAdmissionPolicy)->decide($f);
        $this->assertSame(AtlasMergeGovernorAdmissionPolicy::DECISION_ADMITTED, $r['decision']);
    }

    public function test_verification_court_project_mismatch_blocks(): void
    {
        $f = $this->baseFacts();
        $f['verification_court']['project_id'] = 'OTHER';
        $r = (new AtlasMergeGovernorAdmissionPolicy)->decide($f);
        $this->assertSame(AtlasMergeGovernorAdmissionPolicy::DECISION_BLOCKED, $r['decision']);
        $this->assertContains('verification_court_project_mismatch:OTHER!=demo', $r['blockers']);
    }

    public function test_missing_risk_level_yields_risk_level_missing_blocker(): void
    {
        $f = $this->baseFacts();
        $f['risk_classification'] = ['risk_level' => '', 'reasons' => []];
        $r = (new AtlasMergeGovernorAdmissionPolicy)->decide($f);
        $this->assertSame(AtlasMergeGovernorAdmissionPolicy::DECISION_BLOCKED, $r['decision']);
        $this->assertContains('risk_level_missing', $r['blockers']);
    }

    public function test_absent_risk_classification_yields_risk_level_missing_blocker(): void
    {
        $f = $this->baseFacts();
        unset($f['risk_classification']);
        $r = (new AtlasMergeGovernorAdmissionPolicy)->decide($f);
        $this->assertSame(AtlasMergeGovernorAdmissionPolicy::DECISION_BLOCKED, $r['decision']);
        $this->assertContains('risk_level_missing', $r['blockers']);
    }

    public function test_unknown_risk_level_yields_risk_level_unknown_blocker(): void
    {
        $f = $this->baseFacts();
        $f['risk_classification'] = ['risk_level' => 'catastrophic', 'reasons' => []];
        $r = (new AtlasMergeGovernorAdmissionPolicy)->decide($f);
        $this->assertSame(AtlasMergeGovernorAdmissionPolicy::DECISION_BLOCKED, $r['decision']);
        $this->assertContains('risk_level_unknown:catastrophic', $r['blockers']);
        $this->assertNotContains('risk_level_outside_release_window:catastrophic', $r['blockers']);
    }

    public function test_missing_allowed_risk_levels_yields_blocked_not_implicit_admission(): void
    {
        $f = $this->baseFacts();
        unset($f['release_window_policy']['allowed_risk_levels']);
        $r = (new AtlasMergeGovernorAdmissionPolicy)->decide($f);
        $this->assertSame(AtlasMergeGovernorAdmissionPolicy::DECISION_BLOCKED, $r['decision']);
        $this->assertContains('release_window_allowed_risk_levels_missing', $r['blockers']);
    }

    public function test_empty_allowed_risk_levels_yields_blocked_not_implicit_admission(): void
    {
        $f = $this->baseFacts();
        $f['release_window_policy']['allowed_risk_levels'] = [];
        $r = (new AtlasMergeGovernorAdmissionPolicy)->decide($f);
        $this->assertSame(AtlasMergeGovernorAdmissionPolicy::DECISION_BLOCKED, $r['decision']);
        $this->assertContains('release_window_allowed_risk_levels_missing', $r['blockers']);
    }
}
