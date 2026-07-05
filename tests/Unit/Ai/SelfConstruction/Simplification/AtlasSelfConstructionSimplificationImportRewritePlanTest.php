<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Simplification;

use App\Services\Ai\SelfConstruction\Simplification\AtlasSelfConstructionSimplificationImportRewritePlan;
use PHPUnit\Framework\TestCase;

final class AtlasSelfConstructionSimplificationImportRewritePlanTest extends TestCase
{
    private function planner(): AtlasSelfConstructionSimplificationImportRewritePlan
    {
        return new AtlasSelfConstructionSimplificationImportRewritePlan;
    }

    public function test_class_import_rewrite_produces_deterministic_step(): void
    {
        $result = $this->planner()->plan([
            'target_symbol' => 'App\\Old\\OldClass',
            'replacement_symbol' => 'App\\New\\NewClass',
            'allowed_files' => ['app/Foo/Consumer.php'],
            'consumers' => [
                ['file' => 'app/Foo/Consumer.php'],
            ],
        ]);

        $this->assertFalse($result['unsafe']);
        $this->assertSame([], $result['blockers']);
        $this->assertCount(1, $result['steps']);
        $step = $result['steps'][0];
        $this->assertSame('app/Foo/Consumer.php', $step['file']);
        $this->assertSame('App\\Old\\OldClass', $step['before']);
        $this->assertSame('App\\New\\NewClass', $step['after']);
        $this->assertSame(AtlasSelfConstructionSimplificationImportRewritePlan::REASON_CLASS_IMPORT_REWRITE, $step['reason']);
        $this->assertArrayHasKey('verification_hint', $step);
    }

    public function test_config_string_occurrence_produces_deterministic_step(): void
    {
        $result = $this->planner()->plan([
            'target_symbol' => 'App\\Old\\OldClass',
            'replacement_symbol' => 'App\\New\\NewClass',
            'allowed_files' => ['config/services.php'],
            'config_string_occurrences' => [
                ['file' => 'config/services.php', 'before' => 'App\\Old\\OldClass::class', 'after' => 'App\\New\\NewClass::class'],
            ],
            'behavior_parity_command' => 'php artisan test',
        ]);

        $this->assertFalse($result['unsafe']);
        $this->assertCount(1, $result['steps']);
        $this->assertSame(AtlasSelfConstructionSimplificationImportRewritePlan::REASON_CONFIG_STRING_REWRITE, $result['steps'][0]['reason']);
    }

    public function test_consumer_outside_allowed_files_is_unsafe(): void
    {
        $result = $this->planner()->plan([
            'target_symbol' => 'App\\Old\\OldClass',
            'replacement_symbol' => 'App\\New\\NewClass',
            'allowed_files' => ['app/Foo/Consumer.php'],
            'consumers' => [
                ['file' => 'app/Bar/OtherConsumer.php'],
            ],
        ]);

        $this->assertTrue($result['unsafe']);
        $this->assertContains('consumer_outside_allowed_files:app/Bar/OtherConsumer.php', $result['blockers']);
        $this->assertSame([], $result['steps']);
    }

    public function test_ambiguous_alias_is_unsafe(): void
    {
        $result = $this->planner()->plan([
            'target_symbol' => 'App\\Old\\OldClass',
            'replacement_symbol' => 'App\\New\\NewClass',
            'allowed_files' => ['app/Foo/Consumer.php'],
            'consumers' => [
                ['file' => 'app/Foo/Consumer.php', 'alias' => 'SomethingUnrelated'],
            ],
        ]);

        $this->assertTrue($result['unsafe']);
        $this->assertContains('ambiguous_alias:app/Foo/Consumer.php:SomethingUnrelated', $result['blockers']);
    }

    public function test_identical_before_after_symbols_is_unsafe(): void
    {
        $result = $this->planner()->plan([
            'target_symbol' => 'App\\Old\\OldClass',
            'replacement_symbol' => 'App\\Old\\OldClass',
            'allowed_files' => ['app/Foo/Consumer.php'],
            'consumers' => [
                ['file' => 'app/Foo/Consumer.php'],
            ],
        ]);

        $this->assertTrue($result['unsafe']);
        $this->assertContains('before_and_after_symbols_identical', $result['blockers']);
    }

    public function test_matching_alias_is_safe(): void
    {
        $result = $this->planner()->plan([
            'target_symbol' => 'App\\Old\\OldClass',
            'replacement_symbol' => 'App\\New\\NewClass',
            'allowed_files' => ['app/Foo/Consumer.php'],
            'consumers' => [
                ['file' => 'app/Foo/Consumer.php', 'alias' => 'OldClass'],
            ],
        ]);

        $this->assertFalse($result['unsafe']);
        $this->assertCount(1, $result['steps']);
    }

    public function test_safe_merge_emits_old_new_fqcn_touched_files_and_test_targets(): void
    {
        $result = $this->planner()->plan([
            'target_symbol' => 'App\\Old\\OldClass',
            'replacement_symbol' => 'App\\New\\NewClass',
            'allowed_files' => ['app/Foo/Consumer.php', 'tests/Unit/Foo/ConsumerTest.php'],
            'consumers' => [
                ['file' => 'app/Foo/Consumer.php'],
                ['file' => 'tests/Unit/Foo/ConsumerTest.php'],
            ],
        ]);

        $this->assertSame('App\\Old\\OldClass', $result['old_fqcn']);
        $this->assertSame('App\\New\\NewClass', $result['new_fqcn']);
        $this->assertSame(['app/Foo/Consumer.php', 'tests/Unit/Foo/ConsumerTest.php'], $result['touched_files']);
        $this->assertSame(['tests/Unit/Foo/ConsumerTest.php'], $result['test_targets']);
        $this->assertSame([], $result['unsafe_rewrite']);
    }

    public function test_dynamic_class_reference_is_unsafe(): void
    {
        $result = $this->planner()->plan([
            'target_symbol' => 'App\\Old\\OldClass',
            'replacement_symbol' => 'App\\New\\NewClass',
            'allowed_files' => ['app/Foo/Consumer.php'],
            'consumers' => [
                ['file' => 'app/Foo/Consumer.php', 'dynamic_reference' => true],
            ],
        ]);

        $this->assertTrue($result['unsafe']);
        $this->assertContains('dynamic_class_reference:app/Foo/Consumer.php', $result['unsafe_rewrite']);
        $this->assertSame([], $result['steps']);
    }

    public function test_plan_hash_is_deterministic_for_identical_input(): void
    {
        $input = [
            'target_symbol' => 'App\\Old\\OldClass',
            'replacement_symbol' => 'App\\New\\NewClass',
            'allowed_files' => ['app/Foo/Consumer.php'],
            'consumers' => [['file' => 'app/Foo/Consumer.php']],
        ];

        $first = $this->planner()->plan($input);
        $second = $this->planner()->plan($input);

        $this->assertSame($first['plan_hash'], $second['plan_hash']);
        $this->assertNotSame('', $first['plan_hash']);
    }

    public function test_plan_hash_differs_for_different_input(): void
    {
        $a = $this->planner()->plan([
            'target_symbol' => 'App\\Old\\OldClass',
            'replacement_symbol' => 'App\\New\\NewClass',
            'allowed_files' => ['app/Foo/Consumer.php'],
            'consumers' => [['file' => 'app/Foo/Consumer.php']],
        ]);
        $b = $this->planner()->plan([
            'target_symbol' => 'App\\Old\\OldClass',
            'replacement_symbol' => 'App\\New\\Different',
            'allowed_files' => ['app/Foo/Consumer.php'],
            'consumers' => [['file' => 'app/Foo/Consumer.php']],
        ]);

        $this->assertNotSame($a['plan_hash'], $b['plan_hash']);
    }

    // ── AC2: rewrite batches are capped by risk and include reverse_mapping ──

    public function test_reverse_mapping_present_for_changed_symbol(): void
    {
        $plan = new AtlasSelfConstructionSimplificationImportRewritePlan;
        $result = $plan->plan([
            'target_symbol' => 'App\\OldClass',
            'replacement_symbol' => 'App\\NewClass',
            'consumers' => [['file' => 'app/Services/Foo.php']],
            'allowed_files' => ['app/Services/Foo.php'],
        ]);

        $this->assertArrayHasKey('reverse_mapping', $result);
        $this->assertSame('App\\OldClass', $result['reverse_mapping']['App\\NewClass']);
    }

    public function test_risk_bound_batches_present(): void
    {
        $plan = new AtlasSelfConstructionSimplificationImportRewritePlan;
        $result = $plan->plan([
            'target_symbol' => 'App\\OldClass',
            'replacement_symbol' => 'App\\NewClass',
            'consumers' => [['file' => 'app/Services/Foo.php']],
            'allowed_files' => ['app/Services/Foo.php'],
            'risk_bound_batch_size' => 5,
        ]);

        $this->assertArrayHasKey('risk_bound_batches', $result);
        $this->assertIsArray($result['risk_bound_batches']);
    }

    // ── AC3: config string rewrites require behavior_parity_command ──

    public function test_config_string_rewrite_without_parity_command_blocked(): void
    {
        $plan = new AtlasSelfConstructionSimplificationImportRewritePlan;
        $result = $plan->plan([
            'target_symbol' => 'App\\OldClass',
            'replacement_symbol' => 'App\\NewClass',
            'config_string_occurrences' => [
                ['file' => 'config/app.php', 'before' => 'App\\OldClass', 'after' => 'App\\NewClass'],
            ],
            'allowed_files' => ['config/app.php'],
        ]);

        $this->assertContains('missing_behavior_parity_command', $result['blockers']);
    }

    public function test_config_string_rewrite_with_parity_command_safe(): void
    {
        $plan = new AtlasSelfConstructionSimplificationImportRewritePlan;
        $result = $plan->plan([
            'target_symbol' => 'App\\OldClass',
            'replacement_symbol' => 'App\\NewClass',
            'config_string_occurrences' => [
                ['file' => 'config/app.php', 'before' => 'App\\OldClass', 'after' => 'App\\NewClass'],
            ],
            'allowed_files' => ['config/app.php'],
            'behavior_parity_command' => 'php artisan test',
        ]);

        $this->assertNotContains('missing_behavior_parity_command', $result['blockers']);
        $this->assertSame('php artisan test', $result['behavior_parity_command']);
    }

    // ── AC4: ambiguous or missing target mappings are blocked with stable blocker codes ──

    public function test_missing_target_symbol_blocked(): void
    {
        $plan = new AtlasSelfConstructionSimplificationImportRewritePlan;
        $result = $plan->plan([
            'target_symbol' => '',
            'replacement_symbol' => 'App\\NewClass',
            'consumers' => [],
            'allowed_files' => [],
        ]);

        $this->assertTrue($result['unsafe']);
        $this->assertContains('missing_target_or_replacement_symbol', $result['blockers']);
    }
}
