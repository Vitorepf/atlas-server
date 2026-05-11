<?php

namespace App\Support;

class AiAttachmentPayload
{
    /**
     * @return array<int,array<string,mixed>>
     */
    public static function publicAttachmentsFromPayload(mixed $payload): array
    {
        if (! is_array($payload)) {
            return [];
        }

        $attachments = is_array($payload['attachments'] ?? null) ? $payload['attachments'] : [];
        $public = [];

        foreach (array_values((array) ($attachments['images'] ?? [])) as $index => $attachment) {
            if (! is_array($attachment)) {
                continue;
            }

            $public[] = self::publicImageAttachment($attachment, $index);
        }

        foreach (array_values((array) ($attachments['files'] ?? [])) as $index => $attachment) {
            if (! is_array($attachment)) {
                continue;
            }

            $public[] = self::publicFileAttachment($attachment, $index);
        }

        return $public;
    }

    public static function sanitizePayload(mixed $payload): mixed
    {
        if (! is_array($payload)) {
            return $payload;
        }

        $sanitized = $payload;
        if (is_array($sanitized['attachments'] ?? null)) {
            $sanitized['attachments'] = self::sanitizeAttachments($sanitized['attachments']);
        }

        return $sanitized;
    }

    /**
     * @param  array<string,mixed>  $attachments
     * @return array<string,mixed>
     */
    private static function sanitizeAttachments(array $attachments): array
    {
        return [
            ...$attachments,
            'images' => self::publicImageAttachments((array) ($attachments['images'] ?? [])),
            'files' => self::publicFileAttachments((array) ($attachments['files'] ?? [])),
        ];
    }

    /**
     * @param  array<int,mixed>  $attachments
     * @return array<int,array<string,mixed>>
     */
    private static function publicImageAttachments(array $attachments): array
    {
        $public = [];
        foreach (array_values($attachments) as $index => $attachment) {
            if (is_array($attachment)) {
                $public[] = self::publicImageAttachment($attachment, $index);
            }
        }

        return $public;
    }

    /**
     * @param  array<int,mixed>  $attachments
     * @return array<int,array<string,mixed>>
     */
    private static function publicFileAttachments(array $attachments): array
    {
        $public = [];
        foreach (array_values($attachments) as $index => $attachment) {
            if (is_array($attachment)) {
                $public[] = self::publicFileAttachment($attachment, $index);
            }
        }

        return $public;
    }

    /**
     * @param  array<string,mixed>  $attachment
     * @return array<string,mixed>
     */
    private static function publicImageAttachment(array $attachment, int $index): array
    {
        $sha = is_string($attachment['sha256'] ?? null) ? $attachment['sha256'] : null;
        $mime = is_string($attachment['mime_type'] ?? null) ? $attachment['mime_type'] : 'image';

        return [
            'id' => $sha ?: 'image-'.($index + 1),
            'kind' => 'image',
            'name' => self::displayName($attachment, $index, $mime),
            'mime_type' => $mime,
            'bytes' => is_numeric($attachment['bytes'] ?? null) ? (int) $attachment['bytes'] : null,
            'sha256' => $sha,
            'source' => is_string($attachment['source'] ?? null) ? $attachment['source'] : 'upload',
        ];
    }

    /**
     * @param  array<string,mixed>  $attachment
     */
    private static function displayName(array $attachment, int $index, string $mime): string
    {
        $original = is_string($attachment['original_name'] ?? null)
            ? trim(basename(str_replace('\\', '/', $attachment['original_name'])))
            : '';

        return $original !== ''
            ? mb_substr($original, 0, 160)
            : 'imagem '.($index + 1).self::extensionForMime($mime);
    }

    /**
     * @param  array<string,mixed>  $attachment
     * @return array<string,mixed>
     */
    private static function publicFileAttachment(array $attachment, int $index): array
    {
        $sha = is_string($attachment['sha256'] ?? null) ? $attachment['sha256'] : null;

        return [
            'id' => $sha ?: 'file-'.($index + 1),
            'kind' => 'file',
            'name' => is_string($attachment['original_name'] ?? null)
                ? $attachment['original_name']
                : 'arquivo '.($index + 1),
            'mime_type' => is_string($attachment['mime_type'] ?? null)
                ? $attachment['mime_type']
                : 'application/octet-stream',
            'bytes' => is_numeric($attachment['bytes'] ?? null) ? (int) $attachment['bytes'] : null,
            'sha256' => $sha,
            'source' => is_string($attachment['source'] ?? null) ? $attachment['source'] : 'upload',
            'text_available' => (bool) ($attachment['text_available'] ?? false),
            'text_truncated' => (bool) ($attachment['text_truncated'] ?? false),
            'pdf_page_count' => is_numeric($attachment['pdf_page_count'] ?? null)
                ? (int) $attachment['pdf_page_count']
                : null,
            'pdf_processing_status' => is_string($attachment['pdf_processing_status'] ?? null)
                ? $attachment['pdf_processing_status']
                : null,
            'pdf_chunk_count' => is_numeric($attachment['pdf_chunk_count'] ?? null)
                ? (int) $attachment['pdf_chunk_count']
                : null,
            'pdf_render_status' => is_string($attachment['pdf_render_status'] ?? null)
                ? $attachment['pdf_render_status']
                : null,
            'pdf_rendered_page_count' => is_numeric($attachment['pdf_rendered_page_count'] ?? null)
                ? (int) $attachment['pdf_rendered_page_count']
                : null,
            'pdf_ocr_status' => is_string($attachment['pdf_ocr_status'] ?? null)
                ? $attachment['pdf_ocr_status']
                : null,
            'pdf_visual_understanding_status' => is_string($attachment['pdf_visual_understanding_status'] ?? null)
                ? $attachment['pdf_visual_understanding_status']
                : null,
            'office_processing_status' => is_string($attachment['office_processing_status'] ?? null)
                ? $attachment['office_processing_status']
                : null,
            'office_render_status' => is_string($attachment['office_render_status'] ?? null)
                ? $attachment['office_render_status']
                : null,
            'office_rendered_page_count' => is_numeric($attachment['office_rendered_page_count'] ?? null)
                ? (int) $attachment['office_rendered_page_count']
                : null,
        ];
    }

    private static function extensionForMime(string $mime): string
    {
        return match ($mime) {
            'image/jpeg' => '.jpg',
            'image/png' => '.png',
            'image/webp' => '.webp',
            'image/gif' => '.gif',
            default => '',
        };
    }
}
