<?php

namespace App\Services\Ai\Cli;

use Illuminate\Http\UploadedFile;
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
     * @param  array<int,UploadedFile>  $files
     * @return array<int,array<string,mixed>>
     */
    public function fromUploadedFiles(array $files, string $workspace, string $source = 'upload'): array
    {
        $attachments = [];
        foreach ($files as $file) {
            if (! $file instanceof UploadedFile) {
                continue;
            }

            $attachments[] = $this->fromUploadedFile($file, $workspace, $source);
        }

        return $this->dedupe($attachments);
    }

    /**
     * @return array<string,mixed>
     */
    public function fromUploadedFile(UploadedFile $file, string $workspace, string $source = 'upload'): array
    {
        if (! $file->isValid()) {
            throw new RuntimeException('Imagem enviada invalida.');
        }

        $bytes = (int) $file->getSize();
        if ($bytes <= 0 || $bytes > self::MAX_IMAGE_BYTES) {
            throw new RuntimeException('Imagem enviada invalida ou grande demais.');
        }

        $mime = $file->getMimeType() ?: 'application/octet-stream';
        if (! in_array($mime, self::ALLOWED_MIME_TYPES, true)) {
            throw new RuntimeException("Formato de imagem nao suportado ({$mime}).");
        }

        $target = storage_path('app/ai/attachments/upload-'.now()->format('Ymd-His').'-'.bin2hex(random_bytes(4)).$this->extensionForMime($mime));
        File::ensureDirectoryExists(dirname($target));

        $realPath = $file->getRealPath();
        if (! is_string($realPath) || $realPath === '' || ! File::isFile($realPath)) {
            throw new RuntimeException('Arquivo temporario da imagem enviada nao encontrado.');
        }

        File::copy($realPath, $target);

        return $this->fromPath($target, $workspace, $source);
    }

    /**
     * @return array<string,mixed>
     */
    public function fromLocalUploadPath(string $path, string $originalName, string $mime, string $workspace, string $source = 'chunked_upload'): array
    {
        $resolved = realpath($path);
        if (! $resolved || ! File::isFile($resolved)) {
            throw new RuntimeException('Imagem enviada em chunks nao encontrada.');
        }

        $mime = $this->mimeType($resolved) ?: $mime;
        if (! in_array($mime, self::ALLOWED_MIME_TYPES, true)) {
            throw new RuntimeException("Formato de imagem nao suportado ({$mime}).");
        }

        $target = storage_path('app/ai/attachments/upload-'.now()->format('Ymd-His').'-'.bin2hex(random_bytes(4)).$this->extensionForMime($mime));
        File::ensureDirectoryExists(dirname($target));
        File::copy($resolved, $target);

        return $this->fromPath($target, $workspace, $source);
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
     * @return array<string,mixed>
     */
    public function clipboardStatus(): array
    {
        $pngpaste = (new ExecutableFinder)->find('pngpaste');
        $osascript = '/usr/bin/osascript';
        $sips = '/usr/bin/sips';
        $macos = PHP_OS_FAMILY === 'Darwin';
        $osascriptReady = is_executable($osascript);
        $sipsReady = is_executable($sips);
        $captureReady = $macos && ($pngpaste !== null || ($osascriptReady && $sipsReady));
        $clipboardInfo = $macos && $osascriptReady ? $this->clipboardInfo() : null;
        $currentImage = is_string($clipboardInfo) && $this->clipboardInfoContainsImage($clipboardInfo);

        return [
            'name' => 'clipboard_visual_input',
            'status' => $captureReady ? 'passed' : 'needs_review',
            'detail' => match (true) {
                ! $macos => 'Clipboard visual automatico so esta disponivel no macOS.',
                ! $captureReady => 'Instale pngpaste ou garanta /usr/bin/osascript + /usr/bin/sips disponiveis.',
                $currentImage => 'Runtime de clipboard pronto; imagem detectada no clipboard atual.',
                default => 'Runtime de clipboard pronto; clipboard atual nao parece conter imagem.',
            },
            'macos' => $macos,
            'pngpaste' => [
                'available' => $pngpaste !== null,
                'path' => $pngpaste,
            ],
            'osascript' => [
                'available' => $osascriptReady,
                'path' => $osascript,
            ],
            'sips' => [
                'available' => $sipsReady,
                'path' => $sips,
            ],
            'capture_ready' => $captureReady,
            'current_image_detected' => $currentImage,
            'clipboard_info' => $clipboardInfo,
        ];
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
        $binary = (new ExecutableFinder)->find('pngpaste');
        if (! $binary) {
            return false;
        }

        $process = new Process([$binary, $target], base_path());
        $process->setTimeout(10);
        $process->run();

        return $process->isSuccessful() && File::isFile($target) && File::size($target) > 0;
    }

    private function clipboardInfo(): ?string
    {
        $process = new Process(['/usr/bin/osascript', '-e', 'clipboard info'], base_path());
        $process->setTimeout(5);
        $process->run();

        if (! $process->isSuccessful()) {
            return null;
        }

        $output = trim($process->getOutput());

        return $output !== '' ? $output : null;
    }

    private function clipboardInfoContainsImage(string $clipboardInfo): bool
    {
        foreach (['PNGf', 'TIFF', 'JPEG', 'GIFf'] as $class) {
            if (str_contains($clipboardInfo, $class)) {
                return true;
            }
        }

        return false;
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

    private function extensionForMime(string $mime): string
    {
        return match ($mime) {
            'image/jpeg' => '.jpg',
            'image/webp' => '.webp',
            'image/gif' => '.gif',
            default => '.png',
        };
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
