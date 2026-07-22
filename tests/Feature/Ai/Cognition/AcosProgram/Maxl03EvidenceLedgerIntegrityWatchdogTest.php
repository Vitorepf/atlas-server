<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Cognition\AcosProgram;

use App\Services\Ai\Cognition\Watchdog\Checks\EvidenceLedgerIntegrityWatchdogCheck;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AtomicBacklog\EvidenceLedgerHashChainIntegrityVerifier;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class Maxl03EvidenceLedgerIntegrityWatchdogTest extends TestCase
{
    private string $ledgerPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ledgerPath = sys_get_temp_dir().'/atlas-maxl03-'.uniqid('', true).'.jsonl';
    }

    protected function tearDown(): void
    {
        if (is_file($this->ledgerPath)) {
            @unlink($this->ledgerPath);
        }

        parent::tearDown();
    }

    private function makeCheck(callable $verifyDay): EvidenceLedgerIntegrityWatchdogCheck
    {
        return new EvidenceLedgerIntegrityWatchdogCheck(
            app(EvidenceLedgerHashChainIntegrityVerifier::class),
            $this->ledgerPath,
            CarbonImmutable::parse('2026-07-12T00:00:00+00:00'),
            $verifyDay,
        );
    }

    #[Test]
    public function returns_ok_and_appends_line_when_all_chains_intact(): void
    {
        $check = $this->makeCheck(static fn () => [[
            'chain_key' => 'measure:aobg.latency_ledger.v1',
            'scope_key' => 'measure:aobg.latency_ledger.v1',
            'status' => 'ok',
            'chain_length' => 5,
            'gap_count' => 0,
            'tampered_event_ids' => [],
            'legacy_unchained_count' => 0,
            'chain_head_event_hash' => str_repeat('a', 64),
        ]]);

        $result = $check->run()->toArray();

        $this->assertSame('ok', $result['status']);
        $this->assertSame('wdg-01.evidence_ledger_integrity', $check->id());
        $this->assertSame(1, $result['evidence']['chains']);
        $this->assertSame(5, $result['evidence']['chain_length']);
        $this->assertSame([], $result['evidence']['tampered_event_ids']);
        $this->assertSame(0, $result['evidence']['gap_count']);
        $this->assertNull($result['alert']);

        $this->assertFileExists($this->ledgerPath);
        $contents = file_get_contents($this->ledgerPath);
        $this->assertNotFalse($contents);
        $this->assertStringContainsString('"date":"2026-07-12"', $contents);
        $this->assertStringNotContainsString('"payload"', $contents, 'no raw payload in artifact');
    }

    #[Test]
    public function tampered_events_trigger_alert_and_land_in_ledger(): void
    {
        $check = $this->makeCheck(static fn () => [[
            'chain_key' => 'measure:test.v1',
            'scope_key' => 'measure:test.v1',
            'status' => 'tampered',
            'chain_length' => 4,
            'gap_count' => 0,
            'tampered_event_ids' => ['e-2'],
            'legacy_unchained_count' => 0,
            'chain_head_event_hash' => str_repeat('b', 64),
        ]]);

        $result = $check->run()->toArray();

        $this->assertSame('alert', $result['status']);
        $this->assertSame('evidence_ledger_tampered', $result['alert']['code']);
        $this->assertSame(['e-2'], $result['alert']['tampered_event_ids']);
        $this->assertSame(['e-2'], $result['evidence']['tampered_event_ids']);
        $this->assertStringContainsString('"tampered_total":1', file_get_contents($this->ledgerPath));
    }

    #[Test]
    public function gap_events_trigger_alert(): void
    {
        $check = $this->makeCheck(static fn () => [[
            'chain_key' => 'measure:test.v1',
            'scope_key' => 'measure:test.v1',
            'status' => 'gap',
            'chain_length' => 3,
            'gap_count' => 2,
            'tampered_event_ids' => [],
            'legacy_unchained_count' => 0,
            'chain_head_event_hash' => str_repeat('c', 64),
        ]]);

        $result = $check->run()->toArray();

        $this->assertSame('alert', $result['status']);
        $this->assertSame('evidence_ledger_gap', $result['alert']['code']);
        $this->assertSame(2, $result['alert']['gap_count']);
    }

    #[Test]
    public function empty_chains_produce_ok_without_alert(): void
    {
        $check = $this->makeCheck(static fn () => []);

        $result = $check->run()->toArray();

        $this->assertSame('ok', $result['status']);
        $this->assertSame(0, $result['evidence']['chains']);
        $this->assertNull($result['alert']);
    }
}
