<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Brain2;

use App\Models\AiJob;
use App\Models\AiJobAttempt;
use App\Models\AiTrace;
use App\Services\Ai\AtlasDecide\AiDecisionReceiptRefreshService;
use App\Services\Ai\Kernel\Decision\DecisionReceiptIssuer;
use App\Services\Ai\Kernel\Decision\DecisionReceiptRuntimeGuard;
use App\Services\Ai\Kernel\Envelope\OperationEnvelopeFactory;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Expiry is an authority boundary. A historical transport cannot be silently
 * re-decided from mutable job state until a durable, independently-bound
 * renewal protocol exists.
 */
final class AiDecisionReceiptRefreshServiceHardeningTest extends TestCase
{
    private AiDecisionReceiptRefreshService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new AiDecisionReceiptRefreshService;
    }

    /**
     * @param  callable():mixed  $callback
     */
    private function withRefreshPersistence(callable $callback): mixed
    {
        $createdJobs = false;
        $createdAttempts = false;
        $createdTraces = false;

        if (! Schema::hasTable('ai_traces')) {
            Schema::create('ai_traces', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('trace_key')->nullable()->unique();
                $table->string('source_type')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();
            });
            $createdTraces = true;
        }

        if (! Schema::hasTable('ai_jobs')) {
            Schema::create('ai_jobs', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('trace_id')->nullable();
                $table->string('provider')->nullable();
                $table->string('model')->nullable();
                $table->text('input_text')->nullable();
                $table->text('prompt')->nullable();
                $table->json('payload')->nullable();
                $table->json('metadata')->nullable();
                $table->string('error_code')->nullable();
                $table->text('error_message')->nullable();
                $table->timestamps();
            });
            $createdJobs = true;
        }

        if (! Schema::hasTable('ai_job_attempts')) {
            Schema::create('ai_job_attempts', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('ai_job_id')->index();
                $table->unsignedInteger('attempt_number')->default(1);
                $table->string('worker_id')->nullable();
                $table->string('provider')->nullable();
                $table->string('model')->nullable();
                $table->json('command')->nullable();
                $table->string('prompt_hash')->nullable();
                $table->string('response_hash')->nullable();
                $table->string('status')->nullable();
                $table->integer('exit_code')->nullable();
                $table->text('output_text')->nullable();
                $table->string('error_code')->nullable();
                $table->text('error_message')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();
            });
            $createdAttempts = true;
        }

        try {
            return $callback();
        } finally {
            if ($createdAttempts) {
                Schema::drop('ai_job_attempts');
            }
            if ($createdJobs) {
                Schema::drop('ai_jobs');
            }
            if ($createdTraces) {
                Schema::drop('ai_traces');
            }
        }
    }

    /**
     * @param  array<string,mixed>  $transport
     */
    private function persistedTrace(array $transport): AiTrace
    {
        return AiTrace::query()->create([
            'trace_key' => 'refresh-trace-'.uniqid('', true),
            'source_type' => 'app',
            'metadata' => ['decision_receipt' => $transport],
        ]);
    }

    /**
     * @param  array<string,mixed>  $metadataTransport
     * @param  array<string,mixed>|string|null  $payloadTransport
     */
    private function persistedJob(array $metadataTransport, array|string|null $payloadTransport, ?AiTrace $trace = null): AiJob
    {
        return AiJob::query()->create([
            'trace_id' => $trace?->id,
            'provider' => 'codex_cli',
            'model' => 'gpt-5.5',
            'input_text' => 'refresh receipt only when every persisted copy is safe',
            'prompt' => 'refresh receipt test prompt',
            'payload' => $payloadTransport === null ? [] : ['decision_receipt' => $payloadTransport],
            'metadata' => ['decision_receipt' => $metadataTransport],
        ])->refresh()->load('trace');
    }

    /** @return array<string,mixed> */
    private function issuedV2(string $receiptId, CarbonImmutable $expiresAt): array
    {
        $envelope = app(OperationEnvelopeFactory::class)->create([
            'text' => 'validate refresh receipt hardening',
        ]);

        return app(DecisionReceiptIssuer::class)->issue($envelope, [
            'receipt_id' => $receiptId,
            'issued_at' => CarbonImmutable::now()->subMinute()->toISOString(),
            'expires_at' => $expiresAt->toISOString(),
            'domain' => 'programming',
            'flow' => 'programming.dev',
            'risk' => 'medium',
            'provider_selection' => [
                'primary' => 'codex_cli',
                'model' => 'gpt-5.5',
                'fallbacks' => [],
            ],
            'required_evidence' => ['summary'],
            'repair_policy' => ['enabled' => false, 'max_attempts' => 0],
        ])->toArray();
    }

    public function test_refresh_is_fail_closed_for_a_clean_expired_v2_and_preserves_all_copies(): void
    {
        $this->withRefreshPersistence(function (): void {
            $transport = ['receipt_v2' => $this->issuedV2('rcpt_refresh_closed_clean', CarbonImmutable::now()->subMinute())];
            $trace = $this->persistedTrace($transport);
            $job = $this->persistedJob($transport, $transport, $trace);

            self::assertSame('decision_receipt_expired', (new DecisionReceiptRuntimeGuard)->violationForJob($job)?->errorCode);
            self::assertFalse($this->service->canRefreshExpiredBeforeProviderCall($job));
            self::assertNull($this->service->refreshExpiredBeforeProviderCall($job));

            $persisted = $job->fresh();
            self::assertSame($transport, data_get($persisted, 'metadata.decision_receipt'));
            self::assertSame($transport, data_get($persisted, 'payload.decision_receipt'));
            self::assertSame($transport, data_get($trace->fresh(), 'metadata.decision_receipt'));
            self::assertNull(data_get($persisted, 'metadata.decision_receipt_refresh'));
        });
    }

    public function test_refresh_cannot_launder_mutated_input_payload_or_provider_after_expiry(): void
    {
        $this->withRefreshPersistence(function (): void {
            $transport = ['receipt_v2' => $this->issuedV2('rcpt_refresh_closed_mutated', CarbonImmutable::now()->subMinute())];
            $trace = $this->persistedTrace($transport);
            $job = $this->persistedJob($transport, $transport, $trace);
            $job->forceFill([
                'input_text' => 'mutated input after authority was issued',
                'provider' => 'claude_cli',
                'payload' => array_merge((array) $job->payload, ['task' => 'mutated task after expiry']),
            ])->save();

            self::assertFalse($this->service->canRefreshExpiredBeforeProviderCall($job->fresh()->load('trace')));
            self::assertNull($this->service->refreshExpiredBeforeProviderCall($job->fresh()->load('trace')));

            $persisted = $job->fresh();
            self::assertSame('mutated input after authority was issued', $persisted->input_text);
            self::assertSame('claude_cli', $persisted->provider);
            self::assertSame('mutated task after expiry', data_get($persisted, 'payload.task'));
            self::assertSame($transport, data_get($persisted, 'metadata.decision_receipt'));
            self::assertSame($transport, data_get($persisted, 'payload.decision_receipt'));
            self::assertSame($transport, data_get($trace->fresh(), 'metadata.decision_receipt'));
        });
    }

    public function test_refresh_preserves_a_newer_v3_trace_transport_instead_of_downgrading_it(): void
    {
        $this->withRefreshPersistence(function (): void {
            $expiredTransport = ['receipt_v2' => $this->issuedV2('rcpt_refresh_closed_trace_v3', CarbonImmutable::now()->subMinute())];
            $traceTransport = ['receipt_v3' => ['schema_version' => 'atlas.decide.v3', 'receipt_id' => 'trace-v3']];
            $trace = $this->persistedTrace($traceTransport);
            $job = $this->persistedJob($expiredTransport, $expiredTransport, $trace);

            self::assertFalse($this->service->canRefreshExpiredBeforeProviderCall($job));
            self::assertNull($this->service->refreshExpiredBeforeProviderCall($job));
            self::assertSame($traceTransport, data_get($trace->fresh(), 'metadata.decision_receipt'));
            self::assertSame($expiredTransport, data_get($job->fresh(), 'metadata.decision_receipt'));
            self::assertSame($expiredTransport, data_get($job->fresh(), 'payload.decision_receipt'));
        });
    }

    public function test_refresh_cannot_overwrite_a_v3_trace_promoted_after_the_job_was_loaded(): void
    {
        $this->withRefreshPersistence(function (): void {
            $expiredTransport = ['receipt_v2' => $this->issuedV2('rcpt_refresh_closed_trace_race', CarbonImmutable::now()->subMinute())];
            $trace = $this->persistedTrace($expiredTransport);
            $job = $this->persistedJob($expiredTransport, $expiredTransport, $trace);
            self::assertSame($expiredTransport, data_get($job->trace, 'metadata.decision_receipt'));

            // Deterministic interleaving for the former refresh race: another
            // writer promotes the trace after this job has its stale relation.
            $promotedTraceTransport = [
                'receipt_v3' => [
                    'schema_version' => 'atlas.decide.v3',
                    'receipt_id' => 'trace-v3-promoted-concurrently',
                    'authority_revision' => 2,
                ],
            ];
            $trace->forceFill([
                'metadata' => ['decision_receipt' => $promotedTraceTransport],
            ])->save();

            self::assertFalse($this->service->canRefreshExpiredBeforeProviderCall($job));
            self::assertNull($this->service->refreshExpiredBeforeProviderCall($job));
            self::assertSame($promotedTraceTransport, data_get($trace->fresh(), 'metadata.decision_receipt'));
            self::assertSame($expiredTransport, data_get($job->fresh(), 'metadata.decision_receipt'));
            self::assertSame($expiredTransport, data_get($job->fresh(), 'payload.decision_receipt'));
        });
    }

    public function test_refresh_preserves_an_unknown_trace_transport_instead_of_overwriting_it(): void
    {
        $this->withRefreshPersistence(function (): void {
            $expiredTransport = ['receipt_v2' => $this->issuedV2('rcpt_refresh_closed_trace_unknown', CarbonImmutable::now()->subMinute())];
            $traceTransport = ['receipt_v4' => ['schema_version' => 'atlas.decide.v4', 'receipt_id' => 'trace-v4']];
            $trace = $this->persistedTrace($traceTransport);
            $job = $this->persistedJob($expiredTransport, $expiredTransport, $trace);

            self::assertFalse($this->service->canRefreshExpiredBeforeProviderCall($job));
            self::assertNull($this->service->refreshExpiredBeforeProviderCall($job));
            self::assertSame($traceTransport, data_get($trace->fresh(), 'metadata.decision_receipt'));
            self::assertSame($expiredTransport, data_get($job->fresh(), 'metadata.decision_receipt'));
            self::assertSame($expiredTransport, data_get($job->fresh(), 'payload.decision_receipt'));
        });
    }

    public function test_refresh_does_not_reissue_after_any_provider_history_exists(): void
    {
        $this->withRefreshPersistence(function (): void {
            $transport = ['receipt_v2' => $this->issuedV2('rcpt_refresh_closed_history', CarbonImmutable::now()->subMinute())];
            $trace = $this->persistedTrace($transport);
            $job = $this->persistedJob($transport, $transport, $trace);
            AiJobAttempt::query()->create([
                'ai_job_id' => $job->id,
                'attempt_number' => 1,
                'worker_id' => 'recovery-worker',
                'provider' => 'codex_cli',
                'model' => 'gpt-5.5',
                'command' => [],
                'prompt_hash' => str_repeat('a', 64),
                'status' => 'processing',
                'error_code' => null,
                'metadata' => [],
            ]);

            self::assertFalse($this->service->canRefreshExpiredBeforeProviderCall($job));
            self::assertNull($this->service->refreshExpiredBeforeProviderCall($job));
            self::assertSame($transport, data_get($job->fresh(), 'metadata.decision_receipt'));
            self::assertSame($transport, data_get($job->fresh(), 'payload.decision_receipt'));
            self::assertSame($transport, data_get($trace->fresh(), 'metadata.decision_receipt'));
        });
    }
}
