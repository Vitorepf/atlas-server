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
}
