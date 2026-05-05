<?php

namespace Tests\Unit\Ai\Cli\Repl;

use App\Services\Ai\Cli\Repl\ReplComposer;
use App\Services\Ai\Cli\Repl\StatusBarFormatter;
use Tests\TestCase;

class StatusBarFormatterTest extends TestCase
{
    public function test_format_includes_provider_model_and_duration(): void
    {
        $formatter = new StatusBarFormatter();
        $composer = (new ReplComposer())->insertText('hello world');
        $started = new \DateTimeImmutable('2026-05-04 22:00:00');
        $now = new \DateTimeImmutable('2026-05-04 22:02:14');

        $line = $formatter->format('claude', 'sonnet-4-6', $composer, $started, $now);

        $this->assertStringContainsString('atlas', $line);
        $this->assertStringContainsString('claude', $line);
        $this->assertStringContainsString('sonnet-4-6', $line);
        $this->assertStringContainsString('02:14', $line);
    }

    public function test_format_omits_image_count_when_zero(): void
    {
        $formatter = new StatusBarFormatter();
        $composer = new ReplComposer();
        $line = $formatter->format('claude', null, $composer, new \DateTimeImmutable(), new \DateTimeImmutable());

        $this->assertStringNotContainsString('imagem', $line);
    }

    public function test_format_pluralizes_image_count(): void
    {
        $formatter = new StatusBarFormatter();
        $composer = new ReplComposer();
        $composer->attachImage(['path' => '/tmp/a.png']);
        $line = $formatter->format(null, null, $composer, new \DateTimeImmutable(), new \DateTimeImmutable());
        $this->assertStringContainsString('1 imagem', $line);

        $composer->attachImage(['path' => '/tmp/b.png']);
        $line = $formatter->format(null, null, $composer, new \DateTimeImmutable(), new \DateTimeImmutable());
        $this->assertStringContainsString('2 imagens', $line);
    }

    public function test_estimates_tokens_combines_text_and_images(): void
    {
        $formatter = new StatusBarFormatter();
        $composer = (new ReplComposer())->insertText(str_repeat('a', 100));
        $composer->attachImage(['path' => '/tmp/a.png']);

        $tokens = $formatter->estimateTokens($composer);

        $this->assertGreaterThanOrEqual(1024, $tokens);
        $this->assertLessThanOrEqual(1100, $tokens);
    }

    public function test_human_duration_formats_with_hours(): void
    {
        $formatter = new StatusBarFormatter();
        $started = new \DateTimeImmutable('2026-05-04 09:00:00');
        $now = new \DateTimeImmutable('2026-05-04 12:34:56');

        $this->assertSame('3:34:56', $formatter->humanDuration($started, $now));
    }
}
