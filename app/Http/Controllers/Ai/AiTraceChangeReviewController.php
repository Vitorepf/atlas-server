<?php

declare(strict_types=1);

namespace App\Http\Controllers\Ai;

use App\Models\AiTrace;
use App\Models\AiStreamEvent;
use App\Models\AtlasEngineeringPatchArtifact;
use App\Models\AtlasEngineeringRun;
use App\Services\Ai\AiTraceEngineeringReviewProjection;
use App\Services\Engineering\EngineeringRunFileReviewService;
use App\Services\Engineering\EngineeringRunOperatorActionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use RuntimeException;

final class AiTraceChangeReviewController
{
    public function show(AiTrace $trace, AiTraceEngineeringReviewProjection $projection): JsonResponse
    {
        return response()->json([
            'change_review' => $projection->forTrace($trace),
        ]);
    }

    public function diff(Request $request, AiTrace $trace, AtlasEngineeringPatchArtifact $patch): JsonResponse
    {
        $run = $this->uniqueRunFor($trace);
        abort_unless($patch->engineering_run_id === $run->id, 404);

        $data = $request->validate([
            'max_bytes' => ['nullable', 'integer', 'min:1024', 'max:1048576'],
        ]);
        $maxBytes = (int) ($data['max_bytes'] ?? 262144);
        $content = (string) ($patch->diff_excerpt ?? '');
        $source = 'excerpt';
        $truncated = false;
        $sizeBytes = strlen($content);
        $computedHash = $content !== '' ? hash('sha256', $content) : null;

        if (is_string($patch->diff_path) && $patch->diff_path !== '') {
            $path = $this->safePatchDiffPath($run, $patch->diff_path);
            abort_unless($path !== null, 403);

            if (File::isFile($path)) {
                $sizeBytes = File::size($path);
                $read = file_get_contents($path, false, null, 0, $maxBytes + 1);
                $content = is_string($read) ? $read : '';
                $truncated = strlen($content) > $maxBytes;
                if ($truncated) {
                    $content = substr($content, 0, $maxBytes);
                }
                $source = 'diff_path';
                $computedHash = hash_file('sha256', $path) ?: null;
            }
        }

        return response()->json([
            'patch' => [
                'id' => $patch->id,
                'diff_hash' => $patch->diff_hash,
                'computed_hash' => $computedHash,
                'hash_matches' => $patch->diff_hash && $computedHash ? hash_equals($patch->diff_hash, $computedHash) : null,
                'changed_files' => $patch->changed_files_json ?? [],
                'created_files' => $patch->created_files_json ?? [],
                'deleted_files' => $patch->deleted_files_json ?? [],
                'risk_flags' => $patch->risk_flags_json ?? [],
            ],
            'diff' => [
                'content' => $content,
                'source' => $source,
                'size_bytes' => $sizeBytes,
                'returned_bytes' => strlen($content),
                'truncated' => $truncated,
                'max_bytes' => $maxBytes,
            ],
        ]);
    }

    public function action(
        Request $request,
        AiTrace $trace,
        EngineeringRunFileReviewService $fileReviews,
        EngineeringRunOperatorActionService $operatorActions,
        AiTraceEngineeringReviewProjection $projection,
    ): JsonResponse {
        $data = $request->validate([
            'action' => ['required', Rule::in(['accept', 'reject'])],
            'actor' => ['nullable', 'string', 'max:120'],
            'note' => ['nullable', 'string', 'max:2000'],
            'payload' => ['nullable', 'array'],
        ]);

        try {
            [$action, $receipt] = DB::transaction(function () use ($trace, $data, $fileReviews, $operatorActions): array {
                $run = $this->uniqueRunFor($trace, lockForUpdate: true);
                $acceptedFiles = [];
                if ($data['action'] === 'accept') {
                    $acceptedFiles = $fileReviews->acceptAll($run, $data);
                    $data['payload'] = array_merge((array) ($data['payload'] ?? []), [
                        'file_review' => [
                            'mode' => 'accept_all_captured_files',
                            'accepted_file_count' => count($acceptedFiles),
                        ],
                    ]);
                }
                $action = $operatorActions->apply($run, (string) $data['action'], $data);
                $receipt = $this->recordReceipt($trace, $action->id, $action->action, $action->status_after, $action->decision_after);

                return [$action, $receipt];
            });
        } catch (RuntimeException $e) {
            abort(503, $e->getMessage());
        }

        return response()->json([
            'review_receipt' => [
                'id' => $action->id,
                'action' => $action->action,
                'status_after' => $action->status_after,
                'decision_after' => $action->decision_after,
                'occurred_at' => $receipt->occurred_at?->toIso8601String(),
            ],
            'change_review' => $projection->forTrace($trace->fresh()),
        ]);
    }

    public function fileAction(
        Request $request,
        AiTrace $trace,
        EngineeringRunFileReviewService $fileReviews,
        AiTraceEngineeringReviewProjection $projection,
    ): JsonResponse {
        $data = $request->validate([
            'patch_id' => ['required', 'uuid'],
            'file_path' => ['required', 'string', 'max:1024'],
            'action' => ['required', Rule::in(['accept', 'reject'])],
            'actor' => ['nullable', 'string', 'max:120'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            [$decision, $receipt] = DB::transaction(function () use ($trace, $data, $fileReviews): array {
                $run = $this->uniqueRunFor($trace, lockForUpdate: true);
                $patch = AtlasEngineeringPatchArtifact::query()
                    ->where('engineering_run_id', $run->id)
                    ->findOrFail($data['patch_id']);
                $decision = $fileReviews->decide(
                    $run,
                    $patch,
                    (string) $data['file_path'],
                    (string) $data['action'],
                    $data,
                );
                $receipt = $this->recordReceipt(
                    $trace,
                    $decision->id,
                    'file_'.(string) $decision->action,
                    $run->status,
                    $run->decision,
                    'trace_change_review_file_decided',
                    [
                        'patch_id' => $patch->id,
                        'file_path' => $decision->file_path,
                        'action' => $decision->action,
                    ],
                );

                return [$decision, $receipt];
            });
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'error' => [
                    'code' => 'invalid_file_review_decision',
                    'message' => $e->getMessage(),
                ],
            ], 422);
        } catch (RuntimeException $e) {
            abort(503, $e->getMessage());
        }

        return response()->json([
            'file_review_receipt' => [
                'action' => $decision->action,
                'file_path' => $decision->file_path,
                'patch_id' => $decision->patch_artifact_id,
                'occurred_at' => $receipt->occurred_at?->toIso8601String(),
            ],
            'change_review' => $projection->forTrace($trace->fresh()),
        ]);
    }

    private function uniqueRunFor(AiTrace $trace, bool $lockForUpdate = false): AtlasEngineeringRun
    {
        $query = AtlasEngineeringRun::query()
            ->where('trace_id', $trace->id)
            ->limit(2);
        if ($lockForUpdate) {
            $query->lockForUpdate();
        }
        $runs = $query->get();

        abort_unless($runs->count() === 1, 404);

        /** @var AtlasEngineeringRun $run */
        $run = $runs->sole();

        return $run;
    }

    private function safePatchDiffPath(AtlasEngineeringRun $run, string $path): ?string
    {
        $root = realpath(storage_path('app/engineering-runs/'.$run->id));
        $resolved = realpath($path);
        if (! $root || ! $resolved) {
            return null;
        }

        return Str::startsWith($resolved, rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR) ? $resolved : null;
    }

    private function recordReceipt(
        AiTrace $trace,
        string $actionId,
        string $action,
        ?string $statusAfter,
        ?string $decisionAfter,
        string $eventName = 'trace_change_review_decided',
        array $extraMetadata = [],
    ): AiStreamEvent {
        abort_unless(DB::getSchemaBuilder()->hasTable('ai_stream_events'), 503);

        DB::table('ai_traces')
            ->where('id', $trace->id)
            ->lockForUpdate()
            ->value('id');

        $sequence = (int) AiStreamEvent::query()
            ->where('trace_id', $trace->id)
            ->max('sequence') + 1;

        return AiStreamEvent::query()->create([
            'trace_id' => $trace->id,
            'sequence' => $sequence,
            'event_type' => 'lifecycle',
            'channel' => 'change_review',
            'content' => '',
            'metadata' => [
                'name' => $eventName,
                'review_receipt' => [
                    'action_id' => $actionId,
                    'action' => $action,
                    'status_after' => $statusAfter,
                    'decision_after' => $decisionAfter,
                ],
                ...$extraMetadata,
            ],
            'occurred_at' => now(),
        ]);
    }
}
