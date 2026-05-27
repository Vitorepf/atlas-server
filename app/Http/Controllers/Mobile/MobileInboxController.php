<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Http\Resources\AiInboxItemResource;
use App\Models\AiInboxItem;
use App\Models\AtlasMobileDevice;
use App\Services\Ai\Mobile\AiCriticalInboxReviewReadModel;
use App\Services\Ai\Mobile\AtlasInboxService;
use App\Services\Ai\Mobile\InboxActionRegistry;
use App\Services\Ai\Mobile\MobileGatewayRateLimiter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MobileInboxController extends Controller
{
    public function index(Request $request, AtlasInboxService $inbox): JsonResponse
    {
        $device = $this->device($request);
        $data = $request->validate([
            'status' => ['nullable', Rule::in([...AiInboxItem::STATUSES, 'all', 'active'])],
            'type' => ['nullable', Rule::in(AiInboxItem::TYPES)],
            'severity' => ['nullable', Rule::in(['debug', 'info', 'warning', 'critical'])],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            'cursor' => ['nullable', 'string', 'max:500'],
        ]);

        $page = $inbox->listPageForMobileSummary(
            $device->user_id,
            $data['status'] ?? 'unread',
            $data['type'] ?? null,
            (int) ($data['limit'] ?? 50),
            $data['severity'] ?? null,
            $data['cursor'] ?? null,
        );

        return response()->json([
            'items' => $page['items']
                ->map(fn (AiInboxItem $item): array => (new AiInboxItemResource($item))->toCompactArray())
                ->values()
                ->all(),
            'unread_count' => AiInboxItem::query()->where('user_id', $device->user_id)->where('status', 'unread')->count(),
            'next_cursor' => $page['next_cursor'],
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

    public function criticalReview(Request $request, AiCriticalInboxReviewReadModel $review): JsonResponse
    {
        $device = $this->device($request);
        $data = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return response()->json($review->review($device->user_id, (int) ($data['limit'] ?? 50)));
    }

    public function markRead(Request $request, AiInboxItem $inboxItem, AtlasInboxService $inbox): JsonResponse
    {
        $this->authorizeItem($request, $inboxItem);

        return response()->json([
            'item' => (new AiInboxItemResource($inbox->markRead($inboxItem)))->resolve(),
        ]);
    }

    public function dismiss(Request $request, AiInboxItem $inboxItem, InboxActionRegistry $actions): JsonResponse
    {
        $this->authorizeItem($request, $inboxItem);
        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $result = $actions->handle(
            $inboxItem,
            'dismiss',
            ['reason' => $data['reason'] ?? null],
            $request->header('Idempotency-Key') ?: null,
            $this->device($request),
        );

        return response()->json([
            'item' => (new AiInboxItemResource($result['item']))->resolve(),
        ]);
    }

    public function snooze(Request $request, AiInboxItem $inboxItem, InboxActionRegistry $actions): JsonResponse
    {
        $this->authorizeItem($request, $inboxItem);
        $data = $request->validate([
            'snoozed_until' => ['required', 'date', 'after:now'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $result = $actions->handle(
            $inboxItem,
            'snooze',
            [
                'snoozed_until' => $data['snoozed_until'],
                'reason' => $data['reason'] ?? null,
            ],
            $request->header('Idempotency-Key') ?: null,
            $this->device($request),
        );

        return response()->json([
            'item' => (new AiInboxItemResource($result['item']))->resolve(),
        ]);
    }

    public function respond(Request $request, AiInboxItem $inboxItem, InboxActionRegistry $actions, MobileGatewayRateLimiter $rateLimiter): JsonResponse
    {
        $this->authorizeItem($request, $inboxItem);
        $data = $request->validate([
            'action' => ['required', 'string', 'max:80'],
            'data' => ['nullable', 'array'],
        ]);
        $device = $this->device($request);

        $rateLimiter->assertSensitiveActionAllowed($request, $device, $inboxItem, $data['action']);

        $result = $actions->handle(
            $inboxItem,
            $data['action'],
            $data['data'] ?? [],
            $request->header('Idempotency-Key') ?: null,
            $device,
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

    public function retryDiscussionBootstrap(Request $request, AiInboxItem $inboxItem, InboxActionRegistry $actions): JsonResponse
    {
        $this->authorizeItem($request, $inboxItem);

        $result = $actions->retryDiscussionBootstrap($inboxItem, $this->device($request));

        return response()->json([
            'ok' => true,
            'idempotent' => $result['idempotent'],
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
