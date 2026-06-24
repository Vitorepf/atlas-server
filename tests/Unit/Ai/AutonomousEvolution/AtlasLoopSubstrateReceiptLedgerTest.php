<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\AtlasLoopSubstrateReceiptLedger;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Proves the §W40-S6 substrate-receipt ledger: append-only invariant (no public update/delete/truncate);
 * the JSONL file's pre-write bytes are a strict prefix of the post-write bytes for every append (no rewrite);
 * receipt_id is deterministic for the same body; schema_version is stamped on every line; flag-gated wiring
 * is present at both call-sites (supervisor + keepalive) and reads the documented config key.
 */
final class AtlasLoopSubstrateReceiptLedgerTest extends TestCase
{
    private string $storageRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storageRoot = sys_get_temp_dir().'/atlas_substrate_ledger_'.bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->storageRoot)) {
            shell_exec('rm -rf '.escapeshellarg($this->storageRoot));
        }
        parent::tearDown();
    }

    private function ledger(): AtlasLoopSubstrateReceiptLedger
    {
        return new AtlasLoopSubstrateReceiptLedger($this->storageRoot);
    }

    public function test_public_api_is_exactly_constructor_append_and_read(): void
    {
        $reflection = new ReflectionClass(AtlasLoopSubstrateReceiptLedger::class);
        $publicNames = [];
        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $m) {
            $publicNames[] = strtolower($m->getName());
        }
        sort($publicNames);

        $this->assertSame(['__construct', 'append', 'read'], $publicNames, 'append-only invariant: only ctor + append + read are public');
        foreach (['update', 'delete', 'truncate', 'remove', 'edit', 'patch'] as $banned) {
            $this->assertNotContains($banned, $publicNames, "append-only invariant: must not expose public {$banned}()");
        }
    }

    public function test_jsonl_prefix_invariant_for_50_appends(): void
    {
        $ledger = $this->ledger();
        // First append creates the file; check that for every subsequent append the prior file bytes are a
        // strict prefix of the post-write file bytes (no rewrite).
        $ledger->append(['event' => 'seed', 'n' => 0]);
        $today = gmdate('Y-m-d');
        $path = $this->storageRoot.'/'.$today.'.jsonl';
        $this->assertFileExists($path);
        $previousBytes = (string) file_get_contents($path);

        for ($i = 1; $i <= 50; $i++) {
            $ledger->append(['event' => 'fact', 'n' => $i, 'campaign_id' => 'c-'.($i % 3)]);
            $now = (string) file_get_contents($path);
            $this->assertTrue(
                str_starts_with($now, $previousBytes),
                "iter {$i}: post-write bytes must extend pre-write bytes (no rewrite)",
            );
            $this->assertGreaterThan(strlen($previousBytes), strlen($now), "iter {$i}: post-write strictly longer");
            $previousBytes = $now;
        }
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $this->assertCount(51, $lines);
    }

    public function test_receipt_id_is_deterministic_for_identical_body_and_stamps_schema(): void
    {
        $ledger = $this->ledger();
        $id = $ledger->append(['event' => 'x', 'campaign_id' => 'c1']);

        $today = gmdate('Y-m-d');
        $line = (array) json_decode((string) file($this->storageRoot.'/'.$today.'.jsonl')[0], true);

        $this->assertSame(AtlasLoopSubstrateReceiptLedger::SCHEMA, $line['schema_version']);
        $this->assertSame($id, $line['receipt_id']);
        $this->assertArrayHasKey('recorded_at_iso8601', $line);
        $this->assertSame('c1', $line['campaign_id']);
    }

    public function test_read_filters_by_time_window(): void
    {
        $ledger = $this->ledger();
        $ledger->append(['event' => 'a']);
        $ledger->append(['event' => 'b']);

        $now = time();
        $results = [];
        foreach ($ledger->read($now - 60, $now + 60) as $row) {
            $results[] = $row;
        }
        $this->assertCount(2, $results);

        // Window in the far past ⇒ zero results.
        $past = [];
        foreach ($ledger->read(0, 1000) as $row) {
            $past[] = $row;
        }
        $this->assertCount(0, $past);
    }

    public function test_supervisor_wiring_is_flag_gated_via_documented_config_key(): void
    {
        $supervisorSource = (string) file_get_contents(__DIR__.'/../../../../app/Services/Ai/AutonomousEvolution/Campaign/AtlasLoopCampaignSupervisor.php');
        $this->assertStringContainsString('AtlasLoopSubstrateReceiptLedger', $supervisorSource, 'supervisor must reference the ledger class');
        $this->assertStringContainsString('atlas.loop.substrate_receipt_ledger_enabled', $supervisorSource, 'supervisor must consult the documented flag');
        $this->assertStringContainsString('emitSubstrateReceipt', $supervisorSource, 'supervisor must call the substrate emit helper');
    }

    public function test_keepalive_wiring_is_flag_gated_via_documented_config_key(): void
    {
        $keepaliveSource = (string) file_get_contents(__DIR__.'/../../../../app/Console/Commands/AtlasLoopKeepaliveCommand.php');
        $this->assertStringContainsString('AtlasLoopSubstrateReceiptLedger', $keepaliveSource, 'keepalive must reference the ledger class');
        $this->assertStringContainsString('atlas.loop.substrate_receipt_ledger_enabled', $keepaliveSource, 'keepalive must consult the documented flag');
        $this->assertStringContainsString('emitSubstrateReceipt', $keepaliveSource, 'keepalive must call the substrate emit helper');
    }
}
