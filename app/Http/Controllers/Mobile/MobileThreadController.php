<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Http\Resources\AiThreadResource;
use App\Models\AiInboxItem;
use App\Models\AiThread;
use App\Models\AtlasMobileDevice;
use App\Services\Ai\Mobile\InboxActionRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
        $metadata = $thread->metadata ?? [];
        $inboxItemId = $metadata['inbox_item_id'] ?? null;
        if (is_string($inboxItemId)) {
            $item = AiInboxItem::query()->find($inboxItemId);
            abort_unless($item && $item->user_id === $this->device($request)->user_id, 404);
        }

        return response()->json([
            'thread' => (new AiThreadResource($thread->load('messages')))->resolve(),
        ]);
    }

    private function device(Request $request): AtlasMobileDevice
    {
        /** @var AtlasMobileDevice $device */
        $device = $request->attributes->get('atlas_mobile_device');

        return $device;
    }
}
