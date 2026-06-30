<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskPacketQueueRepository;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * atlas:task:blocked-respec-plan is a read-only operator surface.
 * Seeds raw fixture records directly into the fake storage so quality facts can be
 * controlled without running the inspector. Proves: correct JSON output (blocked_counts,
 * retire_only_ids, replacement_drafts) and zero queue mutations after the command runs.
 */
final class AtlasTaskBlockedRespecPlanCommandTest extends TestCase
{
    private const TASK_PREFIX = 'atlas/self-construction/agent-control-plane/task-queue';

    private const REGISTRY_PATH = 'atlas/self-construction/task-packet-queue-registry.json';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function seedBlocked(string $id, array $qualityFacts = [], array $blockingDefs = [], int $giveBackCount = 0): void
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
            'metadata' => ['give_back_count' => $giveBackCount],
            'task_packet' => [
                'task_packet_id' => $id,
                'objective' => "blocked packet {$id}",
                'allowed_files' => ["app/Tmp/{$id}.php"],
                'packet_quality' => [
                    'self_sufficient' => $blockingDefs === [],
                    'blocking_deficiencies' => $blockingDefs,
                    'facts' => array_merge([
                        'already_done' => false,
                        'dormant_cli_arm_proxy' => false,
                        'test_only_has_contract' => false,
                    ], $qualityFacts),
                ],
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

    public function test_json_output_contains_blocked_counts_retire_only_ids_and_replacement_drafts(): void
    {
        $this->seedBlocked('dup-001', ['already_done' => true]);
        $this->seedBlocked('respec-001', [], ['vague_objective']);

        $output = $this->exec();

        $this->assertArrayHasKey('blocked_counts', $output);
        $this->assertArrayHasKey('retire_only_ids', $output);
        $this->assertArrayHasKey('replacement_drafts', $output);
    }

    public function test_retire_only_ids_contains_duplicate_already_done_packets(): void
    {
        $this->seedBlocked('dup-777', ['already_done' => true]);
        $this->seedBlocked('other-001', [], ['vague_objective']);

        $output = $this->exec();

        $this->assertContains('dup-777', $output['retire_only_ids']);
        $this->assertNotContains('other-001', $output['retire_only_ids']);
    }

    public function test_blocked_counts_reflects_classified_families(): void
    {
        $this->seedBlocked('dup-A', ['already_done' => true]);
        $this->seedBlocked('respec-A', [], ['scope_uncovered_allowed_files']);
        $this->seedBlocked('respec-B', [], ['vague_objective']);

        $output = $this->exec();

        $this->assertSame(1, $output['blocked_counts']['duplicate_already_done'] ?? 0);
        $this->assertSame(2, $output['blocked_counts']['respec_candidate'] ?? 0);
    }

    public function test_replacement_drafts_are_ordered_wave_ascending(): void
    {
        $this->seedBlocked('dup-W', ['already_done' => true]);
        $this->seedBlocked('respec-W', [], ['vague_objective']);

        $output = $this->exec();

        $drafts = $output['replacement_drafts'] ?? [];
        $this->assertNotEmpty($drafts, 'replacement_drafts must be non-empty');
        $waves = array_column($drafts, 'wave');
        for ($i = 1; $i < count($waves); $i++) {
            $this->assertGreaterThanOrEqual($waves[$i - 1], $waves[$i], 'drafts must be ordered by wave ascending');
        }
    }

    public function test_drafts_without_required_fields_have_can_submit_false(): void
    {
        $this->seedBlocked('dup-CS', ['already_done' => true]);

        $output = $this->exec();

        $drafts = $output['replacement_drafts'] ?? [];
        $this->assertNotEmpty($drafts);
        foreach ($drafts as $draft) {
            $this->assertFalse($draft['can_submit'], 'drafter drafts lack allowed_files/acceptance_criteria/required_evidence — can_submit must be false');
            $this->assertNotEmpty($draft['missing_fields']);
        }
    }

    public function test_command_does_not_mutate_queue_status(): void
    {
        $this->seedBlocked('immutable-001', [], ['vague_objective']);

        $this->artisan('atlas:task:blocked-respec-plan', ['--json' => true])->assertExitCode(0);

        $safe = 'immutable-001';
        $raw = (string) Storage::disk('local')->get(self::TASK_PREFIX."/task_{$safe}.json");
        $record = json_decode($raw, true);
        $this->assertSame('blocked', (string) ($record['status'] ?? ''), 'command must not change queue status');
    }

    public function test_empty_queue_produces_empty_counts_and_no_drafts(): void
    {
        $output = $this->exec();

        $this->assertSame([], $output['retire_only_ids']);
        $this->assertSame([], $output['replacement_drafts']);
        $this->assertSame(0, $output['blocked_count']);
    }
}
