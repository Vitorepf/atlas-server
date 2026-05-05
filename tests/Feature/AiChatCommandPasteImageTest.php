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

    public function test_classify_bracketed_paste_multiple_paths_returns_image_paths(): void
    {
        $first = $this->workspace.'/multi-a.png';
        $second = $this->workspace.'/multi-b.png';
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkAAIAAAoAAv/lxKUAAAAASUVORK5CYII=');
        File::put($first, (string) $png);
        File::put($second, (string) $png);

        $command = new AiChatCommand();
        $command->setLaravel(app());
        $method = new ReflectionMethod($command, 'classifyBracketedPaste');
        $method->setAccessible(true);

        $payload = "'".$first."' '".$second."'";
        $result = $method->invoke($command, $payload);

        $this->assertSame('image_paths', $result['kind']);
        $this->assertSame([$first, $second], $result['paths']);
    }

    public function test_classify_bracketed_paste_multiple_paths_with_one_invalid_falls_back_to_text(): void
    {
        $valid = $this->workspace.'/valid.png';
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkAAIAAAoAAv/lxKUAAAAASUVORK5CYII=');
        File::put($valid, (string) $png);

        $command = new AiChatCommand();
        $command->setLaravel(app());
        $method = new ReflectionMethod($command, 'classifyBracketedPaste');
        $method->setAccessible(true);

        $payload = "'".$valid."' '/tmp/que-nao-existe-".bin2hex(random_bytes(4)).".png'";
        $result = $method->invoke($command, $payload);

        $this->assertSame('text', $result['kind'], 'Se algum path for invalido, classifica tudo como texto sem inventar.');
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
        $this->assertStringNotContainsString('Enter=analisar', $label, 'Label simplificado sem sufixo redundante.');
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

}
