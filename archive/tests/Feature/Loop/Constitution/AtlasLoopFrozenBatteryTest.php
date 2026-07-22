<?php

declare(strict_types=1);

namespace Tests\Feature\Loop\Constitution;

use App\Services\Ai\AutonomousEvolution\Constitution\AtlasLoopFrozenBattery;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * LOOP-OS · Fase 3 · Slice 3 — the frozen battery's chain is tamper-evident: removing, reordering, or
 * editing any case breaks the recomputed Merkle chain and REJECTS. The hash is byte-reproducible, and a
 * known-GOOD append (which dilutes the floor) is two-key.
 */
final class AtlasLoopFrozenBatteryTest extends TestCase
{
    private string $dir;

    private AtlasLoopFrozenBattery $battery;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/atlas-battery-'.bin2hex(random_bytes(5));
        $this->battery = new AtlasLoopFrozenBattery($this->dir);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    private function badCase(string $id): array
    {
        return ['id' => $id, 'kind' => 'bad', 'contract' => 'must REFUTE a fake-green no-op', 'expected_verdict' => 'REFUTE', 'diff_bytes' => "--- a\n+++ b\n"];
    }

    public function test_empty_battery_has_the_genesis_root(): void
    {
        $this->assertSame(AtlasLoopFrozenBattery::GENESIS_PREV, $this->battery->rootHash());
        $this->assertSame([], $this->battery->cases());
    }

    public function test_append_links_the_chain_and_moves_the_root(): void
    {
        $root0 = $this->battery->rootHash();
        $h1 = $this->battery->append($this->badCase('bad-1'));
        $this->assertNotSame($root0, $h1);
        $this->assertSame($h1, $this->battery->rootHash());

        $h2 = $this->battery->append($this->badCase('bad-2'));
        $this->assertSame($h2, $this->battery->rootHash());
        $this->assertCount(2, $this->battery->cases());
        // chain links: case 2's prev_hash is case 1's case_hash
        $cases = $this->battery->cases();
        $this->assertSame($h1, $cases[1]['prev_hash']);
    }

    public function test_hash_is_byte_reproducible_regardless_of_key_order(): void
    {
        $a = $this->battery->computeHash('prev', ['b' => 1, 'a' => ['z' => 9, 'y' => 8]]);
        $b = $this->battery->computeHash('prev', ['a' => ['y' => 8, 'z' => 9], 'b' => 1]);
        $this->assertSame($a, $b, 'recursive ksort ⇒ insertion order does not change the hash');
    }

    public function test_editing_a_case_file_breaks_the_chain(): void
    {
        $this->battery->append($this->badCase('bad-1'));
        $file = $this->dir.'/case-0001-bad.json';
        $rec = json_decode((string) File::get($file), true);
        $rec['contract'] = 'WEAKENED — no longer requires refutation'; // tamper the body, keep the stored hash
        File::put($file, json_encode($rec));

        $this->expectExceptionMessageMatches('/battery_chain_broken/');
        $this->battery->verifyAndRoot();
    }

    public function test_reordering_the_manifest_breaks_the_chain(): void
    {
        $this->battery->append($this->badCase('bad-1'));
        $this->battery->append($this->badCase('bad-2'));
        File::put($this->dir.'/manifest.json', json_encode(['cases' => ['case-0002-bad.json', 'case-0001-bad.json']]));

        $this->expectExceptionMessageMatches('/battery_chain_broken/');
        $this->battery->verifyAndRoot();
    }

    public function test_removing_a_case_file_breaks_the_chain(): void
    {
        $this->battery->append($this->badCase('bad-1'));
        File::delete($this->dir.'/case-0001-bad.json');

        $this->expectExceptionMessageMatches('/missing case file/');
        $this->battery->verifyAndRoot();
    }

    public function test_a_known_good_append_is_two_key(): void
    {
        $good = ['id' => 'good-1', 'kind' => 'good', 'contract' => 'must CERTIFY a genuine RED→GREEN', 'expected_verdict' => 'CERTIFY', 'diff_bytes' => "x"];

        // bad/robust strengthen ⇒ autonomous; a GOOD dilutes the floor ⇒ refused without two-key.
        try {
            $this->battery->append($good);
            $this->fail('a known-good append must require two-key approval');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('two-key', $e->getMessage());
        }

        $h = $this->battery->append($good, twoKeyApproved: true);
        $this->assertSame($h, $this->battery->rootHash());
    }
}
