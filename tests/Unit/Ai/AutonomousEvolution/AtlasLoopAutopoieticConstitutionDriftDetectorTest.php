<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\Autopoiesis\Constitution\AtlasLoopAutopoieticConstitutionDriftDetector;
use App\Services\Ai\AutonomousEvolution\Autopoiesis\Constitution\AtlasLoopAutopoieticConstitutionRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Proves the autopoietic constitution drift detector: matching fingerprint ⇒ drifted=false; missing anchor ⇒
 * drifted=false + last_known_fingerprint=null; divergent fingerprint ⇒ drifted=true + diff_summary non-empty;
 * AND the read-only invariant — calling detect() does NOT mutate the fingerprint file on disk.
 */
final class AtlasLoopAutopoieticConstitutionDriftDetectorTest extends TestCase
{
    private string $fingerprintFile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fingerprintFile = sys_get_temp_dir().'/atlas_constitution_fingerprint_'.bin2hex(random_bytes(6)).'.json';
    }

    protected function tearDown(): void
    {
        if (is_file($this->fingerprintFile)) {
            @unlink($this->fingerprintFile);
        }
        parent::tearDown();
    }

    private function detector(): AtlasLoopAutopoieticConstitutionDriftDetector
    {
        return new AtlasLoopAutopoieticConstitutionDriftDetector(new AtlasLoopAutopoieticConstitutionRegistry, $this->fingerprintFile);
    }

    private function writeAnchor(string $fingerprint, ?string $receiptId = 'rec-1', ?string $verifiedAt = '2026-06-24T00:00:00Z'): void
    {
        file_put_contents($this->fingerprintFile, (string) json_encode([
            'fingerprint' => $fingerprint,
            'last_operator_receipt_id' => $receiptId,
            'last_verified_at' => $verifiedAt,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    public function test_missing_anchor_file_yields_drifted_false_and_last_known_null(): void
    {
        // No file written yet — fresh install case.
        $this->assertFileDoesNotExist($this->fingerprintFile);

        $report = $this->detector()->detect();

        $this->assertFalse($report->drifted, 'no anchor ⇒ no panic');
        $this->assertNull($report->lastKnownFingerprint);
        $this->assertNotEmpty($report->currentFingerprint, 'current fingerprint is always emitted');
        $this->assertSame([], $report->diffSummary);
    }

    public function test_matching_fingerprint_yields_drifted_false_with_anchor_metadata(): void
    {
        $registry = new AtlasLoopAutopoieticConstitutionRegistry;
        $this->writeAnchor($registry->fingerprint(), 'rec-7', '2026-06-24T12:00:00Z');

        $report = $this->detector()->detect();

        $this->assertFalse($report->drifted);
        $this->assertSame($registry->fingerprint(), $report->currentFingerprint);
        $this->assertSame($registry->fingerprint(), $report->lastKnownFingerprint);
        $this->assertSame('rec-7', $report->lastOperatorReceiptId);
        $this->assertSame('2026-06-24T12:00:00Z', $report->lastVerifiedAt);
    }

    public function test_divergent_fingerprint_yields_drifted_true_with_diff_summary(): void
    {
        $this->writeAnchor('stale-fingerprint-deadbeef', 'rec-1');

        $report = $this->detector()->detect();

        $this->assertTrue($report->drifted, 'fingerprint divergence is drift');
        $this->assertSame('stale-fingerprint-deadbeef', $report->lastKnownFingerprint);
        $this->assertNotSame('stale-fingerprint-deadbeef', $report->currentFingerprint);
        $this->assertNotEmpty($report->diffSummary, 'drift must surface a structured diff_summary');
        $this->assertSame('fingerprint_divergence', $report->diffSummary['kind'] ?? null);
        $this->assertSame('rec-1', $report->lastOperatorReceiptId);
    }

    public function test_detect_is_read_only_does_not_mutate_the_anchor_file(): void
    {
        $registry = new AtlasLoopAutopoieticConstitutionRegistry;
        $this->writeAnchor($registry->fingerprint(), 'rec-2');
        $hashBefore = (string) hash_file('sha256', $this->fingerprintFile);

        // Call detect() multiple times — including a path that DOES detect drift.
        $this->detector()->detect();
        $this->detector()->detect();
        $this->writeAnchor('different-fp', 'rec-3'); // operator-controlled — outside the detector
        $this->detector()->detect();

        // Compute the hash after detector calls (with the operator's last write); detector NEVER touched the file.
        $expectedAfterOperatorWrite = (string) hash_file('sha256', $this->fingerprintFile);
        $this->assertNotSame($hashBefore, $expectedAfterOperatorWrite, 'sanity: the operator write DID change the file');

        // Now confirm that purely calling detect() additional times does not change the file.
        $hashBeforeMoreDetect = (string) hash_file('sha256', $this->fingerprintFile);
        for ($i = 0; $i < 5; $i++) {
            $this->detector()->detect();
        }
        $this->assertSame($hashBeforeMoreDetect, (string) hash_file('sha256', $this->fingerprintFile), 'detect() must NEVER mutate the anchor file');
    }

    public function test_malformed_anchor_treated_as_no_anchor_drifted_false(): void
    {
        file_put_contents($this->fingerprintFile, '{ this is not valid json'); // malformed

        $report = $this->detector()->detect();

        $this->assertFalse($report->drifted, 'malformed anchor ⇒ treat as absent, never panic-write');
        $this->assertNull($report->lastKnownFingerprint);
    }
}
