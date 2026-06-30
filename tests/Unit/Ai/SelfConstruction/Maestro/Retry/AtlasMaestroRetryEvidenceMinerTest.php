<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Maestro\Retry;

use App\Services\Ai\SelfConstruction\Maestro\Retry\AtlasMaestroRetryEvidenceMiner;
use Tests\TestCase;

final class AtlasMaestroRetryEvidenceMinerTest extends TestCase
{
    private function row(array $overrides = []): array
    {
        return $overrides + [
            'task_packet_id' => 'pkt-A',
            'attempt_index' => 1,
            'reshape_fingerprint' => 'fp-1',
            'original_allowed_files' => ['app/Foo.php'],
            'reshaped_allowed_files' => ['app/Foo.php', 'tests/FooTest.php'],
            'decision' => 'allow',
            'policy_reason' => 'ok',
            'give_back_evidence_hash' => str_repeat('a', 64),
            'structural_metadata' => [
                'forbidden_removed' => ['app/Constitution/Frozen.php'],
                'anchors_added' => ['Foo::run'],
                'scope_widened_bool' => true,
            ],
            'seq' => 1,
        ];
    }

    public function test_attempts_equal_successes_plus_failures_no_score_field(): void
    {
        $rows = [
            $this->row(['task_packet_id' => 'pkt-A', 'seq' => 1]),
            $this->row(['task_packet_id' => 'pkt-B', 'seq' => 2]),
            $this->row(['task_packet_id' => 'pkt-C', 'seq' => 3]),
        ];
        $successByPacket = ['pkt-A' => true, 'pkt-B' => false, 'pkt-C' => true];

        $facts = (new AtlasMaestroRetryEvidenceMiner())->mine($rows, $successByPacket);
        $this->assertCount(1, $facts, 'identical structural deltas aggregate into ONE fact');
        $row = $facts[0];
        $this->assertSame($row['observed_attempts'], $row['observed_successes'] + $row['observed_failures']);
        $this->assertSame(3, $row['observed_attempts']);
        $this->assertSame(2, $row['observed_successes']);
        $this->assertSame(1, $row['observed_failures']);

        $flat = (string) json_encode($row);
        $this->assertDoesNotMatchRegularExpression('/"(score|rank|rating|quality)"\s*:/', $flat);
    }

    public function test_structural_delta_aggregates_across_different_filenames(): void
    {
        // Two rows, different filenames, SAME structural delta.
        $rowA = $this->row([
            'task_packet_id' => 'pkt-A',
            'original_allowed_files' => ['app/Foo.php'],
            'reshaped_allowed_files' => ['app/Foo.php', 'tests/FooTest.php'],
            'structural_metadata' => [
                'forbidden_removed' => ['app/Petreo/A.php'],
                'anchors_added' => ['A::run'],
                'scope_widened_bool' => true,
            ],
        ]);
        $rowB = $this->row([
            'task_packet_id' => 'pkt-B',
            'original_allowed_files' => ['app/Bar.php'],
            'reshaped_allowed_files' => ['app/Bar.php', 'tests/BarTest.php'],
            'structural_metadata' => [
                'forbidden_removed' => ['app/Petreo/A.php'],
                'anchors_added' => ['A::run'],
                'scope_widened_bool' => true,
            ],
        ]);
        $facts = (new AtlasMaestroRetryEvidenceMiner())->mine([$rowA, $rowB], ['pkt-A' => true, 'pkt-B' => false]);

        $this->assertCount(1, $facts, 'filename-only differences must collapse to one structural pattern');
        $this->assertSame(2, $facts[0]['observed_attempts']);
    }

    public function test_different_structural_deltas_produce_distinct_facts(): void
    {
        $rowA = $this->row([
            'task_packet_id' => 'pkt-A',
            'structural_metadata' => [
                'forbidden_removed' => [],
                'anchors_added' => ['Foo::run'],
                'scope_widened_bool' => false,
            ],
        ]);
        $rowB = $this->row([
            'task_packet_id' => 'pkt-B',
            'structural_metadata' => [
                'forbidden_removed' => ['app/Petreo/A.php'],
                'anchors_added' => [],
                'scope_widened_bool' => true,
            ],
        ]);

        $facts = (new AtlasMaestroRetryEvidenceMiner())->mine([$rowA, $rowB], ['pkt-A' => true, 'pkt-B' => false]);
        $this->assertCount(2, $facts);
    }

    public function test_regression_after_reshape_emits_fact_without_score_fields(): void
    {
        $min = AtlasMaestroRetryEvidenceMiner::MIN_SAMPLE_FOR_POLICY;
        $rows = [];
        $successMap = [];
        for ($i = 0; $i < $min; $i++) {
            $id = 'pkt-reg-'.$i;
            $rows[] = $this->row(['task_packet_id' => $id, 'seq' => $i + 1]);
            $successMap[$id] = $i === 0; // first succeeds, rest fail → failures > successes
        }

        $facts = (new AtlasMaestroRetryEvidenceMiner())->mine($rows, $successMap);

        $this->assertCount(1, $facts);
        $fact = $facts[0];
        $this->assertTrue($fact['regression_after_reshape'], 'failures > successes on sufficient sample must set regression_after_reshape');
        $this->assertFalse($fact['insufficient_sample']);
        $this->assertDoesNotMatchRegularExpression('/"(score|rank|rating|quality)"\s*:/', (string) json_encode($fact));
    }

    public function test_insufficient_sample_marked_without_policy_decision(): void
    {
        $min = AtlasMaestroRetryEvidenceMiner::MIN_SAMPLE_FOR_POLICY;
        $rows = [];
        $successMap = [];
        for ($i = 0; $i < $min - 1; $i++) {
            $id = 'pkt-ins-'.$i;
            $rows[] = $this->row(['task_packet_id' => $id, 'seq' => $i + 1]);
            $successMap[$id] = false; // all fail, but insufficient data
        }

        $facts = (new AtlasMaestroRetryEvidenceMiner())->mine($rows, $successMap);

        $this->assertCount(1, $facts);
        $fact = $facts[0];
        $this->assertTrue($fact['insufficient_sample'], 'below MIN_SAMPLE_FOR_POLICY must be marked insufficient_sample');
        $this->assertFalse($fact['regression_after_reshape'], 'insufficient sample must not be treated as regression policy');
    }
}
