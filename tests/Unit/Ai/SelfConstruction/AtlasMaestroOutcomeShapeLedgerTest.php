<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\Maestro\ClosedLoop\AtlasMaestroOutcomeShapeLedger;
use Tests\TestCase;

final class AtlasMaestroOutcomeShapeLedgerTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        parent::setUp();
        $this->path = sys_get_temp_dir().'/atlas-maestro-shape-ledger-'.bin2hex(random_bytes(5)).'.jsonl';
    }

    protected function tearDown(): void
    {
        foreach ([$this->path, $this->path.'.lock'] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        parent::tearDown();
    }

    public function test_record_appends_factual_line_and_is_idempotent_per_task_outcome(): void
    {
        $ledger = $this->ledger();

        $ledger->record('packet-1', $this->shapeFacts(), 'give_back');
        $ledger->record('packet-1', $this->shapeFacts(['score' => 99, 'quality' => 'high']), 'give_back');

        $lines = file($this->path, FILE_IGNORE_NEW_LINES);
        $this->assertCount(1, $lines);
        $row = json_decode($lines[0], true);
        $this->assertSame('packet-1', $row['task_packet_id']);
        $this->assertSame('give_back', $row['outcome']);
        $this->assertSame(2, $row['allowed_files_count']);
        $this->assertForbiddenKeysAbsent($row);
    }

    public function test_stream_yields_entries_in_append_order_without_forbidden_proxy_keys(): void
    {
        $ledger = $this->ledger();
        $ledger->record('packet-1', $this->shapeFacts(['wave_bucket' => 'w1']), 'delivered');
        $ledger->record('packet-2', $this->shapeFacts(['wave_bucket' => 'w2']), 'rejected');
        $ledger->record('packet-3', $this->shapeFacts(['wave_bucket' => 'w3']), 'stale');

        $rows = iterator_to_array($ledger->stream());

        $this->assertSame(['packet-1', 'packet-2', 'packet-3'], array_column($rows, 'task_packet_id'));
        $this->assertSame(['w1', 'w2', 'w3'], array_column($rows, 'wave_bucket'));
        foreach ($rows as $row) {
            $this->assertForbiddenKeysAbsent($row);
        }
    }

    public function test_known_give_back_root_cause_stored_and_unknown_normalized(): void
    {
        $ledger = $this->ledger();
        $ledger->record('p-known', $this->shapeFacts(['give_back_root_cause' => 'malformed_spec']), 'give_back');
        $ledger->record('p-bogus', $this->shapeFacts(['give_back_root_cause' => 'invented_category']), 'give_back');

        $rows = iterator_to_array($ledger->stream());
        $byId = array_column($rows, null, 'task_packet_id');

        $this->assertSame('malformed_spec', $byId['p-known']['give_back_root_cause']);
        $this->assertSame('unknown', $byId['p-bogus']['give_back_root_cause'],
            'unrecognized root cause must normalize to unknown');
    }

    public function test_different_shape_facts_produce_two_entries_for_same_packet_and_outcome(): void
    {
        $ledger = $this->ledger();
        $ledger->record('packet-x', $this->shapeFacts(['allowed_files_count' => 1]), 'give_back');
        $ledger->record('packet-x', $this->shapeFacts(['allowed_files_count' => 5]), 'give_back');

        $lines = file($this->path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $this->assertCount(2, $lines, 'distinct shape_hash must produce a second entry');
    }

    public function test_shape_hash_is_present_and_is_64_char_hex(): void
    {
        $ledger = $this->ledger();
        $ledger->record('p1', $this->shapeFacts(), 'delivered');

        $rows = iterator_to_array($ledger->stream());
        $this->assertArrayHasKey('shape_hash', $rows[0]);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $rows[0]['shape_hash']);
    }

    private function ledger(): AtlasMaestroOutcomeShapeLedger
    {
        return new AtlasMaestroOutcomeShapeLedger($this->path);
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function shapeFacts(array $overrides = []): array
    {
        return $overrides + [
            'origin_kind' => 'orphan',
            'allowed_files_count' => 2,
            'scope_in_size' => 2,
            'acceptance_criteria_count' => 3,
            'required_evidence_count' => 1,
            'has_tests_path' => true,
            'wave_bucket' => 'w0',
        ];
    }

    /**
     * @param  array<string,mixed>  $row
     */
    private function assertForbiddenKeysAbsent(array $row): void
    {
        foreach (['score', 'quality', 'rank', 'ranking'] as $key) {
            $this->assertArrayNotHasKey($key, $row);
        }
    }

    // ── AC2: task family, worker class, proof result, poison signal ───────────

    public function test_record_stores_task_family_worker_class_proof_result_and_poison_signal(): void
    {
        $ledger = $this->ledger();
        $ledger->record('packet-a', $this->shapeFacts([
            'file_family' => 'SelfConstruction',
            'task_shape' => 'bug-fix',
            'worker_id' => 'claude-muscle-1',
            'proof_command_class' => 'artisan-test',
            'proof_result' => 'passed',
            'poison_signal' => true,
        ]), 'delivered');

        $row = iterator_to_array($ledger->stream())[0];

        $this->assertSame('SelfConstruction', $row['file_family']);
        $this->assertSame('bug-fix', $row['task_shape']);
        $this->assertSame('claude-muscle-1', $row['worker_id']);
        $this->assertSame('artisan-test', $row['proof_command_class']);
        $this->assertSame('passed', $row['proof_result']);
        $this->assertTrue($row['poison_signal']);
    }

    public function test_new_fields_default_to_unknown_or_false_when_absent(): void
    {
        $ledger = $this->ledger();
        $ledger->record('packet-b', $this->shapeFacts(), 'delivered');

        $row = iterator_to_array($ledger->stream())[0];

        $this->assertSame('unknown', $row['file_family']);
        $this->assertSame('unknown', $row['task_shape']);
        $this->assertSame('unknown', $row['worker_id']);
        $this->assertSame('unknown', $row['proof_command_class']);
        $this->assertSame('unknown', $row['proof_result']);
        $this->assertFalse($row['poison_signal']);
    }

    public function test_invalid_proof_result_normalizes_to_unknown(): void
    {
        $ledger = $this->ledger();
        $ledger->record('packet-c', $this->shapeFacts(['proof_result' => 'invented_status']), 'delivered');

        $row = iterator_to_array($ledger->stream())[0];

        $this->assertSame('unknown', $row['proof_result']);
    }

    public function test_quarantine_is_a_valid_outcome(): void
    {
        $ledger = $this->ledger();
        $ledger->record('packet-d', $this->shapeFacts(), 'quarantine');

        $row = iterator_to_array($ledger->stream())[0];

        $this->assertSame('quarantine', $row['outcome']);
    }

    // ── AC4: aggregate counts by task family ───────────────────────────────────

    public function test_aggregate_by_task_family_counts_success_give_back_quarantine_and_false_green_risk(): void
    {
        $ledger = $this->ledger();
        $ledger->record('p1', $this->shapeFacts(['file_family' => 'Alpha']), 'delivered');
        $ledger->record('p2', $this->shapeFacts(['file_family' => 'Alpha']), 'give_back');
        $ledger->record('p3', $this->shapeFacts(['file_family' => 'Alpha']), 'quarantine');
        $ledger->record('p4', $this->shapeFacts(['file_family' => 'Alpha', 'poison_signal' => true]), 'delivered');
        $ledger->record('p5', $this->shapeFacts(['file_family' => 'Beta']), 'delivered');

        $aggregate = $ledger->aggregateByTaskFamily();

        $this->assertSame(2, $aggregate['Alpha']['success_count']);
        $this->assertSame(1, $aggregate['Alpha']['give_back_count']);
        $this->assertSame(1, $aggregate['Alpha']['quarantine_count']);
        $this->assertSame(1, $aggregate['Alpha']['false_green_risk_count']);
        $this->assertSame(4, $aggregate['Alpha']['total']);
        $this->assertSame(1, $aggregate['Beta']['success_count']);
        $this->assertSame(1, $aggregate['Beta']['total']);
    }

    public function test_aggregate_by_task_family_is_empty_for_empty_ledger(): void
    {
        $ledger = $this->ledger();

        $this->assertSame([], $ledger->aggregateByTaskFamily());
    }

    public function test_aggregate_by_task_family_groups_missing_family_as_unknown(): void
    {
        $ledger = $this->ledger();
        $ledger->record('p1', $this->shapeFacts(), 'delivered');

        $aggregate = $ledger->aggregateByTaskFamily();

        $this->assertArrayHasKey('unknown', $aggregate);
        $this->assertSame(1, $aggregate['unknown']['success_count']);
    }

    // ── outcomeShapes: routeable_patterns, repair_patterns, confidence_by_pattern ──

    public function test_outcome_shapes_has_required_keys(): void
    {
        $ledger = new AtlasMaestroOutcomeShapeLedger($this->path);
        $result = $ledger->outcomeShapes();

        $this->assertArrayHasKey('outcome_shapes', $result);
        $this->assertArrayHasKey('routeable_patterns', $result);
        $this->assertArrayHasKey('repair_patterns', $result);
        $this->assertArrayHasKey('confidence_by_pattern', $result);
    }

    public function test_routeable_patterns_for_repeat_successes(): void
    {
        $ledger = new AtlasMaestroOutcomeShapeLedger($this->path);
        for ($i = 0; $i < 5; $i++) {
            $ledger->record('success-'.$i, $this->shapeFacts(['file_family' => 'app/Services', 'worker_id' => 'muscle-1']), 'delivered');
        }
        $result = $ledger->outcomeShapes();

        $this->assertNotEmpty($result['routeable_patterns']);
        $pattern = $result['routeable_patterns'][0];
        $this->assertSame('route_to_worker', $pattern['action']);
        $this->assertArrayHasKey('confidence', $pattern);
    }

    public function test_repair_patterns_for_repeated_failures(): void
    {
        $ledger = new AtlasMaestroOutcomeShapeLedger($this->path);
        for ($i = 0; $i < 5; $i++) {
            $ledger->record('fail-'.$i, $this->shapeFacts(['file_family' => 'app/Brain', 'worker_id' => 'muscle-1', 'give_back_root_cause' => 'malformed_spec']), 'give_back');
        }
        $result = $ledger->outcomeShapes();

        $this->assertNotEmpty($result['repair_patterns']);
        $pattern = $result['repair_patterns'][0];
        $this->assertSame('respec_or_quarantine', $pattern['action']);
        $this->assertSame('malformed_spec', $pattern['defect_type']);
    }

    public function test_confidence_by_pattern_includes_routeable_and_repair(): void
    {
        $ledger = new AtlasMaestroOutcomeShapeLedger($this->path);
        for ($i = 0; $i < 5; $i++) {
            $ledger->record('s-'.$i, $this->shapeFacts(['file_family' => 'app/Good', 'worker_id' => 'muscle-1']), 'delivered');
            $ledger->record('f-'.$i, $this->shapeFacts(['file_family' => 'app/Bad', 'worker_id' => 'muscle-1', 'give_back_root_cause' => 'forbidden_scope']), 'give_back');
        }
        $result = $ledger->outcomeShapes();

        $this->assertNotEmpty($result['confidence_by_pattern']);
        foreach ($result['confidence_by_pattern'] as $pattern => $confidence) {
            $this->assertIsFloat($confidence);
            $this->assertGreaterThanOrEqual(0.0, $confidence);
            $this->assertLessThanOrEqual(1.0, $confidence);
        }
    }

    public function test_outcome_shapes_groups_by_task_family_worker_class_defect_type_and_evidence_status(): void
    {
        $ledger = new AtlasMaestroOutcomeShapeLedger($this->path);
        $ledger->record('t1', $this->shapeFacts(['file_family' => 'app/Foo', 'worker_id' => 'w1']), 'delivered');
        $ledger->record('t2', $this->shapeFacts(['file_family' => 'app/Bar', 'worker_id' => 'w2']), 'give_back');

        $result = $ledger->outcomeShapes();
        $this->assertNotEmpty($result['outcome_shapes']);

        foreach ($result['outcome_shapes'] as $shape) {
            $this->assertArrayHasKey('task_family', $shape);
            $this->assertArrayHasKey('worker_class', $shape);
            $this->assertArrayHasKey('defect_type', $shape);
            $this->assertArrayHasKey('evidence_status', $shape);
        }
    }
}
