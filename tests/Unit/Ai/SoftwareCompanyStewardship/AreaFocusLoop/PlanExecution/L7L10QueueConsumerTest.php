<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop\PlanExecution;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\PlanExecution\L7L10QueueConsumer;
use PHPUnit\Framework\TestCase;

final class L7L10QueueConsumerTest extends TestCase
{
    private L7L10QueueConsumer $consumer;

    /** @var array<string,array{0:int,1:int}> small bands for focused logic tests */
    private array $bands = ['L7' => [83, 84], 'L8' => [85, 86]];

    protected function setUp(): void
    {
        $this->consumer = new L7L10QueueConsumer();
    }

    private function doc(array $rows): string
    {
        $table = "| Slice | Entrega | Aceite | Guarda |\n| --- | --- | --- | --- |\n";
        foreach ($rows as $r) {
            $table .= '| '.$r[0].' | '.$r[1].' | '.$r[2].' | '.$r[3]." |\n";
        }

        return "---\nid: test-l7l10\ntitle: Test\n---\n\n## 6. Decomposicao em slices ordenados\n\n".$table."\n";
    }

    public function test_valid_queue_buckets_by_level_and_ignores_out_of_range(): void
    {
        $md = $this->doc([
            ['S1', 'out of range', 'acc', 'g'],   // below band => ignored
            ['S83', 'deliver 83', 'acc 83', 'g'],
            ['S84', 'deliver 84', 'acc 84', 'g'],
            ['S85', 'deliver 85', 'acc 85', 'g'],
            ['S86', 'deliver 86', 'acc 86', 'g'],
            ['S200', 'above range', 'acc', 'g'],  // above band => ignored
        ]);

        $r = $this->consumer->consume($md, $this->bands);

        $this->assertSame('valid', $r['status']);
        $this->assertSame([], $r['bad']);
        $this->assertSame(2, $r['levels']['L7']);
        $this->assertSame(2, $r['levels']['L8']);
        $this->assertSame(4, $r['total']);
        $this->assertSame(['min' => 83, 'max' => 86], $r['range']);
    }

    public function test_malformed_slice_missing_acceptance_is_bad(): void
    {
        $md = $this->doc([
            ['S83', 'deliver 83', 'acc 83', 'g'],
            ['S84', 'deliver 84', '', 'g'],   // empty acceptance => malformed
            ['S85', 'deliver 85', 'acc 85', 'g'],
            ['S86', 'deliver 86', 'acc 86', 'g'],
        ]);

        $r = $this->consumer->consume($md, $this->bands);

        $this->assertSame('invalid', $r['status']);
        $this->assertContains('S84:malformed_missing_delivery_or_acceptance', $r['bad']);
        $this->assertSame(1, $r['levels']['L7']); // only S83 counted
    }

    public function test_missing_slice_is_reported_as_gap(): void
    {
        $md = $this->doc([
            ['S83', 'deliver 83', 'acc 83', 'g'],
            ['S84', 'deliver 84', 'acc 84', 'g'],
            // S85 missing
            ['S86', 'deliver 86', 'acc 86', 'g'],
        ]);

        $r = $this->consumer->consume($md, $this->bands);

        $this->assertSame('invalid', $r['status']);
        $this->assertContains('S85:missing', $r['bad']);
    }

    public function test_extract_child_doc_round_trips_to_the_same_in_range_queue(): void
    {
        $md = $this->doc([
            ['S1', 'out of range', 'acc', 'g'],
            ['S83', 'deliver 83', 'acc 83a ; acc 83b', 'guard 83'],
            ['S84', 'deliver 84', 'acc 84', 'g'],
            ['S85', 'deliver 85', 'acc 85', 'g'],
            ['S86', 'deliver 86', 'acc 86', 'g'],
        ]);

        $child = $this->consumer->extractChildDoc($md, 'atlas-l7l10-child', 'L7-L10 child', $this->bands);

        // The child contains only the in-range slices and re-consumes to the same queue.
        $this->assertStringContainsString('| S83 |', $child);
        $this->assertStringNotContainsString('| S1 |', $child);
        $reconsumed = $this->consumer->consume($child, $this->bands);
        $this->assertSame('valid', $reconsumed['status']);
        $this->assertSame(4, $reconsumed['total']);
        $this->assertSame(2, $reconsumed['levels']['L7']);
        $this->assertSame(2, $reconsumed['levels']['L8']);
        $this->assertSame([], $reconsumed['bad']);
    }

    public function test_default_levels_cover_exactly_s83_to_s165(): void
    {
        // Guard the canonical band arithmetic: L7=18, L8=25, L9=20, L10=20, total=83.
        $widths = [];
        foreach (L7L10QueueConsumer::DEFAULT_LEVELS as $level => [$lo, $hi]) {
            $widths[$level] = $hi - $lo + 1;
        }
        $this->assertSame(['L7' => 18, 'L8' => 25, 'L9' => 20, 'L10' => 20], $widths);
        $this->assertSame(83, array_sum($widths));
        $this->assertSame(83, L7L10QueueConsumer::DEFAULT_LEVELS['L7'][0]);
        $this->assertSame(165, L7L10QueueConsumer::DEFAULT_LEVELS['L10'][1]);
    }
}
