<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasAiPipelineRuntimeService;
use Tests\TestCase;

/**
 * Pins the documented contracts from
 * docs/engineering-knowledge-base/atlas-ai-pipeline.md.
 *
 * Pure, no DB, no RefreshDatabase.
 */
final class AtlasAiPipelineRuntimeTest extends TestCase
{
    private function service(): AtlasAiPipelineRuntimeService
    {
        return new AtlasAiPipelineRuntimeService();
    }

    /**
     * The canonical macro order must be exactly the doc's 14-stage sequence,
     * with Intent BEFORE Domain and the two distinct profile stages.
     */
    public function test_canonical_macro_order_matches_the_doc(): void
    {
        $result = $this->service()->canonicalPipeline();

        $this->assertSame([
            'input', 'intent', 'domain', 'domain_profile', 'flow_profile',
            'context', 'policy', 'decide', 'executor', 'gate',
            'repair_escalation', 'evidence', 'learning', 'output',
        ], $result['stages']);
        $this->assertSame(14, $result['count']);
        $this->assertTrue($result['intent_before_domain']);
        $this->assertSame(['domain_profile', 'flow_profile'], $result['distinct_profile_stages']);
    }

    /**
     * Reordering the macro stages (e.g. Domain before Intent, the order of the
     * sibling domain-pipelines doc) must be rejected — domains may not reinvent
     * the macro order.
     */
    public function test_validate_order_rejects_domain_before_intent(): void
    {
        $stages = [
            'input', 'domain', 'intent', 'domain_profile', 'flow_profile',
            'context', 'policy', 'decide', 'executor', 'gate',
            'repair_escalation', 'evidence', 'learning', 'output',
        ];

        $result = $this->service()->validateOrder($stages);

        $this->assertFalse($result['valid']);
        $this->assertTrue($result['out_of_order']);
        $this->assertSame('stages_out_of_macro_order', $result['reason']);

        // The exact canonical order, by contrast, validates.
        $ok = $this->service()->validateOrder($this->service()->canonicalPipeline()['stages']);
        $this->assertTrue($ok['valid']);
    }

    /**
     * Invariant 7 (Decision Receipt before relevant execution) and invariant 11
     * (evidence to claim success) must fire when a state-changing run omits the
     * receipt id and the evidence packet, while the satisfied invariants pass.
     */
    public function test_invariants_flag_missing_receipt_and_evidence(): void
    {
        $run = [
            'changes_real_state' => true,
            'relevant_execution' => true,
            'claims_operational_success' => true,
            'input' => ['origin' => 'cli', 'surface' => 'cli', 'workspace' => 'atlas', 'attachments' => ['a']],
            'intent_confidence' => 0.91,
            'domain' => 'programming',
            'domain_profile' => 'programming.forge',
            'flow_profile' => 'forge.build',
            // decision_receipt_id missing -> invariant 7 fails
            // evidence_packet missing -> invariant 11 fails
        ];

        $result = $this->service()->checkInvariants($run);

        $this->assertFalse($result['ok']);
        $this->assertContains(7, $result['violated']);
        $this->assertContains(11, $result['violated']);
        // Invariant 3 (explicit domain) and 4 (explicit profiles) are satisfied here.
        $this->assertContains(3, $result['held']);
        $this->assertContains(4, $result['held']);
    }

    /**
     * Invariant 3 forces an explicit Domain when the task changes real state;
     * a non-state-changing run with no domain still holds invariant 3.
     */
    public function test_invariant_3_requires_explicit_domain_only_when_changing_state(): void
    {
        $stateful = $this->service()->checkInvariants([
            'changes_real_state' => true,
            'domain' => '', // missing on a stateful run -> violates
        ]);
        $this->assertContains(3, $stateful['violated']);

        $readonly = $this->service()->checkInvariants([
            'changes_real_state' => false,
            'domain' => '', // allowed when nothing real changes
        ]);
        $this->assertContains(3, $readonly['held']);
    }

    /**
     * Surface aliases are intensities/intents/states of the SAME pipeline:
     * `atlas forge` -> heavy, `atlas fix` -> repair intent, `atlas continue`
     * -> resume state. resolveIntensity maps the alias to its tier obligations.
     */
    public function test_surface_aliases_resolve_on_one_pipeline(): void
    {
        $service = $this->service();

        $forge = $service->resolveSurfaceAlias('atlas forge');
        $this->assertSame('programming', $forge['domain']);
        $this->assertSame('heavy', $forge['intensity']);

        $fix = $service->resolveSurfaceAlias('fix');
        $this->assertSame('repair', $fix['intent']);

        $continue = $service->resolveSurfaceAlias('continue');
        $this->assertSame('resume', $continue['state']);

        // Heavy tier carries the documented heavy obligations.
        $intensity = $service->resolveIntensity('atlas forge');
        $this->assertSame('heavy', $intensity['intensity']);
        $this->assertContains('harness', $intensity['obligations']);
        $this->assertContains('multi_gate', $intensity['obligations']);

        // Unknown intensity falls back to medium, never to the cheapest tier.
        $fallback = $service->resolveIntensity('nonexistent');
        $this->assertSame('medium', $fallback['intensity']);
        $this->assertFalse($fallback['recognized']);
    }

    /**
     * Decision Receipt authority: a manual model not in the policy allow-list is
     * blocked back to auto-best (it is an override, not a new flow), and the
     * execution/evidence/pass authorities are fixed per the doc rule.
     */
    public function test_decision_receipt_blocks_disallowed_manual_override(): void
    {
        $receipt = $this->service()->compileDecisionReceipt([
            'domain_profile' => 'programming.forge',
            'flow_profile' => 'forge.build',
            'policy' => ['allowed_models' => ['atlas-auto-a'], 'auto_best_model' => 'atlas-auto-a'],
            'requested_model' => 'unlisted-model',
        ]);

        $this->assertTrue($receipt['authorized']);
        $this->assertSame('blocked', $receipt['manual_override']['disposition']);
        $this->assertSame('atlas-auto-a', $receipt['manual_override']['effective_model']);
        $this->assertSame('domain_orchestrator', $receipt['execution_authority']);
        $this->assertSame('runtime', $receipt['evidence_authority']);
        $this->assertSame('gate', $receipt['pass_authority']);

        // An allowed manual model is accepted as the effective model.
        $accepted = $this->service()->compileDecisionReceipt([
            'domain_profile' => 'programming.forge',
            'flow_profile' => 'forge.build',
            'policy' => ['allowed_models' => ['atlas-auto-a', 'pinned-model']],
            'requested_model' => 'pinned-model',
        ]);
        $this->assertSame('accepted', $accepted['manual_override']['disposition']);
        $this->assertSame('pinned-model', $accepted['manual_override']['effective_model']);
        $this->assertSame('manual_override', $accepted['model_selection']);
    }

    /**
     * Anti-pattern detection: a surface calling a provider directly and making
     * evidence optional for code-changing work are both flagged.
     */
    public function test_detects_documented_anti_patterns(): void
    {
        $result = $this->service()->detectAntiPatterns([
            'surface_calls_provider_direct' => true,
            'changes_code' => true,
            'evidence_required' => false,
        ]);

        $this->assertFalse($result['clean']);
        $this->assertContains('surface_calls_provider_direct', $result['violations']);
        $this->assertContains('evidence_optional_for_state_changing_work', $result['violations']);

        // A clean run reports no violations.
        $clean = $this->service()->detectAntiPatterns([
            'surface_calls_provider_direct' => false,
            'changes_code' => true,
            'evidence_required' => true,
        ]);
        $this->assertTrue($clean['clean']);
    }
}
