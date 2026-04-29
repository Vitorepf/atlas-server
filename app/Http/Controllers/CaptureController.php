<?php

namespace App\Http\Controllers;

use App\Http\Requests\IndexCaptureRequest;
use App\Http\Requests\StoreCaptureRequest;
use App\Http\Requests\UpdateCaptureRequest;
use App\Http\Resources\CaptureResource;
use App\Models\Capture;
use App\Services\CaptureService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class CaptureController extends Controller
{
    public function index(IndexCaptureRequest $request): JsonResponse
    {
        $data = $request->validated();
        $limit = (int) ($data['limit'] ?? 50);
        $since = $data['since'] ?? null;

        $query = Capture::query();

        if ($since) {
            $query->withTrashed()->where('updated_at', '>', $since);
        }

        if (isset($data['domain'])) {
            $query->where('domain', $data['domain']);
        }

        if (isset($data['kind'])) {
            $query->where('kind', $data['kind']);
        }

        if (isset($data['cursor'])) {
            $cursor = Capture::withTrashed()->find($data['cursor']);

            if ($cursor) {
                if ($since) {
                    $query->where(function ($query) use ($cursor): void {
                        $query
                            ->where('updated_at', '>', $cursor->updated_at)
                            ->orWhere(function ($query) use ($cursor): void {
                                $query->where('updated_at', $cursor->updated_at)->where('id', '>', $cursor->id);
                            });
                    });
                } else {
                    $query->where(function ($query) use ($cursor): void {
                        $query
                            ->where('captured_at', '<', $cursor->captured_at)
                            ->orWhere(function ($query) use ($cursor): void {
                                $query->where('captured_at', $cursor->captured_at)->where('id', '<', $cursor->id);
                            });
                    });
                }
            }
        }

        $query = $since
            ? $query->orderBy('updated_at')->orderBy('id')
            : $query->orderByDesc('captured_at')->orderByDesc('id');

        $captures = $query->limit($limit + 1)->get();
        $hasMore = $captures->count() > $limit;
        $page = $hasMore ? $captures->take($limit)->values() : $captures;

        return response()->json([
            'captures' => CaptureResource::collection($page)->resolve(),
            'next_cursor' => $hasMore ? $page->last()?->id : null,
            'has_more' => $hasMore,
        ]);
    }

    public function store(StoreCaptureRequest $request, CaptureService $captures): JsonResponse
    {
        $result = $captures->create($request->validated(), $request->file('file'));

        return (new CaptureResource($result['capture']))
            ->response()
            ->setStatusCode($result['created'] ? 201 : 200);
    }

    public function show(Capture $capture): CaptureResource
    {
        return new CaptureResource($capture);
    }

    public function update(UpdateCaptureRequest $request, Capture $capture): CaptureResource
    {
        $capture->update($request->validated());

        return new CaptureResource($capture->refresh());
    }

    public function destroy(Capture $capture): CaptureResource
    {
        $capture->delete();

        return new CaptureResource($capture->refresh());
    }

    public function file(Capture $capture): BinaryFileResponse
    {
        abort_if(! $capture->content_file_path, 404, 'Capture has no file.');

        $disk = Storage::disk('atlas');
        abort_if(! $disk->exists($capture->content_file_path), 404, 'Capture file not found.');

        return response()->file($disk->path($capture->content_file_path), [
            'Content-Type' => $capture->content_mime_type ?: 'application/octet-stream',
            'Content-Disposition' => 'inline; filename="'.basename($capture->content_file_path).'"',
        ]);
    }

    public function transcription(Capture $capture): JsonResponse
    {
        return response()->json([
            'status' => $capture->transcription_status,
            'engine' => $capture->transcription_engine,
            'text' => $capture->content_text,
            'duration_seconds' => $capture->content_duration_ms ? $capture->content_duration_ms / 1000 : null,
            'processed_at' => $capture->transcription_status === 'done' ? $capture->updated_at?->toJSON() : null,
            'error' => $capture->transcription_error,
        ]);
    }
}
