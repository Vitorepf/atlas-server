<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainOriginatorSpecNoveltyGate;
use Tests\TestCase;

final class AtlasExternalBrainOriginatorSpecNoveltyGateTest extends TestCase
{
    public function test_fully_novel_candidate_is_safe_to_enqueue_with_high_novelty_score(): void
    {
        $result = (new AtlasExternalBrainOriginatorSpecNoveltyGate)->evaluate([
            'candidates' => [[
                'class_name' => 'AtlasBrandNewWidgetService',
                'objective' => 'Implement a completely unrelated widget rendering pipeline for dashboards.',
                'acceptance' => ['renders widgets correctly on the dashboard'],
                'allowed_files' => ['app/Widgets/AtlasBrandNewWidgetService.php'],
            ]],
            'queued_targets' => [[
                'class_name' => 'AtlasUnrelatedBillingService',
                'objective' => 'Reconcile invoices against payment gateway records.',
                'acceptance' => ['invoices reconcile against gateway totals'],
                'allowed_files' => ['app/Billing/AtlasUnrelatedBillingService.php'],
            ]],
            'existing_class_names' => ['AtlasUnrelatedBillingService'],
        ]);

        $row = $result['results'][0];
        $this->assertTrue($row['safe_to_enqueue']);
        $this->assertSame([], $row['duplicate_evidence']);
        $this->assertNull($row['suggested_merge_or_pivot_action']);
        $this->assertGreaterThan(0.8, $row['novelty_score']);
    }

    public function test_exact_class_name_collision_blocks_even_with_different_objective(): void
    {
        $result = (new AtlasExternalBrainOriginatorSpecNoveltyGate)->evaluate([
            'candidates' => [[
                'class_name' => 'AtlasFooService',
                'objective' => 'Something totally different about reporting.',
                'acceptance' => ['reports generate correctly'],
                'allowed_files' => ['app/Reports/AtlasFooService.php'],
            ]],
            'existing_class_names' => ['AtlasFooService'],
        ]);

        $row = $result['results'][0];
        $this->assertFalse($row['safe_to_enqueue']);
        $this->assertContains('class_name_collision', array_column($row['duplicate_evidence'], 'reason'));
        $this->assertSame('pivot_objective_or_scope', $row['suggested_merge_or_pivot_action']);
    }

    public function test_near_duplicate_with_different_class_name_is_blocked_as_semantic_overlap(): void
    {
        $queuedObjective = 'Strengthen AtlasTaskCoordinationHealthService so it reports the configured serving disk health, whether it is dedicated, and the blocking reason inside the snapshot.';
        $candidateObjective = 'Strengthen AtlasTaskCoordinationHealthService so it reports the configured serving disk health, whether it is dedicated, and the blocking reason inside every snapshot.';

        $result = (new AtlasExternalBrainOriginatorSpecNoveltyGate)->evaluate([
            'candidates' => [[
                'class_name' => 'AtlasTaskCoordinationHealthDiskRenamedService',
                'objective' => $candidateObjective,
                'acceptance' => ['snapshot includes serving disk health ok, disk and reason fields'],
                'allowed_files' => ['app/Services/Ai/SelfConstruction/AtlasTaskCoordinationHealthService.php'],
            ]],
            'queued_targets' => [[
                'class_name' => 'AtlasTaskCoordinationHealthService',
                'objective' => $queuedObjective,
                'acceptance' => ['snapshot includes serving disk health ok, disk and reason fields'],
                'allowed_files' => ['app/Services/Ai/SelfConstruction/AtlasTaskCoordinationHealthService.php'],
            ]],
        ]);

        $row = $result['results'][0];
        $this->assertFalse($row['safe_to_enqueue'], 'near-duplicate spec under a different class name must still be blocked');
        $this->assertContains('semantic_overlap', array_column($row['duplicate_evidence'], 'reason'));
        $this->assertStringStartsWith('merge_with_', (string) $row['suggested_merge_or_pivot_action']);
        $this->assertLessThan(0.4, $row['novelty_score']);
    }

    public function test_recent_authored_specs_are_also_checked_for_duplication(): void
    {
        $result = (new AtlasExternalBrainOriginatorSpecNoveltyGate)->evaluate([
            'candidates' => [[
                'class_name' => 'AtlasAnotherRenamedService',
                'objective' => 'Harden the receipt verifier field matrix for blocking reasons and presence diagnostics.',
                'acceptance' => ['field verification matrix covers all required receipt fields'],
                'allowed_files' => ['app/Services/Ai/SelfConstruction/AtlasReceiptVerifierService.php'],
            ]],
            'recent_authored_specs' => [[
                'class_name' => 'AtlasReceiptVerifierService',
                'objective' => 'Harden the receipt verifier field matrix for blocking reasons and presence diagnostics.',
                'acceptance' => ['field verification matrix covers all required receipt fields'],
                'allowed_files' => ['app/Services/Ai/SelfConstruction/AtlasReceiptVerifierService.php'],
            ]],
        ]);

        $row = $result['results'][0];
        $this->assertFalse($row['safe_to_enqueue']);
        $this->assertSame('merge_with_AtlasReceiptVerifierService', $row['suggested_merge_or_pivot_action']);
    }

    public function test_candidate_count_and_safe_to_enqueue_count_are_reported(): void
    {
        $result = (new AtlasExternalBrainOriginatorSpecNoveltyGate)->evaluate([
            'candidates' => [
                ['class_name' => 'AtlasNovelOneService', 'objective' => 'Build a totally fresh capability nobody has touched before.'],
                ['class_name' => 'AtlasDuplicateService'],
            ],
            'existing_class_names' => ['AtlasDuplicateService'],
        ]);

        $this->assertSame(2, $result['candidate_count']);
        $this->assertSame(1, $result['safe_to_enqueue_count']);
    }
}
