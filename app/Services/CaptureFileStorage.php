<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class CaptureFileStorage
{
    public function store(UploadedFile $file, string $kind): array
    {
        $hash = hash_file('sha256', $file->getRealPath());
        $extension = $this->extensionFor($file);
        $relativePath = $kind.'/'.$hash.$extension;
        $disk = Storage::disk('atlas');
        $created = false;

        if (! $disk->exists($relativePath)) {
            $stream = fopen($file->getRealPath(), 'rb');

            try {
                $disk->put($relativePath, $stream);
                $created = true;
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
        }

        return [
            'relative_path' => $relativePath,
            'absolute_path' => $disk->path($relativePath),
            'sha256' => $hash,
            'size_bytes' => $file->getSize(),
            'mime_type' => $file->getMimeType(),
            'created' => $created,
        ];
    }

    public function deleteIfCreated(?array $storedFile): void
    {
        if (! ($storedFile['created'] ?? false)) {
            return;
        }

        Storage::disk('atlas')->delete($storedFile['relative_path']);
    }

    private function extensionFor(UploadedFile $file): string
    {
        $byMime = [
            'audio/aac' => '.aac',
            'audio/mp4' => '.m4a',
            'audio/mpeg' => '.mp3',
            'audio/wav' => '.wav',
            'audio/x-m4a' => '.m4a',
            'image/heic' => '.heic',
            'image/heif' => '.heif',
            'image/jpeg' => '.jpg',
            'image/png' => '.png',
            'image/webp' => '.webp',
        ];

        $mime = $file->getMimeType();
        if ($mime && isset($byMime[$mime])) {
            return $byMime[$mime];
        }

        $extension = Str::lower($file->getClientOriginalExtension());
        $safe = ['aac', 'bin', 'heic', 'heif', 'jpeg', 'jpg', 'm4a', 'mp3', 'png', 'wav', 'webp'];

        return in_array($extension, $safe, true) ? '.'.$extension : '.bin';
    }
}
