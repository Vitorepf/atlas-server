<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\Governance\AtlasConstitutionalKernelService;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class AtlasLoopConstitutionCommandTest extends TestCase
{
    private string $ledgerPath = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->ledgerPath = sys_get_temp_dir().'/atlas-constitution-ledger-'.bin2hex(random_bytes(6)).'.jsonl';
    }

    protected function tearDown(): void
    {
        @unlink($this->ledgerPath);
        parent::tearDown();
    }

    private function runCmd(array $args): array
    {
        $args['--ledger-path'] = $this->ledgerPath;
        $args['--json'] = true;
        $exit = Artisan::call('atlas:loop:constitution', $args);

        return [$exit, json_decode(trim(Artisan::output()), true)];
    }

    private function ledgerLines(): array
    {
        if (! is_file($this->ledgerPath)) {
            return [];
        }

        return array_filter(explode("\n", (string) file_get_contents($this->ledgerPath)));
    }

    // ---------- list ----------

    public function test_list_returns_invariants_with_required_keys(): void
    {
        [$exit, $payload] = $this->runCmd(['action' => 'list']);

        $this->assertSame(0, $exit);
        $kernel = new AtlasConstitutionalKernelService;
        $expected = count($kernel->listInvariants());
        $this->assertSame($expected, $payload['count']);
        $this->assertCount($expected, $payload['invariants']);

        foreach ($payload['invariants'] as $row) {
            $this->assertArrayHasKey('id', $row, 'each invariant must have id');
            $this->assertArrayHasKey('fingerprint', $row, 'each invariant must have fingerprint');
            $this->assertArrayHasKey('petreo', $row, 'each invariant must have petreo');
            $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $row['fingerprint']);
        }
    }

    public function test_list_petreo_flag_is_true_for_petreo_class_invariants(): void
    {
        [$exit, $payload] = $this->runCmd(['action' => 'list']);

        $this->assertSame(0, $exit);
        $kernel   = new AtlasConstitutionalKernelService;
        $petreoIds = array_map(
            static fn (array $inv): string => (string) $inv['id'],
            $kernel->listInvariants(AtlasConstitutionalKernelService::CLASS_PETREO),
        );

        foreach ($payload['invariants'] as $row) {
            $expectedPetreo = in_array($row['id'], $petreoIds, true);
            $this->assertSame($expectedPetreo, (bool) $row['petreo'], "petreo flag wrong for {$row['id']}");
        }
    }

    // ---------- drift ----------

    public function test_drift_without_baseline_exits_zero_with_ok_true(): void
    {
        [$exit, $payload] = $this->runCmd(['action' => 'drift']);

        $this->assertSame(0, $exit);
        $this->assertTrue($payload['ok'], 'drift with no baseline must report ok=true');
        $this->assertSame([], $payload['missing_ids']);
    }

    public function test_drift_with_baseline_missing_one_invariant_exits_nonzero_with_precise_missing_id(): void
    {
        $kernel = new AtlasConstitutionalKernelService;
        $all    = $kernel->listInvariants();
        $this->assertNotEmpty($all, 'registry must have at least one invariant');

        // Baseline has one extra invariant that no longer exists in the live registry.
        $fakeId  = 'test_invariant_that_does_not_exist_in_registry';
        $baseline = array_map(static fn (array $inv): array => ['id' => (string) $inv['id']], $all);
        $baseline[] = ['id' => $fakeId];

        [$exit, $payload] = $this->runCmd(['action' => 'drift', '--since' => json_encode($baseline)]);

        $this->assertNotSame(0, $exit, 'drift must exit non-zero when baseline has ids absent from current registry');
        $this->assertFalse($payload['ok']);
        $this->assertContains($fakeId, $payload['missing_ids'], 'the missing id must appear in missing_ids');
    }

    // ---------- ledger ----------

    public function test_list_appends_exactly_one_ledger_receipt(): void
    {
        $before = count($this->ledgerLines());
        $this->runCmd(['action' => 'list']);
        $after = count($this->ledgerLines());

        $this->assertSame($before + 1, $after, 'list must append exactly one ledger receipt');
        $lines = $this->ledgerLines();
        $last  = json_decode((string) end($lines), true);
        $this->assertSame('list', $last['action']);
    }

    public function test_drift_appends_exactly_one_ledger_receipt(): void
    {
        $before = count($this->ledgerLines());
        $this->runCmd(['action' => 'drift']);
        $after = count($this->ledgerLines());

        $this->assertSame($before + 1, $after, 'drift must append exactly one ledger receipt');
        $lines = $this->ledgerLines();
        $last  = json_decode((string) end($lines), true);
        $this->assertSame('drift', $last['action']);
    }

    public function test_command_is_registered(): void
    {
        Artisan::call('list', ['--raw' => true]);
        $this->assertStringContainsString('atlas:loop:constitution', Artisan::output());
    }
}
