<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\MultiCycle;

use App\Services\Ai\AutonomousEvolution\MultiCycle\AtlasLoopMultiCycleCoordinationProtocol;
use App\Services\Ai\AutonomousEvolution\MultiCycle\ClaimDeniedException;
use PHPUnit\Framework\TestCase;

final class AtlasLoopMultiCycleCoordinationProtocolTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpDir = sys_get_temp_dir().'/atlas-multicycle-journal-'.bin2hex(random_bytes(5));
        mkdir($this->tmpDir, 0775, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tmpDir)) {
            exec('rm -rf '.escapeshellarg($this->tmpDir));
        }

        parent::tearDown();
    }

    public function test_second_cycle_cannot_claim_the_same_subscope(): void
    {
        $protocol = $this->protocol([
            '2026-06-24T08:00:00+00:00',
            '2026-06-24T08:00:01+00:00',
        ]);
        $subScope = ['files' => ['app/A.php', 'app/B.php']];

        $claim = $protocol->claim('cycle-a', $subScope);
        $this->assertSame('cycle-a', $claim['cycle_id']);

        try {
            $protocol->claim('cycle-b', $subScope);
            $this->fail('The second cycle should not win the same sub-scope claim.');
        } catch (ClaimDeniedException $e) {
            $this->assertSame('cycle-a', $e->payload()['holding_cycle_id']);
        }
    }

    public function test_release_removes_current_holder_but_keeps_append_only_journal_records(): void
    {
        $protocol = $this->protocol([
            '2026-06-24T08:00:00+00:00',
            '2026-06-24T08:05:00+00:00',
        ]);
        $subScope = ['files' => ['app/C.php', 'app/D.php']];

        $protocol->claim('cycle-a', $subScope);
        $release = $protocol->release('cycle-a', $subScope, 'done');

        $this->assertSame('released:done', $release['status']);
        $this->assertSame([], $protocol->holders());

        $lines = array_values(array_filter(array_map('trim', explode("\n", (string) file_get_contents($protocol->journalPath())))));
        $this->assertCount(2, $lines);

        $facts = array_map(static fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR), $lines);
        $this->assertSame('claimed', $facts[0]['status']);
        $this->assertSame('released:done', $facts[1]['status']);
    }

    /**
     * @param  list<string>  $timestamps
     */
    private function protocol(array $timestamps): AtlasLoopMultiCycleCoordinationProtocol
    {
        $index = 0;

        return new AtlasLoopMultiCycleCoordinationProtocol(
            journalPath: $this->tmpDir.'/journal.jsonl',
            clock: function () use (&$index, $timestamps): string {
                $value = $timestamps[min($index, count($timestamps) - 1)];
                $index++;

                return $value;
            },
        );
    }
}
