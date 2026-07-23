<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainResearchToTaskDigestor;
use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainResearchToTaskIntakeLoop;
use Tests\TestCase;

final class AtlasExternalBrainResearchToTaskIntakeLoopTest extends TestCase
{
    private function loop(): AtlasExternalBrainResearchToTaskIntakeLoop
    {
        return new AtlasExternalBrainResearchToTaskIntakeLoop;
    }

    private function groundedItem(array $overrides = []): array
    {
        return array_merge([
            'source' => 'arxiv:2603.15031',
            'source_type' => 'paper',
            'pattern_summary' => 'attention over depth via residual gating',
            'atlas_failure_mode' => 'AtlasBrainLayerRouter::route() lacks depth-aware residual weighting, degrading long-context recall.',
            'target_path' => 'app/Services/Ai/Brain/AtlasBrainLayerRouter.php',
            'adaptation_notes' => 'Add depth-weighted residual term to route() scoring.',
            'allowed_files' => ['app/Services/Ai/Brain/AtlasBrainLayerRouter.php'],
            'test_path' => 'tests/Unit/Ai/Brain/AtlasBrainLayerRouterTest.php',
            'anti_goodhart_risks' => ['could overfit to synthetic long-context benchmark'],
            'runnable_acceptance' => '/opt/homebrew/bin/php artisan test tests/Unit/Ai/Brain/AtlasBrainLayerRouterTest.php',
        ], $overrides);
    }

    public function test_grounded_item_converts_into_packet_ready_intent_with_preserved_fields(): void
    {
        $result = $this->loop()->intake(['research_items' => [$this->groundedItem()]]);

        $this->assertSame(1, $result['intake_count']);
        $this->assertSame(0, $result['rejected_count']);

        $intent = $result['intents'][0];
        $this->assertSame('app/Services/Ai/Brain/AtlasBrainLayerRouter.php', $intent['target_path']);
        $this->assertSame(['app/Services/Ai/Brain/AtlasBrainLayerRouter.php'], $intent['allowed_files']);
        $this->assertSame(['app/Services/Ai/Brain/AtlasBrainLayerRouter.php'], $intent['scope_in']);
        $this->assertContains(
            '/opt/homebrew/bin/php artisan test tests/Unit/Ai/Brain/AtlasBrainLayerRouterTest.php',
            $intent['acceptance_criteria'],
        );
        $this->assertSame(['could overfit to synthetic long-context benchmark'], $intent['anti_goodhart_risks']);
        $this->assertNotSame('', $intent['task_packet_id']);
    }

    public function test_hype_only_item_is_rejected_and_never_produces_an_intent(): void
    {
        $result = $this->loop()->intake(['research_items' => [
            $this->groundedItem(['atlas_failure_mode' => 'This is a revolutionary breakthrough paradigm-shift.']),
        ]]);

        $this->assertSame(0, $result['intake_count']);
        $this->assertSame(1, $result['rejected_count']);
        $this->assertSame(
            AtlasExternalBrainResearchToTaskDigestor::REJECTION_HYPE_ONLY,
            $result['rejected'][0]['rejection_reason'],
        );
    }

    public function test_provider_steady_state_dependent_item_is_rejected_and_never_produces_an_intent(): void
    {
        $result = $this->loop()->intake(['research_items' => [
            $this->groundedItem(['requires_provider_steady_state' => true]),
        ]]);

        $this->assertSame(0, $result['intake_count']);
        $this->assertSame(1, $result['rejected_count']);
        $this->assertSame(
            AtlasExternalBrainResearchToTaskDigestor::REJECTION_PROVIDER_STEADY_STATE_DEPENDENCY,
            $result['rejected'][0]['rejection_reason'],
        );
    }

    public function test_mixed_batch_only_promotes_grounded_items(): void
    {
        $result = $this->loop()->intake(['research_items' => [
            $this->groundedItem(),
            $this->groundedItem(['atlas_failure_mode' => 'unprecedented game-changer']),
        ]]);

        $this->assertSame(1, $result['intake_count']);
        $this->assertSame(1, $result['rejected_count']);
    }

    public function test_empty_research_items_produces_empty_intake(): void
    {
        $result = $this->loop()->intake([]);

        $this->assertSame(0, $result['intake_count']);
        $this->assertSame(0, $result['rejected_count']);
        $this->assertSame([], $result['intents']);
    }

    // ── AC2: allowed_files_closure in every intent ──────────────────────────

    public function test_every_intent_has_allowed_files_closure(): void
    {
        $result = $this->loop()->intake(['research_items' => [$this->groundedItem()]]);

        $intent = $result['intents'][0];
        $this->assertArrayHasKey('allowed_files_closure', $intent);
        $closure = $intent['allowed_files_closure'];
        $this->assertArrayHasKey('implementation_present', $closure);
        $this->assertArrayHasKey('test_present', $closure);
        $this->assertArrayHasKey('target_covered', $closure);
        $this->assertArrayHasKey('runnable_acceptance_present', $closure);
    }

    public function test_grounded_intent_has_complete_closure(): void
    {
        $result = $this->loop()->intake(['research_items' => [$this->groundedItem()]]);

        $closure = $result['intents'][0]['allowed_files_closure'];
        $this->assertTrue($closure['implementation_present']);
        $this->assertTrue($closure['test_present']);   // test_path is set
        $this->assertTrue($closure['target_covered']);
        $this->assertTrue($closure['runnable_acceptance_present']);
        $this->assertNull($result['intents'][0]['closure_gap_reason']);
    }

    public function test_target_not_covered_when_target_path_mismatches_allowed_files(): void
    {
        $result = $this->loop()->intake(['research_items' => [
            $this->groundedItem(['target_path' => 'app/Some/Other/File.php']),
        ]]);

        $closure = $result['intents'][0]['allowed_files_closure'];
        $this->assertFalse($closure['target_covered']);
        $this->assertStringContainsString('target_not_covered', $result['intents'][0]['closure_gap_reason']);
    }

    // ── AC3: closure gap names what is missing without provider calls ────────

    public function test_closure_gap_is_deterministic_string_not_provider_error(): void
    {
        $result = $this->loop()->intake(['research_items' => [
            $this->groundedItem(['target_path' => 'app/Some/Other/File.php']),
        ]]);

        $reason = $result['intents'][0]['closure_gap_reason'];
        $this->assertIsString($reason);
        $this->assertStringNotContainsString('Exception', $reason);
        $this->assertStringNotContainsString('provider', $reason);
    }

    // ── Determinism ─────────────────────────────────────────────────────────

    public function test_output_is_deterministic(): void
    {
        $input = ['research_items' => [$this->groundedItem()]];

        $this->assertSame(
            json_encode($this->loop()->intake($input)),
            json_encode($this->loop()->intake($input)),
        );
    }
}
