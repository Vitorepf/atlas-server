<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AdversarialProofPanelService;
use Tests\TestCase;

/**
 * Unit pins for the independent adversarial proof panel rules engine. Each
 * verifier is DEFAULT-SKEPTICAL and the panel fails CLOSED: merge_allowed is true
 * ONLY when no verifier refutes. Pure and provider-free.
 */
final class AdversarialProofPanelServiceTest extends TestCase
{
    private function panel(): AdversarialProofPanelService
    {
        return new AdversarialProofPanelService();
    }

    /**
     * @return array<string,mixed>
     */
    private function cleanCycle(): array
    {
        return [
            'cycle_id' => 'aes_clean',
            'changed_files' => ['app/Services/Ai/Example.php'],
            'allowed_files' => ['app/Services/Ai/Example.php'],
            'selected_finding' => ['affected_files' => ['app/Services/Ai/Example.php']],
            'validation' => ['ran' => true, 'passed' => true, 'commands' => ['php artisan test']],
            'changed_file_contents' => ['app/Services/Ai/Example.php' => "<?php\nfinal class Example {}\n"],
        ];
    }

    public function test_clean_candidate_clears_every_verifier_and_allows_merge(): void
    {
        $verdict = $this->panel()->refute($this->cleanCycle());

        $this->assertTrue($verdict['merge_allowed']);
        $this->assertSame(0, $verdict['refuted_count']);
        $this->assertFalse($verdict['majority_refuted']);
        $this->assertSame('no_verifier_refuted', $verdict['reason']);
        $this->assertCount(4, $verdict['verifier_verdicts']);
    }

    public function test_empty_diff_is_refuted_as_outcome_not_achieved(): void
    {
        $cycle = $this->cleanCycle();
        $cycle['changed_files'] = [];

        $verdict = $this->panel()->refute($cycle);

        $this->assertFalse($verdict['merge_allowed']);
        $this->assertStringContainsString('outcome_achievement', $verdict['reason']);
    }

    public function test_diff_outside_declared_scope_is_refuted(): void
    {
        $cycle = $this->cleanCycle();
        $cycle['changed_files'] = ['app/Services/Ai/Unrelated.php'];

        $verdict = $this->panel()->refute($cycle);

        $this->assertFalse($verdict['merge_allowed']);
        $this->assertStringContainsString('diff_outside_declared_scope', $verdict['reason']);
    }

    public function test_unrun_or_failed_validation_is_refuted_as_coverage_theater(): void
    {
        $cycle = $this->cleanCycle();
        $cycle['validation'] = ['ran' => true, 'passed' => false, 'commands' => ['php artisan test']];

        $verdict = $this->panel()->refute($cycle);

        $this->assertFalse($verdict['merge_allowed']);
        $this->assertStringContainsString('validation_honesty:validation_not_passed', $verdict['reason']);
    }

    public function test_commandless_validation_is_refuted(): void
    {
        $cycle = $this->cleanCycle();
        $cycle['validation'] = ['ran' => true, 'passed' => true, 'commands' => []];

        $verdict = $this->panel()->refute($cycle);

        $this->assertFalse($verdict['merge_allowed']);
        $this->assertStringContainsString('validation_has_no_commands', $verdict['reason']);
    }

    public function test_todo_marker_in_product_file_is_refuted(): void
    {
        $cycle = $this->cleanCycle();
        $cycle['changed_file_contents'] = [
            'app/Services/Ai/Example.php' => "<?php\n// TODO: implement\nfinal class Example {}\n",
        ];

        $verdict = $this->panel()->refute($cycle);

        $this->assertFalse($verdict['merge_allowed']);
        $this->assertStringContainsString('regression_detection:incompleteness_marker:TODO', $verdict['reason']);
    }

    public function test_marker_in_test_file_is_not_refuted(): void
    {
        $cycle = $this->cleanCycle();
        $cycle['changed_files'] = ['app/Services/Ai/Example.php', 'tests/ExampleTest.php'];
        $cycle['allowed_files'] = ['app/Services/Ai/Example.php', 'tests/ExampleTest.php'];
        $cycle['changed_file_contents'] = [
            'app/Services/Ai/Example.php' => "<?php\nfinal class Example {}\n",
            'tests/ExampleTest.php' => "<?php\n// asserts no TODO leaks through\n",
        ];

        $verdict = $this->panel()->refute($cycle);

        $this->assertTrue($verdict['merge_allowed']);
    }

    public function test_measured_claim_without_metric_is_refuted(): void
    {
        $cycle = $this->cleanCycle();
        $cycle['outcome_measured'] = true;
        $cycle['outcome_metric'] = [];

        $verdict = $this->panel()->refute($cycle);

        $this->assertFalse($verdict['merge_allowed']);
        $this->assertStringContainsString('measured_claim_without_metric', $verdict['reason']);
    }

    public function test_measured_claim_with_unmet_metric_is_refuted(): void
    {
        $cycle = $this->cleanCycle();
        $cycle['outcome_measured'] = true;
        $cycle['outcome_metric'] = ['outcome_met' => false];

        $verdict = $this->panel()->refute($cycle);

        $this->assertFalse($verdict['merge_allowed']);
        $this->assertStringContainsString('measured_claim_metric_not_met', $verdict['reason']);
    }

    public function test_measured_claim_with_met_metric_is_not_refuted(): void
    {
        $cycle = $this->cleanCycle();
        $cycle['outcome_measured'] = true;
        $cycle['outcome_metric'] = ['outcome_met' => true, 'measured_value' => 80.0];

        $verdict = $this->panel()->refute($cycle);

        $this->assertTrue($verdict['merge_allowed']);
    }

    public function test_single_refutation_blocks_even_without_majority(): void
    {
        // Only ONE of four verifiers refutes (no majority) — the gate still
        // fails CLOSED. This is the no-weakening guarantee.
        $cycle = $this->cleanCycle();
        $cycle['changed_file_contents'] = [
            'app/Services/Ai/Example.php' => "<?php\n// FIXME later\nfinal class Example {}\n",
        ];

        $verdict = $this->panel()->refute($cycle);

        $this->assertSame(1, $verdict['refuted_count']);
        $this->assertFalse($verdict['majority_refuted']);
        $this->assertFalse($verdict['merge_allowed']);
    }
}
