<?php

namespace Tests\Unit;

use App\Services\Ai\Cli\AtlasImageAttachmentService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Tests\TestCase;

class AtlasImageAttachmentServiceTest extends TestCase
{
    private string $root;

    private string $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/atlas-image-attachment-test-'.bin2hex(random_bytes(4));
        $this->workspace = $this->root.'/workspace';
        File::ensureDirectoryExists($this->workspace);

        config(['atlas.ai.tool_permissions.allowed_roots' => [$this->root]]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);

        parent::tearDown();
    }

    public function test_builds_lossless_image_attachment_from_path(): void
    {
        $image = $this->workspace.'/screenshot.png';
        File::put($image, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/p9sAAAAASUVORK5CYII='));

        $attachment = app(AtlasImageAttachmentService::class)->fromPath('screenshot.png', $this->workspace);

        $this->assertSame(realpath($image), $attachment['path']);
        $this->assertSame('image/png', $attachment['mime_type']);
        $this->assertSame('file', $attachment['source']);
        $this->assertSame(hash_file('sha256', $image), $attachment['sha256']);
    }

    public function test_rejects_image_outside_allowed_roots(): void
    {
        config(['atlas.ai.tool_permissions.allowed_roots' => []]);
        $outside = $this->root.'/outside.png';
        File::put($outside, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/p9sAAAAASUVORK5CYII='));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('fora das raizes autorizadas');

        app(AtlasImageAttachmentService::class)->fromPath($outside, $this->workspace);
    }

    public function test_builds_image_attachment_from_uploaded_file(): void
    {
        $source = $this->root.'/upload.png';
        File::put($source, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/p9sAAAAASUVORK5CYII='));

        $upload = new UploadedFile($source, 'print.png', 'image/png', null, true);
        $attachment = app(AtlasImageAttachmentService::class)->fromUploadedFile($upload, $this->workspace, 'mobile_upload');

        $this->assertFileExists($attachment['path']);
        $this->assertStringContainsString('/storage/app/ai/attachments/upload-', $attachment['path']);
        $this->assertSame('image/png', $attachment['mime_type']);
        $this->assertSame('mobile_upload', $attachment['source']);
        $this->assertSame(hash_file('sha256', $source), $attachment['sha256']);

        File::delete($attachment['path']);
    }

    public function test_clipboard_status_reports_capture_capability_contract(): void
    {
        $status = app(AtlasImageAttachmentService::class)->clipboardStatus();

        $this->assertSame('clipboard_visual_input', $status['name']);
        $this->assertContains($status['status'], ['passed', 'needs_review']);
        $this->assertArrayHasKey('capture_ready', $status);
        $this->assertArrayHasKey('current_image_detected', $status);
        $this->assertArrayHasKey('pngpaste', $status);
        $this->assertArrayHasKey('osascript', $status);
    }

    public function test_from_clipboard_reports_when_osascript_cannot_inspect(): void
    {
        if (PHP_OS_FAMILY !== 'Darwin') {
            $this->markTestSkipped('fromClipboard so eh implementado no macOS.');
        }

        $service = new class extends AtlasImageAttachmentService {
            protected function clipboardInfo(): ?string
            {
                return null;
            }
        };

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('osascript');

        $service->fromClipboard($this->workspace);
    }

    public function test_from_clipboard_reports_when_clipboard_is_empty(): void
    {
        if (PHP_OS_FAMILY !== 'Darwin') {
            $this->markTestSkipped('fromClipboard so eh implementado no macOS.');
        }

        $service = new class extends AtlasImageAttachmentService {
            protected function clipboardInfo(): ?string
            {
                return '';
            }
        };

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Clipboard vazio');

        $service->fromClipboard($this->workspace);
    }

    public function test_cleanup_stale_attachments_removes_old_files_and_keeps_recent(): void
    {
        $directory = storage_path('app/ai/attachments');
        File::ensureDirectoryExists($directory);

        $oldPath = $directory.'/clipboard-old-'.bin2hex(random_bytes(4)).'.png';
        $newPath = $directory.'/clipboard-new-'.bin2hex(random_bytes(4)).'.png';
        File::put($oldPath, 'old');
        File::put($newPath, 'new');
        touch($oldPath, time() - (10 * 86400));
        touch($newPath, time() - (1 * 86400));

        $removed = app(AtlasImageAttachmentService::class)->cleanupStaleAttachments(7);

        $this->assertGreaterThanOrEqual(1, $removed);
        $this->assertFileDoesNotExist($oldPath);
        $this->assertFileExists($newPath);

        File::delete($newPath);
    }

    public function test_cleanup_stale_attachments_returns_zero_when_directory_missing(): void
    {
        $directory = storage_path('app/ai/attachments');
        $backup = null;
        if (is_dir($directory)) {
            $backup = $directory.'-backup-'.bin2hex(random_bytes(4));
            rename($directory, $backup);
        }

        try {
            $removed = app(AtlasImageAttachmentService::class)->cleanupStaleAttachments(7);
            $this->assertSame(0, $removed);
        } finally {
            if ($backup !== null) {
                rename($backup, $directory);
            }
        }
    }

    public function test_clipboard_kind_classifies_image(): void
    {
        if (PHP_OS_FAMILY !== 'Darwin') {
            $this->markTestSkipped('clipboardKind so eh implementado no macOS.');
        }

        $service = new class extends AtlasImageAttachmentService {
            protected function clipboardInfo(): ?string
            {
                return '«class PNGf», 32426, «class TIFF», 1275610';
            }
        };

        $this->assertSame('image', $service->clipboardKind());
    }

    public function test_clipboard_kind_classifies_text(): void
    {
        if (PHP_OS_FAMILY !== 'Darwin') {
            $this->markTestSkipped('clipboardKind so eh implementado no macOS.');
        }

        $service = new class extends AtlasImageAttachmentService {
            protected function clipboardInfo(): ?string
            {
                return '«class utf8», 12, «class ut16», 26, string, 12, Unicode text, 24';
            }
        };

        $this->assertSame('text', $service->clipboardKind());
    }

    public function test_clipboard_kind_classifies_empty(): void
    {
        if (PHP_OS_FAMILY !== 'Darwin') {
            $this->markTestSkipped('clipboardKind so eh implementado no macOS.');
        }

        $service = new class extends AtlasImageAttachmentService {
            protected function clipboardInfo(): ?string
            {
                return '';
            }
        };

        $this->assertSame('empty', $service->clipboardKind());
    }

    public function test_clipboard_kind_classifies_unknown_when_osascript_blocked(): void
    {
        if (PHP_OS_FAMILY !== 'Darwin') {
            $this->markTestSkipped('clipboardKind so eh implementado no macOS.');
        }

        $service = new class extends AtlasImageAttachmentService {
            protected function clipboardInfo(): ?string
            {
                return null;
            }
        };

        $this->assertSame('unknown', $service->clipboardKind());
    }

    public function test_from_clipboard_reports_when_clipboard_only_has_text(): void
    {
        if (PHP_OS_FAMILY !== 'Darwin') {
            $this->markTestSkipped('fromClipboard so eh implementado no macOS.');
        }

        $service = new class extends AtlasImageAttachmentService {
            protected function clipboardInfo(): ?string
            {
                return 'utf8 text, "hello world"';
            }
        };

        try {
            $service->fromClipboard($this->workspace);
            $this->fail('Esperava RuntimeException quando clipboard nao tem imagem.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Clipboard nao contem imagem', $e->getMessage());
            $this->assertStringContainsString('hello world', $e->getMessage());
        }
    }
}
