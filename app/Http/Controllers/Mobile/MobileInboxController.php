<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Http\Resources\AiInboxItemResource;
use App\Models\AiInboxItem;
use App\Models\AtlasMobileDevice;
use App\Services\Ai\Mobile\AtlasInboxService;
use App\Services\Ai\Mobile\InboxActionRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class MobileInboxController extends Controller
{
    public function index(Request $request, AtlasInboxService $inbox): JsonResponse
    {
        $device = $this->device($request);
        $data = $request->validate([
            'status' => ['nullable', Rule::in([...AiInboxItem::STATUSES, 'all'])],
            'type' => ['nullable', Rule::in(AiInboxItem::TYPES)],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $items = $inbox->list(
            $device->user_id,
            $data['status'] ?? 'unread',
            $data['type'] ?? null,
            (int) ($data['limit'] ?? 50),
        );

        return response()->json([
            'items' => AiInboxItemResource::collection($items)->resolve(),
            'unread_count' => AiInboxItem::query()->where('user_id', $device->user_id)->where('status', 'unread')->count(),
            'generated_at' => now()->toJSON(),
        ]);
    }

    public function show(Request $request, AiInboxItem $inboxItem): JsonResponse
    {
        $this->authorizeItem($request, $inboxItem);

        return response()->json([
            'item' => (new AiInboxItemResource($inboxItem->load('contextBundle')))->resolve(),
        ]);
    }

    public function markRead(Request $request, AiInboxItem $inboxItem, AtlasInboxService $inbox): JsonResponse
    {
        $this->authorizeItem($request, $inboxItem);

        return response()->json([
            'item' => (new AiInboxItemResource($inbox->markRead($inboxItem)))->resolve(),
        ]);
    }

    public function dismiss(Request $request, AiInboxItem $inboxItem, AtlasInboxService $inbox): JsonResponse
    {
        $this->authorizeItem($request, $inboxItem);
        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        return response()->json([
            'item' => (new AiInboxItemResource($inbox->dismiss($inboxItem, $data['reason'] ?? null)))->resolve(),
        ]);
    }

    public function snooze(Request $request, AiInboxItem $inboxItem, AtlasInboxService $inbox): JsonResponse
    {
        $this->authorizeItem($request, $inboxItem);
        $data = $request->validate([
            'snoozed_until' => ['required', 'date', 'after:now'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        return response()->json([
            'item' => (new AiInboxItemResource($inbox->snooze($inboxItem, Carbon::parse($data['snoozed_until']), $data['reason'] ?? null)))->resolve(),
        ]);
    }

    public function respond(Request $request, AiInboxItem $inboxItem, InboxActionRegistry $actions): JsonResponse
    {
        $this->authorizeItem($request, $inboxItem);
        $data = $request->validate([
            'action' => ['required', 'string', 'max:80'],
            'data' => ['nullable', 'array'],
        ]);

        $result = $actions->handle(
            $inboxItem,
            $data['action'],
            $data['data'] ?? [],
            $request->header('Idempotency-Key') ?: null,
            $this->device($request),
        );

        return response()->json([
            'ok' => true,
            'idempotent' => $result['idempotent'],
            'result' => $result['result'],
            'item' => (new AiInboxItemResource($result['item']))->resolve(),
        ]);
    }

    public function discuss(Request $request, AiInboxItem $inboxItem, InboxActionRegistry $actions): JsonResponse
    {
        $this->authorizeItem($request, $inboxItem);

        $result = $actions->handle(
            $inboxItem,
            'discuss',
            [],
            $request->header('Idempotency-Key') ?: 'discuss-'.$inboxItem->id,
            $this->device($request),
        );

        return response()->json([
            'ok' => true,
            'result' => $result['result'],
            'item' => (new AiInboxItemResource($result['item']))->resolve(),
        ]);
    }

    private function authorizeItem(Request $request, AiInboxItem $item): void
    {
        abort_unless($item->user_id === $this->device($request)->user_id, 404);
    }

    private function device(Request $request): AtlasMobileDevice
    {
        /** @var AtlasMobileDevice $device */
        $device = $request->attributes->get('atlas_mobile_device');

        return $device;
    }
}
