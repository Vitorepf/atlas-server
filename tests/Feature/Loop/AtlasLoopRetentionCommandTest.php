<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Console\Commands\AtlasLoopRetentionCommand;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class AtlasLoopRetentionCommandTest extends TestCase
{
    private string $snapshotDir = '';

    private string $ledgerRoot = '';

    protected function setUp(): void
    {
        parent::setUp();
        $tag = bin2hex(random_bytes(4));
        $this->snapshotDir = sys_get_temp_dir().'/atlas-retention-cli-snap-'.$tag;
        $this->ledgerRoot = sys_get_temp_dir().'/atlas-retention-cli-ledger-'.$tag;
        @mkdir($this->snapshotDir, 0o755, true);
        @mkdir($this->ledgerRoot, 0o755, true);

        Config::set('atlas.loop.retention.keep_last_n', 2);
        Config::set('atlas.loop.retention.keep_every_mth', 2);
        Config::set('atlas.loop.retention.snapshot_dir', $this->snapshotDir);
        Config::set('atlas.loop.retention.ledger_root', $this->ledgerRoot);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->snapshotDir.'/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->snapshotDir);
        foreach (glob($this->ledgerRoot.'/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->ledgerRoot);
        parent::tearDown();
    }

    private function seedSnapshots(int $count): void
    {
        for ($i = 1; $i <= $count; $i++) {
            $id = sprintf('snap-%03d', $i);
            file_put_contents($this->snapshotDir.'/'.$id.'.json', '{}');
            touch($this->snapshotDir.'/'.$id.'.json', time() + $i);
        }
    }

    private function runCmd(array $params): array
    {
        $buf = new BufferedOutput();
        /** @var \Illuminate\Contracts\Console\Kernel $kernel */
        $kernel = app(\Illuminate\Contracts\Console\Kernel::class);
        $exit = $kernel->call('atlas:loop:retention', $params, $buf);

        return ['exit' => $exit, 'output' => trim($buf->fetch())];
    }

    public function test_policy_subcommand_prints_keep_last_n_and_keep_every_mth(): void
    {
        $r = $this->runCmd(['action' => 'policy', '--json' => true]);

        self::assertSame(0, $r['exit']);
        $payload = json_decode($r['output'], true);
        self::assertIsArray($payload);
        self::assertSame(2, $payload['keep_last_n']);
        self::assertSame(2, $payload['keep_every_mth']);
    }

    public function test_inspect_subcommand_emits_advisory_json(): void
    {
        $this->seedSnapshots(6);

        $r = $this->runCmd(['action' => 'inspect', '--json' => true]);

        self::assertSame(0, $r['exit']);
        $payload = json_decode($r['output'], true);
        self::assertIsArray($payload);
        self::assertArrayHasKey('kept_ids', $payload);
        self::assertArrayHasKey('prune_candidate_ids', $payload);
        self::assertArrayHasKey('policy_fingerprint', $payload);
        self::assertArrayHasKey('scanned_at', $payload);
    }

    public function test_advise_subcommand_appends_exactly_one_ledger_line(): void
    {
        $this->seedSnapshots(5);

        $before = $this->countLedgerLines();
        $r = $this->runCmd(['action' => 'advise', '--json' => true]);
        $after = $this->countLedgerLines();

        self::assertSame(0, $r['exit']);
        $payload = json_decode($r['output'], true);
        self::assertIsArray($payload);
        self::assertArrayHasKey('kept_ids', $payload);
        self::assertArrayHasKey('prune_candidate_ids', $payload);
        self::assertSame($before + 1, $after, 'advise must append exactly ONE ledger line');
    }

    public function test_apply_flag_is_refused_with_advisory_only_message_and_writes_nothing(): void
    {
        $this->seedSnapshots(4);
        $snapshotFiles = $this->snapshotSnapshot();
        $ledgerBefore = $this->countLedgerLines();

        $r = $this->runCmd(['action' => 'inspect', '--apply' => true]);

        self::assertNotSame(0, $r['exit']);
        self::assertStringContainsString('advisory only', $r['output']);
        self::assertSame($snapshotFiles, $this->snapshotSnapshot(), 'snapshot dir must be unchanged after --apply rejection');
        self::assertSame($ledgerBefore, $this->countLedgerLines(), 'ledger must be unchanged after --apply rejection');
    }

    public function test_delete_and_prune_flags_are_also_refused(): void
    {
        $r1 = $this->runCmd(['action' => 'advise', '--delete' => true]);
        $r2 = $this->runCmd(['action' => 'advise', '--prune' => true]);

        self::assertNotSame(0, $r1['exit']);
        self::assertNotSame(0, $r2['exit']);
    }

    private function countLedgerLines(): int
    {
        $count = 0;
        foreach (glob($this->ledgerRoot.'/*.jsonl') ?: [] as $f) {
            $count += count(file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []);
        }

        return $count;
    }

    private function snapshotSnapshot(): array
    {
        $files = glob($this->snapshotDir.'/*') ?: [];
        sort($files);
        $rows = [];
        foreach ($files as $f) {
            $rows[] = [$f, filesize($f), filemtime($f)];
        }

        return $rows;
    }
}
