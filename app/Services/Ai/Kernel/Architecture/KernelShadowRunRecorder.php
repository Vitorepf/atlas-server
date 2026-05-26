<?php

declare(strict_types=1);

namespace App\Services\Ai\Kernel\Architecture;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

/**
 * Gap1.F3 — Shadow route recorder.
 *
 * Persists `atlas.ai.kernel_shadow_run.v1` envelopes per HTTP request when
 * the shadow flag is enabled (`atlas_ai.kernel_shadow.enabled=true`). The
 * recorder NEVER invokes the Kernel itself — it observes that the Mission
 * envelope was built (Gap1.F2 tracer) and persists the delta between the
 * legacy orchestrator and the Kernel-routed envelope for offline review.
 *
 * Default state: flag off → recorder is a no-op. The operator turns the
 * flag on after Gap1.F2 (already shipped) verifies tracer health. The
 * recorder is provider-safe — it persists hashes and counts, never trace
 * IDs or operator input.
 *
 * Output location: `storage/atlas/evidence/kernel_shadow_run/<date>/<trace>.json`.
 * Rotation/retention is operator concern; this service only writes.
 */
final class KernelShadowRunRecorder
{
    public const SCHEMA_VERSION = 'atlas.ai.kernel_shadow_run.v1';

    private string $storagePath;

    public function __construct(?string $storagePath = null)
    {
        $this->storagePath = $storagePath ?? storage_path('atlas/evidence/kernel_shadow_run');
    }

    /**
     * Returns true when the shadow recorder is allowed to write.
     */
    public function enabled(): bool
    {
        return (bool) config('atlas_ai.kernel_shadow.enabled', false);
    }

    /**
     * Record a shadow run. Returns the envelope written (provider-safe) or
     * `null` when the flag is off — caller decides whether to log.
     *
     * @param  array<string,mixed>  $kernelEnvelope  The Mission envelope built by AiGatewayMissionBridge.
     * @param  array<string,mixed>  $legacyOutcome   Provider-safe summary of legacy orchestrator outcome.
     * @return array<string,mixed>|null
     */
    public function record(string $traceId, array $kernelEnvelope, array $legacyOutcome): ?array
    {
        if (! $this->enabled()) {
            return null;
        }

        $envelope = $this->buildEnvelope($traceId, $kernelEnvelope, $legacyOutcome);

        try {
            $this->writeEnvelope($envelope);
        } catch (\Throwable $e) {
            // Recorder MUST NOT bubble — shadow path is observability,
            // never a blocker. Emit a structured warning instead.
            Log::warning('atlas.ai.kernel_shadow_recorder.write_failed', [
                'trace_id_hash' => $envelope['trace_id_hash'],
                'reason' => $e->getMessage(),
                'exception_class' => $e::class,
            ]);
        }

        return $envelope;
    }

    /**
     * @param  array<string,mixed>  $kernelEnvelope
     * @param  array<string,mixed>  $legacyOutcome
     * @return array<string,mixed>
     */
    private function buildEnvelope(string $traceId, array $kernelEnvelope, array $legacyOutcome): array
    {
        $kernelRouted = (bool) ($kernelEnvelope['kernel_routed'] ?? false);
        $missionId = (string) ($kernelEnvelope['mission_id'] ?? '');
        $missionType = (string) ($kernelEnvelope['mission_type'] ?? '');
        $bridgeError = isset($kernelEnvelope['kernel_bridge_error']) ? 'present' : 'absent';

        $legacyStatus = (string) ($legacyOutcome['status'] ?? 'unknown');
        $legacyLatencyMs = (int) ($legacyOutcome['latency_ms'] ?? 0);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'recorded_at' => now()->toAtomString(),
            'trace_id_hash' => hash('sha256', $traceId),
            'kernel' => [
                'routed' => $kernelRouted,
                'mission_id_hash' => $missionId !== '' ? hash('sha256', $missionId) : null,
                'mission_type' => $missionType,
                'bridge_error' => $bridgeError,
            ],
            'legacy' => [
                'status' => $legacyStatus,
                'latency_ms' => $legacyLatencyMs,
            ],
            'delta' => [
                'kernel_succeeded_but_legacy_failed' => $kernelRouted && $legacyStatus === 'failed',
                'kernel_failed_but_legacy_succeeded' => ! $kernelRouted && $legacyStatus === 'succeeded',
                'both_succeeded' => $kernelRouted && $legacyStatus === 'succeeded',
                'both_failed' => ! $kernelRouted && $legacyStatus !== 'succeeded',
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $envelope
     */
    private function writeEnvelope(array $envelope): void
    {
        $date = substr($envelope['recorded_at'], 0, 10); // YYYY-MM-DD
        $dir = $this->storagePath.'/'.$date;
        File::ensureDirectoryExists($dir);

        $filename = $envelope['trace_id_hash'].'.json';
        $path = $dir.'/'.$filename;

        File::put($path, json_encode($envelope, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}
