<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasCurrentProviderStackService;
use Tests\TestCase;

/**
 * Pins the executable Current Provider Stack policy from the doc: the five-rail
 * allow-list with per-rail roles, the exclusion of providers outside the stack
 * (with an exclusion receipt), subsidy-first / paygo-blocked spend, the Codex
 * premium leverage boundary, the ordered work flow, and the hard stops. Pure,
 * no DB.
 *
 * @see docs/engineering-knowledge-base/atlas-current-provider-stack-v1.md
 */
class AtlasCurrentProviderStackTest extends TestCase
{
    private function service(): AtlasCurrentProviderStackService
    {
        return new AtlasCurrentProviderStackService;
    }

    public function test_current_stack_is_exactly_the_five_documented_rails(): void
    {
        // Doc "Stack Atual" lists exactly five rails — no more, no less.
        $this->assertCount(5, AtlasCurrentProviderStackService::STACK);
        $this->assertSame(
            ['codex_gpt55', 'cursor_cli_composer', 'minimax_m27', 'antigravity_sdk', 'gemini_subsidized'],
            array_keys(AtlasCurrentProviderStackService::STACK),
        );

        // Codex is the premium reviewer and must NOT be a 24/7 worker.
        $codex = $this->service()->describeRail('codex_gpt55');
        $this->assertTrue($codex['in_stack']);
        $this->assertSame('premium_architect_judge_final_reviewer_hard_repair', $codex['primary_role']);
        $this->assertContains('worker_24_7', $codex['do_not_use_for']);
    }

    public function test_provider_outside_stack_is_blocked_with_exclusion_receipt(): void
    {
        // Doc: a provider outside the current stack is NOT an active route.
        $out = $this->service()->routeProvider('some_external_provider');

        $this->assertFalse($out['allowed']);
        $this->assertSame('block_outside_current_stack', $out['decision']);
        $this->assertNotNull($out['exclusion_receipt']);
        $this->assertSame(
            AtlasCurrentProviderStackService::EXCLUSION_SCHEMA,
            $out['exclusion_receipt']['schema_version'],
        );
        $this->assertTrue($out['exclusion_receipt']['requires_new_human_decision']);

        // A NEW human decision is the only thing that opens the route.
        $allowed = $this->service()->routeProvider('some_external_provider', true);
        $this->assertTrue($allowed['allowed']);
        $this->assertNull($allowed['exclusion_receipt']);

        // An in-stack rail is allowed without any extra decision.
        $inStack = $this->service()->routeProvider('minimax_m27');
        $this->assertTrue($inStack['allowed']);
        $this->assertSame('allow', $inStack['decision']);
    }

    public function test_paygo_is_blocked_unless_human_decision_cap_and_evidence(): void
    {
        $svc = $this->service();

        // Subsidy is the default-allowed mode.
        $subsidy = $svc->spendDecision('subsidy');
        $this->assertTrue($subsidy['allowed']);
        $this->assertSame('subsidy_first', $subsidy['resolved_mode']);

        // Paygo with nothing set is blocked and lists all three missing controls.
        $blocked = $svc->spendDecision('paygo');
        $this->assertFalse($blocked['allowed']);
        $this->assertSame('paygo_blocked', $blocked['resolved_mode']);
        $this->assertSame(['human_decision', 'spend_cap', 'evidence'], $blocked['missing']);

        // Missing only evidence is still blocked.
        $partial = $svc->spendDecision('paygo', true, true, false);
        $this->assertFalse($partial['allowed']);
        $this->assertSame(['evidence'], $partial['missing']);

        // All three present -> allowed with controls.
        $ok = $svc->spendDecision('paygo', true, true, true);
        $this->assertTrue($ok['allowed']);
        $this->assertSame('paygo_allowed_with_controls', $ok['resolved_mode']);
    }

    public function test_codex_premium_is_reserved_for_high_leverage_only(): void
    {
        $svc = $this->service();

        // High-leverage work is allowed on Codex.
        $review = $svc->codexSpendDecision('final_review');
        $this->assertTrue($review['allowed']);
        $this->assertSame('allow_codex_premium', $review['decision']);

        // Cheap scout is blocked and routed to a subsidized scout rail instead.
        $scout = $svc->codexSpendDecision('cheap_scout');
        $this->assertFalse($scout['allowed']);
        $this->assertSame('block_codex_for_cheap_work', $scout['decision']);
        $this->assertSame('gemini_subsidized', $scout['route_instead']);

        // Repeated retry is blocked and routed to MiniMax.
        $retry = $svc->codexSpendDecision('repeated_retry');
        $this->assertFalse($retry['allowed']);
        $this->assertSame('minimax_m27', $retry['route_instead']);
    }

    public function test_flow_enforces_documented_order(): void
    {
        $svc = $this->service();

        // A valid prefix reports the correct next step.
        $progress = $svc->flowProgress([
            'local_context_index_shards_ownership',
            'subsidized_scout',
        ]);
        $this->assertTrue($progress['valid_order']);
        $this->assertFalse($progress['complete']);
        $this->assertSame('minimax_work_packets', $progress['next_step']);

        // Out-of-order steps are rejected.
        $outOfOrder = $svc->flowProgress([
            'cursor_cli_patch',
            'local_context_index_shards_ownership',
        ]);
        $this->assertFalse($outOfOrder['valid_order']);
        $this->assertNull($outOfOrder['next_step']);

        // Full ordered run is complete.
        $full = $svc->flowProgress(AtlasCurrentProviderStackService::FLOW_STEPS);
        $this->assertTrue($full['complete']);
        $this->assertNull($full['next_step']);
    }

    public function test_hard_stops_block_when_any_condition_fires(): void
    {
        $svc = $this->service();

        // No condition set -> proceed.
        $clear = $svc->evaluateHardStops([
            'paygo_without_cap' => false,
            'antigravity_cli_as_hot_path' => false,
        ]);
        $this->assertFalse($clear['blocked']);
        $this->assertSame('proceed', $clear['decision']);

        // Two conditions true -> block, both reasons listed.
        $blocked = $svc->evaluateHardStops([
            'paygo_without_cap' => true,
            'minimax_edit_without_lease_or_allowed_files' => true,
        ]);
        $this->assertTrue($blocked['blocked']);
        $this->assertSame('block', $blocked['decision']);
        $this->assertSame(
            ['paygo_without_cap', 'minimax_edit_without_lease_or_allowed_files'],
            $blocked['triggered'],
        );
        $this->assertCount(2, $blocked['reasons']);

        // Unknown condition keys are surfaced, not silently honored.
        $unknown = $svc->evaluateHardStops(['not_a_real_stop' => true]);
        $this->assertFalse($unknown['blocked']);
        $this->assertSame(['not_a_real_stop'], $unknown['unknown_conditions']);
    }
}
