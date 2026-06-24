<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the atlas:loop:quaternity CLI end-to-end against the real composer+ledger: a happy-path cycle writes
 * one JSONL line and exits 0 with seq + envelope_hash; a missing FACT file exits non-zero and DOES NOT touch
 * the ledger; verify exits 0 on a clean ledger and exits 1 after the file is mutated; status reflects the
 * actual tail of the ledger.
 */
final class AtlasLoopQuaternityCommandTest extends TestCase
{
    private string $tmpRoot;

    private string $ledgerFile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpRoot = sys_get_temp_dir().'/atlas_quaternity_cli_'.bin2hex(random_bytes(8));
        mkdir($this->tmpRoot, 0775, true);
        $this->ledgerFile = $this->tmpRoot.'/cycle-receipts.jsonl';
        putenv('ATLAS_QUATERNITY_LEDGER_FILE='.$this->ledgerFile);
        $_ENV['ATLAS_QUATERNITY_LEDGER_FILE'] = $this->ledgerFile;
        config(['atlas.quaternity.receipt_secret' => 'k_secret_test']);
    }

    protected function tearDown(): void
    {
        if (is_file($this->ledgerFile)) {
            @unlink($this->ledgerFile);
        }
        if (is_dir($this->tmpRoot)) {
            foreach (scandir($this->tmpRoot) ?: [] as $f) {
                if ($f !== '.' && $f !== '..') {
                    @unlink($this->tmpRoot.'/'.$f);
                }
            }
            @rmdir($this->tmpRoot);
        }
        putenv('ATLAS_QUATERNITY_LEDGER_FILE');
        unset($_ENV['ATLAS_QUATERNITY_LEDGER_FILE']);
        parent::tearDown();
    }

    /** @return array<string,string> map slot=>file path */
    private function writeFactFiles(): array
    {
        $paths = [];
        $facts = [
            'loop' => ['factId' => 'L1', 'payload' => ['n' => 1]],
            'cortex' => ['factId' => 'C1', 'payload' => ['parent' => 'L1']],
            'maestro' => ['factId' => 'M1', 'payload' => ['parent' => 'C1']],
            'operator_intent' => ['intent' => 'evolve', 'tokens' => ['evolve']],
        ];
        foreach ($facts as $slot => $payload) {
            $path = $this->tmpRoot.'/'.$slot.'.json';
            file_put_contents($path, (string) json_encode($payload));
            $paths[$slot] = $path;
        }

        return $paths;
    }

    /** @param array<string,string> $facts */
    private function cycleArgs(array $facts, ?string $cycleId = null): array
    {
        return [
            'action' => 'cycle',
            '--loop-fact' => $facts['loop'] ?? '',
            '--cortex-fact' => $facts['cortex'] ?? '',
            '--maestro-fact' => $facts['maestro'] ?? '',
            '--operator-intent-fact' => $facts['operator_intent'] ?? '',
            '--cycle-id' => $cycleId ?? 'cycle-test',
        ];
    }

    public function test_artisan_command_atlas_loop_quaternity_is_registered(): void
    {
        $all = Artisan::all();
        $this->assertArrayHasKey('atlas:loop:quaternity', $all);
    }

    public function test_cycle_with_valid_fact_files_writes_one_line_and_returns_seq_and_envelope_hash(): void
    {
        $facts = $this->writeFactFiles();

        $exit = Artisan::call('atlas:loop:quaternity', $this->cycleArgs($facts));
        $this->assertSame(0, $exit, 'happy-path cycle exits 0');

        $output = Artisan::output();
        $decoded = json_decode(trim($output), true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('seq', $decoded);
        $this->assertSame(1, $decoded['seq']);
        $this->assertNotEmpty($decoded['envelope_hash']);

        $this->assertFileExists($this->ledgerFile);
        $lines = file($this->ledgerFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $this->assertCount(1, $lines);
    }

    public function test_cycle_with_missing_fact_file_exits_non_zero_and_does_not_append(): void
    {
        $facts = $this->writeFactFiles();
        unlink($facts['cortex']); // delete one FACT file

        $exit = Artisan::call('atlas:loop:quaternity', $this->cycleArgs($facts));
        $this->assertNotSame(0, $exit, 'missing FACT exits non-zero');

        $this->assertFileDoesNotExist($this->ledgerFile, 'fail-closed: ledger never created');
    }

    public function test_verify_exits_zero_on_clean_ledger_and_one_after_mutation(): void
    {
        $facts = $this->writeFactFiles();
        Artisan::call('atlas:loop:quaternity', $this->cycleArgs($facts, 'c1'));
        Artisan::call('atlas:loop:quaternity', $this->cycleArgs($facts, 'c2'));
        Artisan::call('atlas:loop:quaternity', $this->cycleArgs($facts, 'c3'));

        $exit = Artisan::call('atlas:loop:quaternity', ['action' => 'verify']);
        $this->assertSame(0, $exit, 'clean ledger verifies');

        // Mutate line 2's envelope_hash — line 3 will break.
        $lines = file($this->ledgerFile, FILE_IGNORE_NEW_LINES);
        $line2 = (array) json_decode((string) $lines[1], true);
        $line2['envelope']['envelope_hash'] = str_repeat('f', 64);
        $lines[1] = (string) json_encode($line2, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        file_put_contents($this->ledgerFile, implode("\n", $lines)."\n");

        $exit = Artisan::call('atlas:loop:quaternity', ['action' => 'verify']);
        $this->assertSame(1, $exit, 'tampered ledger exits 1');
        $decoded = json_decode(trim(Artisan::output()), true);
        $this->assertGreaterThanOrEqual(1, $decoded['total'] - $decoded['ok_count']);
        $this->assertNotEmpty($decoded['broken']);
        $this->assertSame(3, $decoded['broken'][0]['seq'], 'seq 3 is the one whose prev was tampered');
    }

    public function test_status_reflects_actual_ledger_tail(): void
    {
        $facts = $this->writeFactFiles();
        Artisan::call('atlas:loop:quaternity', $this->cycleArgs($facts, 'c1'));
        Artisan::call('atlas:loop:quaternity', $this->cycleArgs($facts, 'c2'));

        Artisan::call('atlas:loop:quaternity', ['action' => 'status']);
        $decoded = json_decode(trim(Artisan::output()), true);

        $tail = json_decode((string) file($this->ledgerFile)[1], true);
        $this->assertSame(2, $decoded['last_seq']);
        $this->assertSame((string) $tail['envelope']['envelope_hash'], $decoded['last_envelope_hash']);
        $this->assertSame(2, $decoded['total']);
    }
}
