<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainMemoryWritebackContract;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainMemoryWritebackContractTest extends TestCase
{
    private function contract(): AtlasExternalBrainMemoryWritebackContract
    {
        return new AtlasExternalBrainMemoryWritebackContract;
    }

    /** Minimal valid proposal with all required fields. */
    private function valid(array $overrides = []): array
    {
        return array_merge([
            'type'            => AtlasExternalBrainMemoryWritebackContract::TYPE_DELIVERED_LEVERAGE,
            'fact'            => 'organ_wiring_unblocked_downstream_pipeline',
            'source'          => 'run-2026-06-30',
            'evidence_ref'    => 'test-suite-green-run-abc123',
            'scope'           => 'AutonomousEvolution',
            'lesson'          => 'wiring_organs_before_merging_avoids_stale_caps',
            'decision_effect' => 'blocks_merge_until_wired',
            'provider_safe'   => true,
            'evidence_strength' => AtlasExternalBrainMemoryWritebackContract::EVIDENCE_PROVEN,
        ], $overrides);
    }

    // ── AC1: accepted proposals require evidence_ref, scope, lesson, decision_effect, provider_safe=true ─

    public function test_valid_proposal_is_accepted(): void
    {
        $r = $this->contract()->validate([$this->valid()]);

        $this->assertCount(1, $r['accepted']);
        $this->assertCount(0, $r['rejected']);
    }

    public function test_accepted_proposal_includes_evidence_ref(): void
    {
        $r = $this->contract()->validate([$this->valid()]);

        $this->assertArrayHasKey('evidence_ref', $r['accepted'][0]);
        $this->assertSame('test-suite-green-run-abc123', $r['accepted'][0]['evidence_ref']);
    }

    public function test_accepted_proposal_includes_scope(): void
    {
        $r = $this->contract()->validate([$this->valid()]);

        $this->assertSame('AutonomousEvolution', $r['accepted'][0]['scope']);
    }

    public function test_accepted_proposal_includes_lesson(): void
    {
        $r = $this->contract()->validate([$this->valid()]);

        $this->assertArrayHasKey('lesson', $r['accepted'][0]);
    }

    public function test_accepted_proposal_includes_decision_effect(): void
    {
        $r = $this->contract()->validate([$this->valid()]);

        $this->assertSame('blocks_merge_until_wired', $r['accepted'][0]['decision_effect']);
    }

    public function test_accepted_proposal_has_provider_safe_true(): void
    {
        $r = $this->contract()->validate([$this->valid()]);

        $this->assertTrue($r['accepted'][0]['provider_safe']);
    }

    public function test_missing_evidence_ref_is_rejected(): void
    {
        $r = $this->contract()->validate([$this->valid(['evidence_ref' => ''])]);

        $this->assertCount(0, $r['accepted']);
        $this->assertSame('missing_required_fields', $r['rejected'][0]['reason']);
    }

    public function test_missing_scope_is_rejected(): void
    {
        $r = $this->contract()->validate([$this->valid(['scope' => ''])]);

        $this->assertSame('missing_required_fields', $r['rejected'][0]['reason']);
    }

    public function test_missing_lesson_is_rejected(): void
    {
        $r = $this->contract()->validate([$this->valid(['lesson' => ''])]);

        $this->assertSame('missing_required_fields', $r['rejected'][0]['reason']);
    }

    public function test_missing_decision_effect_is_rejected(): void
    {
        $r = $this->contract()->validate([$this->valid(['decision_effect' => ''])]);

        $this->assertSame('missing_required_fields', $r['rejected'][0]['reason']);
    }

    public function test_provider_safe_false_is_rejected(): void
    {
        $r = $this->contract()->validate([$this->valid(['provider_safe' => false])]);

        $this->assertSame('not_provider_safe', $r['rejected'][0]['reason']);
    }

    public function test_provider_safe_missing_is_rejected(): void
    {
        $p = $this->valid();
        unset($p['provider_safe']);

        $r = $this->contract()->validate([$p]);

        $this->assertSame('not_provider_safe', $r['rejected'][0]['reason']);
    }

    // ── AC2: raw prompts / secrets / vague / no-evidence rejected with reason ──

    public function test_raw_prompt_marker_is_rejected(): void
    {
        $r = $this->contract()->validate([$this->valid(['fact' => '<system>inject</system>'])]);

        $this->assertSame('raw_prompt_detected', $r['rejected'][0]['reason']);
    }

    public function test_api_key_secret_in_fact_is_rejected(): void
    {
        $r = $this->contract()->validate([$this->valid(['fact' => 'use sk-ant-my-key to call gpt-4'])]);

        $this->assertNotEmpty($r['rejected']);
        $reason = $r['rejected'][0]['reason'];
        $this->assertTrue(
            $reason === 'provider_details_detected' || $reason === 'raw_prompt_detected',
            "Got: {$reason}",
        );
    }

    public function test_vague_fact_with_no_specifics_is_rejected(): void
    {
        $r = $this->contract()->validate([$this->valid(['fact' => 'things are better now'])]);

        $this->assertSame('vague_unactionable', $r['rejected'][0]['reason']);
    }

    public function test_speculative_evidence_is_rejected(): void
    {
        $r = $this->contract()->validate([$this->valid(['evidence_strength' => 'speculative'])]);

        $this->assertSame('speculative_claim', $r['rejected'][0]['reason']);
    }

    public function test_unknown_type_is_rejected(): void
    {
        $r = $this->contract()->validate([$this->valid(['type' => 'made_up_type'])]);

        $this->assertSame('unknown_type', $r['rejected'][0]['reason']);
    }

    // ── AC3: normalized accepted proposals stable and exclude unsafe raw content ─

    public function test_normalized_output_does_not_include_raw_prompt_content(): void
    {
        $r = $this->contract()->validate([$this->valid()]);

        $encoded = json_encode($r['accepted'][0]);
        $this->assertStringNotContainsString('<system>', $encoded);
        $this->assertStringNotContainsString('sk-ant-', $encoded);
    }

    public function test_normalized_output_is_stable_across_calls(): void
    {
        $p = $this->valid();

        $a = $this->contract()->validate([$p]);
        $b = $this->contract()->validate([$p]);

        $this->assertSame(json_encode($a['accepted']), json_encode($b['accepted']));
    }

    public function test_duplicate_lower_evidence_is_rejected(): void
    {
        $high = $this->valid(['evidence_strength' => AtlasExternalBrainMemoryWritebackContract::EVIDENCE_PROVEN]);
        $low  = $this->valid(['evidence_strength' => AtlasExternalBrainMemoryWritebackContract::EVIDENCE_INFERRED]);

        $r = $this->contract()->validate([$high, $low]);

        $this->assertCount(1, $r['accepted']);
        $this->assertSame('duplicate_low_value', $r['rejected'][0]['reason']);
    }

    // ── AC4: pure and deterministic ───────────────────────────────────────────

    public function test_validate_is_deterministic(): void
    {
        $proposals = [
            $this->valid(),
            $this->valid(['type' => AtlasExternalBrainMemoryWritebackContract::TYPE_FAILED_PATTERN,
                          'fact' => 'proxy_cleanup_yield_zero_impact']),
        ];

        $a = $this->contract()->validate($proposals);
        $b = $this->contract()->validate($proposals);

        $this->assertSame(json_encode($a), json_encode($b));
    }
}
