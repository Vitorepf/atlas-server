<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainResearchToTaskDigestor;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainResearchToTaskDigestorTest extends TestCase
{
    private AtlasExternalBrainResearchToTaskDigestor $digestor;

    protected function setUp(): void
    {
        $this->digestor = new AtlasExternalBrainResearchToTaskDigestor;
    }

    private function goodItem(array $overrides = []): array
    {
        return array_merge([
            'source'              => 'arxiv:2603.15031',
            'pattern_summary'     => 'Attention residuals over depth layers improve recall',
            'atlas_failure_mode'  => 'Brain comprehension degrades when depth signals are ignored',
            'target_path'         => 'comprehension-deepening',
            'adaptation_notes'    => 'Implement as AtlasBrainDepthScorer reading existing signal maps',
            'allowed_files'       => ['app/Services/Ai/Brain/AtlasBrainDepthScorer.php'],
            'test_path'           => 'tests/Unit/Ai/Brain/AtlasBrainDepthScorerTest.php',
            'anti_goodhart_risks' => ['depth_score_used_as_proxy_without_behavioral_proof'],
            'runnable_acceptance' => '/opt/homebrew/bin/php artisan test --filter=AtlasBrainDepthScorerTest exits 0',
        ], $overrides);
    }

    private function input(array ...$items): array
    {
        return ['research_items' => $items];
    }

    // ── Schema / envelope ─────────────────────────────────────────────────────

    public function test_result_has_required_keys(): void
    {
        $result = $this->digestor->digest($this->input($this->goodItem()));

        foreach (['schema', 'promoted', 'rejected', 'promoted_count', 'rejected_count'] as $k) {
            $this->assertArrayHasKey($k, $result);
        }
        $this->assertSame(AtlasExternalBrainResearchToTaskDigestor::SCHEMA, $result['schema']);
    }

    // ── AC3: good item is promoted ────────────────────────────────────────────

    public function test_complete_item_is_promoted(): void
    {
        $result = $this->digestor->digest($this->input($this->goodItem()));

        $this->assertSame(1, $result['promoted_count']);
        $this->assertSame(0, $result['rejected_count']);
        $this->assertCount(1, $result['promoted']);
    }

    public function test_promoted_candidate_has_all_fields(): void
    {
        $result = $this->digestor->digest($this->input($this->goodItem()));
        $candidate = $result['promoted'][0];

        foreach (['source', 'pattern_summary', 'atlas_failure_mode', 'target_path', 'adaptation_notes', 'allowed_files', 'test_path', 'anti_goodhart_risks', 'runnable_acceptance'] as $k) {
            $this->assertArrayHasKey($k, $candidate, "Promoted candidate missing field: {$k}");
        }
    }

    // ── AC2: reject hype-only items ───────────────────────────────────────────

    public function test_rejects_item_with_no_atlas_failure_mode(): void
    {
        $result = $this->digestor->digest($this->input($this->goodItem(['atlas_failure_mode' => ''])));

        $this->assertSame(0, $result['promoted_count']);
        $this->assertSame(1, $result['rejected_count']);
        $this->assertSame(
            AtlasExternalBrainResearchToTaskDigestor::REJECTION_HYPE_ONLY,
            $result['rejected'][0]['rejection_reason'],
        );
    }

    public function test_rejects_item_missing_atlas_failure_mode_key(): void
    {
        $item = $this->goodItem();
        unset($item['atlas_failure_mode']);

        $result = $this->digestor->digest($this->input($item));

        $this->assertSame(
            AtlasExternalBrainResearchToTaskDigestor::REJECTION_HYPE_ONLY,
            $result['rejected'][0]['rejection_reason'],
        );
    }

    // ── Reject: no target_path ────────────────────────────────────────────────

    public function test_rejects_item_with_no_target_path(): void
    {
        $result = $this->digestor->digest($this->input($this->goodItem(['target_path' => ''])));

        $this->assertSame(
            AtlasExternalBrainResearchToTaskDigestor::REJECTION_NO_TARGET_PATH,
            $result['rejected'][0]['rejection_reason'],
        );
    }

    // ── Reject: no runnable acceptance ────────────────────────────────────────

    public function test_rejects_item_with_no_runnable_acceptance(): void
    {
        $result = $this->digestor->digest($this->input($this->goodItem([
            'runnable_acceptance' => 'The output should be correct',
        ])));

        $this->assertSame(
            AtlasExternalBrainResearchToTaskDigestor::REJECTION_NO_RUNNABLE_ACCEPTANCE,
            $result['rejected'][0]['rejection_reason'],
        );
    }

    public function test_vendor_bin_phpunit_counts_as_runnable(): void
    {
        $result = $this->digestor->digest($this->input($this->goodItem([
            'runnable_acceptance' => './vendor/bin/phpunit tests/Unit/Ai/FooTest.php exits 0',
        ])));

        $this->assertSame(1, $result['promoted_count']);
    }

    // ── AC3: reject incomplete candidates ─────────────────────────────────────

    public function test_rejects_item_missing_adaptation_notes(): void
    {
        $result = $this->digestor->digest($this->input($this->goodItem(['adaptation_notes' => ''])));

        $this->assertSame(
            AtlasExternalBrainResearchToTaskDigestor::REJECTION_INCOMPLETE_CANDIDATE,
            $result['rejected'][0]['rejection_reason'],
        );
    }

    public function test_rejects_item_missing_allowed_files(): void
    {
        $result = $this->digestor->digest($this->input($this->goodItem(['allowed_files' => []])));

        $this->assertSame(
            AtlasExternalBrainResearchToTaskDigestor::REJECTION_INCOMPLETE_CANDIDATE,
            $result['rejected'][0]['rejection_reason'],
        );
    }

    public function test_rejects_item_missing_test_path(): void
    {
        $result = $this->digestor->digest($this->input($this->goodItem(['test_path' => ''])));

        $this->assertSame(
            AtlasExternalBrainResearchToTaskDigestor::REJECTION_INCOMPLETE_CANDIDATE,
            $result['rejected'][0]['rejection_reason'],
        );
    }

    public function test_rejects_item_missing_anti_goodhart_risks(): void
    {
        $result = $this->digestor->digest($this->input($this->goodItem(['anti_goodhart_risks' => []])));

        $this->assertSame(
            AtlasExternalBrainResearchToTaskDigestor::REJECTION_INCOMPLETE_CANDIDATE,
            $result['rejected'][0]['rejection_reason'],
        );
    }

    // ── Mixed batch ───────────────────────────────────────────────────────────

    public function test_batch_with_mixed_items(): void
    {
        $result = $this->digestor->digest($this->input(
            $this->goodItem(),                                                    // promoted
            $this->goodItem(['atlas_failure_mode' => '']),                        // rejected: hype_only
            $this->goodItem(['target_path' => '']),                               // rejected: no_target_path
            $this->goodItem(['runnable_acceptance' => 'prose only']),             // rejected: no_runnable_acceptance
        ));

        $this->assertSame(1, $result['promoted_count']);
        $this->assertSame(3, $result['rejected_count']);
    }

    // ── Empty batch ───────────────────────────────────────────────────────────

    public function test_empty_batch_returns_zero_counts(): void
    {
        $result = $this->digestor->digest(['research_items' => []]);

        $this->assertSame(0, $result['promoted_count']);
        $this->assertSame(0, $result['rejected_count']);
        $this->assertSame([], $result['promoted']);
        $this->assertSame([], $result['rejected']);
    }

    // ── Rejected item carries original item reference ─────────────────────────

    public function test_rejected_entry_includes_original_item(): void
    {
        $item   = $this->goodItem(['atlas_failure_mode' => '']);
        $result = $this->digestor->digest($this->input($item));

        $this->assertArrayHasKey('item', $result['rejected'][0]);
        $this->assertSame($item, $result['rejected'][0]['item']);
    }
}
