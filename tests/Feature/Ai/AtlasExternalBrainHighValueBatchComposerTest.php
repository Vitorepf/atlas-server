<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainHighValueBatchComposer;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainHighValueBatchComposerTest extends TestCase
{
    private AtlasExternalBrainHighValueBatchComposer $composer;

    protected function setUp(): void
    {
        $this->composer = new AtlasExternalBrainHighValueBatchComposer;
    }

    private function compose(array $opps, array $opts = []): array
    {
        return $this->composer->compose($opps, $opts);
    }

    private function opp(array $overrides = []): array
    {
        return array_merge([
            'label'              => 'opp-'.uniqid(),
            'objective'          => 'Improve the reliability of X',
            'category'           => 'bug_fix',
            'allowed_files'      => ['app/Services/Foo.php', 'tests/Feature/FooTest.php'],
            'acceptance_criteria' => ['Runnable test proves Y', 'Runnable test proves Z'],
            'required_evidence'  => ['tests_or_gates_result'],
            'value_mechanism'    => 'runtime_continuity:reduces_failures',
            'final_score'        => 0.8,
        ], $overrides);
    }

    // ── AC1: diversity + dependency order + strategic theme ───────────────────

    public function test_batch_with_multiple_categories_is_diverse(): void
    {
        $result = $this->compose([
            $this->opp(['label' => 'a', 'category' => 'bug_fix']),
            $this->opp(['label' => 'b', 'category' => 'architecture_unlock',
                'allowed_files' => ['app/Services/Bar.php', 'tests/Feature/BarTest.php']]),
        ]);

        $this->assertTrue($result['strategic_diversity']['is_diverse']);
        $this->assertGreaterThanOrEqual(2, $result['strategic_diversity']['distinct_categories']);
    }

    public function test_dependency_wave_order_reflects_category_priority(): void
    {
        $result = $this->compose([
            $this->opp(['label' => 'a', 'category' => 'architecture_unlock']),
            $this->opp(['label' => 'b', 'category' => 'bug_fix',
                'allowed_files' => ['app/Services/Bar.php', 'tests/Feature/BarTest.php']]),
        ]);

        $waves = [];
        foreach ($result['emitted'] as $packet) {
            $waves[$packet['category']] = $packet['dependency_wave'];
        }
        $this->assertSame(1, $waves['architecture_unlock']);
        $this->assertSame(2, $waves['bug_fix']);
    }

    public function test_batch_thesis_is_non_empty_string(): void
    {
        $result = $this->compose([$this->opp()]);

        $this->assertIsString($result['batch_thesis']);
        $this->assertNotEmpty($result['batch_thesis']);
        $this->assertStringContainsString('wave', $result['batch_thesis']);
    }

    public function test_batch_thesis_mentions_task_count(): void
    {
        $result = $this->compose([
            $this->opp(['label' => 'a']),
            $this->opp(['label' => 'b', 'allowed_files' => ['app/Services/Bar.php', 'tests/Feature/BarTest.php']]),
        ]);

        $this->assertStringContainsString('2', $result['batch_thesis']);
    }

    public function test_dependency_chain_summary_shows_ordered_waves(): void
    {
        $result = $this->compose([
            $this->opp(['label' => 'a', 'category' => 'test_gate']),
            $this->opp(['label' => 'b', 'category' => 'docs_sync',
                'allowed_files' => ['app/Services/Bar.php', 'tests/Feature/BarTest.php']]),
        ]);

        $chain = $result['dependency_chain_summary'];
        $this->assertContains(1, $chain['waves_present']);
        $this->assertContains(3, $chain['waves_present']);
        $this->assertStringContainsString('wave 1', $chain['chain_description']);
    }

    // ── AC2: rejections — low-value, duplicate, test-only, template ───────────

    public function test_opportunity_missing_objective_is_rejected(): void
    {
        $result = $this->compose([$this->opp(['objective' => ''])]);

        $this->assertCount(0, $result['emitted']);
        $this->assertSame('missing_required_fields', $result['rejected'][0]['reason']);
        $this->assertStringContainsString('objective', $result['rejected'][0]['detail']);
    }

    public function test_test_only_allowed_files_missing_impl_path_is_rejected(): void
    {
        $result = $this->compose([$this->opp(['allowed_files' => ['tests/Feature/FooTest.php']])]);

        $this->assertCount(0, $result['emitted']);
        $reasons = array_column($result['rejected'], 'reason');
        $this->assertContains('missing_required_fields', $reasons);
        $details = implode(',', array_column($result['rejected'], 'detail'));
        $this->assertStringContainsString('allowed_files_impl_path', $details);
    }

    public function test_impl_only_allowed_files_missing_test_path_is_rejected(): void
    {
        $result = $this->compose([$this->opp(['allowed_files' => ['app/Services/Foo.php']])]);

        $this->assertCount(0, $result['emitted']);
        $details = implode(',', array_column($result['rejected'], 'detail'));
        $this->assertStringContainsString('allowed_files_test_path', $details);
    }

    public function test_generic_value_mechanism_without_concrete_evidence_is_rejected(): void
    {
        $result = $this->compose([$this->opp([
            'value_mechanism'   => 'general',
            'concrete_evidence' => '',
        ])]);

        $this->assertCount(0, $result['emitted']);
        $this->assertSame('generic_value_mechanism', $result['rejected'][0]['reason']);
    }

    public function test_duplicate_allowed_files_collision_is_rejected(): void
    {
        $file = 'app/Services/Foo.php';
        $result = $this->compose([
            $this->opp(['label' => 'a', 'allowed_files' => [$file, 'tests/Feature/FooTest.php']]),
            $this->opp(['label' => 'b', 'allowed_files' => [$file, 'tests/Feature/BarTest.php']]),
        ]);

        $reasons = array_column($result['rejected'], 'reason');
        $this->assertContains('allowed_files_collision', $reasons);
    }

    public function test_template_farm_overflow_is_rejected(): void
    {
        // 5 items with same (category, top-dir-prefix) → > 50% → farm detected
        $make = fn (string $id) => $this->opp([
            'label'         => 'farm-'.$id,
            'category'      => 'bug_fix',
            'allowed_files' => ["app/Services/Farm/Cap{$id}.php", "tests/Feature/Farm/Cap{$id}Test.php"],
        ]);

        $opps = array_map($make, ['A', 'B', 'C', 'D', 'E']);
        $result = $this->compose($opps);

        $reasons = array_column($result['rejected'], 'reason');
        $this->assertContains('template_farm', $reasons);
    }

    public function test_thin_microtask_without_grouping_partner_is_rejected(): void
    {
        $result = $this->compose([$this->opp([
            'label'              => 'isolated-thin',
            'acceptance_criteria' => ['Only one criterion'],
        ])]);

        $reasons = array_column($result['rejected'], 'reason');
        $this->assertContains('thin_microtask_no_grouping_partner', $reasons);
    }

    // ── AC3: emitted packets include impl/test files + acceptance summary ─────

    public function test_emitted_packet_includes_impl_and_test_allowed_files(): void
    {
        $result = $this->compose([$this->opp()]);

        $this->assertNotEmpty($result['emitted']);
        $files = $result['emitted'][0]['allowed_files'];

        $hasImpl = (bool) array_filter($files, fn ($f) => str_starts_with($f, 'app/'));
        $hasTest = (bool) array_filter($files, fn ($f) => str_starts_with($f, 'tests/'));
        $this->assertTrue($hasImpl, 'Emitted packet must include an impl file');
        $this->assertTrue($hasTest, 'Emitted packet must include a test file');
    }

    public function test_emitted_packet_includes_acceptance_criteria(): void
    {
        $result = $this->compose([$this->opp()]);

        $this->assertNotEmpty($result['emitted'][0]['acceptance_criteria']);
    }

    public function test_emitted_packet_includes_required_evidence(): void
    {
        $result = $this->compose([$this->opp()]);

        $this->assertNotEmpty($result['emitted'][0]['required_evidence']);
    }

    public function test_emitted_packet_has_all_required_keys(): void
    {
        $result = $this->compose([$this->opp()]);

        $packet = $result['emitted'][0];
        foreach (['task_packet_id', 'objective', 'category', 'allowed_files',
                  'acceptance_criteria', 'required_evidence', 'value_mechanism',
                  'dependency_wave'] as $key) {
            $this->assertArrayHasKey($key, $packet, "Missing key: {$key}");
        }
    }

    // ── AC4: pure and deterministic ───────────────────────────────────────────

    public function test_output_is_deterministic(): void
    {
        $opps = [$this->opp(['label' => 'fixed-label'])];

        $a = $this->compose($opps);
        $b = $this->compose($opps);

        $this->assertSame(json_encode($a), json_encode($b));
    }

    public function test_schema_is_correct(): void
    {
        $result = $this->compose([]);

        $this->assertSame(AtlasExternalBrainHighValueBatchComposer::SCHEMA, $result['schema']);
    }

    public function test_empty_batch_thesis_says_empty(): void
    {
        $result = $this->compose([]);

        $this->assertStringContainsString('Empty batch', $result['batch_thesis']);
    }
}
