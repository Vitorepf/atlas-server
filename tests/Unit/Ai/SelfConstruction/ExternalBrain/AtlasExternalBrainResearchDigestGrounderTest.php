<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainResearchDigestGrounder;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainResearchDigestGrounderTest extends TestCase
{
    private AtlasExternalBrainResearchDigestGrounder $grounder;

    protected function setUp(): void
    {
        $this->grounder = new AtlasExternalBrainResearchDigestGrounder;
    }

    private function good(array $overrides = []): array
    {
        return array_merge([
            'idea_id'                => 'idea-001',
            'local_symbols'          => ['AtlasBrainDepthScorer', 'AtlasBrainComprehensionLayer'],
            'allowed_files_candidate' => ['app/Services/Ai/SelfConstruction/ExternalBrain/AtlasExternalBrainResearchDigestGrounder.php'],
            'runnable_evidence_path' => '/opt/homebrew/bin/php artisan test --filter=AtlasBrainDepthScorerTest',
            'atlas_capability_gap'   => 'comprehension-deepening',
            'risk_constraints'       => ['no_external_provider_steady_state'],
            'acceptance_seed'        => 'test passes with all gates green',
            'leverage_hint'          => 'depth-scoring-improvement',
            'has_local_owner'        => true,
            'forbidden_scope'        => false,
            'provider_steady_state_dep' => false,
        ], $overrides);
    }

    private function input(array ...$ideas): array
    {
        return ['research_ideas' => $ideas];
    }

    // ── Schema / keys ─────────────────────────────────────────────────────────

    public function test_result_has_required_keys(): void
    {
        $result = $this->grounder->ground($this->input($this->good()));

        foreach (['schema', 'task_candidates', 'rejected', 'promoted_count', 'rejected_count'] as $k) {
            $this->assertArrayHasKey($k, $result);
        }
        $this->assertSame(AtlasExternalBrainResearchDigestGrounder::SCHEMA, $result['schema']);
    }

    // ── AC1: promoted candidate has all required fields ───────────────────────

    public function test_fully_grounded_idea_is_promoted(): void
    {
        $result = $this->grounder->ground($this->input($this->good()));

        $this->assertSame(1, $result['promoted_count']);
        $this->assertSame(0, $result['rejected_count']);
    }

    public function test_promoted_candidate_has_all_required_fields(): void
    {
        $result = $this->grounder->ground($this->input($this->good()));
        $c = $result['task_candidates'][0];

        foreach (['idea_id', 'local_symbols', 'allowed_files_candidate', 'acceptance_seed', 'atlas_capability_gap', 'risk_constraints', 'leverage_hint'] as $k) {
            $this->assertArrayHasKey($k, $c, "Missing field: {$k}");
        }
        $this->assertSame(['AtlasBrainDepthScorer', 'AtlasBrainComprehensionLayer'], $c['local_symbols']);
        $this->assertNotEmpty($c['allowed_files_candidate']);
    }

    // ── AC2: reject hype_only_no_local_symbol ────────────────────────────────

    public function test_rejects_idea_with_no_local_symbols(): void
    {
        $result = $this->grounder->ground($this->input($this->good(['local_symbols' => []])));

        $this->assertSame(0, $result['promoted_count']);
        $this->assertSame(
            AtlasExternalBrainResearchDigestGrounder::REJECTION_HYPE_ONLY_NO_LOCAL_SYMBOL,
            $result['rejected'][0]['rejection_reason'],
        );
    }

    // ── AC2: reject missing_allowed_files_candidate ───────────────────────────

    public function test_rejects_idea_with_no_allowed_files_candidate(): void
    {
        $result = $this->grounder->ground($this->input($this->good([
            'allowed_files_candidate' => [],
        ])));

        $this->assertSame(
            AtlasExternalBrainResearchDigestGrounder::REJECTION_MISSING_ALLOWED_FILES_CANDIDATE,
            $result['rejected'][0]['rejection_reason'],
        );
    }

    // ── AC2: reject no_runnable_evidence_path ────────────────────────────────

    public function test_rejects_idea_with_prose_only_evidence(): void
    {
        $result = $this->grounder->ground($this->input($this->good([
            'runnable_evidence_path' => 'prose description only',
        ])));

        $this->assertSame(
            AtlasExternalBrainResearchDigestGrounder::REJECTION_NO_RUNNABLE_EVIDENCE_PATH,
            $result['rejected'][0]['rejection_reason'],
        );
    }

    public function test_vendor_bin_phpunit_counts_as_runnable(): void
    {
        $result = $this->grounder->ground($this->input($this->good([
            'runnable_evidence_path' => './vendor/bin/phpunit tests/Unit/FooTest.php',
        ])));

        $this->assertSame(1, $result['promoted_count']);
    }

    public function test_opt_homebrew_bin_php_counts_as_runnable(): void
    {
        $result = $this->grounder->ground($this->input($this->good([
            'runnable_evidence_path' => '/opt/homebrew/bin/php ./vendor/bin/phpunit SomeTest.php',
        ])));

        $this->assertSame(1, $result['promoted_count']);
    }

    // ── AC2: reject no_local_owner ────────────────────────────────────────────

    public function test_rejects_when_no_owner_files_and_has_local_owner_false(): void
    {
        $result = $this->grounder->ground($this->input($this->good([
            'has_local_owner' => false,
            'owner_files'     => [],
        ])));

        $this->assertSame(
            AtlasExternalBrainResearchDigestGrounder::REJECTION_NO_LOCAL_OWNER,
            $result['rejected'][0]['rejection_reason'],
        );
    }

    public function test_owner_files_non_empty_accepts_without_has_local_owner_flag(): void
    {
        $result = $this->grounder->ground($this->input($this->good([
            'has_local_owner' => false,
            'owner_files'     => ['app/Services/Ai/'],
        ])));

        $this->assertSame(1, $result['promoted_count']);
    }

    // ── AC2: reject forbidden_scope ───────────────────────────────────────────

    public function test_rejects_idea_in_forbidden_scope(): void
    {
        $result = $this->grounder->ground($this->input($this->good(['forbidden_scope' => true])));

        $this->assertSame(
            AtlasExternalBrainResearchDigestGrounder::REJECTION_FORBIDDEN_SCOPE,
            $result['rejected'][0]['rejection_reason'],
        );
    }

    // ── AC2: reject provider_steady_state_dependency ─────────────────────────

    public function test_rejects_idea_with_provider_steady_state_dependency(): void
    {
        $result = $this->grounder->ground($this->input($this->good(['provider_steady_state_dep' => true])));

        $this->assertSame(
            AtlasExternalBrainResearchDigestGrounder::REJECTION_PROVIDER_STEADY_STATE_DEPENDENCY,
            $result['rejected'][0]['rejection_reason'],
        );
    }

    // ── Rejection hierarchy ───────────────────────────────────────────────────

    public function test_hype_only_beats_missing_allowed_files_and_provider_dep(): void
    {
        $result = $this->grounder->ground($this->input($this->good([
            'local_symbols'             => [],
            'allowed_files_candidate'   => [],
            'provider_steady_state_dep' => true,
        ])));

        $this->assertSame(
            AtlasExternalBrainResearchDigestGrounder::REJECTION_HYPE_ONLY_NO_LOCAL_SYMBOL,
            $result['rejected'][0]['rejection_reason'],
        );
    }

    public function test_missing_allowed_files_beats_no_runnable_evidence(): void
    {
        $result = $this->grounder->ground($this->input($this->good([
            'allowed_files_candidate' => [],
            'runnable_evidence_path'  => 'prose only',
        ])));

        $this->assertSame(
            AtlasExternalBrainResearchDigestGrounder::REJECTION_MISSING_ALLOWED_FILES_CANDIDATE,
            $result['rejected'][0]['rejection_reason'],
        );
    }

    // ── Rejected item includes original idea ──────────────────────────────────

    public function test_rejected_entry_includes_original_idea(): void
    {
        $idea   = $this->good(['local_symbols' => []]);
        $result = $this->grounder->ground($this->input($idea));

        $this->assertArrayHasKey('idea', $result['rejected'][0]);
        $this->assertSame($idea, $result['rejected'][0]['idea']);
    }

    // ── Mixed batch ───────────────────────────────────────────────────────────

    public function test_mixed_batch_separates_promoted_and_rejected(): void
    {
        $result = $this->grounder->ground($this->input(
            $this->good(['idea_id' => 'ok-1']),
            $this->good(['idea_id' => 'no-sym', 'local_symbols' => []]),
            $this->good(['idea_id' => 'ok-2']),
            $this->good(['idea_id' => 'forbidden', 'forbidden_scope' => true]),
        ));

        $this->assertSame(2, $result['promoted_count']);
        $this->assertSame(2, $result['rejected_count']);
    }

    // ── Empty batch ───────────────────────────────────────────────────────────

    public function test_empty_batch_returns_zero_counts(): void
    {
        $result = $this->grounder->ground(['research_ideas' => []]);

        $this->assertSame(0, $result['promoted_count']);
        $this->assertSame(0, $result['rejected_count']);
    }
}
