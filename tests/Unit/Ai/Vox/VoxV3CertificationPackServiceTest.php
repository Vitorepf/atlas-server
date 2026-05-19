<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Vox;

use App\Models\AtlasLedgerEvent;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use App\Services\Ai\Vox\Gate\VoxV3CertificationPackService;
use App\Services\Ai\Vox\Gate\VoxV3PromotionGateService;
use App\Services\Ai\Vox\Metrics\VoxMetricsService;
use App\Services\Ai\Vox\Rivals\VoxRivalsRunner;
use App\Services\Ai\Vox\VoxEvidenceService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Unit guarantees for the V3 certification pack:
 *   - canonical hash is stable across two builds of the same machine state
 *   - changing a metric changes the hash
 *   - safety invariants and machine gates stay separate from the human
 *     review path (the pack never declares `v4_unlock_allowed=true`)
 */
final class VoxV3CertificationPackServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropIfExists('atlas_ledger_events');
        Schema::dropIfExists('atlas_vox_rivals_cases');
        (require database_path('migrations/2026_05_05_020000_create_atlas_ledger_events_table.php'))->up();
        (require database_path('migrations/2026_05_20_010000_create_atlas_vox_rivals_cases_table.php'))->up();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('atlas_vox_rivals_cases');
        Schema::dropIfExists('atlas_ledger_events');
        parent::tearDown();
    }

    private function makeService(): VoxV3CertificationPackService
    {
        /** @var VoxEvidenceService $evidence */
        $evidence = $this->app->make(VoxEvidenceService::class);
        $metrics = new VoxMetricsService();
        $gate = new VoxV3PromotionGateService($metrics);
        $rivals = new VoxRivalsRunner($evidence);

        return new VoxV3CertificationPackService($metrics, $gate, $rivals);
    }

    public function test_pack_carries_schema_hash_and_review_required_flags(): void
    {
        $pack = $this->makeService()->build();

        $this->assertSame(VoxV3CertificationPackService::SCHEMA, $pack['schema']);
        $this->assertArrayHasKey('certification_hash', $pack);
        $this->assertStringStartsWith('sha256:', $pack['certification_hash']);
        $this->assertTrue($pack['vitor_review_required']);
        $this->assertFalse($pack['v4_unlock_allowed']);
        $this->assertSame(
            false,
            $pack['promotion_constraints']['v4_unlock_performed_by_this_service'],
        );
    }

    public function test_hash_is_stable_across_two_builds_when_state_does_not_change(): void
    {
        $svc = $this->makeService();
        $a = $svc->build();
        $b = $svc->build();

        $this->assertSame($a['certification_hash'], $b['certification_hash']);
        // generated_at may be identical when both builds land in the same
        // second (Carbon resolution = 1s). The hash MUST stay identical
        // regardless, which is the real invariant we care about.
    }

    public function test_hash_changes_when_a_metric_changes(): void
    {
        $svc = $this->makeService();
        $before = $svc->build()['certification_hash'];

        // Materialise a new VoxIntentCompiled event so metrics->snapshot()
        // sees one more session next build.
        $this->seedIntent('dictation');

        $after = $svc->build()['certification_hash'];
        $this->assertNotSame($before, $after);
    }

    public function test_pack_blocks_when_raw_audio_persisted_above_zero(): void
    {
        // Inject a transcript event that violates the safety invariant.
        $this->seedRawAudioViolation();

        $pack = $this->makeService()->build();
        $this->assertStringContainsString('BLOQUEADO', $pack['readiness_summary']);
        $rawAudio = collect($pack['safety_invariants'])
            ->firstWhere('name', 'raw_audio_persisted_count_zero');
        $this->assertSame('failed', $rawAudio['status']);
        $this->assertSame(1, $rawAudio['observed']);
        $this->assertFalse($pack['v4_unlock_allowed']);
    }

    public function test_machine_safety_invariants_always_listed_with_stable_set(): void
    {
        $pack = $this->makeService()->build();
        $names = array_column($pack['safety_invariants'], 'name');
        foreach (VoxV3CertificationPackService::SAFETY_INVARIANTS as $required) {
            $this->assertContains($required, $names, "missing required invariant: {$required}");
        }
    }

    public function test_review_decisions_list_is_locked(): void
    {
        $this->assertSame(
            ['approved_for_v4_planning', 'rejected', 'needs_more_usage'],
            VoxV3CertificationPackService::REVIEW_DECISIONS,
        );
    }

    private function seedIntent(string $mode): void
    {
        $sessionId = (string) Str::uuid();
        AtlasLedgerEvent::create([
            'event_id' => (string) Str::ulid(),
            'tenant_id' => 'default',
            'operator_id' => 'system',
            'envelope_id' => $sessionId,
            'correlation_id' => $sessionId,
            'event_type' => LedgerEventType::VoxIntentCompiled->value,
            'emitter_stage' => 'atlas.kernel.vox.v0',
            'emitter_version' => 'v1',
            'payload' => [
                'session_id' => $sessionId,
                'intent_id' => (string) Str::uuid(),
                'mode' => $mode,
            ],
            'payload_hash' => hash('sha256', $sessionId.$mode),
            'occurred_at' => CarbonImmutable::now('UTC'),
        ]);
    }

    private function seedRawAudioViolation(): void
    {
        $sessionId = (string) Str::uuid();
        AtlasLedgerEvent::create([
            'event_id' => (string) Str::ulid(),
            'tenant_id' => 'default',
            'operator_id' => 'system',
            'envelope_id' => $sessionId,
            'correlation_id' => $sessionId,
            'event_type' => LedgerEventType::VoxTranscriptReady->value,
            'emitter_stage' => 'atlas.kernel.vox.v0',
            'emitter_version' => 'v1',
            'payload' => [
                'session_id' => $sessionId,
                'raw_pcm_persisted' => true,
            ],
            'payload_hash' => hash('sha256', $sessionId.'raw_audio'),
            'occurred_at' => CarbonImmutable::now('UTC'),
        ]);
    }

    public function test_compute_hash_is_pure_for_caller_supplied_body(): void
    {
        // Two bodies that differ only on generated_at hash equal.
        $body = [
            'schema' => 'x',
            'gate_status' => 'warming_up',
            'metrics_snapshot' => ['summary' => ['total_sessions' => 5]],
        ];
        $h1 = VoxV3CertificationPackService::computeHash($body + ['generated_at' => '2026-01-01T00:00:00Z']);
        $h2 = VoxV3CertificationPackService::computeHash($body + ['generated_at' => '2026-12-31T23:59:59Z']);
        $this->assertSame($h1, $h2);

        // Same body except one counter — hash changes.
        $changed = $body;
        $changed['metrics_snapshot']['summary']['total_sessions'] = 6;
        $h3 = VoxV3CertificationPackService::computeHash($changed);
        $this->assertNotSame($h1, $h3);
    }
}
