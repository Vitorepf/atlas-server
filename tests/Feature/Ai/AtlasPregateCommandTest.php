<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use Tests\TestCase;

/**
 * P1 (Obra #19) — `atlas:pregate` gates on the check results (fail-closed exit),
 * and no-ops cleanly when there is nothing php to gate. Uses --skip-phpstan so the
 * test stays fast + deterministic (phpstan's larastan boot is exercised by the
 * dogfood run, not the unit test).
 */
final class AtlasPregateCommandTest extends TestCase
{
    public function test_fails_on_a_syntax_error(): void
    {
        $broken = sys_get_temp_dir().'/atlas-pregate-'.bin2hex(random_bytes(5)).'.php';
        file_put_contents($broken, "<?php\n\$x = ;\n"); // deliberate parse error

        try {
            $this->artisan('atlas:pregate', ['paths' => [$broken], '--skip-phpstan' => true])
                ->assertExitCode(1);
        } finally {
            @unlink($broken);
        }
    }

    public function test_noop_when_no_php_targets(): void
    {
        $txt = sys_get_temp_dir().'/atlas-pregate-'.bin2hex(random_bytes(5)).'.txt';
        file_put_contents($txt, "not php\n");

        try {
            $this->artisan('atlas:pregate', ['paths' => [$txt], '--skip-phpstan' => true])
                ->assertExitCode(0);
        } finally {
            @unlink($txt);
        }
    }

    public function test_fails_when_a_requested_php_path_does_not_exist(): void
    {
        // Regressao: um .php inexistente era descartado em silencio e o comando
        // devolvia SUCCESS com "no php targets", ou seja verde sobre nada checado.
        $this->artisan('atlas:pregate', [
            'paths' => [sys_get_temp_dir().'/atlas-pregate-ausente-'.bin2hex(random_bytes(5)).'.php'],
            '--skip-phpstan' => true,
        ])->assertExitCode(1);
    }
}
