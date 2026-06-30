<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainHighValueBatchComposer;
use Tests\TestCase;

final class AtlasExternalBrainHighValueBatchComposerTest extends TestCase
{
    private function composer(): AtlasExternalBrainHighValueBatchComposer
    {
        return new AtlasExternalBrainHighValueBatchComposer;
    }

    private function valid(string $label, array $overrides = []): array
    {
        return array_merge([
            'label'               => $label,
            'objective'           => 'Implement '.$label,
            'category'            => 'bug_fix',
            'allowed_files'       => ['app/Services/'.$label.'.php', 'tests/Unit/'.$label.'Test.php'],
            'acceptance_criteria' => ['test passes: php artisan test --filter='.$label],
            'required_evidence'   => ['tests_or_gates_result'],
            'value_mechanism'     => 'fixes_recurring_bug:'.$label,
            'final_score'         => 0.6,
        ], $overrides);
    }

    private function thin(string $label, array $overrides = []): array
    {
        return array_merge([
            'label'               => $label,
            'objective'           => 'Thin task '.$label,
            'category'            => 'test_gate',
            'allowed_files'       => ['app/Services/'.$label.'.php'],  // single file
            'acceptance_criteria' => ['test passes: php artisan test --filter='.$label],  // single criterion
            'required_evidence'   => ['tests_or_gates_result'],
            'value_mechanism'     => 'adds_gate:'.$label,
            'final_score'         => 0.4,
        ], $overrides);
    }

    public function test_schema_constant(): void
    {
        $this->assertSame(
            'atlas.external_brain.high_value_batch_composer.v1',
            AtlasExternalBrainHighValueBatchComposer::SCHEMA,
        );
    }

    public function test_multi_file_capability_task_is_emitted_with_dependency_wave(): void
    {
        $composer = $this->composer();
        $opp = $this->valid('capability-task', [
            'category'      => 'architecture_unlock',
            'allowed_files' => ['app/Services/Foo.php', 'app/Services/Bar.php', 'tests/Unit/FooTest.php'],
        ]);

        $result = $composer->compose([$opp]);

        $this->assertCount(1, $result['emitted']);
        $this->assertCount(0, $result['rejected']);

        $packet = $result['emitted'][0];
        $this->assertSame('capability-task', $packet['task_packet_id']);
        $this->assertSame(1, $packet['dependency_wave']);  // architecture_unlock → wave 1
        $this->assertCount(3, $packet['allowed_files']);
        $this->assertNotEmpty($packet['acceptance_criteria']);
        $this->assertNotEmpty($packet['required_evidence']);
        $this->assertNotEmpty($packet['value_mechanism']);
    }

    public function test_isolated_thin_microtask_is_rejected(): void
    {
        $composer = $this->composer();
        $result   = $composer->compose([$this->thin('lone-thin-1')]);

        $this->assertCount(0, $result['emitted']);
        $this->assertCount(1, $result['rejected']);
        $this->assertSame('thin_microtask_no_grouping_partner', $result['rejected'][0]['reason']);
        $this->assertSame('lone-thin-1', $result['rejected'][0]['label']);
    }

    public function test_two_thin_microtasks_same_category_are_grouped(): void
    {
        $composer = $this->composer();
        $result   = $composer->compose([
            $this->thin('thin-a', ['category' => 'test_gate']),
            $this->thin('thin-b', ['category' => 'test_gate']),
        ]);

        $this->assertCount(1, $result['emitted']);
        $this->assertCount(0, $result['rejected']);
        $this->assertCount(1, $result['grouped']);

        $packet = $result['emitted'][0];
        $this->assertCount(2, $packet['grouped_from']);
        $this->assertContains('thin-a', $packet['grouped_from']);
        $this->assertContains('thin-b', $packet['grouped_from']);
        $this->assertCount(2, $packet['allowed_files']);
        $this->assertCount(2, $packet['acceptance_criteria']);
    }

    public function test_thin_tasks_in_different_categories_are_not_merged(): void
    {
        $composer = $this->composer();
        $result   = $composer->compose([
            $this->thin('thin-gate-1', ['category' => 'test_gate']),
            $this->thin('thin-bug-1',  ['category' => 'bug_fix']),
        ]);

        // Each is alone in its category → both rejected.
        $this->assertCount(0, $result['emitted']);
        $this->assertCount(2, $result['rejected']);
        $reasons = array_column($result['rejected'], 'reason');
        $this->assertContains('thin_microtask_no_grouping_partner', $reasons);
    }

    public function test_opportunity_missing_objective_is_rejected(): void
    {
        $composer = $this->composer();
        $opp = $this->valid('no-objective');
        unset($opp['objective']);

        $result = $composer->compose([$opp]);

        $this->assertCount(0, $result['emitted']);
        $this->assertCount(1, $result['rejected']);
        $this->assertSame('missing_required_fields', $result['rejected'][0]['reason']);
        $this->assertStringContainsString('objective', $result['rejected'][0]['detail']);
    }

    public function test_opportunity_missing_allowed_files_is_rejected(): void
    {
        $composer = $this->composer();
        $opp = $this->valid('no-files', ['allowed_files' => []]);

        $result = $composer->compose([$opp]);

        $this->assertCount(0, $result['emitted']);
        $this->assertSame('missing_required_fields', $result['rejected'][0]['reason']);
        $this->assertStringContainsString('allowed_files', $result['rejected'][0]['detail']);
    }

    public function test_opportunity_missing_value_mechanism_is_rejected(): void
    {
        $composer = $this->composer();
        $opp = $this->valid('no-value', ['value_mechanism' => '']);

        $result = $composer->compose([$opp]);

        $this->assertCount(0, $result['emitted']);
        $this->assertStringContainsString('value_mechanism', $result['rejected'][0]['detail']);
    }

    public function test_allowed_files_collision_is_rejected(): void
    {
        $composer = $this->composer();
        $sharedFile = 'app/Services/Shared.php';

        $first  = $this->valid('first',  ['allowed_files' => [$sharedFile, 'tests/FirstTest.php']]);
        $second = $this->valid('second', ['allowed_files' => [$sharedFile, 'tests/SecondTest.php']]);

        $result = $composer->compose([$first, $second]);

        $this->assertCount(1, $result['emitted']);
        $this->assertSame('first', $result['emitted'][0]['task_packet_id']);

        $this->assertCount(1, $result['rejected']);
        $this->assertSame('second', $result['rejected'][0]['label']);
        $this->assertSame('allowed_files_collision', $result['rejected'][0]['reason']);
        $this->assertStringContainsString($sharedFile, $result['rejected'][0]['detail']);
    }

    public function test_dependency_wave_assignment_by_category(): void
    {
        $composer = $this->composer();
        $result   = $composer->compose([
            $this->valid('arch',    ['category' => 'architecture_unlock']),
            $this->valid('bug',     ['category' => 'bug_fix']),
            $this->valid('docs',    ['category' => 'docs_sync']),
        ]);

        $waves = array_column($result['emitted'], 'dependency_wave', 'task_packet_id');
        $this->assertSame(1, $waves['arch']);
        $this->assertSame(2, $waves['bug']);
        $this->assertSame(3, $waves['docs']);
    }

    public function test_batch_cap_is_respected(): void
    {
        $composer = $this->composer();
        $opps = array_map(
            fn (int $i): array => $this->valid("task-{$i}", ['allowed_files' => ["app/Services/Task{$i}.php", "tests/Task{$i}Test.php"]]),
            range(1, 5),
        );

        $result = $composer->compose($opps, ['max_batch' => 3]);

        $this->assertCount(3, $result['emitted']);
        $this->assertCount(2, array_filter($result['rejected'], static fn (array $r): bool => $r['reason'] === 'batch_cap_reached'));
    }

    public function test_output_has_canonical_keys(): void
    {
        $result = $this->composer()->compose([$this->valid('canonical-test')]);

        foreach (['schema', 'emitted', 'grouped', 'rejected', 'stats'] as $key) {
            $this->assertArrayHasKey($key, $result);
        }
        $this->assertSame(AtlasExternalBrainHighValueBatchComposer::SCHEMA, $result['schema']);
        $this->assertArrayHasKey('emitted_count', $result['stats']);
        $this->assertArrayHasKey('rejected_count', $result['stats']);
    }

    public function test_emitted_packet_has_all_required_fields(): void
    {
        $result = $this->composer()->compose([$this->valid('packet-fields')]);
        $packet = $result['emitted'][0];

        foreach (['task_packet_id', 'objective', 'allowed_files', 'acceptance_criteria', 'required_evidence', 'value_mechanism', 'dependency_wave'] as $field) {
            $this->assertArrayHasKey($field, $packet, "packet missing: {$field}");
        }
        $this->assertNotEmpty($packet['task_packet_id']);
        $this->assertNotEmpty($packet['allowed_files']);
        $this->assertNotEmpty($packet['acceptance_criteria']);
        $this->assertNotEmpty($packet['required_evidence']);
        $this->assertNotEmpty($packet['value_mechanism']);
        $this->assertIsInt($packet['dependency_wave']);
    }

    public function test_empty_input_returns_empty_batch(): void
    {
        $result = $this->composer()->compose([]);

        $this->assertCount(0, $result['emitted']);
        $this->assertCount(0, $result['rejected']);
        $this->assertSame(0, $result['stats']['opportunities_in']);
    }
}
