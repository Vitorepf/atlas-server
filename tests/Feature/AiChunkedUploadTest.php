<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

class AiChunkedUploadTest extends TestCase
{
    private array $headers = ['X-Atlas-Token' => 'testing-atlas-token-with-enough-length'];

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('atlas.token', 'testing-atlas-token-with-enough-length');
    }

    public function test_chunked_upload_assembles_file_and_reports_resume_chunks(): void
    {
        $start = $this
            ->withHeaders($this->headers)
            ->postJson('/ai/uploads/chunks/start', [
                'client_upload_id' => 'unit-test-upload',
                'kind' => 'file',
                'file_name' => 'documento.txt',
                'mime_type' => 'text/plain',
                'total_bytes' => 11,
                'source' => 'test',
            ])
            ->assertOk()
            ->json('upload');

        $uploadId = $start['id'];

        $this
            ->withHeaders($this->headers)
            ->postJson("/ai/uploads/chunks/{$uploadId}/chunk", [
                'index' => 0,
                'total_chunks' => 2,
                'offset' => 0,
                'bytes' => 6,
                'chunk_base64' => base64_encode('hello '),
            ])
            ->assertOk()
            ->assertJsonPath('upload.received_chunks.0', 0);

        $resume = $this
            ->withHeaders($this->headers)
            ->postJson('/ai/uploads/chunks/start', [
                'client_upload_id' => 'unit-test-upload',
                'kind' => 'file',
                'file_name' => 'documento.txt',
                'mime_type' => 'text/plain',
                'total_bytes' => 11,
                'source' => 'test',
            ])
            ->assertOk()
            ->json('upload');

        $this->assertSame([0], $resume['received_chunks']);

        $this
            ->withHeaders($this->headers)
            ->postJson("/ai/uploads/chunks/{$uploadId}/chunk", [
                'index' => 1,
                'total_chunks' => 2,
                'offset' => 6,
                'bytes' => 5,
                'chunk_base64' => base64_encode('world'),
            ])
            ->assertOk();

        $complete = $this
            ->withHeaders($this->headers)
            ->postJson("/ai/uploads/chunks/{$uploadId}/complete")
            ->assertOk()
            ->assertJsonPath('upload.bytes', 11)
            ->json('upload');

        $path = storage_path("app/ai/uploads/{$uploadId}/assembled/documento.txt");
        $this->assertSame('hello world', File::get($path));

        File::deleteDirectory(storage_path("app/ai/uploads/{$uploadId}"));
        $this->assertSame(hash('sha256', 'hello world'), $complete['sha256']);
    }
}
