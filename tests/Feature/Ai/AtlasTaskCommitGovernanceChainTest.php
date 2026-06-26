<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\Governance\AtlasTaskCommitGovernanceChain;
use App\Services\Ai\SelfConstruction\MergeGovernor\AtlasMergeGovernorReleaseDecisionLedger;
use App\Services\Ai\SelfConstruction\VerificationCourt\AtlasVerificationCourtVerdictLedger;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * SPINE keystone — the governance chain that finally runs the Merge Governor + Verification Court on a LIVE
 * delivery. Proves: observe RECORDS without blocking (so the bootstrap that builds these organs is never
 * self-locked), enforce blocks a non-admitted candidate, off is a no-op, and ANY internal failure fails OPEN.
 */
final class AtlasTaskCommitGovernanceChainTest extends TestCase
{
    /** @var list<string> */
    private array $files = [];

    private const CLOCK = '2026-06-25T00:00:00+00:00';

    protected function tearDown(): void
    {
        foreach ($this->files as $f) {
            @unlink($f);
        }
        parent::tearDown();
    }

    private function tempLedgerPath(string $tag): string
    {
        $p = rtrim(sys_get_temp_dir(), '/').'/atlas-gov-'.$tag.'-'.bin2hex(random_bytes(5)).'.jsonl';
        $this->files[] = $p;

        return $p;
    }

    private function chain(string $mode, string $verdictPath, string $releasePath): AtlasTaskCommitGovernanceChain
    {
        return new AtlasTaskCommitGovernanceChain(
            riskClassifier: null,
            rollbackGate: null,
            admissionPolicy: null,
            verdictLedger: new AtlasVerificationCourtVerdictLedger($verdictPath),
            releaseLedger: new AtlasMergeGovernorReleaseDecisionLedger($releasePath),
            clock: static fn (): string => self::CLOCK,
            modeOverride: $mode,
        );
    }

    private function lineCount(string $path): int
    {
        if (! is_file($path)) {
            return 0;
        }

        return count(array_filter(explode("\n", trim((string) File::get($path))), static fn (string $l): bool => trim($l) !== ''));
    }

    public function test_observe_admits_clean_medium_risk_change_and_records_passed_verdict(): void
    {
        $vp = $this->tempLedgerPath('v');
        $rp = $this->tempLedgerPath('r');

        $out = $this->chain(AtlasTaskCommitGovernanceChain::MODE_OBSERVE, $vp, $rp)->govern([
            'task_packet_id' => 'lane-3-strategy-01',
            'changed_files' => ['app/Services/Ai/SelfConstruction/StrategyCouncil/AtlasStrategyCouncilLeverageRanker.php'],
            'verification' => ['passed' => true, 'checks' => ['syntax' => 'pass', 'boot' => 'pass']],
        ]);

        $this->assertSame('observe', $out['mode']);
        $this->assertSame('admitted', $out['decision']);
        $this->assertTrue($out['admitted']);
        $this->assertFalse($out['enforced_block'], 'observe never blocks');
        $this->assertSame('medium', $out['risk_level']);
        $this->assertSame('ok', $out['recorded']['verdict_ledger']);
        $this->assertSame('ok', $out['recorded']['release_ledger']);
        $this->assertSame(1, $this->lineCount($vp), 'one verdict recorded');
        $this->assertSame(1, $this->lineCount($rp), 'one release decision recorded');
    }

    public function test_observe_records_but_never_blocks_high_risk_governor_change(): void
    {
        // A worker building the Merge Governor itself ⇒ HIGH risk ⇒ outside release window ⇒ decision=blocked.
        // The whole point of observe: this is RECORDED but the commit is NOT blocked, so the bootstrap that
        // builds the governor is never self-locked.
        $vp = $this->tempLedgerPath('v');
        $rp = $this->tempLedgerPath('r');

        $out = $this->chain(AtlasTaskCommitGovernanceChain::MODE_OBSERVE, $vp, $rp)->govern([
            'task_packet_id' => 'lane-1-mergegov-01',
            'changed_files' => ['app/Services/Ai/SelfConstruction/MergeGovernor/AtlasMergeGovernorAdmissionPolicy.php'],
            'verification' => ['passed' => true, 'checks' => ['syntax' => 'pass']],
        ]);

        $this->assertSame('high', $out['risk_level']);
        $this->assertSame('blocked', $out['decision']);
        $this->assertFalse($out['admitted']);
        $this->assertFalse($out['enforced_block'], 'observe must NOT block even a blocked-decision candidate');
        $this->assertSame(1, $this->lineCount($vp), 'the blocked verdict is still recorded');
    }

    public function test_enforce_blocks_a_non_admitted_candidate(): void
    {
        $vp = $this->tempLedgerPath('v');
        $rp = $this->tempLedgerPath('r');

        $out = $this->chain(AtlasTaskCommitGovernanceChain::MODE_ENFORCE, $vp, $rp)->govern([
            'task_packet_id' => 'lane-1-mergegov-02',
            'changed_files' => ['app/Services/Ai/SelfConstruction/MergeGovernor/AtlasMergeGovernorRiskClassifier.php'],
            'verification' => ['passed' => true, 'checks' => []],
        ]);

        $this->assertSame('enforce', $out['mode']);
        $this->assertFalse($out['admitted']);
        $this->assertTrue($out['enforced_block'], 'enforce blocks a non-admitted candidate');
    }

    public function test_enforce_admits_a_clean_in_window_change(): void
    {
        $vp = $this->tempLedgerPath('v');
        $rp = $this->tempLedgerPath('r');

        $out = $this->chain(AtlasTaskCommitGovernanceChain::MODE_ENFORCE, $vp, $rp)->govern([
            'task_packet_id' => 'lane-3-strategy-02',
            'changed_files' => ['app/Services/Ai/SelfConstruction/StrategyCouncil/AtlasStrategyCouncilDecisionLedger.php'],
            'verification' => ['passed' => true, 'checks' => ['syntax' => 'pass', 'boot' => 'pass']],
        ]);

        $this->assertTrue($out['admitted']);
        $this->assertFalse($out['enforced_block'], 'a clean in-window change is admitted even under enforce');
    }

    public function test_off_is_a_noop_with_no_ledger_write(): void
    {
        $vp = $this->tempLedgerPath('v');
        $rp = $this->tempLedgerPath('r');

        $out = $this->chain(AtlasTaskCommitGovernanceChain::MODE_OFF, $vp, $rp)->govern([
            'task_packet_id' => 'whatever',
            'changed_files' => ['app/Services/Ai/SelfConstruction/MergeGovernor/AtlasMergeGovernorAdmissionPolicy.php'],
            'verification' => ['passed' => true],
        ]);

        $this->assertSame('off', $out['mode']);
        $this->assertFalse($out['ran']);
        $this->assertTrue($out['admitted'], 'off admits trivially');
        $this->assertSame(0, $this->lineCount($vp), 'off writes nothing');
        $this->assertSame(0, $this->lineCount($rp), 'off writes nothing');
    }

    public function test_fail_open_when_a_ledger_path_is_unwritable(): void
    {
        // Point a ledger at a path UNDER a regular file ⇒ the append throws ENOTDIR. The chain must isolate that
        // (recorded=error) and STILL admit/never-block — governance can only ever ADD a verdict, never wedge a worker.
        $blocker = $this->tempLedgerPath('blocker');
        File::put($blocker, 'i am a file, not a directory');
        $badPath = $blocker.'/sub/ledger.jsonl';

        $out = $this->chain(AtlasTaskCommitGovernanceChain::MODE_OBSERVE, $badPath, $badPath)->govern([
            'task_packet_id' => 'lane-3-strategy-03',
            'changed_files' => ['app/Services/Ai/SelfConstruction/StrategyCouncil/AtlasStrategyCouncilLeverageRanker.php'],
            'verification' => ['passed' => true, 'checks' => ['syntax' => 'pass']],
        ]);

        $this->assertTrue($out['admitted'], 'a broken ledger must never block the worker');
        $this->assertFalse($out['enforced_block']);
        $this->assertSame('error', $out['recorded']['verdict_ledger']);
    }

    public function test_no_server_green_is_blocked_in_observe_without_blocking_the_commit(): void
    {
        // The verifier was disabled / did not prove green ⇒ no server proof ⇒ the candidate is honestly NOT
        // admissible (the risk classifier itself treats unverified as blocked). Observe still does not block the
        // commit (the legacy honor path keeps working while we gather the verdict trail).
        $vp = $this->tempLedgerPath('v');
        $rp = $this->tempLedgerPath('r');

        $out = $this->chain(AtlasTaskCommitGovernanceChain::MODE_OBSERVE, $vp, $rp)->govern([
            'task_packet_id' => 'lane-3-strategy-04',
            'changed_files' => ['app/Services/Ai/SelfConstruction/StrategyCouncil/AtlasStrategyCouncilLeverageRanker.php'],
            'verification' => ['passed' => false, 'checks' => []],
        ]);

        $this->assertSame('blocked', $out['decision']);
        $this->assertFalse($out['admitted']);
        $this->assertFalse($out['enforced_block'], 'observe records the block but does not wedge the commit');
    }
}
