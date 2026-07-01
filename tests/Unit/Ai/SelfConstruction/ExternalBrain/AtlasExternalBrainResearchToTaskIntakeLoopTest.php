<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainResearchToTaskDigestor;
use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainResearchToTaskIntakeLoop;
use PHPUnit\Framework\TestCase;

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

    // ── Schema / envelope ────────────────────────────────────────────────────

    public function test_output_has_required_keys(): void
    {
        $result = $this->loop()->intake(['research_items' => [$this->groundedItem()]]);

        foreach (['schema', 'intents', 'rejected', 'intake_count', 'rejected_count'] as $key) {
            $this->assertArrayHasKey($key, $result, "Missing key: {$key}");
        }
        $this->assertSame(AtlasExternalBrainResearchToTaskIntakeLoop::SCHEMA, $result['schema']);
    }

    public function test_empty_research_items_produces_empty_intake(): void
    {
        $result = $this->loop()->intake([]);

        $this->assertSame(0, $result['intake_count']);
        $this->assertSame(0, $result['rejected_count']);
        $this->assertSame([], $result['intents']);
    }

    // ── AC2: ungrounded hype research is rejected without a matching Atlas gap ──

    public function test_hype_only_item_lacking_atlas_failure_mode_is_rejected(): void
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

    public function test_hype_language_grounded_in_a_concrete_code_gap_is_not_rejected_as_hype(): void
    {
        $result = $this->loop()->intake(['research_items' => [
            $this->groundedItem(['atlas_failure_mode' => 'A breakthrough fix for AtlasBrainLayerRouter::route() which currently returns exit 1 on deep contexts.']),
        ]]);

        $this->assertSame(1, $result['intake_count']);
        $this->assertSame(0, $result['rejected_count']);
    }

    // ── AC3: grounded research becomes a worker-ready candidate ───────────────

    public function test_grounded_item_produces_intent_with_implementation_target_proof_target_and_risk_notes(): void
    {
        $result = $this->loop()->intake(['research_items' => [$this->groundedItem()]]);

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

    // ── AC4: provider-sensitive / copy-paste content is summarized, not verbatim ──

    public function test_secret_like_content_in_leverage_claim_is_redacted_not_emitted_verbatim(): void
    {
        $result = $this->loop()->intake(['research_items' => [
            $this->groundedItem(['leverage_claim' => 'Use this API_KEY=sk-live-abc123 to reproduce the benchmark.']),
        ]]);

        $intent = $result['intents'][0];
        $this->assertStringNotContainsString('sk-live-abc123', $intent['objective']);
        $this->assertStringContainsString('[REDACTED]', $intent['objective']);
    }

    public function test_secret_like_content_in_source_evidence_is_redacted_not_emitted_verbatim(): void
    {
        $result = $this->loop()->intake(['research_items' => [
            $this->groundedItem(['source_evidence' => 'TOKEN: ghp_supersecretvalue123']),
        ]]);

        $intent = $result['intents'][0];
        $this->assertStringNotContainsString('ghp_supersecretvalue123', $intent['source_evidence']);
        $this->assertStringContainsString('[REDACTED]', $intent['source_evidence']);
    }

    public function test_long_copy_pasted_external_text_is_summarized_not_emitted_verbatim(): void
    {
        $rawExternalText = str_repeat('This external paper paragraph describes the mechanism in exhaustive detail. ', 6);
        $result = $this->loop()->intake(['research_items' => [
            $this->groundedItem(['leverage_claim' => $rawExternalText]),
        ]]);

        $intent = $result['intents'][0];
        $this->assertNotSame($rawExternalText, $intent['objective']);
        $this->assertLessThan(mb_strlen($rawExternalText), mb_strlen($intent['objective']));
        $this->assertStringContainsString('summarized design intent', $intent['objective']);
    }

    public function test_short_grounded_objective_is_passed_through_unmodified(): void
    {
        $result = $this->loop()->intake(['research_items' => [$this->groundedItem()]]);

        $intent = $result['intents'][0];
        $this->assertStringNotContainsString('summarized design intent', $intent['objective']);
        $this->assertStringNotContainsString('[REDACTED]', $intent['objective']);
    }

    // ── Determinism ───────────────────────────────────────────────────────────

    public function test_output_is_deterministic(): void
    {
        $items = ['research_items' => [$this->groundedItem()]];

        $this->assertSame(
            json_encode($this->loop()->intake($items)),
            json_encode($this->loop()->intake($items)),
        );
    }
}
