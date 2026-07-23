<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Cognition;

use App\Models\Capture;
use App\Services\Ai\Memory\AtlasMemoryCognitiveImmuneLearningKernelService;
use App\Services\Ai\Cognition\CognitiveImmunePromotionGateEvaluator;
use App\Services\CaptureService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ImmuneVerdictSingleAuthorityTest extends TestCase
{
    public function test_capture_writes_real_v2_shadow_audit_without_changing_v1_payload(): void
    {
        $this->createCaptureTables();

        $clean = $this->captureWithMetadata([
            'recurrence_count' => 2,
        ]);
        $secret = $this->captureWithMetadata([
            'has_secret_marker' => true,
            'recurrence_count' => 2,
        ]);

        $cleanQuarantine = $this->quarantine($clean);
        $secretQuarantine = $this->quarantine($secret);
        $cleanV1 = $cleanQuarantine['immune_audit'];
        $cleanV2 = $cleanQuarantine['immune_audit_v2'];
        $secretV2 = $secretQuarantine['immune_audit_v2'];

        $this->assertSame([
            'schema_version',
            'status',
            'master_invariant',
            'raw_capture_is_memory',
            'raw_capture_is_context',
            'raw_capture_is_decision',
            'learning_signal_allowed_now',
            'memory_promotion_allowed_now',
            'context_export_allowed_now',
            'constellation_promotion_allowed_now',
            'embedding_allowed_now',
            'noise_gate',
            'promotion_gates',
            'audit_hash',
        ], array_keys($cleanV1));
        $this->assertSame('atlas.capture.cognitive_immune_audit.v1', $cleanV1['schema_version']);
        $this->assertSame('provider_export_blocked', $cleanV1['promotion_gates']['g3_safety']);
        $this->assertArrayNotHasKey('gate_statuses', $cleanV1);

        $this->assertSame('atlas.capture.cognitive_immune_audit.v2', $cleanV2['schema_version']);
        $this->assertSame(
            (new CognitiveImmunePromotionGateEvaluator)->evaluate($cleanV2['signals'])['gate_statuses'],
            $cleanV2['gate_statuses'],
        );
        $this->assertSame('pass', $cleanV2['gate_statuses']['G3']);
        $this->assertSame('block', $secretV2['gate_statuses']['G3']);
        $this->assertNotSame($cleanV2['audit_hash'], $secretV2['audit_hash']);
    }

    public function test_learning_kernel_evaluate_promotion_uses_same_g0_g8_evaluator(): void
    {
        $candidate = [
            'input_class' => 'technical_learning_candidate',
            'scope' => 'project',
            'capture_consented' => true,
            'atomic_claim' => true,
            'future_signal' => true,
            'provider_safe' => false,
            'no_contradiction' => true,
            'outcome_validated' => true,
            'scope_resolved' => true,
            'promotion_mode_set' => true,
            'probation_entered' => true,
            'promotion_mode' => 'auto',
        ];

        $promotion = (new AtlasMemoryCognitiveImmuneLearningKernelService(
            new CognitiveImmunePromotionGateEvaluator,
        ))->evaluatePromotion($candidate);
        $expected = (new CognitiveImmunePromotionGateEvaluator)->evaluate($promotion['immune_signals']);

        $this->assertSame($expected['gate_statuses'], $promotion['gate_statuses']);
        $this->assertSame('blocked', $promotion['promotion_status']);
        $this->assertSame('G3', $promotion['failed_gate']);
        $this->assertContains('G3', $promotion['blocking_gate_ids']);
    }

    public function test_promotion_status_resolution_is_only_defined_by_the_evaluator(): void
    {
        $matches = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path('Services'))) as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $contents = (string) file_get_contents($file->getPathname());
            if (str_contains($contents, 'function resolvePromotionStatus(')) {
                $matches[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname());
            }
        }

        $this->assertSame([
            'app/Services/Ai/Cognition/CognitiveImmunePromotionGateEvaluator.php',
        ], $matches);
    }

    /**
     * @param  array<string,mixed>  $metadata
     */
    private function captureWithMetadata(array $metadata): Capture
    {
        config()->set('atlas.transcription.enabled', false);

        $result = app(CaptureService::class)->create([
            'client_id' => (string) Str::uuid(),
            'kind' => 'audio',
            'domain' => 'outro',
            'content_text' => 'Bug regression with replay evidence. Provenance: local test. This learning should remain a candidate until review.',
            'captured_at' => now()->toJSON(),
            'captured_timezone' => 'America/Sao_Paulo',
            'metadata' => $metadata,
        ]);

        return $result['capture'];
    }

    /**
     * @return array<string,mixed>
     */
    private function quarantine(Capture $capture): array
    {
        $metadata = is_array($capture->metadata) ? $capture->metadata : [];
        $quarantine = $metadata['cognitive_quarantine'] ?? null;

        $this->assertIsArray($quarantine);

        return $quarantine;
    }

    private function createCaptureTables(): void
    {
        Schema::dropIfExists('transcription_jobs');
        Schema::dropIfExists('captures');

        Schema::create('captures', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('client_id')->unique();
            $table->string('kind');
            $table->string('domain');
            $table->text('content_text')->nullable();
            $table->string('content_file_path')->nullable();
            $table->integer('content_duration_ms')->nullable();
            $table->integer('content_size_bytes')->nullable();
            $table->string('content_sha256')->nullable();
            $table->string('content_mime_type')->nullable();
            $table->string('transcription_status')->default('pending');
            $table->string('transcription_engine')->nullable();
            $table->text('transcription_error')->nullable();
            $table->timestamp('captured_at');
            $table->string('captured_timezone');
            $table->decimal('captured_lat', 10, 7)->nullable();
            $table->decimal('captured_lng', 10, 7)->nullable();
            $table->json('metadata')->default('{}');
            $table->json('pre_capture_digital_context')->default('{}');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('transcription_jobs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('capture_id');
            $table->string('status')->default('queued');
            $table->integer('attempts')->default(0);
            $table->integer('max_attempts')->default(3);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();
        });
    }
}
