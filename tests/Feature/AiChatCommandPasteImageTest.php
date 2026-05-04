<?php

namespace Tests\Feature;

use App\Console\Commands\AiChatCommand;
use App\Services\Ai\Cli\AtlasImageAttachmentService;
use Illuminate\Support\Facades\File;
use Mockery;
use ReflectionMethod;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class AiChatCommandPasteImageTest extends TestCase
{
    private string $workspace;

    protected function setUp(): void
    {
        parent::setUp();
        $this->workspace = sys_get_temp_dir().'/atlas-chat-paste-test-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($this->workspace);
        $this->workspace = realpath($this->workspace) ?: $this->workspace;
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->workspace);
        Mockery::close();
        parent::tearDown();
    }

    public function test_classify_bracketed_paste_empty_payload_returns_clipboard_image_kind(): void
    {
        $command = new AiChatCommand();
        $command->setLaravel(app());
        $method = new ReflectionMethod($command, 'classifyBracketedPaste');
        $method->setAccessible(true);

        $result = $method->invoke($command, '');

        $this->assertSame('clipboard_image', $result['kind']);
    }

    public function test_classify_bracketed_paste_whitespace_payload_returns_clipboard_image_kind(): void
    {
        $command = new AiChatCommand();
        $command->setLaravel(app());
        $method = new ReflectionMethod($command, 'classifyBracketedPaste');
        $method->setAccessible(true);

        $result = $method->invoke($command, "\t  \n");

        $this->assertSame('clipboard_image', $result['kind']);
    }

    public function test_dispatch_bracketed_paste_empty_attaches_clipboard_image(): void
    {
        $imageFile = $this->workspace.'/clip.png';
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkAAIAAAoAAv/lxKUAAAAASUVORK5CYII=');
        File::put($imageFile, (string) $png);

        $images = Mockery::mock(AtlasImageAttachmentService::class);
        $images->shouldReceive('fromClipboard')
            ->once()
            ->with($this->workspace)
            ->andReturn([
                'path' => $imageFile,
                'source' => 'clipboard',
                'original_path' => 'clipboard',
                'mime_type' => 'image/png',
                'bytes' => filesize($imageFile),
                'sha256' => hash_file('sha256', $imageFile),
            ]);
        $images->shouldReceive('dedupe')->andReturnUsing(fn ($a) => $a);

        $command = new AiChatCommand();
        $command->setLaravel(app());
        $command->setOutput(new \Illuminate\Console\OutputStyle(new ArrayInput([]), new BufferedOutput()));
        $reflectIn = new \ReflectionProperty($command, 'input');
        $reflectIn->setAccessible(true);
        $reflectIn->setValue($command, new ArrayInput([], $command->getDefinition()));

        $applyPaste = new ReflectionMethod($command, 'applyBracketedPasteClassification');
        $applyPaste->setAccessible(true);

        $buffer = '';
        $label = 'atlas';
        $pending = [];

        $applyPaste->invokeArgs($command, [
            ['kind' => 'clipboard_image'],
            $images,
            $this->workspace,
            &$buffer,
            &$label,
            &$pending,
        ]);

        $this->assertCount(1, $pending);
        $this->assertSame($imageFile, $pending[0]['path']);
        $this->assertStringContainsString('imagem 1', $label);
    }

    public function test_classify_bracketed_paste_text_payload_is_text(): void
    {
        $command = new AiChatCommand();
        $command->setLaravel(app());
        $method = new ReflectionMethod($command, 'classifyBracketedPaste');
        $method->setAccessible(true);

        $result = $method->invoke($command, 'git status');

        $this->assertSame('text', $result['kind']);
    }

    public function test_classify_bracketed_paste_absolute_path_to_png_is_image_path(): void
    {
        $imageFile = $this->workspace.'/screenshot.png';
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkAAIAAAoAAv/lxKUAAAAASUVORK5CYII=');
        File::put($imageFile, (string) $png);

        $command = new AiChatCommand();
        $command->setLaravel(app());
        $method = new ReflectionMethod($command, 'classifyBracketedPaste');
        $method->setAccessible(true);

        $result = $method->invoke($command, $imageFile);

        $this->assertSame('image_path', $result['kind']);
        $this->assertSame($imageFile, $result['path']);
    }

    public function test_classify_bracketed_paste_file_url_is_image_path(): void
    {
        $imageFile = $this->workspace.'/screenshot 2.png';
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkAAIAAAoAAv/lxKUAAAAASUVORK5CYII=');
        File::put($imageFile, (string) $png);

        $command = new AiChatCommand();
        $command->setLaravel(app());
        $method = new ReflectionMethod($command, 'classifyBracketedPaste');
        $method->setAccessible(true);

        $url = 'file://'.rawurlencode($imageFile);
        $url = str_replace('%2F', '/', $url);
        $result = $method->invoke($command, $url);

        $this->assertSame('image_path', $result['kind']);
        $this->assertSame($imageFile, $result['path']);
    }

    public function test_apply_bracketed_paste_image_path_calls_from_paths_and_updates_pending(): void
    {
        $imageFile = $this->workspace.'/from-paste.png';
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkAAIAAAoAAv/lxKUAAAAASUVORK5CYII=');
        File::put($imageFile, (string) $png);

        $images = Mockery::mock(AtlasImageAttachmentService::class);
        $images->shouldReceive('fromPaths')
            ->once()
            ->with([$imageFile], $this->workspace)
            ->andReturn([
                [
                    'path' => $imageFile,
                    'source' => 'file',
                    'original_path' => $imageFile,
                    'mime_type' => 'image/png',
                    'bytes' => filesize($imageFile),
                    'sha256' => hash_file('sha256', $imageFile),
                ],
            ]);
        $images->shouldReceive('dedupe')->andReturnUsing(fn ($a) => $a);

        $command = new AiChatCommand();
        $command->setLaravel(app());
        $command->setOutput(new \Illuminate\Console\OutputStyle(new ArrayInput([]), new BufferedOutput()));
        $reflectIn = new \ReflectionProperty($command, 'input');
        $reflectIn->setAccessible(true);
        $reflectIn->setValue($command, new ArrayInput([], $command->getDefinition()));

        $applyPaste = new ReflectionMethod($command, 'applyBracketedPasteClassification');
        $applyPaste->setAccessible(true);

        $buffer = '';
        $label = 'atlas';
        $pending = [];

        $applyPaste->invokeArgs($command, [
            ['kind' => 'image_path', 'path' => $imageFile],
            $images,
            $this->workspace,
            &$buffer,
            &$label,
            &$pending,
        ]);

        $this->assertCount(1, $pending);
        $this->assertSame($imageFile, $pending[0]['path']);
        $this->assertStringContainsString('imagem 1', $label);
    }

    public function test_interactive_prompt_label_renders_osc8_hyperlinks_for_each_image(): void
    {
        $command = new AiChatCommand();
        $command->setLaravel(app());
        $method = new ReflectionMethod($command, 'interactivePromptLabel');
        $method->setAccessible(true);

        $pending = [
            ['path' => '/tmp/atlas/clip-a.png'],
            ['path' => '/tmp/atlas/clip-b.png'],
        ];

        $label = $method->invoke($command, '0123456789ab', $pending);

        $this->assertStringContainsString('[', $label);
        $this->assertStringContainsString(']', $label);
        $this->assertStringContainsString('Enter=analisar', $label);
        $this->assertStringContainsString("\033]8;;file:///tmp/atlas/clip-a.png\033\\imagem 1\033]8;;\033\\", $label);
        $this->assertStringContainsString("\033]8;;file:///tmp/atlas/clip-b.png\033\\imagem 2\033]8;;\033\\", $label);
        $this->assertStringNotContainsString('[img:', $label);
    }

    public function test_interactive_prompt_label_without_images_omits_brackets(): void
    {
        $command = new AiChatCommand();
        $command->setLaravel(app());
        $method = new ReflectionMethod($command, 'interactivePromptLabel');
        $method->setAccessible(true);

        $label = $method->invoke($command, '0123456789ab', []);

        $this->assertStringNotContainsString('[', $label);
        $this->assertStringNotContainsString('imagem', $label);
    }

    public function test_label_with_image_count_replaces_existing_image_segment(): void
    {
        $command = new AiChatCommand();
        $command->setLaravel(app());
        $method = new ReflectionMethod($command, 'labelWithImageCount');
        $method->setAccessible(true);

        $pending = [
            ['path' => '/tmp/a.png'],
            ['path' => '/tmp/b.png'],
            ['path' => '/tmp/c.png'],
        ];

        $first = $method->invoke($command, 'atlas abcd1234 [imagem 1] Enter=analisar', $pending);
        $this->assertStringContainsString('imagem 1', $first);
        $this->assertStringContainsString('imagem 2', $first);
        $this->assertStringContainsString('imagem 3', $first);
        $this->assertStringNotContainsString('[imagem 1] Enter=analisar', $first);

        $second = $method->invoke($command, 'atlas abcd1234', $pending);
        $this->assertStringContainsString('imagem 3', $second);
    }

    public function test_label_with_image_count_strips_previously_rendered_osc8_segment(): void
    {
        $command = new AiChatCommand();
        $command->setLaravel(app());
        $interactive = new ReflectionMethod($command, 'interactivePromptLabel');
        $interactive->setAccessible(true);
        $rebuild = new ReflectionMethod($command, 'labelWithImageCount');
        $rebuild->setAccessible(true);

        $first = [['path' => '/tmp/atlas/clip-a.png']];
        $rendered = $interactive->invoke($command, '0123456789ab', $first);

        $this->assertStringContainsString("\033]8;;file:///tmp/atlas/clip-a.png", $rendered);

        $second = [
            ['path' => '/tmp/atlas/clip-a.png'],
            ['path' => '/tmp/atlas/clip-b.png'],
        ];
        $relabeled = $rebuild->invoke($command, $rendered, $second);

        $this->assertSame(1, substr_count($relabeled, 'Enter=analisar'));
        $this->assertSame(2, substr_count($relabeled, 'imagem '));
        $this->assertStringNotContainsString('imagem 1] Enter=analisar [', $relabeled);
        $this->assertStringContainsString('imagem 2', $relabeled);
    }
}
