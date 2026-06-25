<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\Programming\AtlasDev\Schemas\Support\CanonicalJson;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class AtlasLoopRecoveryCliTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/atlas-recovery-cli-'.bin2hex(random_bytes(6));
        @mkdir($this->root.'/ledgers', 0o755, true);
        @mkdir($this->root.'/backups', 0o755, true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->root);
        parent::tearDown();
    }

    private function rrmdir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach ((array) scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $dir.'/'.$entry;
            if (is_dir($full)) {
                $this->rrmdir($full);
            } else {
                @unlink($full);
            }
        }
        @rmdir($dir);
    }

    private function makeLedger(string $path, array $events): void
    {
        $lines = [];
        foreach ($events as $event) {
            $event['receipt_hash'] = hash('sha256', CanonicalJson::encode($event));
            $lines[] = json_encode($event, JSON_UNESCAPED_SLASHES);
        }
        file_put_contents($path, implode("\n", $lines));
    }

    private function defaultLedgerPath(): string
    {
        return $this->root.'/ledgers/attempt_ledger.jsonl';
    }

    private function seedDefaultLedger(): void
    {
        $this->makeLedger($this->defaultLedgerPath(), [
            ['attempt_id' => 'pkt-1', 'event' => 'created', 'timestamp' => 1700000001, 'changes' => ['status' => 'new']],
            ['attempt_id' => 'pkt-1', 'event' => 'updated', 'timestamp' => 1700000002, 'changes' => ['status' => 'ok']],
        ]);
    }

    public function test_backup_writes_tar_and_prints_archive_sha(): void
    {
        $this->seedDefaultLedger();
        $exit = Artisan::call('atlas:loop:recovery', [
            'action' => 'backup',
            '--ledger' => $this->defaultLedgerPath(),
            '--json' => true,
        ]);
        $p = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exit);
        $this->assertFileExists($p['tar_path']);
        $this->assertSame(64, strlen((string) $p['archive_sha256']));
    }

    public function test_replay_action_emits_state_and_event_count(): void
    {
        $this->seedDefaultLedger();
        $exit = Artisan::call('atlas:loop:recovery', [
            'action' => 'replay',
            '--ledger' => $this->defaultLedgerPath(),
            '--json' => true,
        ]);
        $p = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exit);
        $this->assertIsArray($p['state']);
        $this->assertSame(2, $p['event_count']);
    }

    public function test_verify_exits_0_when_hashes_match(): void
    {
        $this->seedDefaultLedger();
        // First produce a tar.
        Artisan::call('atlas:loop:recovery', [
            'action' => 'backup',
            '--ledger' => $this->defaultLedgerPath(),
            '--json' => true,
        ]);
        $backup = json_decode(trim(Artisan::output()), true);

        // Compute expected state hash by direct replay.
        $replayer = new \App\Services\Ai\AutonomousEvolution\Recovery\AtlasLoopReceiptReplayer();
        $state = $replayer->replay($this->defaultLedgerPath())['state'];
        ksort($state);
        $expectedHash = hash('sha256', (string) json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $exit = Artisan::call('atlas:loop:recovery', [
            'action' => 'verify',
            '--tar' => $backup['tar_path'],
            '--checkpoint' => json_encode(['expected_state_hash' => $expectedHash]),
            '--json' => true,
        ]);
        $p = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exit);
        $this->assertTrue($p['ok']);
    }

    public function test_verify_exits_2_when_hashes_do_not_match(): void
    {
        $this->seedDefaultLedger();
        Artisan::call('atlas:loop:recovery', [
            'action' => 'backup',
            '--ledger' => $this->defaultLedgerPath(),
            '--json' => true,
        ]);
        $backup = json_decode(trim(Artisan::output()), true);

        $exit = Artisan::call('atlas:loop:recovery', [
            'action' => 'verify',
            '--tar' => $backup['tar_path'],
            '--checkpoint' => json_encode(['expected_state_hash' => str_repeat('0', 64)]),
            '--json' => true,
        ]);
        $p = json_decode(trim(Artisan::output()), true);

        $this->assertSame(2, $exit);
        $this->assertFalse($p['ok']);
    }

    public function test_restore_writes_to_target_outside_live_root(): void
    {
        $this->seedDefaultLedger();
        Artisan::call('atlas:loop:recovery', [
            'action' => 'backup',
            '--ledger' => $this->defaultLedgerPath(),
            '--json' => true,
        ]);
        $backup = json_decode(trim(Artisan::output()), true);
        $target = $this->root.'/restored';

        $exit = Artisan::call('atlas:loop:recovery', [
            'action' => 'restore',
            '--tar' => $backup['tar_path'],
            '--target' => $target,
            '--json' => true,
        ]);
        $p = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exit);
        $this->assertContains('manifest.json', $p['restored']);
        $this->assertFileExists($target.'/manifest.json');
        $this->assertFileExists($target.'/attempt_ledger.jsonl');
    }

    public function test_restore_refuses_live_root_without_safety_flag(): void
    {
        $this->seedDefaultLedger();
        Artisan::call('atlas:loop:recovery', [
            'action' => 'backup',
            '--ledger' => $this->defaultLedgerPath(),
            '--json' => true,
        ]);
        $backup = json_decode(trim(Artisan::output()), true);
        $target = storage_path('app/atlas/loop/ledgers/restore-experiment');

        $exit = Artisan::call('atlas:loop:recovery', [
            'action' => 'restore',
            '--tar' => $backup['tar_path'],
            '--target' => $target,
            '--json' => true,
        ]);
        $p = json_decode(trim(Artisan::output()), true);

        $this->assertNotSame(0, $exit);
        $this->assertSame('refusing_to_write_into_live_ledger_root', $p['error']);
    }

    public function test_signature_is_listed_in_artisan_list(): void
    {
        $code = Artisan::call('list', []);
        $this->assertSame(0, $code);
        $this->assertStringContainsString('atlas:loop:recovery', Artisan::output());
    }
}
