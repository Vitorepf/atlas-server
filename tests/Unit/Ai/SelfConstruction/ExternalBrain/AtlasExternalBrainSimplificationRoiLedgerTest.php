<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainSimplificationRoiLedger;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainSimplificationRoiLedgerTest extends TestCase
{
    private function ledger(): AtlasExternalBrainSimplificationRoiLedger
    {
        return new AtlasExternalBrainSimplificationRoiLedger;
    }

    private function deletion(string $id, string $proof = 'receipt:r1', bool $coverage = true): array
    {
        return [
            'id'                  => $id,
            'action'              => 'delete',
            'target'              => "app/{$id}.php",
            'roi_estimate'        => 0.7,
            'replacement_proof'   => $proof,
            'coverage_maintained' => $coverage,
        ];
    }

    private function merge(string $id, float $roi = 0.5): array
    {
        return ['id' => $id, 'action' => 'merge', 'target' => "app/{$id}.php", 'roi_estimate' => $roi];
    }

    // ── AC1: output shape ─────────────────────────────────────────────────────

    public function test_output_has_required_keys(): void
    {
        $r = $this->ledger()->record([]);
        $this->assertSame(AtlasExternalBrainSimplificationRoiLedger::SCHEMA, $r['schema_version']);
        $this->assertArrayHasKey('approved', $r);
        $this->assertArrayHasKey('refused', $r);
        $this->assertArrayHasKey('total_roi', $r);
        $this->assertArrayHasKey('refused_roi', $r);
    }

    public function test_empty_candidates_gives_zero_roi(): void
    {
        $r = $this->ledger()->record([]);
        $this->assertEmpty($r['approved']);
        $this->assertEmpty($r['refused']);
        $this->assertSame(0.0, $r['total_roi']);
    }

    public function test_valid_deletion_is_approved_and_adds_roi(): void
    {
        $r = $this->ledger()->record(['candidates' => [$this->deletion('DeadService')]]);

        $this->assertCount(1, $r['approved']);
        $this->assertEmpty($r['refused']);
        $this->assertSame(0.7, $r['total_roi']);
        $this->assertSame('delete', $r['approved'][0]['action']);
    }

    public function test_valid_merge_is_approved(): void
    {
        $r = $this->ledger()->record(['candidates' => [$this->merge('FooBar', 0.5)]]);

        $this->assertCount(1, $r['approved']);
        $this->assertSame('merge', $r['approved'][0]['action']);
    }

    // ── AC2: deletion refusal guards ──────────────────────────────────────────

    public function test_deletion_without_replacement_proof_key_is_refused(): void
    {
        $candidate = ['id' => 'c1', 'action' => 'delete', 'target' => 'app/Old.php', 'roi_estimate' => 0.6];
        // No replacement_proof key at all.
        $r = $this->ledger()->record(['candidates' => [$candidate]]);

        $this->assertEmpty($r['approved']);
        $this->assertCount(1, $r['refused']);
        $this->assertSame('deletion_without_replacement_proof', $r['refused'][0]['refusal_reason']);
    }

    public function test_deletion_with_empty_replacement_proof_is_refused(): void
    {
        $r = $this->ledger()->record(['candidates' => [$this->deletion('c1', '')]]);

        $this->assertEmpty($r['approved']);
        $this->assertSame('deletion_without_replacement_proof', $r['refused'][0]['refusal_reason']);
    }

    public function test_deletion_with_explicit_false_coverage_is_refused(): void
    {
        $r = $this->ledger()->record(['candidates' => [$this->deletion('c1', 'proof:ok', false)]]);

        $this->assertEmpty($r['approved']);
        $this->assertSame('deletion_without_coverage_guarantee', $r['refused'][0]['refusal_reason']);
    }

    public function test_proof_check_takes_precedence_over_coverage_check(): void
    {
        // Both missing proof and false coverage → proof refusal fires first.
        $candidate = [
            'id'                  => 'c1',
            'action'              => 'delete',
            'target'              => 'app/Old.php',
            'roi_estimate'        => 0.6,
            'replacement_proof'   => '',
            'coverage_maintained' => false,
        ];
        $r = $this->ledger()->record(['candidates' => [$candidate]]);

        $this->assertSame('deletion_without_replacement_proof', $r['refused'][0]['refusal_reason']);
    }

    public function test_deletion_with_true_coverage_and_proof_is_approved(): void
    {
        // coverage_maintained=true should not trigger refusal.
        $r = $this->ledger()->record(['candidates' => [$this->deletion('c1', 'receipt:ok', true)]]);
        $this->assertCount(1, $r['approved']);
    }

    // ── ROI accounting ────────────────────────────────────────────────────────

    public function test_refused_roi_accumulates_opportunity_cost(): void
    {
        $r = $this->ledger()->record(['candidates' => [
            $this->deletion('c1', ''),  // refused → 0.7 opportunity cost
            $this->merge('c2', 0.4),    // approved
        ]]);

        $this->assertSame(0.4, $r['total_roi']);
        $this->assertSame(0.7, $r['refused_roi']);
    }

    public function test_merge_with_negative_roi_is_refused(): void
    {
        $r = $this->ledger()->record(['candidates' => [$this->merge('c1', -0.1)]]);

        $this->assertEmpty($r['approved']);
        $this->assertSame('negative_roi_estimate', $r['refused'][0]['refusal_reason']);
    }

    public function test_output_is_deterministic(): void
    {
        $facts = ['candidates' => [$this->deletion('x1'), $this->merge('x2'), $this->deletion('x3', '')]];
        $a     = $this->ledger()->record($facts);
        $b     = $this->ledger()->record($facts);
        $this->assertSame(json_encode($a), json_encode($b));
    }
}
