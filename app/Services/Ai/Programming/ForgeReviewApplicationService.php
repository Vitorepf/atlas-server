<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming;

use App\Http\Controllers\AtlasCodeForgeReviewController;
use App\Models\AtlasProject;
use Illuminate\Http\Request;

/**
 * Application façade for forge review/promotion/rollback so operate-path
 * services (enterprise cert) do not import Http Controllers.
 */
final class ForgeReviewApplicationService
{
    public function __construct(
        private readonly AtlasCodeForgeReviewController $reviews,
    ) {}

    /**
     * @return array{status:int,payload:array<string,mixed>}
     */
    public function store(
        AtlasProject $project,
        string $decision,
        ?string $historyId = null,
        ?string $comment = null,
    ): array {
        $payload = array_filter([
            'decision' => $decision,
            'history_id' => $historyId,
            'comment' => $comment,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');

        $request = Request::create('/_internal/forge/reviews', 'POST', $payload);
        $response = $this->reviews->store($request, $project);

        return ['status' => $response->getStatusCode(), 'payload' => (array) $response->getData(true)];
    }

    /**
     * @return array{status:int,payload:array<string,mixed>}
     */
    public function rollback(
        AtlasProject $project,
        string $promotionId,
        ?string $comment = null,
    ): array {
        $payload = array_filter([
            'comment' => $comment,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');

        $request = Request::create(
            '/_internal/forge/promotions/'.$promotionId.'/rollback',
            'POST',
            $payload,
        );
        $response = $this->reviews->rollback($request, $project, $promotionId);

        return ['status' => $response->getStatusCode(), 'payload' => (array) $response->getData(true)];
    }
}
