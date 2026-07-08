<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneTaskPacketQueueRepository;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Proves atlas:task:blocked-respec-plan is wired through the blocked replacement draft
 * completer: a recoverable blocked packet (objective names a concrete class + test path)
 * yields a can_submit=true draft instead of staying stuck at can_submit=false, while the
 * command remains strictly read-only.
 */
final class AtlasTaskBlockedRespecPlanCommandCompletesDraftsTest extends TestCase
{
    private const TASK_PREFIX = 'atlas/self-construction/agent-control-plane/task-queue';

    private const REGISTRY_PATH = 'atlas/self-construction/task-packet-queue-registry.json';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function seedBlocked(string $id, string $objective): void
    {
        $safe = (string) preg_replace('/[^A-Za-z0-9_\-]/', '_', $id);
        $now = now()->toIso8601String();

        $record = [
            'schema_version' => AgentControlPlaneTaskPacketQueueRepository::SCHEMA_VERSION,
            'task_packet_id' => $id,
            'task_packet_hash' => 'test-hash-'.$safe,
            'enqueued_at' => $now,
            'updated_at' => $now,
            'status' => 'blocked',
            'priority' => 5,
            'tags' => [],
            'metadata' => ['give_back_count' => 0],
            'task_packet' => [
                'task_packet_id' => $id,
                'objective' => $objective,
            ],
            'history' => [['event' => 'enqueued', 'at' => $now, 'status' => 'blocked']],
            'receipts' => [],
        ];

        Storage::disk('local')->put(self::TASK_PREFIX."/task_{$safe}.json", json_encode($record));

        $registry = ['entries' => []];
        if (Storage::disk('local')->exists(self::REGISTRY_PATH)) {
            $registry = json_decode((string) Storage::disk('local')->get(self::REGISTRY_PATH), true) ?? ['entries' => []];
        }
        $registry['entries'][] = [
            'task_packet_id' => $id,
            'task_packet_hash' => 'test-hash-'.$safe,
            'status' => 'blocked',
            'updated_at' => $now,
            'tags' => [],
        ];
        Storage::disk('local')->put(self::REGISTRY_PATH, json_encode($registry));
    }

    /** @return array<string,mixed> */
    private function exec(): array
    {
        Artisan::call('atlas:task:blocked-respec-plan', ['--json' => true]);
        $decoded = json_decode(trim(Artisan::output()), true);

        return is_array($decoded) ? $decoded : [];
    }

    public function test_recoverable_objective_yields_can_submit_true_draft(): void
    {
        $this->seedBlocked(
            'recoverable-001',
            'Implement app/Services/Ai/Foo/AtlasFoo.php and tests/Unit/Ai/Foo/AtlasFooTest.php so it validates input.',
        );

        $output = $this->exec();

        $drafts = $output['replacement_drafts'] ?? [];
        $this->assertNotEmpty($drafts);

        $submittable = array_values(array_filter(
            $drafts,
            static fn (array $d): bool => ($d['can_submit'] ?? false) === true,
        ));

        $this->assertNotEmpty($submittable, 'expected at least one can_submit=true draft from a recoverable objective');
        $this->assertSame([], $submittable[0]['missing_fields']);
    }

    public function test_command_remains_read_only(): void
    {
        $this->seedBlocked(
            'recoverable-002',
            'Implement app/Services/Ai/Bar/AtlasBar.php and tests/Unit/Ai/Bar/AtlasBarTest.php so it validates input.',
        );

        $this->artisan('atlas:task:blocked-respec-plan', ['--json' => true])->assertExitCode(0);

        $safe = 'recoverable-002';
        $raw = (string) Storage::disk('local')->get(self::TASK_PREFIX."/task_{$safe}.json");
        $record = json_decode($raw, true);
        $this->assertSame('blocked', (string) ($record['status'] ?? ''), 'command must not change queue status');

        $registry = json_decode((string) Storage::disk('local')->get(self::REGISTRY_PATH), true);
        $this->assertSame('blocked', (string) ($registry['entries'][0]['status'] ?? ''), 'command must not mutate the registry index');
    }
}
