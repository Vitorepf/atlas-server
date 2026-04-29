<?php

namespace App\Http\Controllers;

use App\Services\Digital\RizeWebhookIngestor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RizeWebhookController extends Controller
{
    public function __invoke(Request $request, RizeWebhookIngestor $ingestor): JsonResponse
    {
        if (! $this->isAuthorized($request)) {
            return response()->json([
                'error' => [
                    'code' => 'UNAUTHORIZED',
                    'message' => 'Invalid Rize webhook secret.',
                ],
            ], 401);
        }

        $payload = $request->json()->all();

        if ($payload === []) {
            $payload = $request->all();
        }

        $event = $ingestor->ingest($payload);

        return response()->json([
            'status' => $event->status,
            'event_id' => $event->id,
            'source_event_id' => $event->source_event_id,
            'event_type' => $event->event_type,
        ], $event->status === 'failed' ? 422 : 202);
    }

    private function isAuthorized(Request $request): bool
    {
        $expected = config('services.rize.webhook_secret');
        $atlasToken = config('atlas.token');

        if (is_string($expected) && strlen($expected) >= 24) {
            $provided = (string) (
                $request->header('X-Rize-Webhook-Secret')
                ?: $request->query('secret', '')
            );

            return hash_equals($expected, $provided);
        }

        if (is_string($atlasToken) && strlen($atlasToken) >= 24) {
            return hash_equals($atlasToken, (string) $request->header('X-Atlas-Token', ''));
        }

        return false;
    }
}
