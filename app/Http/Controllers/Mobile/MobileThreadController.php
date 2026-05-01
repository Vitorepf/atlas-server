<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Http\Resources\AiThreadResource;
use App\Http\Resources\AiTraceResource;
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
        $this->authorizeThread($request, $thread);
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
            'provider' => ['nullable', 'string', 'in:claude_cli,codex_cli,claude_codex'],
            'include_semantic_context' => ['nullable', 'boolean'],
            'context_note_limit' => ['nullable', 'integer', 'between:0,20'],
            'payload' => ['nullable', 'array'],
        ]);

        $payload = is_array($data['payload'] ?? null) ? $data['payload'] : [];

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
                'payload' => array_merge($payload, [
                    'app_surface' => 'mobile_thread',
                    'thread_source' => 'mobile_gateway_inbox',
                    'mobile_device_id' => $device->id,
                    'inbox_item_id' => $item->id,
                    'context_bundle_id' => $item->context_bundle_id,
                ]),
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
}
