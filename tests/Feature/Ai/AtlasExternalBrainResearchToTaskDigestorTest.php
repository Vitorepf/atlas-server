<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainResearchToTaskDigestor;
use Tests\TestCase;

final class AtlasExternalBrainResearchToTaskDigestorTest extends TestCase
{
    private AtlasExternalBrainResearchToTaskDigestor $digestor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->digestor = new AtlasExternalBrainResearchToTaskDigestor;
    }

    private function goodItem(array $overrides = []): array
    {
        return array_merge([
            'source'              => 'arXiv:2403.08295',
            'pattern_summary'     => 'Depth-signal attention residuals improve comprehension accuracy',
            'atlas_failure_mode'  => 'Brain comprehension degrades when depth signals are ignored',
            'target_path'         => 'AtlasExternalBrainComprehensionEngine',
            'adaptation_notes'    => 'Apply residual scaling to Atlas comprehension weight computation',
            'allowed_files'       => ['app/Services/Ai/ExternalBrain/AtlasExternalBrainComprehensionEngine.php'],
            'test_path'           => 'tests/Unit/Ai/ExternalBrain/AtlasExternalBrainComprehensionEngineTest.php',
            'anti_goodhart_risks' => ['Optimising for attention score proxy rather than real comprehension'],
            'runnable_acceptance' => 'php artisan test tests/Unit/Ai/ExternalBrain/AtlasExternalBrainComprehensionEngineTest.php',
        ], $overrides);
    }

    // ── AC1: explicit rejection reasons for every missing requirement

    public function test_ac1_item_without_atlas_failure_mode_is_rejected_as_hype_only(): void
    {
        $result = $this->digestor->digest(['research_items' => [
            $this->goodItem(['atlas_failure_mode' => '']),
        ]]);

        $this->assertSame(0, $result['promoted_count']);
        $this->assertSame(1, $result['rejected_count']);
        $this->assertSame(
            AtlasExternalBrainResearchToTaskDigestor::REJECTION_HYPE_ONLY,
            $result['rejected'][0]['rejection_reason'],
        );
    }

    public function test_ac1_item_without_target_path_is_rejected_with_no_target_path(): void
    {
        $result = $this->digestor->digest(['research_items' => [
            $this->goodItem(['target_path' => '']),
        ]]);

        $this->assertSame(1, $result['rejected_count']);
        $this->assertSame(
            AtlasExternalBrainResearchToTaskDigestor::REJECTION_NO_TARGET_PATH,
            $result['rejected'][0]['rejection_reason'],
        );
    }

    public function test_ac1_item_without_runnable_command_in_acceptance_is_rejected(): void
    {
        $result = $this->digestor->digest(['research_items' => [
            $this->goodItem(['runnable_acceptance' => 'just a prose description, no command']),
        ]]);

        $this->assertSame(1, $result['rejected_count']);
        $this->assertSame(
            AtlasExternalBrainResearchToTaskDigestor::REJECTION_NO_RUNNABLE_ACCEPTANCE,
            $result['rejected'][0]['rejection_reason'],
        );
    }

    public function test_ac1_item_missing_adaptation_notes_is_rejected_as_incomplete(): void
    {
        $result = $this->digestor->digest(['research_items' => [
            $this->goodItem(['adaptation_notes' => '']),
        ]]);

        $this->assertSame(1, $result['rejected_count']);
        $this->assertSame(
            AtlasExternalBrainResearchToTaskDigestor::REJECTION_INCOMPLETE_CANDIDATE,
            $result['rejected'][0]['rejection_reason'],
        );
    }

    public function test_ac1_rejected_entry_preserves_original_item(): void
    {
        $item   = $this->goodItem(['atlas_failure_mode' => '']);
        $result = $this->digestor->digest(['research_items' => [$item]]);

        $this->assertArrayHasKey('item', $result['rejected'][0]);
        $this->assertSame($item, $result['rejected'][0]['item']);
    }

    // ── AC2: promoted candidates include all six required output fields

    public function test_ac2_implementation_file_equals_first_allowed_file(): void
    {
        $result    = $this->digestor->digest(['research_items' => [$this->goodItem()]]);
        $candidate = $result['promoted'][0];

        $this->assertArrayHasKey('implementation_file', $candidate);
        $this->assertSame(
            'app/Services/Ai/ExternalBrain/AtlasExternalBrainComprehensionEngine.php',
            $candidate['implementation_file'],
        );
    }

    public function test_ac2_test_file_matches_test_path(): void
    {
        $result    = $this->digestor->digest(['research_items' => [$this->goodItem()]]);
        $candidate = $result['promoted'][0];

        $this->assertArrayHasKey('test_file', $candidate);
        $this->assertSame($this->goodItem()['test_path'], $candidate['test_file']);
    }

    public function test_ac2_leverage_claim_is_non_empty_by_default(): void
    {
        $result    = $this->digestor->digest(['research_items' => [$this->goodItem()]]);
        $candidate = $result['promoted'][0];

        $this->assertArrayHasKey('leverage_claim', $candidate);
        $this->assertNotEmpty($candidate['leverage_claim']);
    }

    public function test_ac2_explicit_leverage_claim_is_preserved(): void
    {
        $result    = $this->digestor->digest(['research_items' => [
            $this->goodItem(['leverage_claim' => 'Reduces comprehension failures by 30%']),
        ]]);
        $candidate = $result['promoted'][0];

        $this->assertSame('Reduces comprehension failures by 30%', $candidate['leverage_claim']);
    }

    public function test_ac2_risk_defaults_to_first_anti_goodhart_risk(): void
    {
        $result    = $this->digestor->digest(['research_items' => [$this->goodItem()]]);
        $candidate = $result['promoted'][0];

        $this->assertArrayHasKey('risk', $candidate);
        $this->assertSame('Optimising for attention score proxy rather than real comprehension', $candidate['risk']);
    }

    public function test_ac2_acceptance_summary_defaults_to_runnable_acceptance(): void
    {
        $result    = $this->digestor->digest(['research_items' => [$this->goodItem()]]);
        $candidate = $result['promoted'][0];

        $this->assertArrayHasKey('acceptance_summary', $candidate);
        $this->assertSame($this->goodItem()['runnable_acceptance'], $candidate['acceptance_summary']);
    }

    public function test_ac2_source_evidence_defaults_to_source(): void
    {
        $result    = $this->digestor->digest(['research_items' => [$this->goodItem()]]);
        $candidate = $result['promoted'][0];

        $this->assertArrayHasKey('source_evidence', $candidate);
        $this->assertSame('arXiv:2403.08295', $candidate['source_evidence']);
    }

    public function test_ac2_explicit_source_evidence_is_preserved(): void
    {
        $result    = $this->digestor->digest(['research_items' => [
            $this->goodItem(['source_evidence' => 'Table 3 in arXiv:2403.08295 shows 12% delta']),
        ]]);
        $candidate = $result['promoted'][0];

        $this->assertSame('Table 3 in arXiv:2403.08295 shows 12% delta', $candidate['source_evidence']);
    }

    // ── AC3: hype keywords alone are rejected; code-grounding rescues

    public function test_ac3_hype_only_failure_mode_is_rejected(): void
    {
        $result = $this->digestor->digest(['research_items' => [
            $this->goodItem(['atlas_failure_mode' => 'This breakthrough approach will revolutionize Atlas performance forever']),
        ]]);

        $this->assertSame(0, $result['promoted_count']);
        $this->assertSame(
            AtlasExternalBrainResearchToTaskDigestor::REJECTION_HYPE_ONLY,
            $result['rejected'][0]['rejection_reason'],
        );
    }

    public function test_ac3_state_of_the_art_without_grounding_is_rejected(): void
    {
        $result = $this->digestor->digest(['research_items' => [
            $this->goodItem(['atlas_failure_mode' => 'state-of-the-art model is transformative and unprecedented']),
        ]]);

        $this->assertSame(0, $result['promoted_count']);
        $this->assertSame(
            AtlasExternalBrainResearchToTaskDigestor::REJECTION_HYPE_ONLY,
            $result['rejected'][0]['rejection_reason'],
        );
    }

    public function test_ac3_hype_keyword_with_php_file_reference_still_promotes(): void
    {
        // "breakthrough" is hype, but ".php" grounds it in code
        $result = $this->digestor->digest(['research_items' => [
            $this->goodItem([
                'atlas_failure_mode' => 'breakthrough: AtlasExternalBrainComprehensionEngine.php loses accuracy when depth signals absent',
            ]),
        ]]);

        $this->assertSame(1, $result['promoted_count']);
    }

    public function test_ac3_hype_keyword_with_artisan_command_reference_still_promotes(): void
    {
        // "revolutionary" is hype, but "artisan" is a concrete code reference
        $result = $this->digestor->digest(['research_items' => [
            $this->goodItem([
                'atlas_failure_mode' => 'revolutionary: artisan test suite exits non-zero when comprehension drops',
            ]),
        ]]);

        $this->assertSame(1, $result['promoted_count']);
    }

    // ── AC4: pure and deterministic

    public function test_ac4_same_input_produces_identical_output(): void
    {
        $input = ['research_items' => [$this->goodItem()]];

        $this->assertSame(
            $this->digestor->digest($input),
            $this->digestor->digest($input),
        );
    }

    public function test_ac4_empty_research_items_returns_zero_counts(): void
    {
        $result = $this->digestor->digest(['research_items' => []]);

        $this->assertSame(0, $result['promoted_count']);
        $this->assertSame(0, $result['rejected_count']);
        $this->assertSame([], $result['promoted']);
        $this->assertSame([], $result['rejected']);
    }
}
