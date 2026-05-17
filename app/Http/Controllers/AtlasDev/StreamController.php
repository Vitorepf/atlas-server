<?php

declare(strict_types=1);

namespace App\Http\Controllers\AtlasDev;

use App\Http\Controllers\Controller;
use App\Services\Ai\Programming\AtlasDev\Persistence\ArtifactNames;
use App\Services\Ai\Programming\AtlasDev\Persistence\ReceiptStorage;
use App\Services\Ai\Programming\AtlasDev\Surface\HttpResponseRedactor;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * GET /ai/interactions/atlas-dev/runs/{run_id}/stream
 *
 * Short-lived SSE snapshot. The endpoint reads the persisted receipt directory
 * at connection time, emits one `phase` event per artifact that already exists,
 * one `receipt` event with the full verification receipt when it is present,
 * a final `stream_closed` event, and **closes immediately**. No
 * keepalive loop, no `sleep()`, no deadline-bound polling on the worker.
 *
 * Rationale (review finding F-01): under PHP-FPM each in-flight stream pins a
 * worker for its entire lifetime. A 300s keepalive loop would exhaust the pool
 * with only a handful of Desktop tabs open. Until Atlas adopts an async
 * runtime (Octane / Reverb / ReactPHP) capable of cheap concurrent streams,
 * the contract is "stream replays state once, then the client polls REST".
 *
 * The companion `ShowController` (GET /runs/{id}) is the REST fallback the
 * Desktop client and CLI poll for ongoing progress.
 *
 * Headers/protocol:
 *   - text/event-stream + nginx-friendly no-cache and X-Accel-Buffering: no.
 *   - Connection: close (we never want intermediaries to hold idle connections).
 *   - No keepalive comments. Replay finishes in the order of milliseconds.
 */
final class StreamController extends Controller
{
    private const PHASE_LOOKUP = [
        'plan_persisted' => ArtifactNames::OPERATION_ENVELOPE,
        'compact_sdd_ready' => ArtifactNames::COMPACT_SDD,
        'context_retrieval_planned' => ArtifactNames::CONTEXT_RETRIEVAL_PLAN,
        'code_discovery_ready' => ArtifactNames::CODE_DISCOVERY_MANIFEST,
        'open_brain_projected' => ArtifactNames::OPEN_BRAIN_PROJECTION,
        'mini_spec_ready' => ArtifactNames::MINI_PROGRAMMING_SPEC,
        'task_contract_ready' => ArtifactNames::TASK_CONTRACT,
        'prompt_projected' => ArtifactNames::PROMPT_PROJECTION,
        'route_decided' => ArtifactNames::ROUTING_DECISION,
        'provider_called' => ArtifactNames::PROVIDER_CALL_RESULT,
        'diff_parsed' => ArtifactNames::DIFF_PARSE_RESULT,
        'patch_applied' => ArtifactNames::PATCH_APPLY_RESULT,
        'scope_guarded' => ArtifactNames::SCOPE_GUARD_RECEIPT,
        'receipt_ready' => ArtifactNames::VERIFICATION_RECEIPT,
    ];

    public function __construct(
        private readonly ReceiptStorage $storage,
        private readonly ConfigRepository $config,
        private readonly HttpResponseRedactor $redactor = new HttpResponseRedactor,
    ) {}

    public function __invoke(string $runId): StreamedResponse|JsonResponse
    {
        if (! $this->storage->exists($runId, ArtifactNames::OPERATION_ENVELOPE)) {
            return response()->json([
                'error' => [
                    'code' => 'RUN_NOT_FOUND',
                    'message' => "No persisted plan for run_id '{$runId}'.",
                ],
            ], 404);
        }

        $response = new StreamedResponse(function () use ($runId): void {
            $this->emitSnapshot($runId);
        });

        $response->headers->set('Content-Type', 'text/event-stream');
        $response->headers->set('Cache-Control', 'no-cache, no-store, must-revalidate');
        $response->headers->set('Connection', 'close');
        $response->headers->set('X-Accel-Buffering', 'no');
        $response->headers->set('X-Atlas-Stream-Mode', 'snapshot-replay-then-close');

        return $response;
    }

    private function emitSnapshot(string $runId): void
    {
        $runState = $this->storage->readLatestVersion($runId, ArtifactNames::RUN_EXECUTION_STATE_BASE);
        if (is_array($runState)) {
            $phase = match ($runState['status'] ?? null) {
                'running' => 'executing',
                'complete', 'failed', 'cancelled' => 'complete',
                default => 'queued',
            };
            $this->emit('phase', [
                'run_id' => $runId,
                'phase' => $phase,
                'artifact' => 'run_execution_state',
                'persisted_at' => isset($runState['recorded_at']) && is_string($runState['recorded_at'])
                    ? $runState['recorded_at']
                    : null,
            ]);
        }

        foreach (self::PHASE_LOOKUP as $phaseName => $filename) {
            if (! $this->storage->exists($runId, $filename)) {
                continue;
            }
            $this->emit('phase', [
                'run_id' => $runId,
                'phase' => $phaseName,
                'artifact' => $filename,
                'persisted_at' => @filemtime($this->storage->path($runId, $filename)) ?: null,
            ]);
        }

        $receipt = $this->storage->read($runId, ArtifactNames::VERIFICATION_RECEIPT);
        if ($receipt !== null) {
            $diffPreview = $this->diffPreview($runId);
            if ($diffPreview !== null) {
                $receipt['ui_hints'] = array_merge(
                    is_array($receipt['ui_hints'] ?? null) ? $receipt['ui_hints'] : [],
                    ['diff_preview' => $diffPreview],
                );
            }
            $completion = is_array($receipt['completion'] ?? null) ? $receipt['completion'] : [];

            // F-04: SSE crosses the same HTTP boundary as the Show endpoint, so
            // the receipt MUST be projected through the redactor before emit.
            // Without this, evidence_refs[].path, persisted_artifact_paths, and
            // any diff_preview carrying absolute paths leak directly to the
            // Desktop/CLI surface.
            $payload = [
                'run_id' => $runId,
                'completion_state' => $completion['status'] ?? null,
                'receipt_hash' => $receipt['receipt_hash'] ?? null,
                'verification_status' => $this->extractGateStatus($receipt, 'verification_gate'),
                'scope_guard_status' => $this->extractGateStatus($receipt, 'scope_guard_light'),
                'receipt' => $receipt,
            ];

            $this->emit('receipt', $this->redactPayload($runId, $payload));
        }

        $this->emit('stream_closed', [
            'run_id' => $runId,
            'reason' => 'snapshot_complete',
            'fallback' => 'poll_rest_show_endpoint',
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function emit(string $event, array $payload): void
    {
        $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($encoded === false) {
            $encoded = '{}';
        }
        echo "event: {$event}\n";
        echo 'data: '.$encoded."\n\n";
        if (function_exists('ob_get_level') && ob_get_level() > 0) {
            @ob_flush();
        }
        @flush();
    }

    /**
     * @param  array<string, mixed>  $receipt
     */
    private function extractGateStatus(array $receipt, string $gateName): ?string
    {
        $gates = $receipt['gates'] ?? null;
        if (! is_array($gates)) {
            return null;
        }
        foreach ($gates as $gate) {
            if (is_array($gate) && ($gate['name'] ?? null) === $gateName) {
                return is_string($gate['status'] ?? null) ? $gate['status'] : null;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function redactPayload(string $runId, array $payload): array
    {
        // Walk both prefixes: the workspace root (from the persisted envelope)
        // turns absolute repo paths into workspace-relative form, and the
        // storage base (from config) turns absolute receipt paths into the
        // canonical `receipts/<run_id>/<file>` ref.
        $envelope = $this->storage->read($runId, ArtifactNames::OPERATION_ENVELOPE);
        $workspace = is_array($envelope) && is_string($envelope['workspace'] ?? null)
            ? (string) $envelope['workspace']
            : '';
        if ($workspace !== '') {
            $payload = $this->redactor->redactWorkspaceIn($payload, $workspace);
        }

        $storageBase = (string) $this->config->get('atlas_dev.receipts_path', '');
        if ($storageBase !== '') {
            $payload = $this->redactor->redactStorageIn($payload, $storageBase);
        }

        return $payload;
    }

    private function diffPreview(string $runId): ?string
    {
        $diffParse = $this->storage->read($runId, ArtifactNames::DIFF_PARSE_RESULT);
        if (! is_array($diffParse)) {
            return null;
        }
        $diff = $diffParse['diff'] ?? null;
        if (! is_string($diff)) {
            return null;
        }
        $trimmed = trim($diff);

        return $trimmed === '' ? null : $trimmed;
    }
}
