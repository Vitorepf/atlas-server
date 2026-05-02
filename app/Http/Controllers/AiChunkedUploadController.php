<?php

namespace App\Http\Controllers;

use App\Services\Ai\Attachments\AiChunkedUploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class AiChunkedUploadController extends Controller
{
    public function start(Request $request, AiChunkedUploadService $uploads): JsonResponse
    {
        $data = $request->validate([
            'client_upload_id' => ['nullable', 'string', 'max:100'],
            'kind' => ['required', 'string', 'in:image,file'],
            'file_name' => ['required', 'string', 'max:180'],
            'mime_type' => ['required', 'string', 'max:120'],
            'total_bytes' => ['required', 'integer', 'min:1', 'max:20971520'],
            'source' => ['nullable', 'string', 'max:80'],
        ]);

        try {
            return response()->json(['upload' => $uploads->start($data)]);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }

    public function chunk(string $upload, Request $request, AiChunkedUploadService $uploads): JsonResponse
    {
        $data = $request->validate([
            'index' => ['required', 'integer', 'min:0', 'max:10000'],
            'total_chunks' => ['required', 'integer', 'min:1', 'max:10001'],
            'offset' => ['nullable', 'integer', 'min:0'],
            'bytes' => ['required', 'integer', 'min:1', 'max:1572864'],
            'chunk_base64' => ['required', 'string'],
        ]);

        try {
            return response()->json(['upload' => $uploads->storeChunk($upload, $data)]);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }

    public function complete(string $upload, AiChunkedUploadService $uploads): JsonResponse
    {
        try {
            return response()->json(['upload' => $uploads->complete($upload)]);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }
}
