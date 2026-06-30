<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\TaskQuality;

use App\Services\Ai\SelfConstruction\TaskQuality\AtlasTaskBlockedReplacementDraftCompleter;
use Tests\TestCase;

final class AtlasTaskBlockedReplacementDraftCompleterSubmitTest extends TestCase
{
    private function completer(): AtlasTaskBlockedReplacementDraftCompleter
    {
        return new AtlasTaskBlockedReplacementDraftCompleter;
    }

    private function reviewRecommendedDraft(array $overrides = []): array
    {
        return array_merge([
            'draft_id' => 'dr_review_1',
            'kind' => 'review_recommended',
            'wave' => 3,
            'source_packet_ids' => ['tp-review-1'],
            'task_packet_id' => 'tp-review-1',
            'allowed_files' => [],
            'acceptance_criteria' => [],
            'required_evidence' => [],
        ], $overrides);
    }

    public function test_review_recommended_draft_with_full_recovery_becomes_can_submit_true(): void
    {
        $result = $this->completer()->complete([[
            'draft' => $this->reviewRecommendedDraft(),
            'field_recovery' => [
                'allowed_files' => ['app/Services/Ai/Foo/AtlasFoo.php'],
                'acceptance_criteria' => ['/opt/homebrew/bin/php artisan test tests/Unit/Ai/Foo/AtlasFooTest.php'],
                'required_evidence' => ['tests_or_gates_result', 'implementation_notes'],
                'trust' => 'trusted',
                'confidence' => 0.9,
            ],
        ]]);

        $completed = $result['completed_drafts'][0];
        $this->assertTrue($completed['can_submit']);
        $this->assertSame([], $completed['missing_fields']);
        $this->assertNotNull($completed['replacement_task_packet_id']);
    }

    public function test_review_recommended_draft_with_partial_recovery_stays_can_submit_false_with_exact_missing_fields(): void
    {
        $result = $this->completer()->complete([[
            'draft' => $this->reviewRecommendedDraft(),
            'field_recovery' => [
                'allowed_files' => ['app/Services/Ai/Foo/AtlasFoo.php'],
                'acceptance_criteria' => ['/opt/homebrew/bin/php artisan test tests/Unit/Ai/Foo/AtlasFooTest.php'],
                'trust' => 'trusted',
                'confidence' => 0.9,
            ],
        ]]);

        $completed = $result['completed_drafts'][0];
        $this->assertFalse($completed['can_submit']);
        $this->assertSame(['required_evidence'], $completed['missing_fields']);
        $this->assertNull($completed['replacement_task_packet_id']);
    }
}
