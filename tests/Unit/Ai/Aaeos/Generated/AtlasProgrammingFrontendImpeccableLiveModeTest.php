<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasProgrammingFrontendImpeccableLiveModeService;
use Tests\TestCase;

/**
 * Pins the Impeccable Live Mode Teardown contract:
 *  - the event loop is an ordered 9-step pipeline; accept can never precede the
 *    agent writing variants (Contratos/Fluxo);
 *  - the 7 "Regras para IA" must all hold and missing evidence is a violation;
 *  - a variant div must hold exactly one top-level element (rule 6);
 *  - a live source mutation without boundary + evidence is refused
 *    (forbidden_changes); accepting never asserts delivery done.
 * Pure, no DB.
 *
 * @see docs/engineering-knowledge-base/domains/programming-frontend-impeccable-live-mode.md
 */
class AtlasProgrammingFrontendImpeccableLiveModeTest extends TestCase
{
    private function service(): AtlasProgrammingFrontendImpeccableLiveModeService
    {
        return new AtlasProgrammingFrontendImpeccableLiveModeService;
    }

    public function test_canonical_flow_executed_in_order_is_contract_clean(): void
    {
        $service = $this->service();
        $flow = $service->flow();

        // The doc lists exactly the 9 Fluxo steps.
        $this->assertCount(9, $flow);
        $this->assertSame('config_and_start_server', $flow[0]);
        $this->assertSame('recovery_by_journal', $flow[8]);

        $result = $service->evaluateEventLoopOrder($flow);

        $this->assertTrue($result['ordered']);
        $this->assertSame([], $result['order_violations']);
        $this->assertSame([], $result['unknown_steps']);
        $this->assertSame('event_loop_respects_documented_order', $result['conclusion']);
    }

    public function test_accept_before_agent_writes_variants_is_an_order_violation(): void
    {
        $service = $this->service();

        // Skip straight to the user accepting before any variant was written.
        $result = $service->evaluateEventLoopOrder([
            'config_and_start_server',
            'serve_live_and_detect',
            'inject_browser_ui',
            'wrap_source_element',
            'user_accept_or_discard', // out of order: agent_writes_variants + browser_detects_variants missing
        ]);

        $this->assertFalse($result['ordered']);
        $this->assertSame('event_loop_out_of_order_contract_violated', $result['conclusion']);

        $violationSteps = array_column($result['order_violations'], 'step');
        $this->assertContains('user_accept_or_discard', $violationSteps);

        $missing = $result['order_violations'][0]['missing_prerequisites'];
        $this->assertContains(AtlasProgrammingFrontendImpeccableLiveModeService::STEP_AGENT_WRITES, $missing);
        $this->assertContains('browser_detects_variants', $missing);
    }

    public function test_unknown_step_is_reported_and_never_counts_as_in_order(): void
    {
        $service = $this->service();

        $result = $service->evaluateEventLoopOrder(['config_and_start_server', 'mutate_random_file']);

        $this->assertContains('mutate_random_file', $result['unknown_steps']);
        $this->assertFalse($result['ordered']);
        $this->assertSame(['config_and_start_server'], $result['executed_in_order']);
    }

    public function test_all_seven_rules_must_hold_and_missing_evidence_is_a_violation(): void
    {
        $service = $this->service();

        $this->assertCount(7, $service->rules());

        $fullyCompliant = [
            'helper_port_distinct_from_app_url' => true,
            'poll_long_timeout_and_repolls' => true,
            'annotated_screenshot_read' => true,
            'text_disambiguation_when_repeated' => true,
            'target_is_not_generated_file' => true,
            'one_top_level_element_per_variant' => true,
            'cleanup_done_before_complete' => true,
        ];
        $clean = $service->evaluateRules($fullyCompliant);
        $this->assertTrue($clean['compliant']);
        $this->assertSame(0, $clean['violation_count']);
        $this->assertSame(7, $clean['total_rules']);

        // Rule 5 (never write a generated file) drops out -> R5 violated.
        $dropGenerated = $fullyCompliant;
        unset($dropGenerated['target_is_not_generated_file']);
        $r5 = $service->evaluateRules($dropGenerated);
        $this->assertFalse($r5['compliant']);
        $this->assertContains('R5', $r5['violated_ids']);

        // Rule 1 (helper port is never app URL) set false -> R1 violated;
        // a truthy-but-not-true value must not pass.
        $portFalse = $fullyCompliant;
        $portFalse['helper_port_distinct_from_app_url'] = 'yes';
        $r1 = $service->evaluateRules($portFalse);
        $this->assertContains('R1', $r1['violated_ids']);
    }

    public function test_variant_must_have_exactly_one_top_level_element(): void
    {
        $service = $this->service();

        $this->assertTrue($service->validateVariantTopLevel(1)['valid']);
        $this->assertSame('variant_has_exactly_one_top_level_element', $service->validateVariantTopLevel(1)['reason']);

        $zero = $service->validateVariantTopLevel(0);
        $this->assertFalse($zero['valid']);
        $this->assertSame('variant_has_no_top_level_element', $zero['reason']);

        $many = $service->validateVariantTopLevel(3);
        $this->assertFalse($many['valid']);
        $this->assertSame('variant_has_multiple_top_level_elements', $many['reason']);
    }

    public function test_source_mutation_refused_without_boundary_and_evidence(): void
    {
        $service = $this->service();

        // Empty context: forbidden change is refused.
        $refused = $service->authorizeSourceMutation([]);
        $this->assertFalse($refused['authorized']);
        $this->assertSame('live_source_mutation_refused_boundary_or_evidence_missing', $refused['reason']);
        $this->assertContains('boundary_declared', $refused['missing_requirements']);
        $this->assertContains('diff_present', $refused['missing_requirements']);
        $this->assertFalse($refused['asserts_delivery_done']);

        // Evidence present but no boundary -> still refused (boundary is mandatory).
        $noBoundary = [
            'boundary_declared' => false,
            'receipt_present' => true,
            'screenshot_present' => true,
            'diff_present' => true,
            'target_is_not_generated' => true,
            'variant_single_top_level' => true,
        ];
        $stillRefused = $service->authorizeSourceMutation($noBoundary);
        $this->assertFalse($stillRefused['authorized']);
        $this->assertSame(['boundary_declared'], $stillRefused['missing_requirements']);

        // Full boundary + evidence -> authorized, but never asserts delivery done.
        $full = array_fill_keys([
            'boundary_declared', 'receipt_present', 'screenshot_present',
            'diff_present', 'target_is_not_generated', 'variant_single_top_level',
        ], true);
        $authorized = $service->authorizeSourceMutation($full);
        $this->assertTrue($authorized['authorized']);
        $this->assertSame('live_source_mutation_has_boundary_and_evidence', $authorized['reason']);
        $this->assertFalse($authorized['asserts_delivery_done']);
        $this->assertContains('run_certification', $authorized['required_next_gates']);
    }
}
