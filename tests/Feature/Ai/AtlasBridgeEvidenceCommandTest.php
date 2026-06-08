<?php

namespace Tests\Feature\Ai;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * The governed evidence-OUT funnel must (1) admit only the closed evidence
 * vocabulary, (2) refuse secret-class data on the provider path, (3) reject
 * self-inferred success, and (4) NEVER allow promotion — every path returns
 * decision=hold / promotion_allowed=false. These are the funnel's safety
 * invariants; the append-only ledger write is covered by LedgerAppendOnlyTest.
 */
class AtlasBridgeEvidenceCommandTest extends TestCase
{
    /**
     * @return array<string,mixed>
     */
    private function bridge(array $options): array
    {
        $code = Artisan::call('atlas:ai:bridge-evidence', array_merge(['--json' => true], $options));
        $json = json_decode(Artisan::output(), true);

        return ['code' => $code, 'json' => is_array($json) ? $json : []];
    }

    public function test_governs_and_holds_clean_provider_evidence(): void
    {
        $r = $this->bridge([
            '--kind' => 'provider_call',
            '--evidence-ref' => 'ev-clean-1',
            '--evidence-complete' => true,
            '--gate-result' => 'pass',
            '--has-outcome' => true,
            '--metric-value' => '0.5',
            '--metric-min' => '0',
            '--metric-max' => '1',
            '--source' => 'claude_cli_workflow',
        ]);

        $this->assertSame(0, $r['code']);
        $this->assertTrue($r['json']['governed']);
        $this->assertSame('govern', $r['json']['govern_action']);
        // Safety invariants — true on EVERY path:
        $this->assertSame('hold', $r['json']['decision']);
        $this->assertFalse($r['json']['promotion_allowed']);
        $this->assertNotEmpty($r['json']['evidence_receipt_hash']);
    }

    public function test_rejects_self_inferred_success(): void
    {
        // claims success with NO gate pass and NO outcome => contaminated inference.
        $r = $this->bridge([
            '--kind' => 'provider_call',
            '--evidence-ref' => 'ev-liar-1',
            '--evidence-complete' => true,
            '--claims-success' => true,
        ]);

        $this->assertSame(1, $r['code']);
        $this->assertFalse($r['json']['governed']);
        $this->assertSame('reject', $r['json']['govern_action']);
        $this->assertFalse($r['json']['promotion_allowed']);
    }

    public function test_refuses_secret_class_on_provider_path(): void
    {
        $r = $this->bridge([
            '--kind' => 'provider_call',
            '--evidence-ref' => 'ev-secret-1',
            '--evidence-complete' => true,
            '--gate-result' => 'pass',
            '--has-outcome' => true,
            '--privacy-class' => 'secret',
        ]);

        $this->assertSame(1, $r['code']);
        $this->assertFalse($r['json']['governed']);
        $this->assertSame('secret_class_refused_on_provider_path', $r['json']['reason']);
        $this->assertFalse($r['json']['promotion_allowed']);
    }

    public function test_rejects_unknown_evidence_kind(): void
    {
        $r = $this->bridge([
            '--kind' => 'totally_made_up_kind',
            '--evidence-ref' => 'ev-x',
        ]);

        $this->assertSame(1, $r['code']);
        $this->assertFalse($r['json']['admitted']);
        $this->assertSame('unknown_evidence_kind', $r['json']['reason']);
        $this->assertFalse($r['json']['promotion_allowed']);
    }
}
