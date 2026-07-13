<?php

namespace Tests\Feature\Ai;

use App\Models\AiTrace;
use App\Models\AtlasLiveActivityPushToken;
use App\Models\AtlasLiveActivityStartToken;
use App\Models\AiStreamEvent;
use App\Services\Ai\Mobile\AtlasLiveActivityPushService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AtlasLiveActivityApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('atlas.token', 'testing-atlas-token-with-enough-length');
        $this->createTables();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('atlas_live_activity_push_tokens');
        Schema::dropIfExists('atlas_live_activity_start_tokens');
        Schema::dropIfExists('ai_traces');

        parent::tearDown();
    }

    public function test_it_registers_a_rotating_activity_token_without_returning_it(): void
    {
        $trace = AiTrace::query()->create([
            'trace_key' => 'mobile:live-activity',
            'status' => 'running',
            'operator_input' => 'test',
            'intent' => 'test',
            'agent_slug' => 'atlas',
        ]);

        $response = $this
            ->withHeader('X-Atlas-Token', 'testing-atlas-token-with-enough-length')
            ->postJson('/ai/live-activities', [
                'trace_id' => $trace->id,
                'activity_id' => 'activity-123',
                'installation_id' => 'install-1234567890',
                'push_token' => 'f00dbabe',
                'environment' => 'sandbox',
                'started_at' => '2026-07-13T21:00:00Z',
                'frequent_updates_enabled' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('registration.trace_id', $trace->id)
            ->assertJsonPath('registration.activity_id', 'activity-123')
            ->assertJsonPath('registration.status', 'active')
            ->assertJsonMissing(['push_token']);

        $token = AtlasLiveActivityPushToken::query()->firstOrFail();
        $this->assertSame('f00dbabe', $token->push_token);
        $this->assertSame('active', $token->status);
        $this->assertNotSame('f00dbabe', $token->getRawOriginal('push_token'));
        $this->assertSame($response->json('registration.id'), $token->id);
    }

    public function test_it_replaces_a_rotated_token_and_invalidates_it_idempotently(): void
    {
        $trace = AiTrace::query()->create([
            'trace_key' => 'mobile:live-activity',
            'status' => 'running',
            'operator_input' => 'test',
            'intent' => 'test',
            'agent_slug' => 'atlas',
        ]);
        $headers = ['X-Atlas-Token' => 'testing-atlas-token-with-enough-length'];

        $this->withHeaders($headers)->postJson('/ai/live-activities', [
            'trace_id' => $trace->id,
            'activity_id' => 'activity-123',
            'installation_id' => 'install-1234567890',
            'push_token' => 'old-token',
            'environment' => 'sandbox',
            'started_at' => '2026-07-13T21:00:00Z',
            'frequent_updates_enabled' => false,
        ])->assertCreated();

        $this->withHeaders($headers)->postJson('/ai/live-activities', [
            'trace_id' => $trace->id,
            'activity_id' => 'activity-123',
            'installation_id' => 'install-1234567890',
            'push_token' => 'new-token',
            'environment' => 'sandbox',
            'started_at' => '2026-07-13T21:00:00Z',
            'frequent_updates_enabled' => true,
        ])->assertOk();

        $this->assertSame(1, AtlasLiveActivityPushToken::query()->count());
        $this->assertSame('new-token', AtlasLiveActivityPushToken::query()->firstOrFail()->push_token);

        $this->withHeaders($headers)
            ->postJson('/ai/live-activities/activity-123/invalidate', [
                'trace_id' => $trace->id,
                'reason' => 'completed',
            ])
            ->assertOk()
            ->assertJsonPath('registration.status', 'invalidated');

        $this->withHeaders($headers)
            ->postJson('/ai/live-activities/activity-123/invalidate', [
                'trace_id' => $trace->id,
                'reason' => 'completed',
            ])
            ->assertOk()
            ->assertJsonPath('registration.status', 'invalidated');
    }

    public function test_terminal_stream_event_projects_a_redacted_activitykit_payload(): void
    {
        $trace = AiTrace::query()->create([
            'trace_key' => 'mobile:live-activity',
            'status' => 'succeeded',
            'operator_input' => 'secreto que nunca entra no push',
            'intent' => 'test',
            'agent_slug' => 'atlas',
        ]);
        $registration = AtlasLiveActivityPushToken::query()->create([
            'trace_id' => $trace->id,
            'activity_id' => 'activity-terminal',
            'installation_id' => 'install-1234567890',
            'push_token' => 'live-token',
            'push_token_hash' => hash('sha256', 'live-token'),
            'environment' => 'sandbox',
            'status' => 'active',
            'started_at' => now()->subMinute(),
            'frequent_updates_enabled' => true,
        ]);
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        openssl_pkey_export($key, $privateKey);
        config()->set('atlas.mobile.live_activities', [
            'enabled' => true,
            'apns_key_id' => 'ABC1234567',
            'apns_team_id' => 'W28WF9A5A2',
            'apns_private_key' => $privateKey,
            'topic' => 'com.vitor.atlas.native.push-type.liveactivity',
            'minimum_update_interval_seconds' => 2,
            'timeout_seconds' => 8,
        ]);
        Http::fake(['https://api.sandbox.push.apple.com/*' => Http::response('', 200)]);

        $event = new AiStreamEvent([
            'trace_id' => $trace->id,
            'event_type' => 'response',
            'metadata' => ['checkpoint' => 'evidence'],
        ]);
        $published = app(AtlasLiveActivityPushService::class)->publish($event);

        $this->assertSame(1, $published);
        $this->assertSame('ended', $registration->refresh()->status);
        Http::assertSent(function (HttpRequest $request): bool {
            $state = data_get($request->data(), 'aps.content-state');

            return $request->url() === 'https://api.sandbox.push.apple.com/3/device/live-token'
                && $request->hasHeader('apns-push-type', 'liveactivity')
                && $request->hasHeader('apns-topic', 'com.vitor.atlas.native.push-type.liveactivity')
                && data_get($request->data(), 'aps.event') === 'end'
                && data_get($state, 'phaseTitle') === 'Resposta pronta'
                && data_get($state, 'finished') === true
                && data_get($request->data(), 'aps.alert.title') === 'Atlas concluiu uma execução'
                && data_get($request->data(), 'aps.alert.body') === 'Resposta pronta'
                && ! str_contains(json_encode($request->data()), 'secreto');
        });
    }

    public function test_it_registers_a_start_token_and_starts_terminal_execution_once(): void
    {
        $headers = ['X-Atlas-Token' => 'testing-atlas-token-with-enough-length'];
        $this->withHeaders($headers)->postJson('/ai/live-activities/start-tokens', [
            'installation_id' => 'install-1234567890',
            'push_token' => 'start-token',
            'environment' => 'sandbox',
        ])->assertCreated()
            ->assertJsonPath('registration.installation_id', 'install-1234567890')
            ->assertJsonMissing(['push_token']);

        $this->assertSame('start-token', AtlasLiveActivityStartToken::query()->firstOrFail()->push_token);
        $trace = AiTrace::query()->create([
            'trace_key' => 'terminal:live-activity',
            'source_type' => 'terminal',
            'status' => 'running',
            'operator_input' => 'segredo',
            'intent' => 'test',
            'agent_slug' => 'atlas',
            'metadata' => ['thread_title' => 'Auditar Atlas Native'],
        ]);
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        openssl_pkey_export($key, $privateKey);
        config()->set('atlas.mobile.live_activities', [
            'enabled' => true, 'apns_key_id' => 'ABC1234567', 'apns_team_id' => 'W28WF9A5A2',
            'apns_private_key' => $privateKey, 'topic' => 'com.vitor.atlas.native.push-type.liveactivity',
            'start_topic' => 'com.vitor.atlas.native.push-type.liveactivity', 'minimum_update_interval_seconds' => 2, 'timeout_seconds' => 8,
        ]);
        Http::fake(['https://api.sandbox.push.apple.com/*' => Http::response('', 200)]);
        $event = new AiStreamEvent(['trace_id' => $trace->id, 'event_type' => 'progress', 'metadata' => ['checkpoint' => 'plan']]);

        $service = app(AtlasLiveActivityPushService::class);
        $this->assertSame(1, $service->startFor($event));
        $this->assertSame(0, $service->startFor($event));
        Http::assertSent(function (HttpRequest $request) use ($trace): bool {
            return $request->url() === 'https://api.sandbox.push.apple.com/3/device/start-token'
                && data_get($request->data(), 'aps.event') === 'start'
                && data_get($request->data(), 'aps.attributes.threadKey') === $trace->id
                && data_get($request->data(), 'aps.attributes.threadTitle') === 'Auditar Atlas Native'
                && ! str_contains(json_encode($request->data()), 'segredo');
        });
    }

    private function createTables(): void
    {
        Schema::create('ai_traces', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('trace_key')->nullable();
            $table->uuid('thread_id')->nullable();
            $table->uuid('session_id')->nullable();
            $table->string('source_type')->nullable();
            $table->uuid('source_id')->nullable();
            $table->string('status')->default('queued');
            $table->text('operator_input')->nullable();
            $table->string('intent')->nullable();
            $table->string('agent_slug')->nullable();
            $table->string('provider')->nullable();
            $table->string('model')->nullable();
            $table->json('skill_versions')->nullable();
            $table->json('context_refs')->nullable();
            $table->string('prompt_hash')->nullable();
            $table->string('response_hash')->nullable();
            $table->text('response_text')->nullable();
            $table->integer('latency_ms')->nullable();
            $table->integer('feedback_score')->nullable();
            $table->string('feedback_action')->nullable();
            $table->text('feedback_comment')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('atlas_live_activity_push_tokens', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('trace_id')->index();
            $table->string('activity_id', 128)->unique();
            $table->string('installation_id', 128)->index();
            $table->text('push_token');
            $table->string('push_token_hash', 128)->index();
            $table->string('environment', 16);
            $table->string('status', 24)->default('active');
            $table->timestamp('started_at');
            $table->timestamp('invalidated_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('last_pushed_at')->nullable();
            $table->boolean('frequent_updates_enabled')->default(false);
            $table->timestamps();
        });

        Schema::create('atlas_live_activity_start_tokens', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('installation_id', 128)->unique();
            $table->text('push_token');
            $table->string('push_token_hash', 128)->index();
            $table->string('environment', 16);
            $table->timestamp('last_seen_at')->nullable();
            $table->uuid('last_started_trace_id')->nullable()->index();
            $table->timestamps();
        });
    }
}
