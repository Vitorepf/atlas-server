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

    // ── AC: blast_radius, required_parity_checks, migration_notes, rollback_hooks, unsafe_consumers, promotable ──

    public function test_no_consumer_case_has_zero_blast_radius_and_is_promotable(): void
    {
        $result = (new AtlasSelfConstructionSimplificationConsumerImpactAnalyzer)->analyze(['consumers' => []]);

        $this->assertSame(
            ['total_consumers' => 0, 'direct_consumer_count' => 0, 'transitive_consumer_count' => 0, 'categories_touched' => 0, 'touches_public_surface' => false],
            $result['blast_radius'],
        );
        $this->assertSame([], $result['required_parity_checks']);
        $this->assertSame([], $result['migration_notes']);
        $this->assertSame([], $result['rollback_hooks']);
        $this->assertSame([], $result['unsafe_consumers']);
        $this->assertTrue($result['promotable']);
    }

    public function test_safe_consumer_case_is_promotable_with_no_unsafe_consumers(): void
    {
        $result = (new AtlasSelfConstructionSimplificationConsumerImpactAnalyzer)->analyze([
            'consumers' => [
                ['name' => 'CallerService', 'category' => 'runtime', 'proof_refs' => ['git:blob1']],
            ],
        ]);

        $this->assertTrue($result['promotable']);
        $this->assertSame([], $result['unsafe_consumers']);
        $this->assertContains('runtime_behavior_parity_check', $result['required_parity_checks']);
        $this->assertContains('git_revert_last_commit', $result['rollback_hooks']);
        $this->assertSame(1, $result['blast_radius']['total_consumers']);
    }

    public function test_unsafe_consumer_case_names_the_unclassified_consumer_and_blocks_promotion(): void
    {
        $result = (new AtlasSelfConstructionSimplificationConsumerImpactAnalyzer)->analyze([
            'consumers' => [
                ['name' => 'MysteryCaller', 'category' => 'unknown_thing', 'proof_refs' => []],
            ],
        ]);

        $this->assertFalse($result['promotable']);
        $this->assertContains('MysteryCaller', $result['unsafe_consumers']);
        $this->assertContains('classify every consumer into a known category before promoting', $result['migration_notes']);
    }

    public function test_missing_parity_case_names_the_category_needing_proof_and_blocks_promotion(): void
    {
        $result = (new AtlasSelfConstructionSimplificationConsumerImpactAnalyzer)->analyze([
            'consumers' => [
                ['name' => 'CallerService', 'category' => 'runtime', 'proof_refs' => []],
            ],
        ]);

        $this->assertFalse($result['promotable']);
        $this->assertContains('CallerService', $result['unsafe_consumers']);
        $this->assertContains('attach at least one proof_ref for the runtime category before promoting', $result['migration_notes']);
        $this->assertContains('runtime_behavior_parity_check', $result['required_parity_checks']);
    }

    public function test_public_command_touch_adds_signature_parity_check_and_docs_migration_note(): void
    {
        $result = (new AtlasSelfConstructionSimplificationConsumerImpactAnalyzer)->analyze([
            'consumers' => [
                ['name' => 'ArtisanCommand', 'category' => 'command', 'proof_refs' => ['app/Console/Commands/FooCommand.php'], 'is_public_command' => true],
            ],
        ]);

        $this->assertContains('public_command_signature_parity_check', $result['required_parity_checks']);
        $this->assertContains('update CLI help/usage docs alongside the public command signature change', $result['migration_notes']);
        $this->assertContains('restore_prior_symbol_from_worktree_snapshot', $result['rollback_hooks']);
        $this->assertTrue($result['blast_radius']['touches_public_surface']);
    }

    public function test_transitive_consumers_add_reindex_rollback_hook(): void
    {
        $result = (new AtlasSelfConstructionSimplificationConsumerImpactAnalyzer)->analyze([
            'consumers' => [
                ['name' => 'DirectCaller', 'category' => 'runtime', 'proof_refs' => ['git:blob1']],
                ['name' => 'TransitiveCaller', 'category' => 'runtime', 'proof_refs' => ['git:blob2'], 'transitive' => true],
            ],
        ]);

        $this->assertContains('reindex_transitive_consumer_call_graph', $result['rollback_hooks']);
        $this->assertSame(2, $result['blast_radius']['total_consumers']);
    }

    public function test_output_is_deterministic_and_provider_safe_across_repeat_calls(): void
    {
        $candidate = [
            'consumers' => [
                ['name' => 'CallerService', 'category' => 'runtime', 'proof_refs' => ['git:blob1'], 'internal_provider_prompt' => 'super secret system prompt', 'api_key' => 'sk-secret-123'],
            ],
        ];

        $analyzer = new AtlasSelfConstructionSimplificationConsumerImpactAnalyzer;
        $first = $analyzer->analyze($candidate);
        $second = $analyzer->analyze($candidate);

        $this->assertSame(
            json_encode($first, JSON_UNESCAPED_SLASHES),
            json_encode($second, JSON_UNESCAPED_SLASHES),
        );

        $encoded = (string) json_encode($first);
        $this->assertStringNotContainsString('super secret system prompt', $encoded);
        $this->assertStringNotContainsString('sk-secret-123', $encoded);
    }
}
