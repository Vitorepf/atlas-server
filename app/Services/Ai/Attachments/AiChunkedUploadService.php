<?php

namespace App\Services\Ai\Attachments;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;

class AiChunkedUploadService
{
    /**
     * @return array<string,mixed>
     */
    public function start(array $input): array
    {
        $uploadId = $this->uploadId((string) ($input['client_upload_id'] ?? ''));
        $dir = $this->uploadDir($uploadId);
        File::ensureDirectoryExists($dir.'/chunks');

        $meta = [
            'id' => $uploadId,
            'kind' => $this->safeKind((string) ($input['kind'] ?? 'file')),
            'file_name' => $this->safeFileName((string) ($input['file_name'] ?? 'arquivo')),
            'mime_type' => $this->safeMime((string) ($input['mime_type'] ?? 'application/octet-stream')),
            'total_bytes' => max(0, (int) ($input['total_bytes'] ?? 0)),
            'source' => is_string($input['source'] ?? null) ? mb_substr($input['source'], 0, 80) : 'chunked_upload',
            'created_at' => now()->toJSON(),
            'updated_at' => now()->toJSON(),
        ];

        $existing = $this->readMeta($uploadId);
        if ($existing) {
            $meta = [
                ...$existing,
                ...array_filter($meta, fn (mixed $value): bool => $value !== '' && $value !== 0),
                'updated_at' => now()->toJSON(),
            ];
        }

        File::put($this->metaPath($uploadId), json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return [
            'id' => $uploadId,
            'received_chunks' => $this->receivedChunks($uploadId),
            'meta' => $meta,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function storeChunk(string $uploadId, array $input): array
    {
        $uploadId = $this->assertUploadId($uploadId);
        $meta = $this->readMeta($uploadId);
        if (! $meta) {
            throw new RuntimeException('Upload em chunks nao iniciado.');
        }

        $index = (int) ($input['index'] ?? -1);
        $totalChunks = (int) ($input['total_chunks'] ?? 0);
        $bytes = (int) ($input['bytes'] ?? 0);
        $chunkBase64 = is_string($input['chunk_base64'] ?? null) ? $input['chunk_base64'] : '';
        if ($index < 0 || $totalChunks <= 0 || $bytes <= 0 || $chunkBase64 === '') {
            throw new RuntimeException('Chunk invalido.');
        }

        $maxBytes = max(262_144, (int) config('atlas.attachments.chunked_upload.chunk_max_bytes', 1_572_864));
        if ($bytes > $maxBytes) {
            throw new RuntimeException('Chunk grande demais.');
        }

        $decoded = base64_decode($chunkBase64, true);
        if (! is_string($decoded) || strlen($decoded) !== $bytes) {
            throw new RuntimeException('Chunk corrompido.');
        }

        File::ensureDirectoryExists($this->uploadDir($uploadId).'/chunks');
        File::put($this->chunkPath($uploadId, $index), $decoded);

        $meta['total_chunks'] = $totalChunks;
        $meta['updated_at'] = now()->toJSON();
        File::put($this->metaPath($uploadId), json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return [
            'id' => $uploadId,
            'index' => $index,
            'received_chunks' => $this->receivedChunks($uploadId),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function complete(string $uploadId): array
    {
        $uploadId = $this->assertUploadId($uploadId);
        $meta = $this->readMeta($uploadId);
        if (! $meta) {
            throw new RuntimeException('Upload em chunks nao encontrado.');
        }

        $totalChunks = (int) ($meta['total_chunks'] ?? 0);
        if ($totalChunks <= 0) {
            throw new RuntimeException('Upload sem chunks.');
        }

        $assembled = $this->assembledPath($uploadId, (string) ($meta['file_name'] ?? 'arquivo'));
        File::ensureDirectoryExists(dirname($assembled));
        $out = fopen($assembled, 'wb');
        if (! $out) {
            throw new RuntimeException('Nao foi possivel montar upload.');
        }

        try {
            for ($index = 0; $index < $totalChunks; $index++) {
                $chunk = $this->chunkPath($uploadId, $index);
                if (! File::isFile($chunk)) {
                    throw new RuntimeException("Chunk {$index} ausente.");
                }

                $in = fopen($chunk, 'rb');
                if (! $in) {
                    throw new RuntimeException("Chunk {$index} ilegivel.");
                }

                stream_copy_to_stream($in, $out);
                fclose($in);
            }
        } finally {
            fclose($out);
        }

        $bytes = File::size($assembled);
        $expected = (int) ($meta['total_bytes'] ?? 0);
        if ($expected > 0 && $bytes !== $expected) {
            File::delete($assembled);
            throw new RuntimeException('Upload montado com tamanho divergente.');
        }

        $meta = [
            ...$meta,
            'assembled_path' => $assembled,
            'bytes' => $bytes,
            'sha256' => hash_file('sha256', $assembled),
            'completed_at' => now()->toJSON(),
            'updated_at' => now()->toJSON(),
        ];
        File::put($this->metaPath($uploadId), json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $this->publicUpload($meta);
    }

    /**
     * @return array<string,mixed>
     */
    public function consume(string $uploadId): array
    {
        $uploadId = $this->assertUploadId($uploadId);
        $meta = $this->readMeta($uploadId);
        if (! $meta || ! is_string($meta['assembled_path'] ?? null) || ! File::isFile($meta['assembled_path'])) {
            throw new RuntimeException('Upload em chunks ainda nao foi concluido.');
        }

        return $meta;
    }

    /**
     * @return array<int,int>
     */
    private function receivedChunks(string $uploadId): array
    {
        $files = glob($this->uploadDir($uploadId).'/chunks/chunk-*.bin') ?: [];

        return collect($files)
            ->map(function (string $path): ?int {
                preg_match('/chunk-(\d+)\.bin$/', $path, $matches);

                return isset($matches[1]) ? (int) $matches[1] : null;
            })
            ->filter(fn (?int $index): bool => $index !== null)
            ->sort()
            ->values()
            ->all();
    }

    private function uploadId(string $clientId): string
    {
        $clientId = preg_replace('/[^A-Za-z0-9._-]+/', '-', $clientId) ?: '';
        $clientId = trim($clientId, '.-_');

        return $clientId !== ''
            ? 'up_'.mb_substr($clientId, 0, 80)
            : 'up_'.Str::uuid()->toString();
    }

    private function assertUploadId(string $uploadId): string
    {
        $uploadId = preg_replace('/[^A-Za-z0-9._-]+/', '', $uploadId) ?: '';
        if ($uploadId === '') {
            throw new RuntimeException('Upload id invalido.');
        }

        return $uploadId;
    }

    private function safeKind(string $kind): string
    {
        return in_array($kind, ['image', 'file'], true) ? $kind : 'file';
    }

    private function safeFileName(string $name): string
    {
        $name = trim($name) !== '' ? trim($name) : 'arquivo';
        $name = preg_replace('/[^\pL\pN._ -]+/u', '_', $name) ?: 'arquivo';

        return mb_substr($name, 0, 180);
    }

    private function safeMime(string $mime): string
    {
        $mime = trim($mime);

        return preg_match('/^[A-Za-z0-9.+_-]+\/[A-Za-z0-9.+_-]+$/', $mime)
            ? $mime
            : 'application/octet-stream';
    }

    private function uploadDir(string $uploadId): string
    {
        return storage_path('app/ai/uploads/'.$uploadId);
    }

    private function metaPath(string $uploadId): string
    {
        return $this->uploadDir($uploadId).'/meta.json';
    }

    private function chunkPath(string $uploadId, int $index): string
    {
        return $this->uploadDir($uploadId).'/chunks/chunk-'.$index.'.bin';
    }

    private function assembledPath(string $uploadId, string $fileName): string
    {
        return $this->uploadDir($uploadId).'/assembled/'.$this->safeFileName($fileName);
    }

    /**
     * @return array<string,mixed>|null
     */
    private function readMeta(string $uploadId): ?array
    {
        $path = $this->metaPath($uploadId);
        if (! File::isFile($path)) {
            return null;
        }

        $decoded = json_decode(File::get($path), true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param  array<string,mixed>  $meta
     * @return array<string,mixed>
     */
    private function publicUpload(array $meta): array
    {
        return [
            'id' => $meta['id'],
            'kind' => $meta['kind'] ?? 'file',
            'file_name' => $meta['file_name'] ?? 'arquivo',
            'mime_type' => $meta['mime_type'] ?? 'application/octet-stream',
            'bytes' => $meta['bytes'] ?? $meta['total_bytes'] ?? null,
            'sha256' => $meta['sha256'] ?? null,
            'completed_at' => $meta['completed_at'] ?? null,
        ];
    }
}
