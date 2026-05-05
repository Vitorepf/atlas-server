<?php

namespace Tests\Unit\Ai\Cli\Repl;

use App\Services\Ai\Cli\Repl\SlashCommandRegistry;
use Tests\TestCase;

class SlashCommandRegistryTest extends TestCase
{
    public function test_dispatches_canonical_name(): void
    {
        $registry = new SlashCommandRegistry();
        $captured = null;
        $registry->register('paste-image', [], function (string $args) use (&$captured) {
            $captured = $args;
        });

        $this->assertTrue($registry->dispatch('/paste-image'));
        $this->assertSame('', $captured);
    }

    public function test_dispatches_alias_to_same_handler(): void
    {
        $registry = new SlashCommandRegistry();
        $count = 0;
        $registry->register('paste-image', ['paste', 'p', 'img'], function () use (&$count) {
            $count++;
        });

        $registry->dispatch('/paste-image');
        $registry->dispatch('/paste');
        $registry->dispatch('/p');
        $registry->dispatch('/img');

        $this->assertSame(4, $count);
    }

    public function test_dispatch_passes_args_after_command(): void
    {
        $registry = new SlashCommandRegistry();
        $captured = null;
        $registry->register('image', [], function (string $args) use (&$captured) {
            $captured = $args;
        });

        $registry->dispatch('/image /tmp/foo bar.png');

        $this->assertSame('/tmp/foo bar.png', $captured);
    }

    public function test_returns_false_for_unknown_command(): void
    {
        $registry = new SlashCommandRegistry();
        $this->assertFalse($registry->dispatch('/inexistente'));
        $this->assertFalse($registry->dispatch('texto sem barra'));
        $this->assertFalse($registry->dispatch(''));
    }

    public function test_alias_does_not_clobber_existing_handler(): void
    {
        $registry = new SlashCommandRegistry();
        $clearImages = 0;
        $pasteImage = 0;

        $registry->register('clear-images', ['c'], function () use (&$clearImages) {
            $clearImages++;
        });
        $registry->register('paste-image', ['c'], function () use (&$pasteImage) {
            $pasteImage++;
        });

        $registry->dispatch('/c');

        $this->assertSame(1, $clearImages, 'Alias /c registrado primeiro deve manter handler de clear-images.');
        $this->assertSame(0, $pasteImage, 'Alias duplicado nao deve sobrescrever handler existente.');
    }

    public function test_canonical_overrides_existing_alias(): void
    {
        $registry = new SlashCommandRegistry();
        $first = 0;
        $second = 0;

        $registry->register('paste-image', ['paste'], function () use (&$first) {
            $first++;
        });
        $registry->register('paste', [], function () use (&$second) {
            $second++;
        });

        $registry->dispatch('/paste');

        $this->assertSame(0, $first);
        $this->assertSame(1, $second, 'Canonical e declaracao explicita e deve prevalecer.');
    }

    public function test_dispatch_is_case_insensitive(): void
    {
        $registry = new SlashCommandRegistry();
        $hits = 0;
        $registry->register('paste-image', [], function () use (&$hits) {
            $hits++;
        });

        $this->assertTrue($registry->dispatch('/PASTE-IMAGE'));
        $this->assertTrue($registry->dispatch('/Paste-Image'));
        $this->assertSame(2, $hits);
    }

    public function test_knows_returns_true_for_registered_aliases(): void
    {
        $registry = new SlashCommandRegistry();
        $registry->register('paste-image', ['p'], fn () => null);

        $this->assertTrue($registry->knows('paste-image'));
        $this->assertTrue($registry->knows('p'));
        $this->assertFalse($registry->knows('inexistente'));
    }
}
