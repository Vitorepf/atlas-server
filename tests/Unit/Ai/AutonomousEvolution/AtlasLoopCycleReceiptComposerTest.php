<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\AtlasLoopImpactReceiptService;
use App\Services\Ai\AutonomousEvolution\Receipts\AtlasLoopCycleReceiptComposer;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

final class AtlasLoopCycleReceiptComposerTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_compose_returns_normalized_cycle_receipt_body(): void
    {
        Carbon::setTestNow('2026-06-24T12:00:00+00:00');

        $receipt = (new AtlasLoopCycleReceiptComposer)->compose('cycle-1', $this->sources());

        $this->assertSame(AtlasLoopCycleReceiptComposer::SCHEMA_VERSION, $receipt['schema_version']);
        $this->assertSame('cycle-1', $receipt['cycle_id']);
        $this->assertSame('base123', $receipt['base_commit']);
        $this->assertSame('head456', $receipt['head_commit']);
        $this->assertArrayHasKey('impact', $receipt['facts']);
        $this->assertArrayHasKey('frozen_verdicts', $receipt['facts']);
        $this->assertArrayHasKey('telemetry', $receipt['facts']);
        $this->assertArrayHasKey('feedback', $receipt['facts']);
        $this->assertArrayHasKey('maestro', $receipt['facts']);
        $this->assertSame('2026-06-24T12:00:00+00:00', $receipt['composed_at_iso']);
    }

    public function test_compose_is_deterministic_for_same_inputs_and_frozen_clock(): void
    {
        Carbon::setTestNow('2026-06-24T12:00:00+00:00');
        $composer = new AtlasLoopCycleReceiptComposer;

        $first = json_encode(
            $composer->compose('cycle-1', $this->sources()),
            JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION,
        );
        $second = json_encode(
            $composer->compose('cycle-1', $this->sources()),
            JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION,
        );

        $this->assertSame($first, $second);
    }

    public function test_impact_receipts_preserve_existing_v1_entries_without_mutation(): void
    {
        Carbon::setTestNow('2026-06-24T12:00:00+00:00');
        $sources = $this->sources();

        $receipt = (new AtlasLoopCycleReceiptComposer)->compose('cycle-1', $sources);

        $this->assertSame($sources['impact_receipts'], $receipt['facts']['impact']);
        $this->assertSame([
            'schema_version',
            'category',
            'target_path',
            'impact_score',
            'proposal_hash',
            'commit',
        ], array_keys($receipt['facts']['impact'][0]));
        $this->assertSame(AtlasLoopImpactReceiptService::SCHEMA_VERSION, $receipt['facts']['impact'][0]['schema_version']);
        $this->assertSame('app/A.php', $receipt['facts']['impact'][0]['target_path']);
        $this->assertSame('app/B.php', $receipt['facts']['impact'][1]['target_path']);
    }

    public function test_compose_has_no_disk_or_database_writes(): void
    {
        Carbon::setTestNow('2026-06-24T12:00:00+00:00');
        $tmp = sys_get_temp_dir().'/atlas-cycle-receipt-'.bin2hex(random_bytes(5));
        mkdir($tmp, 0o755, true);
        $cwd = getcwd();

        try {
            chdir($tmp);
            (new AtlasLoopCycleReceiptComposer)->compose('cycle-1', $this->sources());

            $this->assertSame([], array_values(array_diff(scandir($tmp) ?: [], ['.', '..'])));
        } finally {
            if (is_string($cwd)) {
                chdir($cwd);
            }
            @rmdir($tmp);
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function sources(): array
    {
        return [
            'git_contract' => [
                'base_commit' => 'base123',
                'head_commit' => 'head456',
            ],
            'impact_receipts' => [
                [
                    'schema_version' => AtlasLoopImpactReceiptService::SCHEMA_VERSION,
                    'category' => 'runtime',
                    'target_path' => 'app/A.php',
                    'impact_score' => 0.75,
                    'proposal_hash' => 'proposal-a',
                    'commit' => 'commit-a',
                ],
                [
                    'schema_version' => AtlasLoopImpactReceiptService::SCHEMA_VERSION,
                    'category' => 'test',
                    'target_path' => 'app/B.php',
                    'impact_score' => 0.25,
                    'proposal_hash' => 'proposal-b',
                    'commit' => 'commit-b',
                ],
            ],
            'frozen_verdicts' => [
                ['schema_version' => 'atlas.evolution.frozen_judge_verdict.v1', 'passed' => true],
            ],
            'telemetry' => [
                'durations_ms' => ['judge' => 12.5, 'materialize' => 4.0],
                'attempt_count' => 2,
                'provider' => 'codex',
            ],
            'feedback' => [
                'handle' => 'feedback:cycle-1',
                'schema_version' => 'atlas.loop.feedback_handle.v1',
            ],
            'maestro' => [
                'schema_version' => 'atlas.maestro.feedback_handle.v1',
                'status' => 'pending',
            ],
        ];
    }
}
