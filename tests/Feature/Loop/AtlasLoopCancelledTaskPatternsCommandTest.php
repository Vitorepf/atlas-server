<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\Trinity\MaestroToLoop\AtlasLoopCancelledTaskServingReader;
use DateTimeImmutable;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the cancelled-task pattern miner is live at the operator surface: three recent cancelled records that
 * share a reason, gate, and allowed_files path-prefix surface as ONE cluster (member_count >= 3, non-empty
 * shared_prefix) — recurring doomed-packet patterns made visible.
 */
final class AtlasLoopCancelledTaskPatternsCommandTest extends TestCase
{
    public function test_recurring_cancelled_pattern_surfaces_as_a_cluster(): void
    {
        $recent = (new DateTimeImmutable('-1 hour'))->format('c');
        $records = [
            ['task_packet_id' => 'pkt-a', 'reason' => 'repeated_give_back', 'gate' => 'acceptance_not_runnable', 'cancelled_at' => $recent, 'allowed_files' => ['app/Services/Ai/AutonomousEvolution/Brain/AtlasBrainFooService.php']],
            ['task_packet_id' => 'pkt-b', 'reason' => 'repeated_give_back', 'gate' => 'acceptance_not_runnable', 'cancelled_at' => $recent, 'allowed_files' => ['app/Services/Ai/AutonomousEvolution/Brain/AtlasBrainBarService.php']],
            ['task_packet_id' => 'pkt-c', 'reason' => 'repeated_give_back', 'gate' => 'acceptance_not_runnable', 'cancelled_at' => $recent, 'allowed_files' => ['app/Services/Ai/AutonomousEvolution/Brain/AtlasBrainBazService.php']],
        ];

        $this->app->bind(
            AtlasLoopCancelledTaskServingReader::class,
            fn (): AtlasLoopCancelledTaskServingReader => new AtlasLoopCancelledTaskServingReader($records),
        );

        $exit = Artisan::call('atlas:loop:cancelled-task-patterns', ['--json' => true]);
        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exit);
        $this->assertIsArray($decoded['patterns']);
        $this->assertNotEmpty($decoded['patterns']);

        $cluster = $decoded['patterns'][0];
        $this->assertGreaterThanOrEqual(3, $cluster['member_count']);
        $this->assertNotSame('', $cluster['shared_prefix']);
        $this->assertSame('repeated_give_back', $cluster['reason']);
    }
}
