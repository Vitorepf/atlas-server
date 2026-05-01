<?php

namespace App\Services\Ai\Cli;

use Illuminate\Support\Facades\File;
use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

class AtlasImageAttachmentService
{
    private const MAX_IMAGE_BYTES = 20_971_520;

    /** @var array<int,string> */
    private const ALLOWED_MIME_TYPES = [
        'image/png',
        'image/jpeg',
        'image/webp',
        'image/gif',
    ];

    /**
     * @param  array<int,string>  $paths
     * @return array<int,array<string,mixed>>
     */
    public function fromPaths(array $paths, string $workspace): array
    {
        $attachments = [];
        foreach ($paths as $path) {
            $path = trim($path);
            if ($path === '') {
                continue;
            }

            $attachments[] = $this->fromPath($path, $workspace, 'file');
        }

        return $this->dedupe($attachments);
    }

    /**
     * @return array<string,mixed>
     */
    public function fromPath(string $path, string $workspace, string $source = 'file'): array
    {
        $originalPath = $path;
        $path = $this->expandPath($path, $workspace);
        $resolved = realpath($path);

        if (! $resolved || ! File::isFile($resolved)) {
            throw new RuntimeException("Imagem nao encontrada: {$originalPath}");
        }

        if (! $this->isInsideAllowedRoot($resolved, $workspace)) {
            throw new RuntimeException("Imagem fora das raizes autorizadas: {$originalPath}");
        }

        $bytes = File::size($resolved);
        if ($bytes <= 0 || $bytes > self::MAX_IMAGE_BYTES) {
            throw new RuntimeException("Imagem invalida ou grande demais: {$originalPath}");
        }

        $mime = $this->mimeType($resolved);
        if (! in_array($mime, self::ALLOWED_MIME_TYPES, true)) {
            throw new RuntimeException("Formato de imagem nao suportado ({$mime}): {$originalPath}");
        }

        return [
            'path' => $resolved,
            'source' => $source,
            'original_path' => $originalPath,
            'mime_type' => $mime,
            'bytes' => $bytes,
            'sha256' => hash_file('sha256', $resolved),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function fromClipboard(string $workspace): array
    {
        if (PHP_OS_FAMILY !== 'Darwin') {
            throw new RuntimeException('Clipboard image so esta implementado no macOS.');
        }

        $target = storage_path('app/ai/attachments/clipboard-'.now()->format('Ymd-His').'-'.bin2hex(random_bytes(4)).'.png');
        File::ensureDirectoryExists(dirname($target));

        if (! $this->captureClipboardWithPngpaste($target)) {
            $this->captureClipboardWithOsascript($target);
        }

        return $this->fromPath($target, $workspace, 'clipboard');
    }

    /**
     * @param  array<int,array<string,mixed>>  $attachments
     * @return array<int,array<string,mixed>>
     */
    public function dedupe(array $attachments): array
    {
        $byHash = [];
        foreach ($attachments as $attachment) {
            $hash = is_string($attachment['sha256'] ?? null) ? $attachment['sha256'] : null;
            if (! $hash || isset($byHash[$hash])) {
                continue;
            }

            $byHash[$hash] = $attachment;
        }

        return array_values($byHash);
    }

    private function captureClipboardWithPngpaste(string $target): bool
    {
        $binary = (new ExecutableFinder())->find('pngpaste');
        if (! $binary) {
            return false;
        }

        $process = new Process([$binary, $target], base_path());
        $process->setTimeout(10);
        $process->run();

        return $process->isSuccessful() && File::isFile($target) && File::size($target) > 0;
    }

    private function captureClipboardWithOsascript(string $target): void
    {
        if ($this->writeClipboardClassToFile('PNGf', $target)) {
            return;
        }

        $tiffTarget = $target.'.tiff';
        if ($this->writeClipboardClassToFile('TIFF', $tiffTarget)) {
            $process = new Process(['/usr/bin/sips', '-s', 'format', 'png', $tiffTarget, '--out', $target], base_path());
            $process->setTimeout(10);
            $process->run();
            File::delete($tiffTarget);

            if ($process->isSuccessful() && File::isFile($target) && File::size($target) > 0) {
                return;
            }
        }

        File::delete($target);
        throw new RuntimeException('Nao encontrei imagem no clipboard. Tire/copie o screenshot e rode /paste-image de novo.');
    }

    private function writeClipboardClassToFile(string $clipboardClass, string $target): bool
    {
        $escapedTarget = str_replace(['\\', '"'], ['\\\\', '\"'], $target);
        $process = new Process([
            '/usr/bin/osascript',
            '-e', 'set outputPath to POSIX file "'.$escapedTarget.'"',
            '-e', 'try',
            '-e', 'set imageData to the clipboard as «class '.$clipboardClass.'»',
            '-e', 'on error',
            '-e', 'error "clipboard_does_not_contain_image"',
            '-e', 'end try',
            '-e', 'set fileRef to open for access outputPath with write permission',
            '-e', 'try',
            '-e', 'set eof of fileRef to 0',
            '-e', 'write imageData to fileRef',
            '-e', 'close access fileRef',
            '-e', 'on error errMsg',
            '-e', 'try',
            '-e', 'close access fileRef',
            '-e', 'end try',
            '-e', 'error errMsg',
            '-e', 'end try',
        ], base_path());
        $process->setTimeout(10);
        $process->run();

        if (! $process->isSuccessful() || ! File::isFile($target) || File::size($target) <= 0) {
            File::delete($target);

            return false;
        }

        return true;
    }

    private function expandPath(string $path, string $workspace): string
    {
        if (str_starts_with($path, '~/')) {
            $home = rtrim((string) ($_SERVER['HOME'] ?? getenv('HOME') ?: dirname(base_path())), DIRECTORY_SEPARATOR);

            return $home.substr($path, 1);
        }

        if (str_starts_with($path, '/')) {
            return $path;
        }

        return rtrim($workspace, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$path;
    }

    private function mimeType(string $path): string
    {
        $imageInfo = @getimagesize($path);
        if (is_array($imageInfo) && is_string($imageInfo['mime'] ?? null)) {
            return $imageInfo['mime'];
        }

        return File::mimeType($path) ?: 'application/octet-stream';
    }

    private function isInsideAllowedRoot(string $path, string $workspace): bool
    {
        $roots = config('atlas.ai.tool_permissions.allowed_roots', []);
        $roots = is_array($roots) ? $roots : [];
        $roots[] = $workspace;
        $roots[] = storage_path('app/ai/attachments');

        foreach ($roots as $root) {
            if (! is_string($root) || trim($root) === '') {
                continue;
            }

            $resolvedRoot = realpath($this->expandPath($root, $workspace));
            if (! $resolvedRoot || ! is_dir($resolvedRoot)) {
                continue;
            }

            if ($path === $resolvedRoot || str_starts_with($path, rtrim($resolvedRoot, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR)) {
                return true;
            }
        }

        return false;
    }
}
