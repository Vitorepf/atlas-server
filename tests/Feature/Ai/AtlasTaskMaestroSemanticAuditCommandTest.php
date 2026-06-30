<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Tests\TestCase;

/**
 * The Maestro semantic v+3 gate CLI: exit 0 = passed, 1 = semantically rejected (receipt printed), 2 = missing
 * or invalid packet.
 */
final class AtlasTaskMaestroSemanticAuditCommandTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/atlas-semantic-audit-'.bin2hex(random_bytes(6));
        @mkdir($this->dir, 0o755, true);
    }

    protected function tearDown(): void
    {
        foreach ((array) glob($this->dir.'/*') as $f) {
            @unlink((string) $f);
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    public function test_good_packet_passes_with_exit_zero(): void
    {
        // Objective cites no symbol (allowed-files-intent passes), no insertion_site (orphan voter skips/passes),
        // and acceptance cites exactly ONE real Atlas symbol (acceptance-symbol voter passes) ⇒ quorum 3/3.
        $file = $this->writePacket('good', [
            'task_packet_id' => 'good-1',
            'objective' => 'Audit a packet for semantic correctness and report.',
            'allowed_files' => ['app/Console/Commands/AtlasTaskMaestroSemanticAuditCommand.php'],
            'acceptance_criteria' => ['Runs AtlasMaestroSemanticAuditPanel and asserts the quorum.'],
        ]);

        [$exit, $out] = $this->runCommand(['--packet-file' => $file]);

        $this->assertSame(0, $exit, $out);
        $this->assertStringContainsString('PASS', $out);
    }

    public function test_fabricated_symbol_packet_is_rejected_with_receipt_and_exit_one(): void
    {
        // Objective cites a REAL symbol that lives OUTSIDE allowed_files (intent voter fails), and acceptance
        // cites a FABRICATED symbol (acceptance-symbol voter fails) ⇒ quorum 1/3 ⇒ rejection.
        $file = $this->writePacket('bad', [
            'task_packet_id' => 'bad-1',
            'objective' => 'Refactor AtlasMaestroSemanticAuditPanel internals.',
            'allowed_files' => ['app/Nowhere/Unrelated.php'],
            'acceptance_criteria' => ['Calls AtlasMaestroTotallyFabricatedSymbolXyz to do the work.'],
        ]);

        [$exit, $out] = $this->runCommand(['--packet-file' => $file]);

        $this->assertSame(1, $exit, $out);
        $this->assertStringContainsString('rejected_voters', $out);
        $this->assertStringContainsString('offending_symbol', $out);
    }

    public function test_missing_packet_file_exits_two(): void
    {
        [$exit, $out] = $this->runCommand(['--packet-file' => $this->dir.'/does-not-exist.json']);

        $this->assertSame(2, $exit);
        $this->assertStringContainsString('packet_not_found', $out);
    }

    public function test_empty_task_packet_id_falls_back_to_packet_id_option(): void
    {
        $file = $this->writePacket('empty-id', [
            'task_packet_id' => '',
            'objective' => 'Refactor AtlasMaestroSemanticAuditPanel internals.',
            'allowed_files' => ['app/Nowhere/Unrelated.php'],
            'acceptance_criteria' => ['Calls AtlasMaestroTotallyFabricatedSymbolXyz to do the work.'],
        ]);

        [$exit, $out] = $this->runCommand(['--packet-file' => $file, '--packet-id' => 'pkt-42']);

        $this->assertSame(1, $exit, $out);
        $this->assertStringContainsString('pkt-42', $out, 'receipt must use --packet-id fallback, not blank');
    }

    /**
     * @param  array<string,mixed>  $packet
     */
    private function writePacket(string $name, array $packet): string
    {
        $path = $this->dir.'/'.$name.'.json';
        file_put_contents($path, (string) json_encode($packet, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

        return $path;
    }

    /**
     * @param  array<string,mixed>  $options
     * @return array{0:int,1:string}
     */
    private function runCommand(array $options): array
    {
        $kernel = $this->app->make(ConsoleKernel::class);
        $exit = $kernel->call('atlas:task:maestro-semantic-audit', $options);

        return [$exit, $kernel->output()];
    }
}
