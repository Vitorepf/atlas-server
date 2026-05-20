<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\LongHorizon;

use App\Models\AtlasMemoryEntry;
use App\Services\Ai\LongHorizon\AtlasLongHorizonCanon;
use App\Services\Ai\LongHorizon\StrategicForgettingService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\CreatesAtlasMemoryEntryTable;
use Tests\TestCase;

class StrategicForgettingServiceTest extends TestCase
{
    use CreatesAtlasMemoryEntryTable;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createAtlasMemoryEntryTable();
    }

    public function test_plans_all_seven_strategic_forgetting_policies(): void
    {
        $now = CarbonImmutable::parse('2026-05-19T12:00:00Z');
        $this->memory('retain', ['metadata' => ['must_keep' => true], 'confidence' => 0.1]);
        $this->memory('compress', ['confidence' => 0.5, 'recorded_at' => $now->subDays(45)]);
        $this->memory('archive', ['confidence' => 0.1, 'recorded_at' => $now->subDays(120), 'last_used_at' => $now->subDays(120)]);
        $this->memory('demote', ['valid_until' => $now->subDay(), 'confidence' => 0.9]);
        $this->memory('expire', ['stale_after' => $now->subDay(), 'confidence' => 0.9]);
        $superseder = $this->memory('newer', ['confidence' => 0.9]);
        $this->memory('supersede', ['superseded_by_id' => $superseder->id]);
        $this->memory('forget', ['privacy_class' => 'secret', 'confidence' => 0.9]);

        $payload = (new StrategicForgettingService)->plan(['now' => $now, 'limit' => 20]);

        $this->assertSame(AtlasLongHorizonCanon::STRATEGIC_FORGETTING_RECEIPT_SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame(StrategicForgettingService::STATUS_READY, $payload['status']);
        foreach (AtlasLongHorizonCanon::STRATEGIC_FORGETTING_POLICIES as $policy) {
            $this->assertGreaterThanOrEqual(1, $payload['summary']['by_policy'][$policy], "missing policy {$policy}");
        }
        $this->assertSame(1, $payload['summary']['human_review_required']);
    }

    public function test_secret_or_requested_deletion_never_executes_delete_in_plan(): void
    {
        $entry = $this->memory('delete me', [
            'privacy_class' => 'secret',
            'metadata' => ['requested_for_deletion' => true],
        ]);

        $payload = (new StrategicForgettingService)->plan(['scope_type' => 'global']);
        $decision = $this->decisionFor($payload, $entry->id);

        $this->assertSame(AtlasLongHorizonCanon::FORGETTING_POLICY_FORGET, $decision['policy']);
        $this->assertTrue($decision['requires_human_review']);
        $this->assertSame('requires_tombstone_and_human_approved_delete', $decision['read_only_effect']);
        $this->assertDatabaseHas('atlas_memory_entries', ['id' => $entry->id]);
    }

    public function test_scope_filter_limits_receipt(): void
    {
        $project = $this->memory('project', ['scope_type' => 'project', 'scope_id' => 'p1']);
        $this->memory('other', ['scope_type' => 'project', 'scope_id' => 'p2']);

        $payload = (new StrategicForgettingService)->plan(['scope_type' => 'project', 'scope_id' => 'p1']);

        $this->assertSame(1, $payload['summary']['total']);
        $this->assertSame($project->id, $payload['decisions'][0]['memory_entry_id']);
    }

    public function test_receipt_hash_is_deterministic(): void
    {
        $now = CarbonImmutable::parse('2026-05-19T12:00:00Z');
        $this->memory('same', ['confidence' => 0.9, 'recorded_at' => $now]);

        $a = (new StrategicForgettingService)->plan(['now' => $now]);
        $b = (new StrategicForgettingService)->plan(['now' => $now]);

        $this->assertSame($a['receipt_hash'], $b['receipt_hash']);
    }

    public function test_invalid_scope_type_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new StrategicForgettingService)->plan(['scope_type' => 'invalid']);
    }

    public function test_command_emits_json(): void
    {
        $this->memory('cmd', ['confidence' => 0.9]);

        $exit = Artisan::call('atlas:long-horizon:strategic-forgetting', ['--json' => true]);

        $this->assertSame(0, $exit);
        $payload = json_decode(Artisan::output(), true);
        $this->assertSame(AtlasLongHorizonCanon::STRATEGIC_FORGETTING_RECEIPT_SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame(StrategicForgettingService::STATUS_READY, $payload['status']);
    }

    /**
     * @param  array<string,mixed>  $overrides
     */
    private function memory(string $title, array $overrides = []): AtlasMemoryEntry
    {
        $now = CarbonImmutable::parse('2026-05-19T12:00:00Z');

        return AtlasMemoryEntry::query()->create(array_merge([
            'memory_type' => 'technical_context',
            'scope_type' => 'global',
            'scope_id' => null,
            'title' => $title,
            'body' => 'redacted body for '.$title,
            'summary' => 'summary',
            'importance' => 3,
            'priority' => 50,
            'confidence' => 0.9,
            'privacy_class' => 'normal',
            'external_ai_allowed' => true,
            'redaction_status' => 'clean',
            'source_type' => 'test',
            'source_id' => sha1($title),
            'source_label' => 'test',
            'status' => 'active',
            'tags' => [],
            'metadata' => [],
            'recorded_at' => $now,
            'last_used_at' => $now,
            'authority_level' => 'verified',
        ], $overrides));
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function decisionFor(array $payload, string $memoryEntryId): array
    {
        foreach ($payload['decisions'] as $decision) {
            if ($decision['memory_entry_id'] === $memoryEntryId) {
                return $decision;
            }
        }

        $this->fail("decision not found for {$memoryEntryId}");
    }
}
