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

    // ── learning_feedback ─────────────────────────────────────────────────────

    public function test_admitted_has_no_learning_feedback(): void
    {
        $r = (new AtlasMergeGovernorAdmissionPolicy)->decide($this->baseFacts());
        $this->assertArrayNotHasKey('learning_feedback', $r);
    }

    public function test_learning_feedback_has_all_six_required_keys(): void
    {
        $f = $this->baseFacts();
        $f['verification_court']['server_side_green'] = false;
        $lf = (new AtlasMergeGovernorAdmissionPolicy)->decide($f)['learning_feedback'];

        foreach (['blocker_family', 'repair_task_family', 'missing_evidence', 'rollback_need', 'release_window_need', 'should_requeue'] as $key) {
            $this->assertArrayHasKey($key, $lf);
        }
    }

    public function test_rejected_learning_feedback_is_evidence_family_and_requeueable(): void
    {
        $f = $this->baseFacts();
        $f['verification_court']['server_side_green'] = false;
        $lf = (new AtlasMergeGovernorAdmissionPolicy)->decide($f)['learning_feedback'];

        $this->assertSame('evidence', $lf['blocker_family']);
        $this->assertSame('rerun_verification', $lf['repair_task_family']);
        $this->assertContains('server_side_green', $lf['missing_evidence']);
        $this->assertTrue($lf['should_requeue']);
        $this->assertFalse($lf['release_window_need']);
    }

    public function test_repair_missing_evidence_hash_yields_evidence_family(): void
    {
        $f = $this->baseFacts();
        $f['verification_court']['evidence_hash'] = '';
        $lf = (new AtlasMergeGovernorAdmissionPolicy)->decide($f)['learning_feedback'];

        $this->assertSame('evidence', $lf['blocker_family']);
        $this->assertContains('evidence_hash', $lf['missing_evidence']);
        $this->assertTrue($lf['should_requeue']);
    }

    public function test_repair_missing_rerun_items_surfaced_in_missing_evidence(): void
    {
        $f = $this->baseFacts();
        $f['verification_court']['missing_rerun'] = ['mutation', 'lint'];
        $lf = (new AtlasMergeGovernorAdmissionPolicy)->decide($f)['learning_feedback'];

        $this->assertSame('evidence', $lf['blocker_family']);
        $this->assertContains('rerun:mutation', $lf['missing_evidence']);
        $this->assertContains('rerun:lint', $lf['missing_evidence']);
    }

    public function test_repair_non_conformant_rollback_yields_rollback_family(): void
    {
        $f = $this->baseFacts(AtlasMergeGovernorRiskClassifier::RISK_MEDIUM);
        $f['rollback_gate'] = ['conformant' => false, 'blockers' => ['no_restore']];
        $lf = (new AtlasMergeGovernorAdmissionPolicy)->decide($f)['learning_feedback'];

        $this->assertSame('rollback', $lf['blocker_family']);
        $this->assertSame('fix_rollback_plan', $lf['repair_task_family']);
        $this->assertTrue($lf['rollback_need']);
        $this->assertTrue($lf['should_requeue']);
    }

    public function test_blocked_project_mismatch_yields_project_mismatch_family_not_requeueable(): void
    {
        $f = $this->baseFacts();
        $f['verification_court']['project_id'] = 'OTHER';
        $lf = (new AtlasMergeGovernorAdmissionPolicy)->decide($f)['learning_feedback'];

        $this->assertSame('project_mismatch', $lf['blocker_family']);
        $this->assertSame('realign_project_scope', $lf['repair_task_family']);
        $this->assertFalse($lf['should_requeue']);
    }

    public function test_blocked_outside_release_window_yields_release_window_family_requeueable(): void
    {
        $f = $this->baseFacts(AtlasMergeGovernorRiskClassifier::RISK_HIGH);
        $lf = (new AtlasMergeGovernorAdmissionPolicy)->decide($f)['learning_feedback'];

        $this->assertSame('release_window', $lf['blocker_family']);
        $this->assertSame('defer_to_next_window', $lf['repair_task_family']);
        $this->assertTrue($lf['release_window_need']);
        $this->assertTrue($lf['should_requeue']);
    }

    public function test_blocked_risk_level_missing_yields_risk_family_not_requeueable(): void
    {
        $f = $this->baseFacts();
        $f['risk_classification'] = ['risk_level' => '', 'reasons' => []];
        $lf = (new AtlasMergeGovernorAdmissionPolicy)->decide($f)['learning_feedback'];

        $this->assertSame('risk', $lf['blocker_family']);
        $this->assertSame('respec_risk_class', $lf['repair_task_family']);
        $this->assertFalse($lf['should_requeue']);
    }
}
