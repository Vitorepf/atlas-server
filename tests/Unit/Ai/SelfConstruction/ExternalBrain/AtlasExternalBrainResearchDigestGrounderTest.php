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
            'idea_id'                 => 'idea-001',
            'local_symbols'           => ['AtlasBrainDepthScorer', 'AtlasBrainComprehensionLayer'],
            'allowed_files_candidate' => ['app/Services/Ai/SelfConstruction/ExternalBrain/AtlasExternalBrainResearchDigestGrounder.php'],
            'runnable_evidence_path'  => '/opt/homebrew/bin/php artisan test --filter=AtlasBrainDepthScorerTest',
            'atlas_capability_gap'    => 'comprehension-deepening',
            'risk_constraints'        => ['no_external_provider_steady_state'],
            'acceptance_seed'         => 'test passes with all gates green',
            'leverage_hint'           => 'depth-scoring-improvement',
            'has_local_owner'         => true,
            'forbidden_scope'         => false,
            'provider_steady_state_dep' => false,
            'owner_files'             => ['app/Services/Ai/SelfConstruction/Brain/AtlasBrainDepthScorer.php'],
            'implementation_strategy' => 'Add a depth layer to AtlasBrainDepthScorer that recursively measures comprehension per node.',
            'risk_level'              => 'low',
            'task_family'             => 'feature',
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

    // ── Promoted: existing fields ─────────────────────────────────────────────

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

    // ── Promoted: new Task-Fabric fields ─────────────────────────────────────

    public function test_promoted_candidate_has_new_task_fabric_fields(): void
    {
        $result = $this->grounder->ground($this->input($this->good()));
        $c = $result['task_candidates'][0];

        foreach (['grounded_symbols', 'owner_files', 'implementation_strategy', 'risk_level', 'task_family'] as $k) {
            $this->assertArrayHasKey($k, $c, "Missing Task-Fabric field: {$k}");
        }
    }

    public function test_grounded_symbols_mirrors_local_symbols(): void
    {
        $result = $this->grounder->ground($this->input($this->good()));
        $c = $result['task_candidates'][0];

        $this->assertSame($c['local_symbols'], $c['grounded_symbols']);
    }

    public function test_owner_files_populated_from_input(): void
    {
        $result = $this->grounder->ground($this->input($this->good([
            'owner_files' => ['app/Services/Ai/Foo.php', 'app/Services/Ai/Bar.php'],
        ])));
        $c = $result['task_candidates'][0];

        $this->assertContains('app/Services/Ai/Foo.php', $c['owner_files']);
        $this->assertContains('app/Services/Ai/Bar.php', $c['owner_files']);
    }

    public function test_implementation_strategy_populated_from_input(): void
    {
        $strategy = 'Add depth-recursive pass to AtlasBrainDepthScorer.';
        $result   = $this->grounder->ground($this->input($this->good([
            'implementation_strategy' => $strategy,
        ])));

        $this->assertSame($strategy, $result['task_candidates'][0]['implementation_strategy']);
    }

    public function test_risk_level_defaults_to_medium_when_not_provided(): void
    {
        $result = $this->grounder->ground($this->input($this->good(['risk_level' => null])));

        $this->assertSame('medium', $result['task_candidates'][0]['risk_level']);
    }

    public function test_risk_level_preserved_when_provided(): void
    {
        $result = $this->grounder->ground($this->input($this->good(['risk_level' => 'low'])));

        $this->assertSame('low', $result['task_candidates'][0]['risk_level']);
    }

    public function test_task_family_defaults_to_feature_when_not_provided(): void
    {
        $result = $this->grounder->ground($this->input($this->good(['task_family' => null])));

        $this->assertSame('feature', $result['task_candidates'][0]['task_family']);
    }

    public function test_task_family_preserved_when_provided(): void
    {
        $result = $this->grounder->ground($this->input($this->good(['task_family' => 'infrastructure'])));

        $this->assertSame('infrastructure', $result['task_candidates'][0]['task_family']);
    }

    // ── AC1 reject: hype_only ─────────────────────────────────────────────────

    public function test_rejects_idea_marked_as_hype(): void
    {
        $result = $this->grounder->ground($this->input($this->good(['is_hype' => true])));

        $this->assertSame(0, $result['promoted_count']);
        $this->assertSame(
            AtlasExternalBrainResearchDigestGrounder::REJECTION_HYPE_ONLY,
            $result['rejected'][0]['rejection_reason'],
        );
    }

    public function test_hype_beats_missing_local_symbol_in_hierarchy(): void
    {
        $result = $this->grounder->ground($this->input($this->good([
            'is_hype'       => true,
            'local_symbols' => [],   // would also be rejected, but hype fires first
        ])));

        $this->assertSame(
            AtlasExternalBrainResearchDigestGrounder::REJECTION_HYPE_ONLY,
            $result['rejected'][0]['rejection_reason'],
        );
    }

    // ── AC1 reject: missing_local_symbol ─────────────────────────────────────

    public function test_rejects_idea_with_no_local_symbols(): void
    {
        $result = $this->grounder->ground($this->input($this->good(['local_symbols' => []])));

        $this->assertSame(0, $result['promoted_count']);
        $this->assertSame(
            AtlasExternalBrainResearchDigestGrounder::REJECTION_MISSING_LOCAL_SYMBOL,
            $result['rejected'][0]['rejection_reason'],
        );
    }

    // ── AC1 reject: missing_allowed_files_candidate (unchanged) ──────────────

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

    // ── AC1 reject: no_runnable_gate ─────────────────────────────────────────

    public function test_rejects_idea_with_prose_only_evidence(): void
    {
        $result = $this->grounder->ground($this->input($this->good([
            'runnable_evidence_path' => 'prose description only',
        ])));

        $this->assertSame(
            AtlasExternalBrainResearchDigestGrounder::REJECTION_NO_RUNNABLE_GATE,
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

    // ── AC1 reject: no_owner_file ─────────────────────────────────────────────

    public function test_rejects_when_no_owner_files_and_has_local_owner_false(): void
    {
        $result = $this->grounder->ground($this->input($this->good([
            'has_local_owner' => false,
            'owner_files'     => [],
        ])));

        $this->assertSame(
            AtlasExternalBrainResearchDigestGrounder::REJECTION_NO_OWNER_FILE,
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

    // ── AC1 reject: forbidden_scope (unchanged) ───────────────────────────────

    public function test_rejects_idea_in_forbidden_scope(): void
    {
        $result = $this->grounder->ground($this->input($this->good(['forbidden_scope' => true])));

        $this->assertSame(
            AtlasExternalBrainResearchDigestGrounder::REJECTION_FORBIDDEN_SCOPE,
            $result['rejected'][0]['rejection_reason'],
        );
    }

    // ── AC1 reject: provider_dependency ──────────────────────────────────────

    public function test_rejects_idea_with_provider_steady_state_dependency(): void
    {
        $result = $this->grounder->ground($this->input($this->good(['provider_steady_state_dep' => true])));

        $this->assertSame(
            AtlasExternalBrainResearchDigestGrounder::REJECTION_PROVIDER_DEPENDENCY,
            $result['rejected'][0]['rejection_reason'],
        );
    }

    // ── AC1 reject: no_capability_delta (new) ────────────────────────────────

    public function test_rejects_when_atlas_capability_gap_is_empty(): void
    {
        $result = $this->grounder->ground($this->input($this->good(['atlas_capability_gap' => ''])));

        $this->assertSame(0, $result['promoted_count']);
        $this->assertSame(
            AtlasExternalBrainResearchDigestGrounder::REJECTION_NO_CAPABILITY_DELTA,
            $result['rejected'][0]['rejection_reason'],
        );
    }

    public function test_rejects_when_atlas_capability_gap_is_whitespace_only(): void
    {
        $result = $this->grounder->ground($this->input($this->good(['atlas_capability_gap' => '   '])));

        $this->assertSame(
            AtlasExternalBrainResearchDigestGrounder::REJECTION_NO_CAPABILITY_DELTA,
            $result['rejected'][0]['rejection_reason'],
        );
    }

    // ── Rejection hierarchy ───────────────────────────────────────────────────

    public function test_missing_local_symbol_beats_missing_allowed_files_and_provider_dep(): void
    {
        $result = $this->grounder->ground($this->input($this->good([
            'local_symbols'             => [],
            'allowed_files_candidate'   => [],
            'provider_steady_state_dep' => true,
        ])));

        $this->assertSame(
            AtlasExternalBrainResearchDigestGrounder::REJECTION_MISSING_LOCAL_SYMBOL,
            $result['rejected'][0]['rejection_reason'],
        );
    }

    public function test_missing_allowed_files_beats_no_runnable_gate(): void
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

    public function test_provider_dependency_beats_no_capability_delta(): void
    {
        $result = $this->grounder->ground($this->input($this->good([
            'provider_steady_state_dep' => true,
            'atlas_capability_gap'      => '',
        ])));

        $this->assertSame(
            AtlasExternalBrainResearchDigestGrounder::REJECTION_PROVIDER_DEPENDENCY,
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

    // ── AC3: runnable proof examples ──────────────────────────────────────────

    public function test_promoted_research_example(): void
    {
        $result = $this->grounder->ground($this->input([
            'idea_id'                 => 'research-depth-scoring-improvement',
            'local_symbols'           => ['AtlasBrainDepthScorer', 'AtlasComprehensionExtractor'],
            'allowed_files_candidate' => [
                'app/Services/Ai/SelfConstruction/Brain/AtlasBrainDepthScorer.php',
                'tests/Unit/Ai/SelfConstruction/Brain/AtlasBrainDepthScorerTest.php',
            ],
            'runnable_evidence_path'  => '/opt/homebrew/bin/php artisan test --filter=AtlasBrainDepthScorerTest',
            'atlas_capability_gap'    => 'depth-weighted-comprehension-scoring',
            'acceptance_seed'         => 'AtlasBrainDepthScorer::score() returns float weighted by recursion depth',
            'leverage_hint'           => 'replace flat scan with recursive depth accumulator',
            'risk_constraints'        => ['no_external_provider_steady_state'],
            'has_local_owner'         => true,
            'owner_files'             => ['app/Services/Ai/SelfConstruction/Brain/AtlasBrainDepthScorer.php'],
            'implementation_strategy' => 'Add recursive depth parameter to AtlasBrainDepthScorer::score(); weight contribution by depth.',
            'risk_level'              => 'low',
            'task_family'             => 'feature',
            'forbidden_scope'         => false,
            'provider_steady_state_dep' => false,
        ]));

        $this->assertSame(1, $result['promoted_count'], 'Valid grounded research must be promoted');
        $c = $result['task_candidates'][0];
        $this->assertSame('research-depth-scoring-improvement', $c['idea_id']);
        $this->assertSame('low',     $c['risk_level']);
        $this->assertSame('feature', $c['task_family']);
        $this->assertNotEmpty($c['grounded_symbols']);
        $this->assertNotEmpty($c['owner_files']);
        $this->assertNotEmpty($c['implementation_strategy']);
    }

    public function test_hype_example_rejected(): void
    {
        $result = $this->grounder->ground($this->input([
            'idea_id'                 => 'hype-agi-everywhere',
            'is_hype'                 => true,   // explicit hype flag
            'local_symbols'           => ['AtlasBrainDepthScorer'],
            'allowed_files_candidate' => ['app/Services/Ai/SelfConstruction/Brain/AtlasBrainDepthScorer.php'],
            'runnable_evidence_path'  => '/opt/homebrew/bin/php artisan test',
            'atlas_capability_gap'    => 'make everything smarter with AGI',
            'has_local_owner'         => true,
        ]));

        $this->assertSame(0, $result['promoted_count']);
        $this->assertSame(
            AtlasExternalBrainResearchDigestGrounder::REJECTION_HYPE_ONLY,
            $result['rejected'][0]['rejection_reason'],
        );
    }

    public function test_forbidden_example_rejected(): void
    {
        $result = $this->grounder->ground($this->input([
            'idea_id'                 => 'forbidden-external-integration',
            'local_symbols'           => ['AtlasExternalBrainGateway'],
            'allowed_files_candidate' => ['app/Services/Ai/SelfConstruction/ExternalBrain/AtlasExternalBrainGateway.php'],
            'runnable_evidence_path'  => '/opt/homebrew/bin/php artisan test --filter=GatewayTest',
            'atlas_capability_gap'    => 'external-provider-sync',
            'has_local_owner'         => true,
            'forbidden_scope'         => true,   // out-of-bounds scope
            'provider_steady_state_dep' => false,
        ]));

        $this->assertSame(0, $result['promoted_count']);
        $this->assertSame(
            AtlasExternalBrainResearchDigestGrounder::REJECTION_FORBIDDEN_SCOPE,
            $result['rejected'][0]['rejection_reason'],
        );
    }

    public function test_provider_dependent_example_rejected(): void
    {
        $result = $this->grounder->ground($this->input([
            'idea_id'                 => 'requires-openai-api',
            'local_symbols'           => ['AtlasBrainDepthScorer'],
            'allowed_files_candidate' => ['app/Services/Ai/SelfConstruction/Brain/AtlasBrainDepthScorer.php'],
            'runnable_evidence_path'  => '/opt/homebrew/bin/php artisan test',
            'atlas_capability_gap'    => 'gpt-4-powered-scoring',
            'has_local_owner'         => true,
            'forbidden_scope'         => false,
            'provider_steady_state_dep' => true,  // requires live provider
        ]));

        $this->assertSame(0, $result['promoted_count']);
        $this->assertSame(
            AtlasExternalBrainResearchDigestGrounder::REJECTION_PROVIDER_DEPENDENCY,
            $result['rejected'][0]['rejection_reason'],
        );
    }

    public function test_no_runnable_gate_example_rejected(): void
    {
        $result = $this->grounder->ground($this->input([
            'idea_id'                 => 'no-test-proof',
            'local_symbols'           => ['AtlasBrainDepthScorer'],
            'allowed_files_candidate' => ['app/Services/Ai/SelfConstruction/Brain/AtlasBrainDepthScorer.php'],
            'runnable_evidence_path'  => 'Look at the code and confirm it looks right',  // no runnable command
            'atlas_capability_gap'    => 'depth-scoring-improvement',
            'has_local_owner'         => true,
            'forbidden_scope'         => false,
            'provider_steady_state_dep' => false,
        ]));

        $this->assertSame(0, $result['promoted_count']);
        $this->assertSame(
            AtlasExternalBrainResearchDigestGrounder::REJECTION_NO_RUNNABLE_GATE,
            $result['rejected'][0]['rejection_reason'],
        );
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
