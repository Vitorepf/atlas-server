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
}
