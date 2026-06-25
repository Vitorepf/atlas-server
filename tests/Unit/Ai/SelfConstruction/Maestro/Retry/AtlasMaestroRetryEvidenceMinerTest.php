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
        $this->assertSame($row->observedAttempts, $row->observedSuccesses + $row->observedFailures);
        $this->assertSame(3, $row->observedAttempts);
        $this->assertSame(2, $row->observedSuccesses);
        $this->assertSame(1, $row->observedFailures);

        $flat = (string) json_encode($row->toArray());
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
        $this->assertSame(2, $facts[0]->observedAttempts);
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
}
