<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Simplification;

use App\Services\Ai\SelfConstruction\Simplification\AtlasSelfConstructionSimplificationConsumerImpactAnalyzer;
use PHPUnit\Framework\TestCase;

final class AtlasSelfConstructionSimplificationConsumerImpactAnalyzerTest extends TestCase
{
    public function test_classifies_runtime_test_docs_config_and_command_consumers(): void
    {
        $result = (new AtlasSelfConstructionSimplificationConsumerImpactAnalyzer)->analyze([
            'consumers' => [
                ['name' => 'CallerService', 'category' => 'runtime', 'proof_refs' => ['git:blob1']],
                ['name' => 'CallerTest', 'category' => 'test', 'proof_refs' => ['tests/FooTest.php']],
                ['name' => 'readme-section', 'category' => 'docs', 'proof_refs' => ['docs/foo.md']],
                ['name' => 'config-key', 'category' => 'config', 'proof_refs' => ['config/app.php']],
                ['name' => 'artisan-command', 'category' => 'command', 'proof_refs' => ['app/Console/Commands/FooCommand.php']],
            ],
        ]);

        $this->assertSame(
            ['runtime', 'test', 'docs', 'config', 'command'],
            $result['impacted_classes'],
        );
        $this->assertTrue($result['safe_to_continue']);
        $this->assertFalse($result['fail_closed']);
        $this->assertSame([], $result['blockers']);
    }

    public function test_fails_closed_when_consumer_is_unclassified(): void
    {
        $result = (new AtlasSelfConstructionSimplificationConsumerImpactAnalyzer)->analyze([
            'consumers' => [
                ['name' => 'MysteryCaller', 'category' => 'unknown_thing', 'proof_refs' => []],
            ],
        ]);

        $this->assertTrue($result['fail_closed']);
        $this->assertFalse($result['safe_to_continue']);
        $this->assertContains('consumer_unclassified:MysteryCaller', $result['blockers']);
    }

    public function test_fails_closed_when_impacted_class_has_zero_proof_coverage(): void
    {
        $result = (new AtlasSelfConstructionSimplificationConsumerImpactAnalyzer)->analyze([
            'consumers' => [
                ['name' => 'CallerService', 'category' => 'runtime', 'proof_refs' => []],
            ],
        ]);

        $this->assertTrue($result['fail_closed']);
        $this->assertFalse($result['safe_to_continue']);
        $this->assertContains('missing_proof_coverage:runtime', $result['blockers']);
    }

    public function test_safe_to_continue_true_only_when_all_classified_and_proven(): void
    {
        $result = (new AtlasSelfConstructionSimplificationConsumerImpactAnalyzer)->analyze([
            'consumers' => [
                ['name' => 'CallerA', 'category' => 'runtime', 'proof_refs' => ['git:blob1']],
                ['name' => 'CallerB', 'category' => 'runtime', 'proof_refs' => []],
            ],
        ]);

        $this->assertTrue($result['safe_to_continue'], 'one proven consumer per class is enough coverage');

        $result2 = (new AtlasSelfConstructionSimplificationConsumerImpactAnalyzer)->analyze([
            'consumers' => [
                ['name' => 'CallerA', 'category' => 'runtime', 'proof_refs' => []],
                ['name' => 'CallerB', 'category' => 'test'],
            ],
        ]);

        $this->assertFalse($result2['safe_to_continue']);
        $this->assertContains('missing_proof_coverage:runtime', $result2['blockers']);
        $this->assertContains('missing_proof_coverage:test', $result2['blockers']);
    }

    public function test_empty_consumers_is_safe_to_continue(): void
    {
        $result = (new AtlasSelfConstructionSimplificationConsumerImpactAnalyzer)->analyze(['consumers' => []]);

        $this->assertTrue($result['safe_to_continue']);
        $this->assertSame([], $result['blockers']);
        $this->assertSame([], $result['impacted_classes']);
    }

    public function test_direct_and_transitive_consumers_are_counted_and_public_touch_raises_risk(): void
    {
        $result = (new AtlasSelfConstructionSimplificationConsumerImpactAnalyzer)->analyze([
            'consumers' => [
                ['name' => 'DirectCaller', 'category' => 'runtime', 'proof_refs' => ['git:blob1']],
                ['name' => 'TransitiveCaller', 'category' => 'runtime', 'proof_refs' => ['git:blob2'], 'transitive' => true],
                ['name' => 'ArtisanCommand', 'category' => 'command', 'proof_refs' => ['app/Console/Commands/FooCommand.php'], 'is_public_command' => true],
            ],
        ]);

        $this->assertSame(2, $result['direct_consumer_count']);
        $this->assertSame(1, $result['transitive_consumer_count']);
        $this->assertTrue($result['touches_public_command']);
        $this->assertSame(AtlasSelfConstructionSimplificationConsumerImpactAnalyzer::RISK_HIGH, $result['risk_level']);
    }

    public function test_test_and_docs_only_usage_is_lower_risk_but_still_reported(): void
    {
        $result = (new AtlasSelfConstructionSimplificationConsumerImpactAnalyzer)->analyze([
            'consumers' => [
                ['name' => 'CallerTest', 'category' => 'test', 'proof_refs' => ['tests/FooTest.php']],
                ['name' => 'readme-section', 'category' => 'docs', 'proof_refs' => ['docs/foo.md']],
            ],
        ]);

        $this->assertSame(AtlasSelfConstructionSimplificationConsumerImpactAnalyzer::RISK_LOW, $result['risk_level']);
        $this->assertSame(['test', 'docs'], $result['impacted_classes']);
        $this->assertSame(2, $result['direct_consumer_count']);
    }

    public function test_high_risk_from_public_contract_touch_emits_blocking_reason_over_safe_refactor_floor(): void
    {
        $result = (new AtlasSelfConstructionSimplificationConsumerImpactAnalyzer)->analyze([
            'consumers' => [
                ['name' => 'ContractCaller', 'category' => 'runtime', 'proof_refs' => ['git:blob1'], 'is_public_contract' => true],
            ],
        ]);

        $this->assertSame(AtlasSelfConstructionSimplificationConsumerImpactAnalyzer::RISK_HIGH, $result['risk_level']);
        $this->assertContains('consumer_risk_exceeds_safe_refactor_floor:high', $result['blocking_reasons']);
        $this->assertFalse($result['safe_to_continue']);
    }

    public function test_transitive_consumer_count_above_floor_without_proof_raises_high_risk(): void
    {
        $result = (new AtlasSelfConstructionSimplificationConsumerImpactAnalyzer)->analyze([
            'consumers' => [
                ['name' => 'T1', 'category' => 'runtime', 'proof_refs' => [], 'transitive' => true],
                ['name' => 'T2', 'category' => 'runtime', 'proof_refs' => [], 'transitive' => true],
                ['name' => 'T3', 'category' => 'runtime', 'proof_refs' => [], 'transitive' => true],
            ],
        ]);

        $this->assertSame(AtlasSelfConstructionSimplificationConsumerImpactAnalyzer::RISK_HIGH, $result['risk_level']);
        $this->assertContains('transitive_consumer_count_exceeds_floor_without_proof', $result['blockers']);
        $this->assertFalse($result['safe_to_continue']);
    }

    public function test_transitive_consumer_count_above_floor_with_proof_does_not_force_high_risk(): void
    {
        $result = (new AtlasSelfConstructionSimplificationConsumerImpactAnalyzer)->analyze([
            'consumers' => [
                ['name' => 'T1', 'category' => 'runtime', 'proof_refs' => ['git:blob1'], 'transitive' => true],
                ['name' => 'T2', 'category' => 'runtime', 'proof_refs' => ['git:blob2'], 'transitive' => true],
                ['name' => 'T3', 'category' => 'runtime', 'proof_refs' => ['git:blob3'], 'transitive' => true],
            ],
        ]);

        $this->assertNotContains('transitive_consumer_count_exceeds_floor_without_proof', $result['blockers']);
    }

    public function test_transitive_consumer_count_at_or_below_floor_does_not_require_proof(): void
    {
        $result = (new AtlasSelfConstructionSimplificationConsumerImpactAnalyzer)->analyze([
            'consumers' => [
                ['name' => 'T1', 'category' => 'runtime', 'proof_refs' => ['git:blob1'], 'transitive' => true],
                ['name' => 'T2', 'category' => 'runtime', 'proof_refs' => [], 'transitive' => true],
            ],
        ]);

        $this->assertNotContains('transitive_consumer_count_exceeds_floor_without_proof', $result['blockers']);
    }
}
