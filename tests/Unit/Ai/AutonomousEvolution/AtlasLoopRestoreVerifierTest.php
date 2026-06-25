<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\Recovery\AtlasLoopBackupComposer;
use App\Services\Ai\AutonomousEvolution\Recovery\AtlasLoopReceiptReplayer;
use App\Services\Ai\AutonomousEvolution\Recovery\AtlasLoopRestoreVerifier;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\CanonicalJson;
use Tests\TestCase;

final class AtlasLoopRestoreVerifierTest extends TestCase
{
    private string $root = '';

    private string $liveLedgerRoot = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/atlas-restore-verifier-'.bin2hex(random_bytes(6));
        @mkdir($this->root.'/ledgers', 0o755, true);
        @mkdir($this->root.'/backups', 0o755, true);
        // Bind storage_path('app/atlas/loop/ledgers') is not in our scope; the verifier never
        // touches it. We assert via direct path inspection.
        $this->liveLedgerRoot = storage_path('app/atlas/loop/ledgers');
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

    private function expectedState(array $events): array
    {
        $replayer = new AtlasLoopReceiptReplayer();
        $tmp = $this->root.'/expected.jsonl';
        $this->makeLedger($tmp, $events);
        $state = $replayer->replay($tmp)['state'];
        @unlink($tmp);

        return $state;
    }

    public function test_ok_when_backup_replays_to_matching_state_hash(): void
    {
        $events = [
            ['attempt_id' => 'pkt-1', 'event' => 'created', 'timestamp' => 1700000001, 'changes' => ['status' => 'new']],
            ['attempt_id' => 'pkt-1', 'event' => 'updated', 'timestamp' => 1700000002, 'changes' => ['status' => 'ok']],
        ];
        $ledgerPath = $this->root.'/ledgers/attempt_ledger.jsonl';
        $this->makeLedger($ledgerPath, $events);
        $expectedState = $this->expectedState($events);
        $expectedHash = hash('sha256', (string) json_encode($expectedState, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $composer = new AtlasLoopBackupComposer([$ledgerPath]);
        $tar = $composer->compose($this->root.'/backups/out.tar');

        $verifier = new AtlasLoopRestoreVerifier(new AtlasLoopReceiptReplayer());
        $result = $verifier->verify($tar, ['expected_state_hash' => $expectedHash]);

        $this->assertTrue($result->ok, "verifier reason: {$result->reason}");
        $this->assertSame($expectedHash, $result->actualStateHash);
        $this->assertGreaterThan(0, $result->replayedEventCount);
    }

    public function test_mutating_one_byte_in_tar_makes_verify_fail_without_touching_live_ledgers(): void
    {
        $events = [
            ['attempt_id' => 'pkt-1', 'event' => 'created', 'timestamp' => 1700000001, 'changes' => ['status' => 'new']],
        ];
        $ledgerPath = $this->root.'/ledgers/attempt_ledger.jsonl';
        $this->makeLedger($ledgerPath, $events);
        $expectedState = $this->expectedState($events);
        $expectedHash = hash('sha256', (string) json_encode($expectedState, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $composer = new AtlasLoopBackupComposer([$ledgerPath]);
        $tar = $composer->compose($this->root.'/backups/out.tar');

        // Mutate one byte in the data region of the tar.
        $raw = (string) file_get_contents($tar);
        $offset = strrpos($raw, '"status":"new"');
        $this->assertNotFalse($offset);
        $raw[$offset + 11] = 'X'; // flip a byte inside "status":"new"
        file_put_contents($tar, $raw);

        $beforeLive = is_dir($this->liveLedgerRoot) ? count((array) glob($this->liveLedgerRoot.'/*')) : 0;
        $verifier = new AtlasLoopRestoreVerifier(new AtlasLoopReceiptReplayer());
        $result = $verifier->verify($tar, ['expected_state_hash' => $expectedHash]);
        $afterLive = is_dir($this->liveLedgerRoot) ? count((array) glob($this->liveLedgerRoot.'/*')) : 0;

        $this->assertFalse($result->ok);
        $this->assertSame($beforeLive, $afterLive, 'verifier MUST NOT touch live ledger paths');
    }

    public function test_temp_directory_is_cleaned_up_after_exception(): void
    {
        $verifier = new AtlasLoopRestoreVerifier(new AtlasLoopReceiptReplayer());
        $tempCountBefore = count((array) glob(sys_get_temp_dir().'/atlas-restore-*'));

        // Feed nonexistent tar — internal extract gets empty content, replay throws, finally must clean up.
        $result = $verifier->verify($this->root.'/does_not_exist.tar', ['expected_state_hash' => 'na']);
        $tempCountAfter = count((array) glob(sys_get_temp_dir().'/atlas-restore-*'));

        $this->assertFalse($result->ok);
        $this->assertLessThanOrEqual($tempCountBefore, $tempCountAfter, 'temp directory must be cleaned up on exception');
    }

    public function test_verifier_delegates_replay_to_injected_replayer(): void
    {
        $src = (string) file_get_contents(base_path('app/Services/Ai/AutonomousEvolution/Recovery/AtlasLoopRestoreVerifier.php'));
        $this->assertStringContainsString('AtlasLoopReceiptReplayer', $src, 'verifier must depend on AtlasLoopReceiptReplayer');
        $this->assertStringContainsString('public function __construct(', $src);
        $this->assertStringContainsString('$this->replayer->replay', $src);
    }
}
