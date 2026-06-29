<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\AtlasLoopArchitectureDraftService;
use Closure;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * Proves the architecture draft service is live at the operator surface: with the flag ON and a frontier writer
 * wired, a sufficient leverage envelope + orientation produces a non-null draft; with the flag OFF the draft is
 * a byte-identical null no-op.
 */
final class AtlasLoopArchDraftCommandTest extends TestCase
{
    private function bindWriter(): void
    {
        $writer = static fn (string $objective, array $citedSymbols, array $orientation): array => [
            'proposed_files' => ['app/Services/Ai/Foo.php'],
            'proposed_seams' => ['constructor_seam'],
            'provider' => 'claude',
        ];
        $this->app->instance(
            AtlasLoopArchitectureDraftService::class,
            new AtlasLoopArchitectureDraftService(Closure::fromCallable($writer)),
        );
    }

    private function draft(array $leverage, array $orientation): array
    {
        $exit = Artisan::call('atlas:loop:arch-draft', [
            '--leverage' => (string) json_encode($leverage),
            '--orientation' => (string) json_encode($orientation),
            '--json' => true,
        ]);

        return ['exit' => $exit, 'd' => json_decode(trim(Artisan::output()), true)];
    }

    public function test_flag_on_with_writer_drafts(): void
    {
        Config::set('atlas.loop.architecture_draft_enabled', true);
        $this->bindWriter();

        ['exit' => $exit, 'd' => $d] = $this->draft(
            ['selected_objective' => 'Extract a payment service', 'cited_symbols' => ['App\\Payments\\Foo']],
            ['scope' => 'payments'],
        );

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.loop.arch_draft.v1', $d['schema']);
        $this->assertNotNull($d['draft'], (string) json_encode($d));
        $this->assertTrue($d['draft']['drafted']);
        $this->assertContains('app/Services/Ai/Foo.php', $d['draft']['proposed_files']);
    }

    public function test_flag_off_is_null_draft(): void
    {
        Config::set('atlas.loop.architecture_draft_enabled', false);

        ['exit' => $exit, 'd' => $d] = $this->draft(
            ['selected_objective' => 'Extract a payment service'],
            ['scope' => 'payments'],
        );

        $this->assertSame(0, $exit);
        $this->assertNull($d['draft']);
    }

    public function test_missing_orientation_is_usage_error(): void
    {
        $exit = Artisan::call('atlas:loop:arch-draft', ['--leverage' => '{}', '--json' => true]);

        $this->assertNotSame(0, $exit);
        $this->assertStringContainsString('usage_error', Artisan::output());
    }
}
