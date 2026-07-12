<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Compaction;

use App\Models\AiSessionState;
use App\Models\AtlasLongHorizonCompactionReceipt;
use App\Services\Ai\AiCompactionService;
use App\Services\Ai\LongHorizon\AtlasLongHorizonCanon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesLongHorizonPersistenceTables;
use Tests\TestCase;

final class CompactionRecoverySampleCommandTest extends TestCase
{
    use CreatesLongHorizonPersistenceTables;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->createLongHorizonPersistenceTables();
        $this->createSessionStateTable();
        config()->set('atlas.compaction.recovery_sample_evidence_path', 'atlas/evidence/test-compaction-recovery-samples.jsonl');
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('ai_session_states');
        $this->dropLongHorizonPersistenceTables();

        parent::tearDown();
    }

    public function test_zero_receipts_never_passes_the_recovery_sampler(): void
    {
        $this->assertSame(1, Artisan::call('atlas:compaction:recovery-sample', ['--json' => true]));

        $payload = $this->latestJsonArtisanOutput();

        $this->assertSame('atlas.compaction.recovery_sample.v1', $payload['schema_version']);
        $this->assertSame('insufficient_sample', $payload['status']);
        $this->assertSame(0, $payload['sampled_receipts']);
        $this->assertSame(20, $payload['minimum_samples']);
        $this->assertFalse($payload['passed']);
        Storage::disk('local')->assertMissing('atlas/evidence/test-compaction-recovery-samples.jsonl');
    }

    public function test_sampler_recovers_recent_receipts_and_appends_provider_safe_evidence(): void
    {
        AiSessionState::query()->create([
            'thread_id' => '00000000-0000-0000-0000-00000000f002',
            'session_id' => '00000000-0000-0000-0000-00000000f003',
            'active' => true,
            'objective' => 'MAXF-02 recovery sample',
            'decisions' => array_map(
                static fn (int $i): array => ['id' => 'decision-'.$i, 'text' => 'Decision '.$i.' remains recoverable.'],
                range(1, 20),
            ),
            'open_loops' => [],
            'next_steps' => [],
            'constraints' => [],
        ]);

        foreach (range(1, 20) as $i) {
            app(AiCompactionService::class)->compactForScope([
                'scope_type' => AtlasLongHorizonCanon::SCOPE_TYPE_DEV_SESSION,
                'scope_id' => '00000000-0000-0000-0000-00000000f002',
                'forced_discards' => [
                    ['id' => 'decision-'.$i, 'reason' => AtlasLongHorizonCanon::DISCARDED_REASON_BUDGET_PRESSURE],
                ],
            ]);
        }

        $this->assertSame(20, AtlasLongHorizonCompactionReceipt::query()->count());

        $this->assertSame(0, Artisan::call('atlas:compaction:recovery-sample', ['--json' => true, '--limit' => 20]));

        $payload = $this->latestJsonArtisanOutput();

        $this->assertSame('ok', $payload['status']);
        $this->assertTrue($payload['passed']);
        $this->assertSame(20, $payload['sampled_receipts']);
        $this->assertSame(1.0, $payload['recovery_rate']);
        $this->assertSame(20, data_get($payload, 'by_kind.decision.sampled'));
        $this->assertSame(1.0, data_get($payload, 'by_kind.decision.recovery_rate'));
        $this->assertSame(20, data_get($payload, 'by_scope.dev_session.sampled'));
        $this->assertTrue(data_get($payload, 'policy.read_only'));
        $this->assertFalse(data_get($payload, 'policy.writes_recovered_content'));

        Storage::disk('local')->assertExists('atlas/evidence/test-compaction-recovery-samples.jsonl');
        $line = trim((string) Storage::disk('local')->get('atlas/evidence/test-compaction-recovery-samples.jsonl'));
        $evidence = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('atlas.compaction.recovery_sample.v1', $evidence['schema_version']);
        $this->assertSame(20, $evidence['sampled_receipts']);
        $this->assertSame(1.0, $evidence['recovery_rate']);
        $this->assertArrayNotHasKey('items', $evidence, 'Evidence must not persist recovered raw content.');
    }

    private function latestJsonArtisanOutput(): array
    {
        $raw = trim(Artisan::output());

        return json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
    }

    private function createSessionStateTable(): void
    {
        Schema::dropIfExists('ai_session_states');
        Schema::create('ai_session_states', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('thread_id')->nullable();
            $table->uuid('session_id')->nullable();
            $table->integer('version')->default(1);
            $table->boolean('active')->default(true);
            $table->text('objective')->nullable();
            $table->string('current_phase')->nullable();
            $table->json('decisions')->nullable();
            $table->json('open_loops')->nullable();
            $table->json('next_steps')->nullable();
            $table->json('constraints')->nullable();
            $table->timestamps();
        });
    }
}
