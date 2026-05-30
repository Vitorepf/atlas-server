<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Foundry\Rsi\EarnedAutonomy;

use App\Services\Ai\Foundry\Rsi\EarnedAutonomy\TrustLedgerService;
use RuntimeException;
use Tests\TestCase;

/**
 * Earned Autonomy · TrustLedgerService proof.
 *
 * The trust ledger is the UNFORGEABLE basis of any earned autonomy. This test
 * proves the loop cannot inflate its own tier:
 *
 *   - earnedTier() is a PURE fold over injected records (no I/O), returning the
 *     max-auto-rank int per the frozen thresholds {<3:-1, 3..9:0, 10..29:1,
 *     >=30:2} — and NEVER returns 3.
 *   - a cycle qualifies ONLY when all three real signals are genuinely true;
 *     any missing/non-boolean signal FAILS CLOSED (no trust accrued).
 *   - a revocation event resets accrued trust to the floor.
 *   - the persisted ledger is append-only with a prior-hash chain; any rewrite
 *     or removal of a prior line is a tamper signal that replay() REJECTS.
 *   - there is NO method that writes a pass without the four real signals.
 */
final class TrustLedgerServiceTest extends TestCase
{
    private const CLOCK = '2026-05-30T00:00:00+00:00';

    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpRoot = sys_get_temp_dir().'/atlas_ea_trust_ledger_'.bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tmpRoot)) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->tmpRoot, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($it as $f) {
                $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
            }
            @rmdir($this->tmpRoot);
        }
        parent::tearDown();
    }

    private function service(): TrustLedgerService
    {
        $svc = new TrustLedgerService(fn (): string => self::CLOCK);
        $svc->setStorageRootForTesting($this->tmpRoot);

        return $svc;
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function provenRecord(array $overrides = []): array
    {
        return array_merge([
            'event_type' => TrustLedgerService::EVENT_CYCLE_PROVEN,
            'outcome_proven' => true,
            'red_team_survived' => true,
            'drift_clean' => true,
        ], $overrides);
    }

    // ---- earnedTier is a pure fold over injected records ------------------

    public function test_earned_tier_is_a_pure_fold_returning_max_auto_rank_per_thresholds(): void
    {
        $svc = $this->service();

        // 0 proven cycles => no autonomy.
        $this->assertSame(-1, $svc->earnedTier('area', 'focus', []));

        // 2 < 3 => still no autonomy.
        $records = array_fill(0, 2, $this->provenRecord());
        $this->assertSame(-1, $svc->earnedTier('area', 'focus', $records));

        // 3 => tier 1 max-auto-rank 0 (cosmetic).
        $records = array_fill(0, 3, $this->provenRecord());
        $this->assertSame(0, $svc->earnedTier('area', 'focus', $records));

        // 10 => tier 2 max-auto-rank 1.
        $records = array_fill(0, 10, $this->provenRecord());
        $this->assertSame(1, $svc->earnedTier('area', 'focus', $records));

        // 30 => tier 3 max-auto-rank 2.
        $records = array_fill(0, 30, $this->provenRecord());
        $this->assertSame(2, $svc->earnedTier('area', 'focus', $records));
    }

    public function test_earned_tier_never_returns_three_even_with_huge_history(): void
    {
        $svc = $this->service();
        $records = array_fill(0, 5000, $this->provenRecord());

        $this->assertSame(2, $svc->earnedTier('area', 'focus', $records));
    }

    // ---- cannot forge a pass: every signal must be genuinely true ---------

    public function test_a_cycle_with_a_false_signal_does_not_accrue_trust(): void
    {
        $svc = $this->service();

        // 30 cycles but each missing drift_clean => zero consecutive qualifying.
        $records = array_fill(0, 30, $this->provenRecord(['drift_clean' => false]));
        $this->assertSame(-1, $svc->earnedTier('area', 'focus', $records));
    }

    public function test_truthy_non_boolean_signals_fail_closed_and_do_not_qualify(): void
    {
        $svc = $this->service();

        // recordCycle must coerce non-strict-true signals to false in storage,
        // so a loop cannot pass "1"/"true"/1 as a proof.
        foreach (['outcome_proven' => 1, 'red_team_survived' => 'true', 'drift_clean' => '1'] as $key => $truthy) {
            $event = $svc->recordCycle(
                ['area_id' => 'a', 'focus' => $key, 'cycle_id' => 'c1'],
                array_merge($this->provenRecord(), [$key => $truthy])
            );
            $this->assertFalse($event[$key], "signal {$key} must fail closed to false");
            $this->assertFalse($event['qualifies'], "cycle with non-strict {$key} must not qualify");
        }
    }

    public function test_there_is_no_method_that_writes_a_pass_without_the_four_signals(): void
    {
        // The ONLY public writers are recordCycle (validates signals) and
        // recordRevocation (never qualifies). No setter/forcing method exists.
        $methods = array_map(
            static fn (\ReflectionMethod $m): string => $m->getName(),
            (new \ReflectionClass(TrustLedgerService::class))->getMethods(\ReflectionMethod::IS_PUBLIC)
        );

        $writers = array_values(array_filter(
            $methods,
            static fn (string $n): bool => str_starts_with($n, 'record') || str_starts_with($n, 'set') || str_starts_with($n, 'append')
        ));

        sort($writers);
        $this->assertSame(['recordCycle', 'recordRevocation', 'setStorageRootForTesting'], $writers);
    }

    // ---- revocation resets accrued trust ----------------------------------

    public function test_revocation_resets_consecutive_qualifying_counter(): void
    {
        $svc = $this->service();

        $records = array_merge(
            array_fill(0, 30, $this->provenRecord()),                 // would be tier 3
            [['event_type' => TrustLedgerService::EVENT_REVOKED]],      // revoke
            array_fill(0, 2, $this->provenRecord())                     // only 2 since revoke
        );

        // 2 < 3 since the revocation => back to no autonomy.
        $this->assertSame(-1, $svc->earnedTier('area', 'focus', $records));
    }

    public function test_qualifying_cycles_after_revocation_re_accrue(): void
    {
        $svc = $this->service();

        $records = array_merge(
            array_fill(0, 5, $this->provenRecord()),
            [['event_type' => TrustLedgerService::EVENT_REVOKED]],
            array_fill(0, 3, $this->provenRecord())                     // exactly tier 1 again
        );

        $this->assertSame(0, $svc->earnedTier('area', 'focus', $records));
    }

    // ---- append-only + prior-hash chain integrity -------------------------

    public function test_record_cycle_persists_a_chained_append_only_event(): void
    {
        $svc = $this->service();

        $e1 = $svc->recordCycle(['area_id' => 'a', 'focus' => 'f', 'cycle_id' => 'c1'], $this->provenRecord());
        $e2 = $svc->recordCycle(['area_id' => 'a', 'focus' => 'f', 'cycle_id' => 'c2'], $this->provenRecord());

        $this->assertSame('genesis', $e1['prev_hash']);
        $this->assertSame($e1['event_hash'], $e2['prev_hash'], 'second event must chain to first');
        $this->assertNotSame($e1['event_hash'], $e2['event_hash']);

        $replayed = $svc->replay('a', 'f');
        $this->assertCount(2, $replayed);
        $this->assertSame('c1', $replayed[0]['cycle_id']);
        $this->assertSame('c2', $replayed[1]['cycle_id']);
        // Folded from the real persisted chain: 2 proven < 3 => -1.
        $this->assertSame(-1, $svc->earnedTier('a', 'f'));
    }

    public function test_replay_rejects_a_rewritten_prior_line_as_tamper(): void
    {
        $svc = $this->service();
        $svc->recordCycle(['area_id' => 'a', 'focus' => 'f', 'cycle_id' => 'c1'], $this->provenRecord());
        $svc->recordCycle(['area_id' => 'a', 'focus' => 'f', 'cycle_id' => 'c2'], $this->provenRecord());

        $path = $svc->ledgerPath('a', 'f');
        $lines = array_values(array_filter(explode("\n", (string) file_get_contents($path)), static fn ($l) => trim($l) !== ''));
        // Rewrite the FIRST line (forge an extra qualifying cycle) — this breaks
        // the prev-hash chain for the second line.
        $forged = json_decode($lines[0], true);
        $forged['cycle_id'] = 'forged';
        $forged['event_hash'] = 'tampered';
        $lines[0] = (string) json_encode($forged, JSON_UNESCAPED_SLASHES);
        file_put_contents($path, implode("\n", $lines)."\n");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('prev-hash chain break');
        $svc->replay('a', 'f');
    }

    public function test_replay_rejects_a_removed_prior_line_as_tamper(): void
    {
        $svc = $this->service();
        $svc->recordCycle(['area_id' => 'a', 'focus' => 'f', 'cycle_id' => 'c1'], $this->provenRecord());
        $svc->recordCycle(['area_id' => 'a', 'focus' => 'f', 'cycle_id' => 'c2'], $this->provenRecord());

        $path = $svc->ledgerPath('a', 'f');
        $lines = array_values(array_filter(explode("\n", (string) file_get_contents($path)), static fn ($l) => trim($l) !== ''));
        // Remove the FIRST line: the surviving second line's prev_hash no longer
        // matches genesis => chain break.
        file_put_contents($path, $lines[1]."\n");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('prev-hash chain break');
        $svc->replay('a', 'f');
    }

    // ---- revocation event API ---------------------------------------------

    public function test_record_revocation_appends_a_revoked_event_with_closed_set_reason(): void
    {
        $svc = $this->service();
        $svc->recordCycle(['area_id' => 'a', 'focus' => 'f', 'cycle_id' => 'c1'], $this->provenRecord());
        $rev = $svc->recordRevocation(['area_id' => 'a', 'focus' => 'f', 'cycle_id' => 'c1'], 'drift_anomaly');

        $this->assertSame(TrustLedgerService::EVENT_REVOKED, $rev['event_type']);
        $this->assertSame('drift_anomaly', $rev['reason']);
        $this->assertFalse($rev['qualifies']);

        // An out-of-set reason is normalized to a safe closed-set value.
        $rev2 = $svc->recordRevocation(['area_id' => 'a', 'focus' => 'f'], 'made_up_reason');
        $this->assertSame('anomaly_detected', $rev2['reason']);
    }

    public function test_tier_name_maps_max_auto_rank_to_human_facing_tier(): void
    {
        $svc = $this->service();
        $this->assertSame(TrustLedgerService::TIER_0, $svc->tierName(-1));
        $this->assertSame(TrustLedgerService::TIER_1, $svc->tierName(0));
        $this->assertSame(TrustLedgerService::TIER_2, $svc->tierName(1));
        $this->assertSame(TrustLedgerService::TIER_3, $svc->tierName(2));
    }
}
