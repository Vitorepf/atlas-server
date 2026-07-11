<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Aemor;

use App\Models\AtlasAemorOutcome;
use App\Models\AtlasLedgerEvent;
use App\Services\Ai\Aemor\AtlasAemorRuntimeService;
use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesAemorTables;
use Tests\TestCase;

final class AtlasAemorCloseOutcomeCommandTest extends TestCase
{
    use CreatesAemorTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createAemorTables();
        (require database_path('migrations/2026_05_05_020000_create_atlas_ledger_events_table.php'))->up();
    }

    protected function tearDown(): void
    {
        (require database_path('migrations/2026_05_05_020000_create_atlas_ledger_events_table.php'))->down();
        $this->dropAemorTables();
        parent::tearDown();
    }

    public function test_caller_metrics_without_resolvable_evidence_are_not_verified_and_do_not_auto_promote(): void
    {
        $episodeId = $this->openEpisode();
        $metricsJson = json_encode(['tests_passed' => true, 'attribution_reviewed' => true], JSON_THROW_ON_ERROR);

        $exitCode = Artisan::call('atlas:aemor:close-outcome', [
            '--episode' => $episodeId,
            '--status' => 'succeeded',
            '--summary' => 'Caller claimed green without verifiable evidence.',
            '--evidence' => ['outcome:unverifiable-ref'],
            '--metrics-json' => $metricsJson,
            '--json' => true,
        ]);

        $this->assertSame(0, $exitCode);
        $closePayload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertFalse((bool) ($closePayload['metrics_verified'] ?? true));
        $this->assertSame('caller_claim', $closePayload['metrics_provenance'] ?? null);

        $outcome = AtlasAemorOutcome::query()->firstOrFail();
        $this->assertFalse((bool) data_get($outcome->metrics, 'metrics_verified'));
        $this->assertSame('caller_claim', data_get($outcome->metrics, 'provenance'));
        $this->assertSame(true, data_get($outcome->metrics, 'caller_claims.tests_passed'));
        $this->assertNull(data_get($outcome->metrics, 'tests_passed'));

        $distill = app(AtlasAemorRuntimeService::class)->distill([
            'episode_id' => $episodeId,
            'outcome_id' => $closePayload['outcome_id'],
            'claim' => 'Should stay blocked until evidence resolves.',
            'evidence_refs' => ['outcome:unverifiable-ref'],
        ]);

        $this->assertSame('blocked', $distill['status']);
        $this->assertContains('metrics_not_verified', data_get($distill, 'promotion_gate.blockers', []));
        $this->assertNull(data_get($distill, 'memory_candidate_id'));
        $this->assertNull(data_get($distill, 'memory_delta_id'));
    }

    public function test_resolvable_evidence_verifies_metrics_and_allows_promotion_gate_pass(): void
    {
        $eventId = (string) Str::ulid();
        AtlasLedgerEvent::query()->create([
            'event_id' => $eventId,
            'schema_version' => AtlasEvidenceLedger::SCHEMA_VERSION,
            'tenant_id' => 'default',
            'operator_id' => 'system',
            'envelope_id' => 'env-aemor-fee02',
            'correlation_id' => 'corr-aemor-fee02',
            'event_type' => LedgerEventType::GatePassed->value,
            'emitter_stage' => 'atlas.aemor',
            'emitter_version' => 'v1',
            'payload' => [
                'tests_passed' => true,
                'attribution_reviewed' => true,
                'gate_id' => 'targeted_tests_before_completion',
            ],
            'payload_hash' => hash('sha256', 'fee02-verified-gate'),
            'occurred_at' => now(),
        ]);

        $episodeId = $this->openEpisode();
        $evidenceRef = 'ledger:'.$eventId;

        $exitCode = Artisan::call('atlas:aemor:close-outcome', [
            '--episode' => $episodeId,
            '--status' => 'succeeded',
            '--summary' => 'Verified by ledger gate evidence.',
            '--evidence' => [$evidenceRef],
            '--metrics-json' => json_encode(['tests_passed' => false, 'attribution_reviewed' => false], JSON_THROW_ON_ERROR),
            '--json' => true,
        ]);

        $this->assertSame(0, $exitCode);
        $closePayload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertTrue((bool) ($closePayload['metrics_verified'] ?? false));
        $this->assertSame('evidence_resolved', $closePayload['metrics_provenance'] ?? null);

        $outcome = AtlasAemorOutcome::query()->firstOrFail();
        $this->assertTrue((bool) data_get($outcome->metrics, 'metrics_verified'));
        $this->assertTrue((bool) data_get($outcome->metrics, 'tests_passed'));
        $this->assertTrue((bool) data_get($outcome->metrics, 'attribution_reviewed'));
        $this->assertSame('evidence_resolved', data_get($outcome->metrics, 'provenance'));

        $distill = app(AtlasAemorRuntimeService::class)->distill([
            'episode_id' => $episodeId,
            'outcome_id' => $closePayload['outcome_id'],
            'claim' => 'Run targeted tests before completion.',
            'evidence_refs' => [$evidenceRef],
        ]);

        $this->assertSame('candidate', $distill['status']);
        $this->assertSame('pass', data_get($distill, 'promotion_gate.status'));
        $this->assertNotNull(data_get($distill, 'memory_candidate_id'));
        $this->assertNotNull(data_get($distill, 'memory_delta_id'));
    }

    private function openEpisode(): string
    {
        $payload = app(AtlasAemorRuntimeService::class)->openEpisode([
            'objective' => 'FEE-02 close outcome verification',
            'evidence_refs' => ['episode:fee02'],
        ]);

        return (string) $payload['episode_id'];
    }
}
