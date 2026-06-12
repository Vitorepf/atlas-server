<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Programming;

use App\Services\Ai\Programming\AtlasForgeGovernedExecutionService;
use ReflectionMethod;
use Tests\TestCase;

/**
 * L2-10 (dívida de segurança do sweep O-1): a validação governada do Forge não pode
 * aceitar `php -r` livre como prova (gameável: `exit(0)` carimba verde sem exercitar
 * nada). Com o flag de endurecimento ON, só um test runner REAL ancorado em testes
 * nomeados é runnable. Default OFF preserva os callers de marker legados.
 */
final class AtlasForgeValidationAllowlistTest extends TestCase
{
    private function firstRunnable(array $commands): ?array
    {
        $m = new ReflectionMethod(AtlasForgeGovernedExecutionService::class, 'firstRunnableCommand');
        $m->setAccessible(true);

        return $m->invoke(app(AtlasForgeGovernedExecutionService::class), $commands);
    }

    public function test_test_runner_command_is_always_runnable(): void
    {
        config(['atlas.forge.validation_test_runner_only' => true]);
        $cmd = $this->firstRunnable(['php artisan test --filter=FooTest']);
        $this->assertNotNull($cmd);
        $this->assertContains('test', $cmd['argv']);
        $this->assertContains('FooTest', $cmd['argv']);
    }

    public function test_hardening_on_rejects_free_php_r_snippet(): void
    {
        config(['atlas.forge.validation_test_runner_only' => true]);

        // O exploit do achado: um snippet que sempre passa.
        $this->assertNull($this->firstRunnable(['php -r "exit(0);"']), 'php -r gameável deve ser rejeitado com hardening ON');
        $this->assertNull($this->firstRunnable(['php -r "echo \'ok\';"']));
    }

    public function test_default_off_preserves_legacy_marker_callers(): void
    {
        config(['atlas.forge.validation_test_runner_only' => false]);

        // Default: os markers de smoke legados continuam runnable (sem quebra silenciosa).
        $cmd = $this->firstRunnable(['php -r "echo \'atlas-governed-ok\';"']);
        $this->assertNotNull($cmd, 'default OFF preserva callers existentes');
        $this->assertSame(PHP_BINARY, $cmd['argv'][0]);
    }

    public function test_test_runner_is_preferred_over_php_r_when_both_present(): void
    {
        config(['atlas.forge.validation_test_runner_only' => false]);
        // Mesmo com php -r permitido, o test runner real vem primeiro na ordem de preferência.
        $cmd = $this->firstRunnable(['php artisan test --filter=BarTest', 'php -r "exit(0);"']);
        $this->assertContains('BarTest', $cmd['argv']);
    }
}
