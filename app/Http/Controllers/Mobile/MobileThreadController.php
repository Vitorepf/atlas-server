<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Http\Resources\AiThreadResource;
use App\Http\Resources\AiTraceResource;
use App\Models\AiContextBundle;
use App\Models\AiInboxItem;
use App\Models\AiThread;
use App\Models\AtlasMobileDevice;
use App\Services\Ai\AiGatewayService;
use App\Services\Ai\Mobile\InboxActionRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class MobileThreadController extends Controller
{
    public function fromInbox(Request $request, AiInboxItem $inboxItem, InboxActionRegistry $actions): JsonResponse
    {
        abort_unless($inboxItem->user_id === $this->device($request)->user_id, 404);

        $result = $actions->handle(
            $inboxItem,
            'discuss',
            [],
            $request->header('Idempotency-Key') ?: 'thread-from-inbox-'.$inboxItem->id,
            $this->device($request),
        );

        return response()->json([
            'ok' => true,
            'thread_id' => $result['result']['thread_id'] ?? null,
            'deep_link' => $result['result']['deep_link'] ?? null,
            'item' => $result['item']->id,
        ]);
    }

    public function show(Request $request, AiThread $thread): JsonResponse
    {
        $item = $this->authorizeThread($request, $thread)->loadMissing('contextBundle');
        $traces = $thread->traces()
            ->with(['job', 'jobs'])
            ->latest('created_at')
            ->limit(20)
            ->get()
            ->sortBy('created_at')
            ->values();

        return response()->json([
            'thread' => (new AiThreadResource($thread->load('messages')))->resolve(),
            'traces' => AiTraceResource::collection($traces)->resolve(),
            'mobile_context' => $this->mobileContextPayload($thread, $item),
        ]);
    }

    public function reply(Request $request, AiThread $thread, AiGatewayService $gateway): JsonResponse
    {
        $device = $this->device($request);
        $item = $this->authorizeThread($request, $thread);
        $data = $request->validate([
            'input_text' => ['required', 'string', 'max:50000'],
            'client_id' => ['nullable', 'uuid'],
            'agent_slug' => ['nullable', 'string', 'max:80', 'regex:/^[a-z0-9][a-z0-9_-]*$/'],
            'provider' => ['nullable', 'string', 'in:claude_cli,codex_cli,gemini_cli,hermes_cli,minimax_m27_cli,claude_codex'],
            'include_semantic_context' => ['nullable', 'boolean'],
            'context_note_limit' => ['nullable', 'integer', 'between:0,20'],
            'payload' => ['nullable', 'array'],
        ]);

        $payload = is_array($data['payload'] ?? null) ? $data['payload'] : [];
        $payload = $this->mobileThreadPayload($payload, $device, $item);

        try {
            $trace = $gateway->enqueueInteraction(trim((string) $data['input_text']), [
                'client_id' => $data['client_id'] ?? null,
                'thread_id' => $thread->id,
                'new_thread' => false,
                'agent_slug' => $data['agent_slug'] ?? null,
                'provider' => $data['provider'] ?? null,
                'kind' => 'interaction',
                'source_type' => 'app',
                'source_id' => $thread->id,
                'include_semantic_context' => $data['include_semantic_context'] ?? true,
                'context_note_limit' => $data['context_note_limit'] ?? 5,
                'payload' => $payload,
            ]);
        } catch (RuntimeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }

        return response()->json([
            'trace' => (new AiTraceResource($trace))->resolve(),
            'thread' => (new AiThreadResource($thread->refresh()->load('messages')))->resolve(),
        ], 202);
    }

    private function device(Request $request): AtlasMobileDevice
    {
        /** @var AtlasMobileDevice $device */
        $device = $request->attributes->get('atlas_mobile_device');

        return $device;
    }

    private function authorizeThread(Request $request, AiThread $thread): AiInboxItem
    {
        $item = $this->threadInboxItem($thread);
        abort_unless($item && $item->user_id === $this->device($request)->user_id, 404);

        return $item;
    }

    private function threadInboxItem(AiThread $thread): ?AiInboxItem
    {
        $metadata = $thread->metadata ?? [];
        $metadataInboxItemId = $metadata['inbox_item_id'] ?? null;
        $sourceInboxItemId = $thread->source_type === 'inbox_item' ? $thread->source_id : null;
        $inboxItemId = is_string($sourceInboxItemId) && $sourceInboxItemId !== ''
            ? $sourceInboxItemId
            : (is_string($metadataInboxItemId) ? $metadataInboxItemId : null);

        return $inboxItemId ? AiInboxItem::query()->find($inboxItemId) : null;
    }

    /**
     * @return array<string,mixed>
     */
    private function mobileContextPayload(AiThread $thread, AiInboxItem $item): array
    {
        $metadata = $thread->metadata ?? [];
        $bundle = $item->contextBundle;

        return [
            'source' => [
                'type' => 'ai_inbox_item',
                'id' => $item->id,
                'inbox_type' => $item->type,
                'category' => $item->category,
                'severity' => $item->severity,
                'status' => $item->status,
                'title' => $item->title,
                'summary' => $item->summary,
                'initiator' => $item->initiator,
                'created_at' => $item->created_at?->toJSON(),
            ],
            'context_bundle' => $bundle ? $this->contextBundlePayload($bundle) : null,
            'policy' => [
                'atlas_focus' => $this->metadataString($metadata, 'atlas_focus', 'operational'),
                'capability_profile' => $this->metadataString($metadata, 'capability_profile', 'mobile_operational_read'),
                'permission_policy' => $this->metadataString($metadata, 'permission_policy', 'read_only_until_approval'),
                'execution_policy' => $this->metadataString($metadata, 'execution_policy', 'no_code_execution'),
                'allows_code_execution' => false,
                'requires_approval_for_changes' => true,
            ],
            'refs_count' => $this->contextRefsCount($bundle),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function contextBundlePayload(AiContextBundle $bundle): array
    {
        return [
            'id' => $bundle->id,
            'purpose' => $bundle->purpose,
            'title' => $bundle->title,
            'summary' => $bundle->summary,
            'body_preview' => $this->preview($bundle->body_for_thread),
            'redaction_status' => $bundle->redaction_status,
            'token_estimate' => $bundle->token_estimate,
            'expires_at' => $bundle->expires_at?->toJSON(),
        ];
    }

    /**
     * @return array{sources:int,traces:int,jobs:int,metrics:int,files:int,diffs:int,total:int}
     */
    private function contextRefsCount(?AiContextBundle $bundle): array
    {
        $counts = [
            'sources' => $this->countRefs($bundle?->source_refs),
            'traces' => $this->countRefs($bundle?->trace_refs),
            'jobs' => $this->countRefs($bundle?->job_refs),
            'metrics' => $this->countRefs($bundle?->metric_refs),
            'files' => $this->countRefs($bundle?->file_refs),
            'diffs' => $this->countRefs($bundle?->diff_refs),
        ];

        return $counts + ['total' => array_sum($counts)];
    }

    /**
     * @param  array<string,mixed>  $metadata
     */
    private function metadataString(array $metadata, string $key, string $default): string
    {
        $value = $metadata[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : $default;
    }

    private function countRefs(mixed $refs): int
    {
        return is_array($refs) ? count($refs) : 0;
    }

    private function preview(?string $value): ?string
    {
        $value = is_string($value) ? trim($value) : '';
        if ($value === '') {
            return null;
        }

        return mb_strlen($value) > 700 ? mb_substr($value, 0, 697).'...' : $value;
    }

    /**
     * Mobile discussions are analysis-only. Operational code changes or destructive
     * runtime modes must be expressed as approval inbox items, not silent chat replies.
     *
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function mobileThreadPayload(array $payload, AtlasMobileDevice $device, AiInboxItem $item): array
    {
        return array_merge($payload, [
            'app_surface' => 'mobile_thread',
            'thread_source' => 'mobile_gateway_inbox',
            'mobile_device_id' => $device->id,
            'inbox_item_id' => $item->id,
            'context_bundle_id' => $item->context_bundle_id,
            'atlas_focus' => 'operational',
            'capability_profile' => 'mobile_operational_read',
            'permission_mode' => 'read',
            'tool_permissions' => [
                'mode' => 'read',
                'workspace' => (string) config('atlas.ai.workdir'),
                'confirmed' => false,
                'allow_unsandboxed_provider' => false,
                'source' => 'mobile_thread_safe_default',
            ],
            'mobile_runtime_policy' => [
                'allows_code_execution' => false,
                'reason' => 'Mobile discussion is analysis-only; executable actions must go through operational approvals.',
            ],
        ]);
    }
}
