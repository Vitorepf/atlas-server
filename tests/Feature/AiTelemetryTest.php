<?php

namespace Tests\Feature;

use App\Models\AiTelemetryEvent;
use App\Models\AtlasMobileDevice;
use App\Services\Ai\Cli\AtlasCliTelemetry;
use App\Services\Ai\Mobile\MobilePairingService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class AiTelemetryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('atlas.token', 'testing-atlas-token-with-enough-length');
        config()->set('atlas.ai_metrics.telemetry_max_batch', 10);
        config()->set('atlas.ai_metrics.telemetry_max_metadata_bytes', 12000);
        $this->createTables();
    }

    protected function tearDown(): void
    {
        File::delete(storage_path('app/atlas/ai-telemetry/cli-events.jsonl'));
        Schema::dropIfExists('ai_telemetry_events');
        Schema::dropIfExists('atlas_mobile_devices');

        parent::tearDown();
    }

    public function test_server_batch_records_redacted_events_idempotently(): void
    {
        $eventKey = 'mobile:test:send:'.Str::uuid();
        $correlationId = (string) Str::uuid();

        $payload = [
            'events' => [[
                'event_key' => $eventKey,
                'correlation_id' => $correlationId,
                'surface' => 'mobile',
                'runtime' => 'ios',
                'event_name' => 'message_send_pressed',
                'occurred_at_client' => now()->toJSON(),
                'duration_ms' => 42,
                'metadata' => [
                    'screen' => 'atlas_ai_sheet',
                    'api_key' => 'sk-proj-abcdefghijklmnopqrstuvwxyz123456',
                    'nested' => ['header' => 'Authorization: Bearer abcdefghijklmnopqrstuvwxyz'],
                ],
            ]],
        ];

        $this
            ->withHeader('X-Atlas-Token', 'testing-atlas-token-with-enough-length')
            ->postJson('/ai/telemetry/events', $payload)
            ->assertStatus(202)
            ->assertJsonPath('accepted', 1)
            ->assertJsonPath('duplicates', 0)
            ->assertJsonPath('rejected', 0)
            ->assertJsonPath('events.0.event_key', $eventKey)
            ->assertJsonPath('events.0.duplicate', false);

        $event = AiTelemetryEvent::query()->where('event_key', $eventKey)->firstOrFail();
        $encoded = json_encode($event->metadata);

        $this->assertSame('mobile', $event->surface);
        $this->assertSame('message_send_pressed', $event->event_name);
        $this->assertSame(42, $event->duration_ms);
        $this->assertStringContainsString('[redacted]', (string) $encoded);
        $this->assertStringNotContainsString('abcdefghijklmnopqrstuvwxyz123456', (string) $encoded);

        $this
            ->withHeader('X-Atlas-Token', 'testing-atlas-token-with-enough-length')
            ->postJson('/ai/telemetry/events', $payload)
            ->assertStatus(202)
            ->assertJsonPath('accepted', 0)
            ->assertJsonPath('duplicates', 1)
            ->assertJsonPath('rejected', 0)
            ->assertJsonPath('events.0.duplicate', true);

        $this->assertSame(1, AiTelemetryEvent::query()->where('event_key', $eventKey)->count());
    }

    public function test_mobile_telemetry_requires_bearer_and_forces_mobile_surface(): void
    {
        $token = 'mobile-device-token-for-telemetry-test';
        $device = AtlasMobileDevice::query()->create([
            'user_id' => 'vitor',
            'device_label' => 'iPhone Test',
            'platform' => 'ios',
            'app_version' => '1.2.3',
            'device_token_hash' => app(MobilePairingService::class)->hashSecret($token),
            'notification_permissions' => 'granted',
            'metadata' => [],
        ]);

        $eventKey = 'mobile:test:background:'.Str::uuid();

        $this
            ->postJson('/v1/mobile/telemetry/events', [
                'events' => [[
                    'event_key' => $eventKey,
                    'surface' => 'cli',
                    'runtime' => 'mac_cli',
                    'event_name' => 'app_backgrounded_during_trace',
                    'metadata' => ['reason' => 'test'],
                ]],
            ])
            ->assertUnauthorized();

        $this
            ->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/v1/mobile/telemetry/events', [
                'events' => [[
                    'event_key' => $eventKey,
                    'surface' => 'cli',
                    'runtime' => 'mac_cli',
                    'event_name' => 'app_backgrounded_during_trace',
                    'metadata' => ['reason' => 'test'],
                ]],
            ])
            ->assertStatus(202)
            ->assertJsonPath('accepted', 1);

        $event = AiTelemetryEvent::query()->where('event_key', $eventKey)->firstOrFail();

        $this->assertSame('mobile', $event->surface);
        $this->assertSame('ios', $event->runtime);
        $this->assertSame('1.2.3', $event->app_version);
        $this->assertSame($device->id, $event->metadata['atlas_mobile_device_id']);
    }

    public function test_validation_rejects_invalid_events_before_persisting(): void
    {
        $this
            ->withHeader('X-Atlas-Token', 'testing-atlas-token-with-enough-length')
            ->postJson('/ai/telemetry/events', [
                'events' => [[
                    'event_key' => 'invalid-event',
                    'surface' => 'mobile',
                ]],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('events.0.event_name');

        $this->assertSame(0, AiTelemetryEvent::query()->count());
    }

    public function test_cli_telemetry_records_terminal_events_without_raw_workspace_path(): void
    {
        config()->set('atlas.version', 'test-cli-version');

        $workspace = sys_get_temp_dir().'/atlas-cli-telemetry-'.Str::random(8);
        File::ensureDirectoryExists($workspace);
        $correlationId = (string) Str::uuid();

        try {
            app(AtlasCliTelemetry::class)->interactionSubmitted(
                $correlationId,
                'explique o estado do atlas',
                $workspace,
                null,
                'claude_cli',
                'orquestrador',
                'direct',
                'read',
                false,
                true,
            );

            $event = AiTelemetryEvent::query()
                ->where('event_name', 'cli_message_submitted')
                ->firstOrFail();

            $encodedMetadata = json_encode($event->metadata, JSON_UNESCAPED_SLASHES);

            $this->assertSame('cli', $event->surface);
            $this->assertSame('mac_cli', $event->runtime);
            $this->assertSame('test-cli-version', $event->cli_version);
            $this->assertSame($correlationId, $event->correlation_id);
            $this->assertSame('claude_cli', $event->provider);
            $this->assertSame('orquestrador', $event->agent_slug);
            $this->assertSame('chars', $event->unit);
            $this->assertArrayHasKey('workspace_hash', $event->metadata);
            $this->assertStringNotContainsString($workspace, (string) $encodedMetadata);
        } finally {
            File::deleteDirectory($workspace);
        }
    }

    public function test_cli_telemetry_spools_when_telemetry_table_is_missing(): void
    {
        $path = storage_path('app/atlas/ai-telemetry/cli-events.jsonl');
        File::delete($path);
        Schema::dropIfExists('ai_telemetry_events');

        app(AtlasCliTelemetry::class)->record('cli_spool_smoke', [
            'metadata' => ['phase' => 'test'],
        ]);

        $this->assertFileExists($path);
        $this->assertStringContainsString('cli_spool_smoke', File::get($path));
    }

    private function createTables(): void
    {
        Schema::dropIfExists('ai_telemetry_events');
        Schema::dropIfExists('atlas_mobile_devices');

        Schema::create('ai_telemetry_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('event_key', 180)->unique();
            $table->uuid('correlation_id')->nullable()->index();
            $table->uuid('trace_id')->nullable()->index();
            $table->uuid('thread_id')->nullable()->index();
            $table->uuid('session_id')->nullable()->index();
            $table->uuid('ai_job_id')->nullable()->index();
            $table->uuid('ai_job_attempt_id')->nullable()->index();
            $table->uuid('client_id')->nullable()->index();
            $table->string('surface', 24);
            $table->string('runtime', 32)->nullable();
            $table->string('app_version', 64)->nullable();
            $table->string('cli_version', 64)->nullable();
            $table->string('provider', 80)->nullable();
            $table->string('model', 120)->nullable();
            $table->string('agent_slug', 120)->nullable();
            $table->string('event_name', 100);
            $table->string('event_phase', 60)->nullable();
            $table->timestamp('occurred_at_client')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->integer('duration_ms')->nullable();
            $table->decimal('numeric_value', 14, 4)->nullable();
            $table->string('unit', 32)->nullable();
            $table->json('metadata')->nullable();
            $table->json('privacy')->nullable();
            $table->unsignedSmallInteger('schema_version')->default(1);
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('atlas_mobile_devices', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('user_id')->default('vitor')->index();
            $table->string('device_label', 80);
            $table->string('platform', 16);
            $table->string('app_version', 32)->nullable();
            $table->string('os_version', 64)->nullable();
            $table->text('expo_push_token')->nullable();
            $table->string('push_token_hash', 128)->nullable()->index();
            $table->string('device_token_hash', 128)->unique();
            $table->string('notification_permissions', 32)->default('unknown');
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('paired_at')->nullable();
            $table->timestamp('revoked_at')->nullable()->index();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }
}
