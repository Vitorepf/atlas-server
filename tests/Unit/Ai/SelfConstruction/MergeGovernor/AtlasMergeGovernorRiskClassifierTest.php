<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\MergeGovernor;

use App\Services\Ai\SelfConstruction\MergeGovernor\AtlasMergeGovernorRiskClassifier;
use PHPUnit\Framework\TestCase;

/**
 * Proves AtlasMergeGovernorRiskClassifier: docs-only ⇒ low; a service change ⇒ medium; a core-adjacent
 * organ touched ⇒ high; missing verification ⇒ blocked with verification_not_passed reason; project-lane
 * mismatch ⇒ blocked with project_lane_mismatch reason; forbidden core organ ⇒ blocked with
 * forbidden_organ_touched reason.
 */
final class AtlasMergeGovernorRiskClassifierTest extends TestCase
{
    private function baseGood(): array
    {
        return [
            'verification_result' => ['passed' => true],
            'rollback_plan' => ['mode' => 'revert_commit'],
            'project_lane' => ['project_id' => 'demo', 'allowed_scope_roots' => ['app/']],
            'scope_deviations' => [],
            'task_evidence_ref' => 'task-ref-001',
        ];
    }

    public function test_docs_only_change_yields_low_risk(): void
    {
        $r = (new AtlasMergeGovernorRiskClassifier)->classify(array_merge($this->baseGood(), [
            'changed_files' => ['docs/x.md', 'docs/y.md'],
            'touched_organs' => [],
            'project_lane' => ['project_id' => 'demo', 'allowed_scope_roots' => ['docs/', 'app/']],
        ]));
        $this->assertSame(AtlasMergeGovernorRiskClassifier::RISK_LOW, $r['risk_level']);
        $this->assertContains('docs_only_change', $r['reasons']);
    }

    public function test_service_change_yields_medium_risk(): void
    {
        $r = (new AtlasMergeGovernorRiskClassifier)->classify(array_merge($this->baseGood(), [
            'changed_files' => ['app/Demo/Service.php'],
            'touched_organs' => ['Demo'],
        ]));
        $this->assertSame(AtlasMergeGovernorRiskClassifier::RISK_MEDIUM, $r['risk_level']);
    }

    public function test_core_adjacent_organ_touched_yields_high_risk(): void
    {
        $r = (new AtlasMergeGovernorRiskClassifier)->classify(array_merge($this->baseGood(), [
            'changed_files' => ['app/MergeGovernor/X.php'],
            'touched_organs' => ['Merge Governor'],
        ]));
        $this->assertSame(AtlasMergeGovernorRiskClassifier::RISK_HIGH, $r['risk_level']);
        $this->assertStringContainsString('core_adjacent_organ_touched', implode(',', $r['reasons']));
    }

    public function test_missing_verification_blocks(): void
    {
        $candidate = array_merge($this->baseGood(), [
            'changed_files' => ['app/Foo.php'],
            'touched_organs' => ['Foo'],
            'verification_result' => ['passed' => false],
        ]);
        $r = (new AtlasMergeGovernorRiskClassifier)->classify($candidate);
        $this->assertSame(AtlasMergeGovernorRiskClassifier::RISK_BLOCKED, $r['risk_level']);
        $this->assertContains('verification_not_passed', $r['reasons']);
    }

    public function test_missing_rollback_blocks(): void
    {
        $candidate = array_merge($this->baseGood(), [
            'changed_files' => ['app/Foo.php'],
            'rollback_plan' => [],
        ]);
        $r = (new AtlasMergeGovernorRiskClassifier)->classify($candidate);
        $this->assertSame(AtlasMergeGovernorRiskClassifier::RISK_BLOCKED, $r['risk_level']);
        $this->assertContains('rollback_plan_missing', $r['reasons']);
    }

    public function test_forbidden_organ_blocks(): void
    {
        $candidate = array_merge($this->baseGood(), [
            'changed_files' => ['app/Constitution/Bylaw.php'],
            'touched_organs' => ['Constitution'],
        ]);
        $r = (new AtlasMergeGovernorRiskClassifier)->classify($candidate);
        $this->assertSame(AtlasMergeGovernorRiskClassifier::RISK_BLOCKED, $r['risk_level']);
        $this->assertStringContainsString('forbidden_organ_touched:Constitution', implode(',', $r['reasons']));
    }

    public function test_project_lane_mismatch_blocks(): void
    {
        $candidate = array_merge($this->baseGood(), [
            'changed_files' => ['/etc/passwd'],
            'touched_organs' => [],
            'project_lane' => ['project_id' => 'demo', 'allowed_scope_roots' => ['/repo/lane-a/']],
        ]);
        $r = (new AtlasMergeGovernorRiskClassifier)->classify($candidate);
        $this->assertSame(AtlasMergeGovernorRiskClassifier::RISK_BLOCKED, $r['risk_level']);
        $this->assertStringContainsString('project_lane_mismatch', implode(',', $r['reasons']));
    }

    public function test_scope_deviations_block(): void
    {
        $candidate = array_merge($this->baseGood(), [
            'changed_files' => ['app/Foo.php'],
            'scope_deviations' => [['path' => 'app/Bar.php']],
        ]);
        $r = (new AtlasMergeGovernorRiskClassifier)->classify($candidate);
        $this->assertSame(AtlasMergeGovernorRiskClassifier::RISK_BLOCKED, $r['risk_level']);
        $this->assertStringContainsString('scope_deviations_present', implode(',', $r['reasons']));
    }

    public function test_empty_changed_files_blocks(): void
    {
        $r = (new AtlasMergeGovernorRiskClassifier)->classify(array_merge($this->baseGood(), [
            'changed_files' => [],
        ]));
        $this->assertSame(AtlasMergeGovernorRiskClassifier::RISK_BLOCKED, $r['risk_level']);
        $this->assertContains('empty_changed_files', $r['reasons']);
    }

    public function test_missing_project_lane_blocks(): void
    {
        $candidate = $this->baseGood();
        unset($candidate['project_lane']);
        $candidate['changed_files'] = ['app/Foo.php'];
        $r = (new AtlasMergeGovernorRiskClassifier)->classify($candidate);
        $this->assertSame(AtlasMergeGovernorRiskClassifier::RISK_BLOCKED, $r['risk_level']);
        $this->assertContains('project_lane_missing', $r['reasons']);
    }

    public function test_unknown_risk_classification_blocks(): void
    {
        $r = (new AtlasMergeGovernorRiskClassifier)->classify(array_merge($this->baseGood(), [
            'changed_files' => ['app/Foo.php'],
            'risk_classification' => 'chaos',
        ]));
        $this->assertSame(AtlasMergeGovernorRiskClassifier::RISK_BLOCKED, $r['risk_level']);
        $this->assertContains('unknown_risk_classification:chaos', $r['reasons']);
    }

    public function test_missing_task_evidence_ref_blocks(): void
    {
        $candidate = $this->baseGood();
        unset($candidate['task_evidence_ref']);
        $candidate['changed_files'] = ['app/Foo.php'];
        $r = (new AtlasMergeGovernorRiskClassifier)->classify($candidate);
        $this->assertSame(AtlasMergeGovernorRiskClassifier::RISK_BLOCKED, $r['risk_level']);
        $this->assertContains('missing_task_evidence_ref', $r['reasons']);
    }

    public function test_docs_only_with_full_lane_verification_rollback_and_task_evidence_classifies_low(): void
    {
        $r = (new AtlasMergeGovernorRiskClassifier)->classify([
            'changed_files' => ['docs/guide.md', 'docs/api.txt'],
            'touched_organs' => [],
            'verification_result' => ['passed' => true],
            'rollback_plan' => ['mode' => 'revert_commit'],
            'project_lane' => ['project_id' => 'atlas', 'allowed_scope_roots' => ['docs/']],
            'scope_deviations' => [],
            'task_evidence_ref' => 'task-docs-042',
        ]);
        $this->assertSame(AtlasMergeGovernorRiskClassifier::RISK_LOW, $r['risk_level']);
        $this->assertContains('docs_only_change', $r['reasons']);
    }
}
